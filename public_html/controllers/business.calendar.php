<?php

require_once __DIR__."/business.store.php";

/*=============================================
Google Calendar

Dos formas de ver la agenda en el celular:

1) Sincronización automática (recomendada, al instante)
   Una "cuenta de servicio" de Google Cloud escribe las
   citas en tu calendario. Se crea, se edita y se borra
   el evento cada vez que cambia la cita.

2) Suscripción por enlace (sin configurar nada en Google)
   Google Calendar → Otros calendarios → "Desde URL".
   Google lo actualiza solo cada varias horas.
=============================================*/

class BizCalendar{

	const SCOPE = "https://www.googleapis.com/auth/calendar";
	const TOKEN_URL = "https://oauth2.googleapis.com/token";
	const API = "https://www.googleapis.com/calendar/v3/calendars/";

	/*=============================================
	Configuración
	=============================================*/

	static public function settings(){

		return BizStore::get("gcal");

	}

	static public function credentials($raw = null){

		$json = json_decode((string)($raw ?? self::settings()["credentials"]), true);

		if(!is_array($json) || empty($json["client_email"]) || empty($json["private_key"])){

			return null;
		}

		return $json;

	}

	static public function enabled(){

		$s = self::settings();

		return !empty($s["enabled"]) && trim((string)$s["calendar_id"]) !== "" && self::credentials() !== null;

	}

	/* Token secreto del enlace de suscripción (se crea una sola vez) */

	static public function icsToken(){

		$s = self::settings();

		if(empty($s["ics_token"])){

			$s["ics_token"] = bin2hex(random_bytes(16));
			BizStore::set("gcal", $s);
		}

		return $s["ics_token"];

	}

	static public function icsUrl(){

		return cms_base_url()."ajax/calendar.php?token=".self::icsToken();

	}

	/*=============================================
	Token de acceso (JWT firmado con la llave privada)
	Se guarda en config/ (carpeta privada) por 55 min
	=============================================*/

	static private function b64($data){

		return rtrim(strtr(base64_encode($data), "+/", "-_"), "=");

	}

	static private function cacheFile(){

		return CMS_DIR."/config/gcal_token.json";

	}

	static public function accessToken($cred = null, $useCache = true){

		$cred = $cred ?: self::credentials();

		if(!$cred){

			throw new Exception("Faltan las credenciales de la cuenta de servicio.");
		}

		$cacheKey = md5($cred["client_email"].$cred["private_key"]);

		if($useCache && is_file(self::cacheFile())){

			$cache = json_decode((string)@file_get_contents(self::cacheFile()), true);

			if(is_array($cache) && ($cache["key"] ?? "") === $cacheKey && ($cache["exp"] ?? 0) > time() + 60){

				return $cache["token"];
			}
		}

		if(!function_exists("openssl_sign")){

			throw new Exception("El servidor no tiene la extensión OpenSSL de PHP.");
		}

		$now = time();
		$header = self::b64(json_encode(array("alg" => "RS256", "typ" => "JWT")));
		$claims = self::b64(json_encode(array(
			"iss" => $cred["client_email"],
			"scope" => self::SCOPE,
			"aud" => self::TOKEN_URL,
			"iat" => $now,
			"exp" => $now + 3600
		)));

		$key = openssl_pkey_get_private($cred["private_key"]);

		if(!$key){

			throw new Exception("La llave privada del archivo JSON no es válida.");
		}

		$signature = "";
		openssl_sign($header.".".$claims, $signature, $key, OPENSSL_ALGO_SHA256);

		list($code, $json) = self::http("POST", self::TOKEN_URL, null, http_build_query(array(
			"grant_type" => "urn:ietf:params:oauth:grant-type:jwt-bearer",
			"assertion" => $header.".".$claims.".".self::b64($signature)
		)), "application/x-www-form-urlencoded");

		if(empty($json["access_token"])){

			throw new Exception("Google rechazó las credenciales: ".($json["error_description"] ?? $json["error"] ?? "HTTP ".$code));
		}

		@file_put_contents(self::cacheFile(), json_encode(array("key" => $cacheKey, "token" => $json["access_token"], "exp" => $now + (int)($json["expires_in"] ?? 3600) - 300)), LOCK_EX);

		return $json["access_token"];

	}

	static private function http($method, $url, $token, $body = null, $type = "application/json"){

		$curl = curl_init($url);
		$headers = array("Accept: application/json");

		if($token){

			$headers[] = "Authorization: Bearer ".$token;
		}

		if($body !== null){

			$headers[] = "Content-Type: ".$type;
		}

		curl_setopt_array($curl, array(
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_CUSTOMREQUEST => $method,
			CURLOPT_CONNECTTIMEOUT => 8,
			CURLOPT_TIMEOUT => 15,
			CURLOPT_HTTPHEADER => $headers
		));

		if($body !== null){

			curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
		}

		$raw = curl_exec($curl);
		$code = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
		$error = curl_error($curl);
		curl_close($curl);

		if($raw === false){

			throw new Exception("Sin conexión con Google: ".$error);
		}

		return array($code, json_decode((string)$raw, true));

	}

	/*=============================================
	Evento de Google a partir de la cita
	=============================================*/

	static private function event(array $appt){

		$tz = date_default_timezone_get();
		$start = new DateTime($appt["date_appointment"]." ".$appt["time_appointment"], new DateTimeZone($tz));
		$end = (clone $start)->modify("+".max(5, (int)$appt["duration_appointment"])." minutes");
		$company = BizStore::get("company");

		$pending = $appt["status_appointment"] === "pendiente";
		$title = ($pending ? "⏳ " : "").trim(($appt["service_appointment"] ?: "Cita")." · ".($appt["name_appointment"] ?: "+".$appt["phone_appointment"]));

		if(!empty($appt["people_appointment"])){

			$title .= " (".(int)$appt["people_appointment"]." pers.)";
		}

		$lines = array();
		$lines[] = $pending ? "Estado: PENDIENTE de confirmar" : "Estado: ".ucfirst($appt["status_appointment"]);
		if(!empty($appt["name_appointment"])){ $lines[] = "Cliente: ".$appt["name_appointment"]; }
		if(!empty($appt["phone_appointment"])){ $lines[] = "WhatsApp: +".$appt["phone_appointment"]; $lines[] = "https://wa.me/".$appt["phone_appointment"]; }
		if(!empty($appt["people_appointment"])){ $lines[] = "Personas: ".(int)$appt["people_appointment"]; }
		if(!empty($appt["branch_appointment"])){ $lines[] = "Sucursal: ".$appt["branch_appointment"]; }
		if(!empty($appt["summary_appointment"])){ $lines[] = ""; $lines[] = $appt["summary_appointment"]; }
		$lines[] = "";
		$lines[] = "Chat: ".cms_base_url()."chat?phone=".$appt["phone_appointment"];

		/* Solicitud sin hora: evento de todo el día */

		$allDay = substr((string)$appt["time_appointment"], 0, 5) === "00:00";

		return array(
			"summary" => $title,
			"description" => implode("\n", $lines),
			"location" => (string)($company["address"] ?? ""),
			"allDay" => $allDay,
			"start" => $allDay ? array("date" => $appt["date_appointment"]) : array("dateTime" => $start->format("Y-m-d\TH:i:s"), "timeZone" => $tz),
			"end" => $allDay ? array("date" => date("Y-m-d", strtotime($appt["date_appointment"]." +1 day"))) : array("dateTime" => $end->format("Y-m-d\TH:i:s"), "timeZone" => $tz),
			"colorId" => $pending ? "5" : ($appt["status_appointment"] === "atendida" ? "2" : "9"),
			"reminders" => array("useDefault" => false, "overrides" => array(array("method" => "popup", "minutes" => 30))),
			"extendedProperties" => array("private" => array("chatcenter_id" => (string)$appt["id_appointment"]))
		);

	}

	/*=============================================
	Crear / actualizar / borrar el evento de una cita
	=============================================*/

	static public function sync($id){

		if(!self::enabled()){

			return false;
		}

		$appt = BizStore::appointment($id);

		if(!$appt){

			return false;
		}

		$calendar = rawurlencode(trim(self::settings()["calendar_id"]));
		$token = self::accessToken();
		$eventId = (string)($appt["gcal_event_appointment"] ?? "");

		/* Cancelada: quitar del calendario */

		if($appt["status_appointment"] === "cancelada"){

			if($eventId !== ""){

				self::http("DELETE", self::API.$calendar."/events/".rawurlencode($eventId), $token);
				self::saveEventId($id, null);
			}

			return true;
		}

		$event = self::event($appt);
		unset($event["allDay"]);

		$body = json_encode($event, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

		if($eventId !== ""){

			list($code, $json) = self::http("PUT", self::API.$calendar."/events/".rawurlencode($eventId), $token, $body);

			if($code === 200){

				return true;
			}

			/* El evento fue borrado en Google: crearlo de nuevo */
		}

		list($code, $json) = self::http("POST", self::API.$calendar."/events", $token, $body);

		if(!empty($json["id"])){

			self::saveEventId($id, $json["id"]);

			return true;
		}

		throw new Exception("No se pudo crear el evento (HTTP ".$code."): ".($json["error"]["message"] ?? "sin detalle"));

	}

	static private function saveEventId($id, $eventId){

		$stmt = BizStore::pdo()->prepare("UPDATE appointments SET gcal_event_appointment = ? WHERE id_appointment = ?");
		$stmt->execute(array($eventId, (int)$id));

	}

	/*=============================================
	Probar acceso al calendario
	=============================================*/

	static public function test($calendarId, $rawCredentials){

		$cred = self::credentials($rawCredentials);

		if(!$cred){

			return array("ok" => false, "error" => "El archivo JSON no es de una cuenta de servicio (falta client_email o private_key).");
		}

		try{

			$token = self::accessToken($cred, false);

			list($code, $json) = self::http("GET", self::API.rawurlencode(trim($calendarId)), $token);

			if($code === 200){

				return array("ok" => true, "message" => "Conectado al calendario «".($json["summary"] ?? $calendarId)."».");
			}

			if($code === 404){

				return array("ok" => false, "error" => "No se encontró el calendario. Revisa el ID y compártelo con ".$cred["client_email"]." con permiso «Hacer cambios en eventos».");
			}

			return array("ok" => false, "error" => "Google respondió HTTP ".$code.": ".($json["error"]["message"] ?? "sin detalle"));

		}catch(Throwable $e){

			return array("ok" => false, "error" => $e->getMessage());
		}

	}

	/* Enviar todas las citas próximas (al conectar por primera vez) */

	static public function syncUpcoming(){

		$stmt = BizStore::pdo()->prepare("SELECT id_appointment FROM appointments WHERE date_appointment >= ? AND status_appointment <> 'cancelada' ORDER BY date_appointment, time_appointment LIMIT 300");
		$stmt->execute(array(date("Y-m-d")));

		$ok = 0;
		$fail = 0;

		foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {

			try{

				self::sync((int)$id) ? $ok++ : $fail++;

			}catch(Throwable $e){

				$fail++;
				error_log("Google Calendar sync ".$id.": ".$e->getMessage());
			}
		}

		return array("ok" => $ok, "fail" => $fail);

	}

	/*=============================================
	Calendario en formato iCalendar (.ics)
	=============================================*/

	static private function icsText($value){

		return str_replace(array("\\", ";", ",", "\r\n", "\n"), array("\\\\", "\\;", "\\,", "\\n", "\\n"), (string)$value);

	}

	static private function icsFold($line){

		$out = "";

		while(strlen($line) > 74){

			$cut = 74;

			/* No cortar caracteres UTF-8 por la mitad */
			while($cut > 0 && (ord($line[$cut]) & 0xC0) === 0x80){ $cut--; }

			$out .= substr($line, 0, $cut)."\r\n ";
			$line = substr($line, $cut);
		}

		return $out.$line."\r\n";

	}

	static public function ics(){

		$from = date("Y-m-d", strtotime("-60 days"));
		$to = date("Y-m-d", strtotime("+365 days"));
		$company = BizStore::get("company");
		$name = trim((string)($company["name"] ?? "")) ?: "Agenda";
		$tz = date_default_timezone_get();

		$out = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//ChatCenter//Agenda//ES\r\nCALSCALE:GREGORIAN\r\nMETHOD:PUBLISH\r\n";
		$out .= self::icsFold("X-WR-CALNAME:".self::icsText($name." · Citas"));
		$out .= self::icsFold("X-WR-TIMEZONE:".$tz);
		$out .= "X-PUBLISHED-TTL:PT1H\r\nREFRESH-INTERVAL;VALUE=DURATION:PT1H\r\n";

		foreach (BizStore::appointments($from, $to, false) as $a) {

			$event = self::event($a);
			$start = new DateTime($a["date_appointment"]." ".$a["time_appointment"], new DateTimeZone($tz));
			$end = (clone $start)->modify("+".max(5, (int)$a["duration_appointment"])." minutes");
			$start->setTimezone(new DateTimeZone("UTC"));
			$end->setTimezone(new DateTimeZone("UTC"));

			$out .= "BEGIN:VEVENT\r\n";
			$out .= "UID:cita-".(int)$a["id_appointment"]."@".preg_replace('/[^a-z0-9.-]/i', '', parse_url(cms_base_url(), PHP_URL_HOST) ?: "chatcenter")."\r\n";
			$out .= "DTSTAMP:".gmdate("Ymd\THis\Z")."\r\n";
			if($event["allDay"]){

				$out .= "DTSTART;VALUE=DATE:".date("Ymd", strtotime($a["date_appointment"]))."\r\n";
				$out .= "DTEND;VALUE=DATE:".date("Ymd", strtotime($a["date_appointment"]." +1 day"))."\r\n";

			}else{

				$out .= "DTSTART:".$start->format("Ymd\THis\Z")."\r\n";
				$out .= "DTEND:".$end->format("Ymd\THis\Z")."\r\n";
			}
			$out .= self::icsFold("SUMMARY:".self::icsText($event["summary"]));
			$out .= self::icsFold("DESCRIPTION:".self::icsText($event["description"]));

			if($event["location"] !== ""){

				$out .= self::icsFold("LOCATION:".self::icsText($event["location"]));
			}

			$out .= "STATUS:".($a["status_appointment"] === "pendiente" ? "TENTATIVE" : "CONFIRMED")."\r\n";
			$out .= "BEGIN:VALARM\r\nACTION:DISPLAY\r\nDESCRIPTION:Recordatorio\r\nTRIGGER:-PT30M\r\nEND:VALARM\r\n";
			$out .= "END:VEVENT\r\n";
		}

		return $out."END:VCALENDAR\r\n";

	}

}
