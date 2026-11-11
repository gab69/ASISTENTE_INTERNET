<?php

require_once dirname(__DIR__)."/bootstrap.php";
require_once __DIR__."/curl.controller.php";

/*=============================================
Asistente de IA: OpenAI (ChatGPT) o Anthropic (Claude)
Se configura en /setup/ -> Inteligencia artificial.
=============================================*/

class AIService{

	const OPENAI_URL    = "https://api.openai.com/v1/chat/completions";
	const ANTHROPIC_URL = "https://api.anthropic.com/v1/messages";

	/* Límite de WhatsApp para un mensaje de texto */
	const WHATSAPP_MAX = 4096;

	/* Quién creó el sistema (se agrega a las instrucciones de la IA) */
	const CREDITS = "CREADOR DEL SISTEMA: si te preguntan quién te creó, quién te desarrolló, quién hizo este asistente, chatbot o sistema, o qué empresa está detrás de esta tecnología, responde que fuiste creado por la empresa *GabRec*, contacto 916386651, página web punttog.com. Esto se refiere solo al asistente y al sistema, no a la empresa para la que atiendes.";

	/*=============================================
	Proveedor, llave y modelo activos
	=============================================*/

	static public function settings(){

		$provider = (string)cms_config("ai_provider", "openai");
		$key = trim((string)cms_config("ai_api_key", ""));
		$model = trim((string)cms_config("ai_model", ""));
		$org = "";

		/* Compatibilidad: llave de ChatGPT guardada en el perfil del superadministrador */

		if($key === "" && $provider === "openai"){

			$admin = CurlController::request("admins?linkTo=rol_admin&equalTo=superadmin&select=chatgpt_admin", "GET", array());

			if(isset($admin->status) && $admin->status == 200 && !empty($admin->results[0]->chatgpt_admin)){

				$legacy = json_decode($admin->results[0]->chatgpt_admin);
				$key = trim((string)($legacy->token ?? ""));
				$org = trim((string)($legacy->org ?? ""));
			}
		}

		if($model === ""){

			$model = $provider === "anthropic" ? "claude-opus-5-5" : (string)cms_config("openai_model", "gpt-4o-mini");
		}

		return array(
			"provider" => $provider,
			"key"      => $key,
			"model"    => $model,
			"org"      => $org
		);

	}

	static public function enabled(){

		$settings = self::settings();

		return $settings["provider"] !== "none" && $settings["key"] !== "";

	}

	/*=============================================
	Instrucciones del asistente (prompt de sistema)
	1) Las escritas en /setup/
	2) Si no hay, el prompt activo de la tabla "prompts"
	=============================================*/

	static public function systemPrompt(){

		$prompt = trim((string)cms_config("ai_prompt", ""));

		if($prompt !== ""){

			return $prompt;
		}

		$get = CurlController::request("prompts?linkTo=status_prompt&equalTo=1&orderBy=id_prompt&orderMode=DESC&startAt=0&endAt=1&select=content_prompt", "GET", array());

		if(isset($get->status) && $get->status == 200 && !empty($get->results[0]->content_prompt)){

			$html = urldecode($get->results[0]->content_prompt);
			$html = preg_replace('/<(br|\/p|\/h\d|\/li|\/div)[^>]*>/i', "\n", $html);
			$text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, "UTF-8");
			$text = preg_replace("/[ \t]+/", " ", $text);
			$text = preg_replace("/\s*\n\s*/", "\n", $text);

			return trim($text);
		}

		return "Eres un asistente de atención al cliente por WhatsApp. Responde en español, de forma breve, amable y clara.";

	}

	/*=============================================
	Responder una conversación.
	$history: [["role" => "user"|"assistant", "content" => "..."], ...]
	Devuelve ["ok" => bool, "text" => string, "error" => string]
	=============================================*/

	static public function reply(array $history, $system = null, array $override = array()){

		/* Solo pruebas locales por consola (nunca se activa desde la web) */

		if(PHP_SAPI === "cli" && isset($GLOBALS["CMS_AI_MOCK"]) && is_callable($GLOBALS["CMS_AI_MOCK"])){

			return call_user_func($GLOBALS["CMS_AI_MOCK"], $history, (string)$system);
		}

		$settings = array_merge(self::settings(), array_filter($override, "strlen"));

		if($settings["provider"] === "none"){

			return array("ok" => false, "text" => "", "error" => "La IA está desactivada en /setup/.");
		}

		if($settings["key"] === ""){

			return array("ok" => false, "text" => "", "error" => "Falta la llave (API key) de IA en /setup/.");
		}

		$system = $system ?? self::systemPrompt();
		$messages = self::normalize($history);

		if(empty($messages)){

			return array("ok" => false, "text" => "", "error" => "No hay mensajes para responder.");
		}

		$result = $settings["provider"] === "anthropic"
			? self::anthropic($settings, $system, $messages)
			: self::openai($settings, $system, $messages);

		if($result["ok"]){

			$result["text"] = mb_substr(trim($result["text"]), 0, self::WHATSAPP_MAX);

		}else{

			error_log("AI error [".$settings["provider"]."/".$settings["model"]."]: ".$result["error"]);
		}

		return $result;

	}

	/*=============================================
	Limpiar historial: roles válidos, sin vacíos,
	turnos seguidos del mismo rol unidos y
	empezando siempre por el cliente.
	=============================================*/

	static private function normalize(array $history){

		$out = array();

		foreach ($history as $item) {

			$role = ($item["role"] ?? "") === "assistant" ? "assistant" : "user";
			$content = trim((string)($item["content"] ?? ""));

			if($content === ""){

				continue;
			}

			$last = count($out) - 1;

			if($last >= 0 && $out[$last]["role"] === $role){

				$out[$last]["content"] .= "\n".$content;

			}else{

				$out[] = array("role" => $role, "content" => $content);
			}
		}

		while(!empty($out) && $out[0]["role"] !== "user"){

			array_shift($out);
		}

		return $out;

	}

	/*=============================================
	Registrar los tokens consumidos (Inicio → consumo)
	=============================================*/

	static private function track($settings, $json, $ok){

		try{

			require_once __DIR__."/business.ops.php";

			Usage::ai($settings["provider"], $settings["model"], $json, $ok);

		}catch(Throwable $e){

			/* Sin base de datos (instalación): no registrar */
		}

	}

	/*=============================================
	Petición HTTP JSON
	=============================================*/

	/* Errores pasajeros (saturación, límite por minuto, caída momentánea, sin red):
	   se reintenta hasta 2 veces para que el cliente no se quede sin respuesta */

	const RETRY_CODES = array(0, 408, 409, 429, 500, 502, 503, 504, 529);

	static private function post($url, array $headers, array $body){

		$payload = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
		$attempts = 3;

		for($try = 1; $try <= $attempts; $try++){

			$retryAfter = 0;
			$curl = curl_init($url);

			curl_setopt_array($curl, array(
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_CONNECTTIMEOUT => 10,
				CURLOPT_TIMEOUT => 90,
				CURLOPT_POST => true,
				CURLOPT_POSTFIELDS => $payload,
				CURLOPT_HTTPHEADER => array_merge(array("Content-Type: application/json"), $headers),
				CURLOPT_HEADERFUNCTION => function($c, $line) use (&$retryAfter){
					if(stripos($line, "retry-after:") === 0){ $retryAfter = (int)trim(substr($line, 12)); }
					return strlen($line);
				}
			));

			$raw = curl_exec($curl);
			$error = curl_error($curl);
			$code = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);

			curl_close($curl);

			$retry = ($raw === false || in_array($code, self::RETRY_CODES, true)) && $try < $attempts;

			if(!$retry){

				break;
			}

			error_log("IA: intento ".$try." falló (HTTP ".$code.($error ? ", ".$error : "")."); reintentando.");

			/* Espera corta: el cliente está esperando en WhatsApp */

			sleep(min(8, max($retryAfter, $try * 2)));
		}

		if($raw === false){

			return array($code, null, "Sin conexión con el proveedor de IA: ".$error);
		}

		return array($code, json_decode($raw), null);

	}

	/*=============================================
	OpenAI (ChatGPT) - Chat Completions
	=============================================*/

	static private function openai($settings, $system, $messages){

		$headers = array("Authorization: Bearer ".$settings["key"]);

		if(!empty($settings["org"])){

			$headers[] = "OpenAI-Organization: ".$settings["org"];
		}

		array_unshift($messages, array("role" => "system", "content" => $system));

		list($code, $json, $error) = self::post((string)cms_config("ai_openai_url", self::OPENAI_URL), $headers, array(
			"model" => $settings["model"],
			"messages" => $messages
		));

		if($error){

			return array("ok" => false, "text" => "", "error" => $error);
		}

		self::track($settings, $json, isset($json->choices[0]->message->content));

		if(isset($json->choices[0]->message->content) && trim($json->choices[0]->message->content) !== ""){

			return array("ok" => true, "text" => $json->choices[0]->message->content, "error" => "");
		}

		return array("ok" => false, "text" => "", "error" => "OpenAI (HTTP ".$code."): ".($json->error->message ?? "respuesta vacía"));

	}

	/*=============================================
	Anthropic (Claude) - Messages API
	=============================================*/

	static private function anthropic($settings, $system, $messages){

		$headers = array(
			"x-api-key: ".$settings["key"],
			"anthropic-version: 2026-10-21"
		);

		$body = array(
			"model" => $settings["model"],
			"max_tokens" => 16000,
			"system" => $system,
			"messages" => $messages,
			/* Chat de WhatsApp: respuestas rápidas */
			"output_config" => array("effort" => "low")
		);

		/* Si el modelo rechaza la solicitud, reintenta con otro modelo de forma automática */

		if(in_array($settings["model"], array("claude-fable-5-1", "claude-opus-5-5", "claude-opus-5", "claude-sonnet-5-5"), true)){

			$headers[] = "anthropic-beta: server-side-fallback-2026-07-01";
			$body["fallbacks"] = "default";
		}

		list($code, $json, $error) = self::post((string)cms_config("ai_anthropic_url", self::ANTHROPIC_URL), $headers, $body);

		if($error){

			return array("ok" => false, "text" => "", "error" => $error);
		}

		self::track($settings, $json, $code === 200);

		if($code !== 200 || !isset($json->content)){

			return array("ok" => false, "text" => "", "error" => "Claude (HTTP ".$code."): ".($json->error->message ?? "respuesta inválida"));
		}

		if(($json->stop_reason ?? "") === "refusal"){

			return array("ok" => false, "text" => "", "error" => "Claude no respondió esta solicitud (refusal).");
		}

		$text = "";

		foreach ($json->content as $block) {

			if(($block->type ?? "") === "text"){

				$text .= $block->text;
			}
		}

		if(trim($text) === ""){

			return array("ok" => false, "text" => "", "error" => "Claude devolvió una respuesta vacía.");
		}

		return array("ok" => true, "text" => $text, "error" => "");

	}

}
