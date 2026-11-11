<?php

require_once dirname(__DIR__)."/bootstrap.php";
require_once __DIR__."/business.store.php";
require_once __DIR__."/chat.service.php";

/*=============================================
Cuenta de WhatsApp Cloud API (tabla whatsapps)

Antes de guardar se consulta a Meta con el ID del
número y el token: en pocos segundos se sabe si
los datos son correctos y a qué número pertenecen.
=============================================*/

class WhatsAppAccount{

	static private function pdo(){

		return BizStore::pdo();

	}

	static public function graph(){

		return rtrim((string)cms_config("graph_base", "https://graph.facebook.com"), "/")."/".cms_config("graph_version", "v22.0")."/";

	}

	/* Valores guardados por el formulario antiguo (urlencode) */

	static private function clean($value){

		$value = (string)$value;

		return preg_match('/%[0-9A-F]{2}|\+/i', $value) ? urldecode($value) : $value;

	}

	static public function all(){

		$rows = self::pdo()->query("SELECT * FROM whatsapps ORDER BY status_whatsapp DESC, id_whatsapp DESC")->fetchAll();

		foreach ($rows as &$r) {

			foreach (array("number_whatsapp", "id_number_whatsapp", "id_app_whatsapp", "token_whatsapp") as $k) {

				$r[$k] = self::clean($r[$k]);
			}
		}

		return $rows;

	}

	static public function find($id){

		foreach (self::all() as $r) {

			if((int)$r["id_whatsapp"] === (int)$id){

				return $r;
			}
		}

		return null;

	}

	/* Para mostrar en el navegador: el token nunca completo */

	static public function publicRow(array $r){

		$token = (string)$r["token_whatsapp"];
		$r["token_hint"] = $token !== "" ? substr($token, 0, 6)."…".substr($token, -4) : "";
		$r["has_token"] = $token !== "";
		unset($r["token_whatsapp"]);

		return $r;

	}

	/*=============================================
	Petición a Meta (rápida: máximo 8 s)
	=============================================*/

	static private function http($method, $url, $token, $body = null){

		$curl = curl_init($url);
		$headers = array("Authorization: Bearer ".$token);

		if($body !== null){

			$headers[] = "Content-Type: application/json";
		}

		curl_setopt_array($curl, array(
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_CUSTOMREQUEST => $method,
			CURLOPT_CONNECTTIMEOUT => 5,
			CURLOPT_TIMEOUT => 8,
			CURLOPT_HTTPHEADER => $headers
		));

		if($body !== null){

			curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE));
		}

		$raw = curl_exec($curl);
		$error = curl_error($curl);
		$code = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
		curl_close($curl);

		if($raw === false){

			return array(0, null, "No hubo respuesta de Meta en 8 segundos (".$error."). Revisa la conexión del servidor e inténtalo de nuevo.");
		}

		return array($code, json_decode((string)$raw), null);

	}

	/*=============================================
	Verificar ID del número + token
	Devuelve ok, number, name, quality, error
	=============================================*/

	static public function verify($phoneId, $token){

		$phoneId = trim((string)$phoneId);
		$token = trim((string)$token);

		if(!preg_match('/^\d{6,25}$/', $phoneId)){

			return array("ok" => false, "error" => "El «ID del número de teléfono» debe ser solo números (lo ves en Meta for Developers → WhatsApp → Configuración de la API, debajo del número). No es el número de celular.");
		}

		if(strlen($token) < 20){

			return array("ok" => false, "error" => "El token parece incompleto. Cópialo completo desde Meta (empieza normalmente con «EAA»).");
		}

		list($code, $json, $error) = self::http("GET", self::graph().$phoneId."?fields=display_phone_number,verified_name,quality_rating,code_verification_status,name_status", $token);

		if($error){

			return array("ok" => false, "error" => $error);
		}

		if($code === 200 && !empty($json->display_phone_number)){

			return array(
				"ok" => true,
				"number" => preg_replace('/\D/', '', (string)$json->display_phone_number),
				"display" => (string)$json->display_phone_number,
				"name" => (string)($json->verified_name ?? ""),
				"quality" => (string)($json->quality_rating ?? ""),
				"error" => ""
			);
		}

		$metaCode = (int)($json->error->code ?? 0);
		$sub = (int)($json->error->error_subcode ?? 0);

		if($metaCode === 190){

			$message = $sub === 463
				? "El token venció. Los tokens temporales duran 24 horas: crea un token permanente (Usuario del sistema en Meta Business) y pégalo aquí."
				: "El token no es válido. Cópialo de nuevo completo desde Meta (sin espacios).";

		}else if($metaCode === 100 || $code === 404){

			$message = "Meta no reconoce ese «ID del número de teléfono» con este token. Revisa que el ID sea el del número (no el de la app ni el de la cuenta) y que el token pertenezca a la misma cuenta de WhatsApp Business.";

		}else if(in_array($metaCode, array(10, 200), true)){

			$message = "El token no tiene permiso para WhatsApp. Al crearlo marca los permisos whatsapp_business_messaging y whatsapp_business_management.";

		}else{

			$message = "Meta respondió: ".($json->error->message ?? "error HTTP ".$code);
		}

		return array("ok" => false, "error" => $message.($metaCode ? " (código ".$metaCode.")" : ""));

	}

	/*=============================================
	Guardar (verificando primero con Meta)
	=============================================*/

	static public function save(array $data, $force = false){

		$id = (int)($data["id"] ?? 0);
		$current = $id ? self::find($id) : null;

		$phoneId = trim((string)($data["id_number"] ?? ""));
		$appId = trim((string)($data["id_app"] ?? ""));
		$token = trim((string)($data["token"] ?? ""));
		$number = preg_replace('/\D/', '', (string)($data["number"] ?? ""));

		/* Token vacío al editar = conservar el actual */

		if($token === "" && $current){

			$token = (string)$current["token_whatsapp"];
		}

		$check = self::verify($phoneId, $token);

		if(!$check["ok"] && !$force){

			return array("ok" => false, "verified" => false, "error" => $check["error"], "can_force" => preg_match('/^\d{6,25}$/', $phoneId) && strlen($token) >= 20);
		}

		if($check["ok"]){

			$number = $check["number"];
		}

		$active = !empty($data["status"]) ? 1 : 0;
		$ai = !empty($data["ai"]) ? 1 : 0;

		/* Solo una cuenta activa a la vez: la que responde a los clientes */

		if($active){

			self::pdo()->prepare("UPDATE whatsapps SET status_whatsapp = 0 WHERE id_whatsapp <> ?")->execute(array($id));
		}

		if($current){

			$stmt = self::pdo()->prepare("UPDATE whatsapps SET number_whatsapp = ?, id_number_whatsapp = ?, id_app_whatsapp = ?, token_whatsapp = ?, status_whatsapp = ?, ai_whatsapp = ? WHERE id_whatsapp = ?");
			$stmt->execute(array($number, $phoneId, $appId, $token, $active, $ai, $id));

		}else{

			$stmt = self::pdo()->prepare("INSERT INTO whatsapps (number_whatsapp, id_number_whatsapp, id_app_whatsapp, token_whatsapp, status_whatsapp, ai_whatsapp, date_created_whatsapp) VALUES (?, ?, ?, ?, ?, ?, ?)");
			$stmt->execute(array($number, $phoneId, $appId, $token, $active, $ai, date("Y-m-d")));
			$id = (int)self::pdo()->lastInsertId();
		}

		return array(
			"ok" => true,
			"verified" => $check["ok"],
			"id" => $id,
			"message" => $check["ok"]
				? "Conectado a ".$check["display"].($check["name"] !== "" ? " (".$check["name"].")" : "").". Los datos quedaron guardados."
				: "Guardado SIN verificar: ".$check["error"]
		);

	}

	static public function delete($id){

		return self::pdo()->prepare("DELETE FROM whatsapps WHERE id_whatsapp = ?")->execute(array((int)$id));

	}

	/*=============================================
	Mensaje de prueba
	Primero texto (si el número escribió en las
	últimas 24 h); si no, la plantilla hello_world
	que Meta crea en todas las cuentas.
	=============================================*/

	static public function test($id, $to){

		$account = self::find($id);
		$to = preg_replace('/\D/', '', (string)$to);

		if(!$account){

			return array("ok" => false, "error" => "La cuenta no existe.");
		}

		if(strlen($to) < 9){

			return array("ok" => false, "error" => "Escribe el número de destino con código de país (ej.: 51987654321).");
		}

		$url = self::graph().$account["id_number_whatsapp"]."/messages";

		list($code, $json, $error) = self::http("POST", $url, $account["token_whatsapp"], array(
			"messaging_product" => "whatsapp", "to" => $to, "type" => "text",
			"text" => array("body" => "✅ Prueba de conexión de ChatCenter: tu WhatsApp está conectado correctamente.")
		));

		if(!$error && !empty($json->messages[0]->id)){

			return array("ok" => true, "message" => "Mensaje de prueba enviado a +".$to.". Revisa ese WhatsApp.");
		}

		list($code2, $json2, $error2) = self::http("POST", $url, $account["token_whatsapp"], array(
			"messaging_product" => "whatsapp", "to" => $to, "type" => "template",
			"template" => array("name" => "hello_world", "language" => array("code" => "en_US"))
		));

		if(!$error2 && !empty($json2->messages[0]->id)){

			return array("ok" => true, "message" => "Se envió la plantilla «hello_world» a +".$to." (ese número no te escribió en las últimas 24 h, por eso no se pudo enviar texto libre).");
		}

		return array("ok" => false, "error" => $error ?: ChatService::errorText($json2 ?: $json));

	}

}
