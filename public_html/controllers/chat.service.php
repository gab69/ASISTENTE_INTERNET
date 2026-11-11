<?php

require_once dirname(__DIR__)."/bootstrap.php";
require_once __DIR__."/curl.controller.php";

/*=============================================
Envío de mensajes a WhatsApp Cloud API y registro
en la base de datos (usado por el chat manual, los
bots y el asistente de IA).
=============================================*/

class ChatService{

	private static $apiCache = array();

	/*=============================================
	Cuenta de WhatsApp activa
	=============================================*/

	static public function api($phoneNumberId = null){

		$key = $phoneNumberId ?: "active";

		if(!array_key_exists($key, self::$apiCache)){

			$url = $phoneNumberId
				? "whatsapps?linkTo=id_number_whatsapp&equalTo=".rawurlencode($phoneNumberId)
				: "whatsapps?linkTo=status_whatsapp&equalTo=1&orderBy=id_whatsapp&orderMode=DESC&startAt=0&endAt=1";

			$get = CurlController::request($url, "GET", array());

			self::$apiCache[$key] = (isset($get->status) && $get->status == 200) ? $get->results[0] : null;
		}

		return self::$apiCache[$key];

	}

	/*=============================================
	Solo dígitos (formato internacional sin +)
	=============================================*/

	static public function cleanPhone($phone){

		$digits = preg_replace("/\D/", "", (string)$phone);

		/* Celular peruano escrito sin código de país (987654321 → 51987654321) */

		if(strlen($digits) === 9 && $digits[0] === "9"){

			$digits = "51".$digits;
		}

		if(strlen($digits) > 11 && substr($digits, 0, 2) === "00"){

			$digits = substr($digits, 2);
		}

		return $digits;

	}

	/*=============================================
	Último mensaje registrado con un teléfono
	=============================================*/

	static public function lastMessage($phone){

		$get = CurlController::request("messages?linkTo=phone_message&equalTo=".rawurlencode($phone)."&orderBy=id_message&orderMode=DESC&startAt=0&endAt=1", "GET", array());

		return (isset($get->status) && $get->status == 200) ? $get->results[0] : null;

	}

	/*=============================================
	¿La conversación ya venció? (sin fecha = vencida)
	=============================================*/

	static public function expired($message){

		$expiration = (string)($message->expiration_message ?? "");

		return $expiration === "" || strpos($expiration, "0000-00-00") === 0 || $expiration < date("Y-m-d H:i:s");

	}

	/*=============================================
	Cuerpos JSON para WhatsApp
	=============================================*/

	static public function textPayload($phone, $text){

		return array(
			"messaging_product" => "whatsapp",
			"recipient_type" => "individual",
			"to" => self::cleanPhone($phone),
			"type" => "text",
			"text" => array(
				"preview_url" => true,
				"body" => mb_substr((string)$text, 0, 4096)
			)
		);

	}

	static public function mediaPayload($phone, $type, $link, $caption = ""){

		$media = array("link" => $link);

		if($caption !== "" && in_array($type, array("image", "video", "document"), true)){

			$media["caption"] = $caption;
		}

		if($type === "document"){

			$media["filename"] = basename(parse_url($link, PHP_URL_PATH) ?: "documento.pdf");
		}

		return array(
			"messaging_product" => "whatsapp",
			"recipient_type" => "individual",
			"to" => self::cleanPhone($phone),
			"type" => $type,
			$type => $media
		);

	}

	/*=============================================
	Explicación entendible de los errores de Meta
	=============================================*/

	static public function errorText($response){

		if($response === null){

			return "Sin respuesta de WhatsApp (revisa la conexión del servidor).";
		}

		$error = $response->error ?? null;
		$code = (int)($error->code ?? 0);
		$detail = trim((string)($error->error_data->details ?? $error->message ?? "Error desconocido"));

		$known = array(
			131047 => "Pasaron más de 24 horas desde el último mensaje del cliente. WhatsApp solo permite responder con una plantilla aprobada hasta que el cliente vuelva a escribir.",
			131030 => "El número no está en la lista de destinatarios permitidos. En modo de prueba agrégalo en Meta for Developers > WhatsApp > Configuración de la API.",
			131026 => "El mensaje no se pudo entregar: el número no tiene WhatsApp o no acepta mensajes de empresas.",
			131048 => "WhatsApp limitó los envíos de este número por calidad (spam). Espera antes de volver a enviar.",
			131056 => "Demasiados mensajes seguidos al mismo número. Espera unos segundos.",
			130429 => "Se alcanzó el límite de envío de la API. Intenta de nuevo en unos minutos.",
			131031 => "La cuenta de WhatsApp Business está bloqueada o restringida.",
			131053 => "WhatsApp no pudo descargar el archivo. Verifica que el enlace sea público (https) y el formato sea compatible.",
			190    => "El token de acceso de WhatsApp venció o es inválido. Genera un token permanente y actualízalo en la configuración de WhatsApp.",
			100    => "Parámetro inválido enviado a WhatsApp: ".$detail,
			10     => "La aplicación de Meta no tiene permiso para enviar mensajes (whatsapp_business_messaging).",
			200    => "La aplicación de Meta no tiene permiso para enviar mensajes (whatsapp_business_messaging)."
		);

		return ($known[$code] ?? $detail).($code ? " (código ".$code.")" : "");

	}

	/*=============================================
	Enviar a la API de WhatsApp.
	Devuelve ["ok", "wamid", "error", "code", "to"]
	=============================================*/

	static public function send($getApiWS, array $payload){

		/* Solo pruebas locales por consola (nunca se activa desde la web) */

		if(PHP_SAPI === "cli" && isset($GLOBALS["CMS_WA_MOCK"]) && is_callable($GLOBALS["CMS_WA_MOCK"])){

			return call_user_func($GLOBALS["CMS_WA_MOCK"], $payload);
		}

		if(empty($getApiWS) || empty($getApiWS->id_number_whatsapp) || empty($getApiWS->token_whatsapp)){

			return array("ok" => false, "wamid" => null, "code" => 0, "to" => $payload["to"] ?? "",
				"error" => "No hay una cuenta de WhatsApp activa configurada (ID del número y token).");
		}

		require_once __DIR__."/business.ops.php";

		/* Límite de envíos por minuto (Configuración → Límites y consumo) */

		if(Limits::sendBlocked()){

			return array("ok" => false, "wamid" => null, "code" => 0, "to" => $payload["to"] ?? "",
				"error" => "No se envió: se alcanzó el límite de mensajes por minuto (Configuración → Límites y consumo).");
		}

		$response = CurlController::apiWS($getApiWS, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE));
		$code = (int)($response->error->code ?? 0);

		Usage::log("wa", "whatsapp", (string)($payload["type"] ?? "text"), 0, 0, !empty($response->messages[0]->id), (string)($payload["type"] ?? "text"), (string)($payload["to"] ?? ""));

		/*=============================================
		Números de México (521...) y Argentina (549...):
		WhatsApp los reporta con un dígito extra que a veces
		no acepta al responder. Reintentar sin ese dígito.
		=============================================*/

		if(empty($response->messages) && in_array($code, array(131030, 131026, 100), true)){

			$to = $payload["to"];
			$alt = null;

			if(preg_match('/^521(\d{10})$/', $to, $m)){ $alt = "52".$m[1]; }
			if(preg_match('/^549(\d{10})$/', $to, $m)){ $alt = "54".$m[1]; }

			if($alt){

				$payload["to"] = $alt;
				$retry = CurlController::apiWS($getApiWS, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE));

				if(!empty($retry->messages)){

					$response = $retry;
				}
			}
		}

		if(!empty($response->messages[0]->id)){

			return array("ok" => true, "wamid" => $response->messages[0]->id, "error" => "", "code" => 0, "to" => $payload["to"]);
		}

		$error = self::errorText($response);

		error_log("WhatsApp send error to ".$payload["to"].": ".json_encode($response));

		return array("ok" => false, "wamid" => null, "error" => $error, "code" => $code, "to" => $payload["to"]);

	}

	/*=============================================
	Enviar y guardar la respuesta del negocio.
	Mantiene el orden y la conversación del cliente
	aunque Meta no envíe el objeto "conversation".
	=============================================*/

	static public function deliver($getApiWS, $phone, array $payload, $businessMessage, $templateMessage){

		$phone = self::cleanPhone($phone);
		$result = self::send($getApiWS, $payload);

		$last = self::lastMessage($phone);

		if($last && !self::expired($last)){

			$order = (int)$last->order_message + 1;
			$conversation = $last->id_conversation_message;
			$expiration = $last->expiration_message;

		}else{

			$order = 0;
			$conversation = "c_".bin2hex(random_bytes(12));
			$expiration = date("Y-m-d H:i:s", time() + 86400);
		}

		$fields = array(
			"type_message" => "business",
			"id_whatsapp_message" => $getApiWS->id_whatsapp ?? 0,
			"business_message" => $businessMessage,
			"phone_message" => $phone,
			"order_message" => $order,
			"template_message" => $templateMessage,
			"id_conversation_message" => $conversation,
			"expiration_message" => $expiration,
			"wamid_message" => $result["wamid"],
			"status_message" => $result["ok"] ? "sent" : "failed",
			"error_message" => $result["ok"] ? null : $result["error"],
			"date_created_message" => date("Y-m-d")
		);

		$save = CurlController::request("messages?token=no&except=id_message", "POST", $fields);

		$result["id"] = (isset($save->status) && $save->status == 200) ? (int)($save->results->lastId ?? 0) : 0;

		if(!$result["id"]){

			error_log("No se pudo guardar el mensaje del negocio: ".json_encode($save));
		}

		return $result;

	}

	/*=============================================
	Actualizar el estado de un mensaje (webhook)
	sent -> delivered -> read  |  failed
	=============================================*/

	static public function updateStatus($wamid, $status, $error = null){

		if(!$wamid){

			return false;
		}

		$get = CurlController::request("messages?linkTo=wamid_message&equalTo=".rawurlencode($wamid)."&select=id_message,status_message", "GET", array());

		if(!isset($get->status) || $get->status != 200){

			return false;
		}

		$rank = array("" => 0, "sent" => 1, "delivered" => 2, "read" => 3, "failed" => 4);
		$current = (string)($get->results[0]->status_message ?? "");

		/* No retroceder (Meta puede enviar "delivered" después de "read") */

		if(($rank[$status] ?? 0) <= ($rank[$current] ?? 0)){

			return true;
		}

		$fields = array("status_message" => $status);

		if($error !== null){

			$fields["error_message"] = $error;
		}

		$put = CurlController::request("messages?id=".(int)$get->results[0]->id_message."&nameId=id_message&token=no&except=id_message", "PUT", http_build_query($fields));

		return isset($put->status) && $put->status == 200;

	}

}
