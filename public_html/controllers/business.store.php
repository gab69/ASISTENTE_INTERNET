<?php

require_once dirname(__DIR__)."/bootstrap.php";
require_once __DIR__."/schema.php";

/*=============================================
Datos del negocio y agenda de citas.
Se guardan en las tablas `settings` y `appointments`
(creadas por la migración v3 de controllers/schema.php).
=============================================*/

class BizStore{

	private static $pdo = null;
	private static $cache = array();

	/* Secciones de configuración y sus valores por defecto */

	static public function defaults(){

		return array(

			"company" => array(
				"name" => "",
				"description" => "",
				"address" => "",
				"hours" => "",
				"website" => "",
				"email" => "",
				"social" => "",
				"extra" => ""
			),

			"contacts" => array(),   /* Números por área: [{label, name, phone, purpose, share, orders, appts, cancel}] */

			"payments" => array(),   /* [{id, name, holder, account, qr, notes}] */

			"images" => array(),     /* [{id, keyword, description, url}] */

			"agenda" => array(
				"enabled" => true,
				"notify_phone" => "",
				"slot_minutes" => 30,
				"capacity" => 1,
				"days" => array(1, 2, 3, 4, 5),
				"open" => "08:00",
				"close" => "18:00",
				"break_start" => "",
				"break_end" => "",
				"max_days" => 30,
				"min_hours" => 1,
				"services" => "",
				/* Horarios fijos (ej. instalaciones): si hay, solo se ofrecen esas horas */
				"times" => ""
			),

			"inquiries" => array(
				"enabled" => true,
				"phone" => "",
				"name" => ""
			),

			/* Número principal del negocio: recibe TODAS las órdenes, citas, cancelaciones
			   y consultas, y puede consultar el sistema por WhatsApp */

			"main" => array(
				"phone" => ""
			),

			/* Plantilla aprobada de WhatsApp para avisos al equipo fuera de las 24 h */

			"notices" => array(
				"template" => trim((string)cms_config("notify_template", "")),          /* migra el valor antiguo de /setup/ */
				"lang" => trim((string)cms_config("notify_template_lang", "es")) ?: "es"
			),

			/* Delivery: cobertura por distancia y/o por distritos */

			"delivery" => array(
				"enabled" => false,
				"origin_address" => "",
				"lat" => "",
				"lng" => "",
				"radius_km" => 5,
				"zones" => "",          /* distritos/zonas con cobertura (uno por línea) */
				"excluded" => "",       /* zonas sin cobertura (uno por línea) */
				"fee" => 0,             /* costo de envío base (S/) */
				"fee_per_km" => 0,      /* adicional por km */
				"free_from" => 0,       /* envío gratis desde este monto */
				"min_order" => 0,
				"pickup" => true,       /* permite recojo en tienda */
				"city_hint" => "",      /* ciudad/país que se agrega al buscar direcciones */
				"maps_key" => "",       /* Google Maps (Geocoding) opcional */
				"notify_phone" => "",   /* número central que recibe TODAS las órdenes */
				"branches" => array(),  /* [{id, name, address, lat, lng, radius_km, zones, excluded, phone}] */
				"zones_confirmed" => null /* {at, by, hash}: zonas revisadas en el mapa */
			),

			/* Tipo de venta: pedidos a domicilio o servicio con instalación (internet / TV) */

			"service" => array(
				"mode" => "delivery",          /* delivery | installation */
				"install_fee" => 0,            /* costo de instalación (S/) */
				"tv_enabled" => false,         /* ofrece señal de TV abierta */
				"tv_price" => 0,               /* precio mensual de la TV (0 = incluida) */
				"tvbox_price" => 0,            /* precio del TV box (0 = no se vende) */
				"tv_notes" => "",
				"requirements" => "DNI del titular",
				"pay_on_install" => true,      /* el pago se hace en persona el día de la instalación */
				"cancel_hours" => 4            /* el cliente puede cancelar hasta N horas antes */
			),

			/* Google Calendar (cuenta de servicio) y enlace de suscripción */

			"gcal" => array(
				"enabled" => false,
				"calendar_id" => "",
				"credentials" => "",
				"ics_token" => ""
			)
		);

	}

	static public function pdo(){

		if(self::$pdo === null){

			self::$pdo = cms_schema_pdo();
			self::$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
			self::$pdo->exec("SET time_zone = '".date("P")."'");
		}

		return self::$pdo;

	}

	/*=============================================
	Configuración
	=============================================*/

	static public function get($key){

		$defaults = self::defaults();

		if(!array_key_exists($key, $defaults)){

			return null;
		}

		if(!array_key_exists($key, self::$cache)){

			$value = null;

			try{

				$stmt = self::pdo()->prepare("SELECT value_setting FROM settings WHERE key_setting = ?");
				$stmt->execute(array($key));
				$raw = $stmt->fetchColumn();
				$value = $raw !== false ? json_decode((string)$raw, true) : null;

			}catch(Throwable $e){

				error_log("BizStore::get(".$key."): ".$e->getMessage());
			}

			$default = $defaults[$key];

			if(!is_array($value)){

				$value = $default;

			}else if(!empty($default) && array_keys($default) !== range(0, count($default) - 1)){

				/* Objeto: completar campos nuevos */
				$value = array_merge($default, $value);
			}

			self::$cache[$key] = $value;
		}

		return self::$cache[$key];

	}

	/* Todo menos las credenciales de Google (nunca se envían al navegador) */

	static public function all(){

		$out = array();

		foreach (array_keys(self::defaults()) as $key) {

			if($key !== "gcal"){

				$out[$key] = self::get($key);
			}
		}

		return $out;

	}

	static public function set($key, $value){

		if(!array_key_exists($key, self::defaults())){

			return false;
		}

		$stmt = self::pdo()->prepare("INSERT INTO settings (key_setting, value_setting) VALUES (?, ?) ON DUPLICATE KEY UPDATE value_setting = VALUES(value_setting)");
		$ok = $stmt->execute(array($key, json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)));

		unset(self::$cache[$key]);

		return $ok;

	}

	/*=============================================
	Agenda: utilidades de horario
	=============================================*/

	static public function minutes($hhmm){

		if(!preg_match('/^(\d{1,2}):(\d{2})/', (string)$hhmm, $m)){

			return null;
		}

		return (int)$m[1] * 60 + (int)$m[2];

	}

	static public function hhmm($minutes){

		return sprintf("%02d:%02d", intdiv($minutes, 60), $minutes % 60);

	}

	/*=============================================
	Fechas y horas escritas de cualquier forma:
	2026-11-20 · 20/11/2026 · 20-11 · 20/11/26
	19:30 · 7:30 pm · 7pm · 19h · 7 y media de la noche
	=============================================*/

	static public function normalizeDate($value){

		$value = mb_strtolower(trim((string)$value));

		if(self::validDate($value)){

			return $value;
		}

		if(preg_match('/^(\d{4})[\/.-](\d{1,2})[\/.-](\d{1,2})$/', $value, $m)){

			$date = sprintf("%04d-%02d-%02d", $m[1], $m[2], $m[3]);

			return self::validDate($date) ? $date : "";
		}

		if(preg_match('/^(\d{1,2})[\/.-](\d{1,2})(?:[\/.-](\d{2,4}))?$/', $value, $m)){

			$hasYear = isset($m[3]) && $m[3] !== "";
			$year = $hasYear ? (int)$m[3] : (int)date("Y");
			$year = $year < 100 ? 2000 + $year : $year;
			$date = sprintf("%04d-%02d-%02d", $year, $m[2], $m[1]);

			/* Sin año y ya pasó: es del próximo año */

			if(!$hasYear && self::validDate($date) && $date < date("Y-m-d")){

				$date = sprintf("%04d-%02d-%02d", $year + 1, $m[2], $m[1]);
			}

			return self::validDate($date) ? $date : "";
		}

		if(in_array($value, array("hoy", "today"), true)){ return date("Y-m-d"); }
		if(in_array($value, array("mañana", "manana", "tomorrow"), true)){ return date("Y-m-d", strtotime("+1 day")); }
		if(in_array($value, array("pasado mañana", "pasado manana"), true)){ return date("Y-m-d", strtotime("+2 day")); }

		return "";

	}

	static public function normalizeTime($value){

		$value = mb_strtolower(trim((string)$value));
		$value = str_replace(array("a. m.", "p. m.", "a.m.", "p.m."), array("am", "pm", "am", "pm"), $value);
		$value = preg_replace('/\s+/', " ", $value);

		if(!preg_match('/(\d{1,2})(?:\s*[:.h]\s*(\d{2}))?\s*(?:h|hrs|horas)?\s*(am|pm|de la mañana|de la tarde|de la noche)?/u', $value, $m)){

			return "";
		}

		$h = (int)$m[1];
		$min = isset($m[2]) && $m[2] !== "" ? (int)$m[2] : 0;
		$suffix = $m[3] ?? "";

		if($suffix === "" && preg_match('/(am|pm|de la mañana|de la tarde|de la noche)/u', $value, $s)){

			$suffix = $s[1];
		}

		if(strpos($value, "media") !== false && $min === 0){ $min = 30; }

		if(in_array($suffix, array("pm", "de la tarde", "de la noche"), true) && $h < 12){ $h += 12; }
		if(in_array($suffix, array("am", "de la mañana"), true) && $h === 12){ $h = 0; }

		if($h > 23 || $min > 59){

			return "";
		}

		return sprintf("%02d:%02d", $h, $min);

	}

	static public function validDate($date){

		$d = DateTime::createFromFormat("Y-m-d", (string)$date);

		return $d && $d->format("Y-m-d") === $date;

	}

	/*=============================================
	Turnos posibles de un día (sin mirar ocupación)
	=============================================*/

	const INSTALL_TIMES = "08:00, 10:00, 12:00, 14:00, 16:00, 17:00";

	/* "8, 10, 12:30, 2pm" → ["08:00", "10:00", "12:30", "14:00"] */

	static public function parseTimes($text){

		$out = array();

		foreach (preg_split('/[,;\s]+(?![ap]\.?\s*m)/iu', (string)$text) as $raw) {

			if(!preg_match('/^(\d{1,2})(?::(\d{2}))?\s*([ap])?\.?\s*m?\.?$/iu', trim($raw), $m)){

				continue;
			}

			$h = (int)$m[1];
			$min = isset($m[2]) && $m[2] !== "" ? (int)$m[2] : 0;
			$ap = strtolower($m[3] ?? "");

			if($ap === "p" && $h < 12){ $h += 12; }
			if($ap === "a" && $h === 12){ $h = 0; }

			if($h > 23 || $min > 59){

				continue;
			}

			$out[] = sprintf("%02d:%02d", $h, $min);
		}

		$out = array_values(array_unique($out));
		sort($out);

		return $out;

	}

	/* Horarios fijos de la Agenda (vacío = turnos por rango de apertura/cierre).
	   Internet sin horarios escritos usa los de instalación por defecto */

	static public function fixedTimes(){

		$text = trim((string)(self::get("agenda")["times"] ?? ""));

		if($text === "" && (self::get("service")["mode"] ?? "") === "installation"){

			$text = self::INSTALL_TIMES;
		}

		return self::parseTimes($text);

	}

	/* El cliente eligió un turno ("el viernes a las 10", "lunes 9 de noviembre 2pm", "mañana a las 4"):
	   devuelve ["date", "time"] si es un turno libre, o null */

	static public function matchSlot($text){

		$t = mb_strtolower(trim((string)$text));
		$t = strtr($t, array("á" => "a", "é" => "e", "í" => "i", "ó" => "o", "ú" => "u", "ñ" => "n"));

		if($t === ""){

			return null;
		}

		/* Día */

		$days = array("lunes" => 1, "martes" => 2, "miercoles" => 3, "jueves" => 4, "viernes" => 5, "sabado" => 6, "domingo" => 7);
		$months = array("enero" => 1, "febrero" => 2, "marzo" => 3, "abril" => 4, "mayo" => 5, "junio" => 6, "julio" => 7, "agosto" => 8, "septiembre" => 9, "setiembre" => 9, "octubre" => 10, "noviembre" => 11, "diciembre" => 12);

		$weekday = null; $dayNum = null; $month = null; $date = null;

		if(preg_match('/\bpasado manana\b/u', $t)){

			$date = date("Y-m-d", strtotime("+2 day"));

		}else if(preg_match('/\bmanana\b(?!\s*(a|en)\s*la\s*manana)/u', preg_replace('/(de|por|en) la manana/u', '', $t))){

			$date = date("Y-m-d", strtotime("+1 day"));

		}else if(preg_match('/\bhoy\b/u', $t)){

			$date = date("Y-m-d");
		}

		foreach ($days as $name => $n) {

			if(preg_match('/\b'.$name.'\b/u', $t)){

				$weekday = $n;
			}
		}

		if(preg_match('/\b(\d{1,2})\s*(?:de\s*)?('.implode("|", array_keys($months)).')\b/u', $t, $m)){

			$dayNum = (int)$m[1];
			$month = $months[$m[2]];

		}else if(preg_match('/\b(\d{1,2})\/(\d{1,2})\b/u', $t, $m)){

			$dayNum = (int)$m[1];
			$month = (int)$m[2];
		}

		/* Hora (lo que queda tras quitar la fecha) */

		$rest = preg_replace('/\b\d{1,2}\s*(?:de\s*)?('.implode("|", array_keys($months)).')\b|\b\d{1,2}\/\d{1,2}\b/u', ' ', $t);

		if($weekday !== null && $dayNum === null && preg_match('/\b(?:'.implode("|", array_keys($days)).')\s+(\d{1,2})\b(?!\s*(?::|am|pm|a\.?\s*m|p\.?\s*m|h|hrs|horas|de la|en punto))/u', $rest, $m) && !preg_match('/\b(a|las)\s+'.$m[1].'\b/u', $rest)){

			/* "viernes 6 a las 10": el 6 es el día del mes */

			$dayNum = (int)$m[1];
			$rest = preg_replace('/\b('.implode("|", array_keys($days)).')\s+'.$m[1].'\b/u', '$1', $rest, 1);
		}

		if(!preg_match('/(?:\b(?:a\s+)?las?\s+|\b)(\d{1,2})(?::(\d{2}))?\s*(a\.?\s*m\.?|p\.?\s*m\.?|de la manana|de la tarde|de la noche|hrs?|horas)?(?!\s*(?:de\s+)?(?:'.implode("|", array_keys($months)).'))/u', $rest, $h)){

			return null;
		}

		$hour = (int)$h[1];
		$min = isset($h[2]) && $h[2] !== "" ? (int)$h[2] : 0;
		$suffix = (string)($h[3] ?? "");

		if(preg_match('/^p|tarde|noche/u', $suffix) && $hour < 12){ $hour += 12; }

		/* Turnos libres de los próximos días */

		$free = array();

		foreach (self::nextFree(200) as $s) {

			$free[$s["date"]][] = $s["time"];
		}

		$candidates = array();

		foreach ($free as $d => $times) {

			$ts = strtotime($d);

			if($date !== null && $d !== $date){ continue; }
			if($weekday !== null && (int)date("N", $ts) !== $weekday){ continue; }
			if($dayNum !== null && (int)date("j", $ts) !== $dayNum){ continue; }
			if($month !== null && (int)date("n", $ts) !== $month){ continue; }

			$candidates[$d] = $times;
		}

		/* Sin ningún día escrito no se adivina */

		if($date === null && $weekday === null && $dayNum === null){

			return null;
		}

		foreach ($candidates as $d => $times) {

			foreach (array($hour, $hour < 12 && $suffix === "" ? $hour + 12 : -1) as $try) {

				$hhmm = sprintf("%02d:%02d", $try, $min);

				if($try >= 0 && in_array($hhmm, $times, true)){

					return array("date" => $d, "time" => $hhmm);
				}
			}
		}

		return null;

	}

	static public function daySlots($date){

		$agenda = self::get("agenda");

		if(!self::validDate($date)){

			return array();
		}

		$weekday = (int)date("N", strtotime($date)); /* 1 = lunes ... 7 = domingo */

		if(!in_array($weekday, array_map("intval", (array)$agenda["days"]), true)){

			return array();
		}

		/* Horarios fijos: solo esas horas (instalaciones: 8, 10, 12, 2, 4 y 5 por defecto) */

		$fixed = self::fixedTimes();

		if(!empty($fixed)){

			return $fixed;
		}

		$step = max(5, (int)$agenda["slot_minutes"]);
		$open = self::minutes($agenda["open"]);
		$close = self::minutes($agenda["close"]);
		$bs = self::minutes($agenda["break_start"]);
		$be = self::minutes($agenda["break_end"]);

		if($open === null || $close === null || $close <= $open){

			return array();
		}

		$slots = array();

		for($t = $open; $t + $step <= $close; $t += $step){

			if($bs !== null && $be !== null && $t < $be && $t + $step > $bs){

				continue;
			}

			$slots[] = self::hhmm($t);
		}

		return $slots;

	}

	/*=============================================
	Citas activas de un rango de fechas
	=============================================*/

	static public function appointments($from, $to, $includeCancelled = false){

		$sql = "SELECT * FROM appointments WHERE date_appointment BETWEEN ? AND ?".($includeCancelled ? "" : " AND status_appointment <> 'cancelada'")." ORDER BY date_appointment, time_appointment";

		$stmt = self::pdo()->prepare($sql);
		$stmt->execute(array($from, $to));

		return $stmt->fetchAll();

	}

	static public function appointment($id){

		$stmt = self::pdo()->prepare("SELECT * FROM appointments WHERE id_appointment = ?");
		$stmt->execute(array((int)$id));

		return $stmt->fetch() ?: null;

	}

	/* Cuántas citas activas se cruzan con [start, start+duration) */

	static private function overlapping($date, $start, $duration, $excludeId = 0){

		$stmt = self::pdo()->prepare("SELECT time_appointment, duration_appointment FROM appointments WHERE date_appointment = ? AND status_appointment <> 'cancelada' AND id_appointment <> ?");
		$stmt->execute(array($date, (int)$excludeId));

		$count = 0;

		/* Horarios fijos (8, 10, 12, 2, 4, 5): cada hora es un turno propio; una cita
		   en un horario fijo solo ocupa ESA hora (no se "come" la siguiente por duración) */

		$fixed = array_map(array(__CLASS__, "minutes"), self::fixedTimes());

		foreach ($stmt->fetchAll() as $row) {

			$s = self::minutes($row["time_appointment"]);
			$e = $s + max(5, (int)$row["duration_appointment"]);

			if(!empty($fixed) && in_array($s, $fixed, true)){

				if($s === $start){

					$count++;
				}

				continue;
			}

			if($s < $start + $duration && $e > $start){

				$count++;
			}
		}

		return $count;

	}

	/*=============================================
	Turnos libres de un día
	=============================================*/

	static public function freeSlots($date, $excludeId = 0){

		$agenda = self::get("agenda");
		$step = max(5, (int)$agenda["slot_minutes"]);
		$capacity = max(1, (int)$agenda["capacity"]);
		$minStart = time() + max(0, (float)$agenda["min_hours"]) * 3600;

		$free = array();

		foreach (self::daySlots($date) as $slot) {

			if(strtotime($date." ".$slot.":00") < $minStart){

				continue;
			}

			if(self::overlapping($date, self::minutes($slot), $step, $excludeId) < $capacity){

				$free[] = $slot;
			}
		}

		return $free;

	}

	/*=============================================
	Próximos turnos libres (para sugerir al cliente)
	=============================================*/

	static public function nextFree($limit = 8, $fromDate = null){

		$agenda = self::get("agenda");
		$days = max(1, min(120, (int)$agenda["max_days"]));
		$start = $fromDate && self::validDate($fromDate) ? strtotime($fromDate) : strtotime(date("Y-m-d"));

		$out = array();

		for($i = 0; $i <= $days && count($out) < $limit; $i++){

			$date = date("Y-m-d", strtotime("+".$i." day", $start));

			foreach (self::freeSlots($date) as $slot) {

				$out[] = array("date" => $date, "time" => $slot);

				if(count($out) >= $limit){

					break;
				}
			}
		}

		return $out;

	}

	/*=============================================
	Reservar un turno sin choques.
	Un bloqueo de MySQL (GET_LOCK) evita que dos
	conversaciones tomen el mismo turno a la vez.
	Devuelve ["ok", "id", "error", "suggest"]
	=============================================*/

	static public function book(array $data, $excludeId = 0){

		$result = self::bookInner($data, $excludeId);

		if($result["ok"]){

			self::syncCalendar($result["id"]);
		}

		return $result;

	}

	static private function bookInner(array $data, $excludeId = 0){

		$agenda = self::get("agenda");
		$date = self::normalizeDate($data["date"] ?? "");
		$time = self::normalizeTime($data["time"] ?? "");
		$step = max(5, (int)$agenda["slot_minutes"]);
		$capacity = max(1, (int)$agenda["capacity"]);
		$force = !empty($data["force"]);

		if(!self::validDate($date) || $time === "" || self::minutes($time) === null){

			return array("ok" => false, "error" => "Fecha u hora inválida.", "suggest" => self::nextFree(6));
		}

		$time = self::hhmm(self::minutes($time));

		if(!$force){

			if(!in_array($time, self::daySlots($date), true)){

				return array("ok" => false, "error" => "Ese horario no está dentro de la atención.", "suggest" => self::nextFree(6, $date));
			}

			if(strtotime($date." ".$time.":00") < time() + max(0, (float)$agenda["min_hours"]) * 3600){

				return array("ok" => false, "error" => "Ese horario ya pasó o está muy próximo.", "suggest" => self::nextFree(6));
			}
		}

		$pdo = self::pdo();
		$lock = (int)$pdo->query("SELECT GET_LOCK('cms_agenda_booking', 10)")->fetchColumn();

		try{

			if(self::overlapping($date, self::minutes($time), $step, $excludeId) >= $capacity){

				return array("ok" => false, "error" => "Ese turno ya está ocupado.", "suggest" => self::nextFree(6, $date));
			}

			$fields = array(
				"phone" => biz_clean_phone((string)($data["phone"] ?? "")),
				"name" => mb_substr(trim((string)($data["name"] ?? "")), 0, 191),
				"service" => mb_substr(trim((string)($data["service"] ?? "")), 0, 191),
				"summary" => trim((string)($data["summary"] ?? "")),
				"source" => (string)($data["source"] ?? "ia"),
				"status" => in_array($data["status"] ?? "", array("confirmada", "pendiente"), true) ? $data["status"] : "confirmada",
				"people" => !empty($data["people"]) ? max(1, min(500, (int)$data["people"])) : null
			);

			/* La misma cita del mismo cliente no se guarda dos veces */

			if(!$excludeId && $fields["phone"] !== ""){

				$dup = $pdo->prepare("SELECT id_appointment FROM appointments WHERE phone_appointment = ? AND date_appointment = ? AND time_appointment = ? AND status_appointment <> 'cancelada' LIMIT 1");
				$dup->execute(array($fields["phone"], $date, $time.":00"));
				$dupId = (int)$dup->fetchColumn();

				if($dupId){

					return array("ok" => true, "id" => $dupId, "date" => $date, "time" => $time, "error" => "", "duplicate" => true);
				}
			}

			if($excludeId){

				$stmt = $pdo->prepare("UPDATE appointments SET phone_appointment = ?, name_appointment = ?, service_appointment = ?, date_appointment = ?, time_appointment = ?, duration_appointment = ?, summary_appointment = ?, people_appointment = COALESCE(?, people_appointment), status_appointment = IF(status_appointment = 'pendiente', 'confirmada', status_appointment) WHERE id_appointment = ?");
				$stmt->execute(array($fields["phone"], $fields["name"], $fields["service"], $date, $time.":00", $step, $fields["summary"], $fields["people"], (int)$excludeId));
				$id = (int)$excludeId;

			}else{

				$stmt = $pdo->prepare("INSERT INTO appointments (phone_appointment, name_appointment, service_appointment, date_appointment, time_appointment, duration_appointment, summary_appointment, people_appointment, status_appointment, source_appointment, date_created_appointment) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
				$stmt->execute(array($fields["phone"], $fields["name"], $fields["service"], $date, $time.":00", $step, $fields["summary"], $fields["people"], $fields["status"], $fields["source"], date("Y-m-d H:i:s")));
				$id = (int)$pdo->lastInsertId();
			}

			return array("ok" => true, "id" => $id, "date" => $date, "time" => $time, "error" => "");

		}finally{

			if($lock){

				$pdo->query("SELECT RELEASE_LOCK('cms_agenda_booking')");
			}
		}

	}

	static public function setStatus($id, $status){

		if(!in_array($status, array("confirmada", "cancelada", "atendida", "pendiente"), true)){

			return false;
		}

		$stmt = self::pdo()->prepare("UPDATE appointments SET status_appointment = ? WHERE id_appointment = ?");
		$ok = $stmt->execute(array($status, (int)$id));

		if($ok){

			self::syncCalendar($id);
		}

		return $ok;

	}

	/*=============================================
	Guardar una solicitud de reserva que no se pudo
	confirmar sola (sin hora exacta o fuera de horario).
	Queda "pendiente" en la agenda para confirmarla.
	=============================================*/

	static public function request(array $data){

		$date = self::normalizeDate($data["date"] ?? "") ?: date("Y-m-d");
		$time = self::normalizeTime($data["time"] ?? "") ?: "00:00";
		$phone = biz_clean_phone((string)($data["phone"] ?? ""));
		$people = !empty($data["people"]) ? max(1, min(500, (int)$data["people"])) : null;
		$name = mb_substr(trim((string)($data["name"] ?? "")), 0, 191);
		$service = mb_substr(trim((string)($data["service"] ?? "")) ?: "Reserva", 0, 191);
		$summary = trim((string)($data["summary"] ?? ""));
		$pdo = self::pdo();

		/* Una sola solicitud pendiente por cliente cada 30 minutos */

		$dup = $pdo->prepare("SELECT id_appointment FROM appointments WHERE phone_appointment = ? AND status_appointment = 'pendiente' AND date_created_appointment >= ? ORDER BY id_appointment DESC LIMIT 1");
		$dup->execute(array($phone, date("Y-m-d H:i:s", time() - 1800)));
		$dupId = (int)$dup->fetchColumn();

		if($dupId){

			$stmt = $pdo->prepare("UPDATE appointments SET name_appointment = ?, service_appointment = ?, date_appointment = ?, time_appointment = ?, summary_appointment = ?, people_appointment = ? WHERE id_appointment = ?");
			$stmt->execute(array($name, $service, $date, $time.":00", $summary, $people, $dupId));
			$id = $dupId;

		}else{

			$stmt = $pdo->prepare("INSERT INTO appointments (phone_appointment, name_appointment, service_appointment, date_appointment, time_appointment, duration_appointment, summary_appointment, people_appointment, status_appointment, source_appointment, date_created_appointment) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'pendiente', ?, ?)");
			$stmt->execute(array($phone, $name, $service, $date, $time.":00", max(5, (int)self::get("agenda")["slot_minutes"]), $summary, $people, (string)($data["source"] ?? "bot"), date("Y-m-d H:i:s")));
			$id = (int)$pdo->lastInsertId();
		}

		self::syncCalendar($id);

		return $id;

	}

	/*=============================================
	Google Calendar: si falla nunca detiene la reserva
	=============================================*/

	static public function syncCalendar($id){

		try{

			require_once __DIR__."/business.calendar.php";

			BizCalendar::sync((int)$id);

		}catch(Throwable $e){

			error_log("Google Calendar: ".$e->getMessage());
		}

	}

	static public function markNotified($id){

		$stmt = self::pdo()->prepare("UPDATE appointments SET notified_appointment = 1 WHERE id_appointment = ?");

		return $stmt->execute(array((int)$id));

	}

	/* Próximas citas activas de un cliente */

	static public function upcomingFor($phone){

		$stmt = self::pdo()->prepare("SELECT * FROM appointments WHERE phone_appointment = ? AND status_appointment <> 'cancelada' AND CONCAT(date_appointment, ' ', time_appointment) >= ? ORDER BY date_appointment, time_appointment LIMIT 5");
		$stmt->execute(array(biz_clean_phone($phone), date("Y-m-d H:i:s")));

		return $stmt->fetchAll();

	}

	/*=============================================
	Formatos legibles en español
	=============================================*/

	static public function dayName($date){

		$days = array(1 => "lunes", "martes", "miércoles", "jueves", "viernes", "sábado", "domingo");
		$months = array(1 => "enero", "febrero", "marzo", "abril", "mayo", "junio", "julio", "agosto", "septiembre", "octubre", "noviembre", "diciembre");
		$ts = strtotime($date);

		return $days[(int)date("N", $ts)]." ".(int)date("j", $ts)." de ".$months[(int)date("n", $ts)];

	}

	/* Hora corta para ofrecer turnos: 8:00, 10:00, 12:00, 2:00, 4:00, 5:00 */

	static public function timeShort($hhmm){

		$m = self::minutes($hhmm);

		if($m === null){

			return (string)$hhmm;
		}

		$h = intdiv($m, 60);

		return sprintf("%d:%02d", ($h % 12) ?: 12, $m % 60);

	}

	/* Una línea de turnos: Sábado 7 de noviembre: 8:00, 10:00, 12:00, 2:00, 4:00, 5:00 */

	static public function slotLine($date, array $times){

		$day = self::dayName($date);

		return mb_strtoupper(mb_substr($day, 0, 1)).mb_substr($day, 1).": ".implode(", ", array_map(array(__CLASS__, "timeShort"), $times));

	}

	static public function time12($hhmm){

		$m = self::minutes($hhmm);

		if($m === null){

			return (string)$hhmm;
		}

		$h = intdiv($m, 60);

		return sprintf("%d:%02d %s", ($h % 12) ?: 12, $m % 60, $h < 12 ? "a. m." : "p. m.");

	}

	/*=============================================
	Enlace para agregar la cita a Google Calendar
	=============================================*/

	static public function googleCalendarLink(array $appt){

		$tz = new DateTimeZone(date_default_timezone_get());
		$start = new DateTime($appt["date_appointment"]." ".$appt["time_appointment"], $tz);
		$end = (clone $start)->modify("+".max(5, (int)$appt["duration_appointment"])." minutes");
		$company = self::get("company");

		$title = trim(($appt["service_appointment"] ?: "Cita")." - ".($appt["name_appointment"] ?: $appt["phone_appointment"]));
		$details = trim(($appt["summary_appointment"] ?? "")."\nWhatsApp: +".$appt["phone_appointment"]);

		return "https://calendar.google.com/calendar/render?".http_build_query(array(
			"action" => "TEMPLATE",
			"text" => $title,
			"dates" => $start->format("Ymd\THis")."/".$end->format("Ymd\THis"),
			"ctz" => $tz->getName(),
			"details" => $details,
			"location" => (string)($company["address"] ?? "")
		), "", "&", PHP_QUERY_RFC3986);

	}

}

/* Solo dígitos (sin depender de ChatService) */

function biz_clean_phone($phone){

	$digits = preg_replace("/\D/", "", (string)$phone);

	/* Celular peruano escrito sin código de país (987654321 → 51987654321) */

	if(strlen($digits) === 9 && $digits[0] === "9"){

		$digits = "51".$digits;
	}

	/* 0051… → 51… */

	if(strlen($digits) > 11 && substr($digits, 0, 2) === "00"){

		$digits = substr($digits, 2);
	}

	return $digits;

}
