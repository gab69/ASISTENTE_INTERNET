<?php

require_once dirname(__DIR__)."/bootstrap.php";

class CurlController{

	/*=============================================
	Handle de cURL reutilizable: mantiene viva la conexión
	con la API entre peticiones del mismo script (más rápido)
	=============================================*/

	private static $handle = null;

	static private function handle(){

		if(self::$handle === null){

			self::$handle = curl_init();

		}else{

			curl_reset(self::$handle);
		}

		return self::$handle;

	}

	/*=============================================
	Codificar caracteres no válidos en la URL
	(espacios, tildes, ñ, #...) sin tocar los ya codificados
	=============================================*/

	static private function safeUrl($url){

		$safe = preg_replace_callback('/[^A-Za-z0-9\-._~:\/?\[\]@!$&\'()*+,;=%]/u', function($m){

			return rawurlencode($m[0]);

		}, $url);

		return $safe !== null ? $safe : str_replace(" ", "%20", $url);

	}

	/*=============================================
	Respuesta de error con el mismo formato que la API
	=============================================*/

	static private function errorResponse($status, $message){

		$error = new stdClass();
		$error->status = $status;
		$error->results = $message;

		return $error;

	}

	/*=============================================
	Peticiones a la API
	=============================================*/

	static public function request($url,$method,$fields){

		$method = strtoupper($method);

		/*=============================================
		API en el mismo servidor: ejecutarla en proceso
		(sin HTTP). Solo se usa HTTP si en /setup/ se
		configuró una URL de API externa.
		=============================================*/

		if(trim((string)cms_config("api_url", "")) === ""){

			require_once CMS_DIR."/api/internal.php";

			$response = api_internal_request(self::safeUrl(ltrim($url, "/")), $method, $fields);
			$decoded = json_decode($response);

			if(!is_object($decoded)){

				error_log("API invalid internal response [".$method." ".$url."]: ".substr((string)$response, 0, 500));

				return self::errorResponse(502, "Respuesta inválida de la API");
			}

			return $decoded;
		}
		$endpoint = self::safeUrl(cms_api_url().ltrim($url, "/"));
		$apiKey = cms_config("api_key", "");
		$verifySSL = (bool)cms_config("api_verify_ssl", true);

		$options = array(
			CURLOPT_URL => $endpoint,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_ENCODING => "",
			CURLOPT_MAXREDIRS => 5,
			CURLOPT_CONNECTTIMEOUT => 10,
			CURLOPT_TIMEOUT => (int)cms_config("api_timeout", 30),
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
			CURLOPT_CUSTOMREQUEST => $method,
			CURLOPT_SSL_VERIFYPEER => $verifySSL,
			CURLOPT_SSL_VERIFYHOST => $verifySSL ? 2 : 0,
			CURLOPT_HTTPHEADER => array(
				"Authorization: ".$apiKey,
				"X-Api-Key: ".$apiKey,
				"Accept: application/json",
				"Expect:"
			),
		);

		if($method !== "GET" && !empty($fields)){

			$options[CURLOPT_POSTFIELDS] = $fields;
		}

		$curl = self::handle();
		curl_setopt_array($curl, $options);

		$response = curl_exec($curl);

		if($response === false){

			$error = curl_error($curl);

			error_log("API request failed [".$method." ".$endpoint."]: ".$error);

			return self::errorResponse(503, "No se pudo conectar con la API: ".$error);
		}

		$decoded = json_decode($response);

		if(!is_object($decoded)){

			$httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);

			error_log("API invalid response [".$method." ".$endpoint."] HTTP ".$httpCode.": ".substr($response, 0, 500));

			return self::errorResponse(502, "Respuesta inválida de la API (HTTP ".$httpCode.")");
		}

		return $decoded;

	}

	/*=============================================
	Validar el token de un administrador
	(para scripts AJAX que tocan archivos del servidor)
	=============================================*/

	static public function validAdminToken($token){

		if(!is_string($token) || trim($token) === "" || $token === "null"){

			return false;
		}

		$admin = self::request("admins?linkTo=token_admin&equalTo=".rawurlencode($token)."&select=id_admin,token_exp_admin", "GET", array());

		return isset($admin->status) && $admin->status == 200
			&& !empty($admin->results[0])
			&& time() < (int)$admin->results[0]->token_exp_admin;

	}

	/*=============================================
	Compatibilidad: generar texto con IA a partir de un
	arreglo JSON de mensajes estilo ChatGPT. Usa el
	proveedor configurado en /setup/ (OpenAI o Claude);
	$token/$org solo se usan si no hay llave en /setup/.
	=============================================*/

	static public function chatGPT($messages,$token,$org){

		require_once __DIR__."/ai.service.php";

		$list = json_decode((string)$messages, true);

		if(!is_array($list)){

			$list = array(array("role" => "user", "content" => (string)$messages));
		}

		$system = array();
		$history = array();

		foreach ($list as $item) {

			if(($item["role"] ?? "") === "system"){

				$system[] = (string)($item["content"] ?? "");

			}else{

				$history[] = array("role" => $item["role"] ?? "user", "content" => (string)($item["content"] ?? ""));
			}
		}

		$override = array();

		if(trim((string)cms_config("ai_api_key", "")) === "" && !empty($token)){

			$override = array("provider" => "openai", "key" => (string)$token, "org" => (string)$org);
		}

		$result = AIService::reply($history, empty($system) ? "Eres un asistente útil. Responde en español." : implode("\n\n", $system), $override);

		return $result["ok"] ? $result["text"] : "Lo siento, en este momento no puedo responder. Intenta de nuevo en unos minutos.";

	}

	/*=============================================
	Peticiones a la API de WS
	=============================================*/

	static public function apiWS($getApiWS,$json){

		$graph = rtrim((string)cms_config("graph_base", "https://graph.facebook.com"), "/")."/".cms_config("graph_version", "v22.0")."/";

		/*=============================================
		Enviar mensaje (POST)
		=============================================*/

		if(str_contains($json, "{")){

			$curl = curl_init();

			curl_setopt_array($curl, array(
				CURLOPT_URL => $graph.$getApiWS->id_number_whatsapp."/messages",
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_ENCODING => "",
				CURLOPT_CONNECTTIMEOUT => 10,
				CURLOPT_TIMEOUT => 30,
				CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
				CURLOPT_CUSTOMREQUEST => "POST",
				CURLOPT_POSTFIELDS => $json,
				CURLOPT_HTTPHEADER => array(
					"Authorization: Bearer ".$getApiWS->token_whatsapp,
					"Content-Type: application/json"
				),
			));

			$raw = curl_exec($curl);

			/* Un reintento si falló la conexión (red inestable del hosting) */

			if($raw === false){

				error_log("WhatsApp connection error: ".curl_error($curl));
				usleep(300000);
				$raw = curl_exec($curl);
			}

			if($raw === false){

				error_log("WhatsApp connection error (retry): ".curl_error($curl));
			}

			curl_close($curl);

			return $raw === false ? null : json_decode($raw);

		}

		/*=============================================
		Descargar archivo multimedia (GET)
		=============================================*/

		$idArchive = preg_replace("/[^A-Za-z0-9_\-]/", "", explode("_", $json)[0]);

		if($idArchive === ""){

			return null;
		}

		$directory = CMS_DIR."/views/assets/ws/";

		if(!is_dir($directory)){

			@mkdir($directory, 0755, true);
		}

		/*=============================================
		Si ya se descargó antes, no volver a pedirlo a Meta
		=============================================*/

		$cached = glob($directory.$idArchive.".*");

		if(!empty($cached)){

			return "/views/assets/ws/".basename($cached[0]);
		}

		if(empty($getApiWS->token_whatsapp)){

			return null;
		}

		/*=============================================
		Pedir la URL temporal del archivo
		=============================================*/

		$curl = curl_init();

		curl_setopt_array($curl, array(
			CURLOPT_URL => $graph.$idArchive,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_ENCODING => "",
			CURLOPT_CONNECTTIMEOUT => 10,
			CURLOPT_TIMEOUT => 30,
			CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
			CURLOPT_HTTPHEADER => array(
				"Authorization: Bearer ".$getApiWS->token_whatsapp
			),
		));

		$media = json_decode(curl_exec($curl));

		if(empty($media->url)){

			curl_close($curl);

			error_log("WhatsApp media not found: ".$idArchive);

			return null;
		}

		/*=============================================
		Descargar el archivo
		=============================================*/

		curl_setopt_array($curl, array(
			CURLOPT_URL => $media->url,
			CURLOPT_TIMEOUT => 120,
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_MAXREDIRS => 5,
			CURLOPT_HTTPHEADER => array(
				"Authorization: Bearer ".$getApiWS->token_whatsapp
			),
		));

		$file = curl_exec($curl);
		$httpcode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
		$contentType = curl_getinfo($curl, CURLINFO_CONTENT_TYPE);

		curl_close($curl);

		if($httpcode != 200 || $file === false){

			return null;
		}

		/*=============================================
		Extensión a partir del tipo MIME
		("audio/ogg; codecs=opus" -> "ogg")
		=============================================*/

		$mime = trim(explode(";", (string)($contentType ?: ($media->mime_type ?? "application/octet-stream")))[0]);
		$extension = preg_replace("/[^a-z0-9]/", "", strtolower(explode("/", $mime)[1] ?? "bin"));

		$fileName = $idArchive.".".($extension ?: "bin");

		file_put_contents($directory.$fileName, $file);

		return "/views/assets/ws/".$fileName;

	}

}
