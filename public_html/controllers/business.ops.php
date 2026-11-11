<?php

require_once __DIR__."/business.store.php";
require_once __DIR__."/business.geo.php";
require_once __DIR__."/chat.service.php";

/*=============================================
Operación diaria:
 - Usage:    consumo de tokens de IA y mensajes de WhatsApp
 - Limits:   límites por minuto y de tokens (Configuración)
 - Notify:   avisos al negocio (central, agenda y sucursal)
             con respaldo por plantilla fuera de las 24 h
 - Followup: cierre amable de la conversación después de una orden
 - Tasks:    tareas automáticas (cron o cada minuto con el tráfico)
=============================================*/

class Usage{

	static public function log($kind, $provider, $model, $in, $out, $ok, $detail = "", $phone = ""){

		try{

			BizStore::pdo()->prepare("INSERT INTO usage_log (kind_usage, provider_usage, model_usage, input_usage, output_usage, ok_usage, detail_usage, phone_usage, created_usage) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)")
				->execute(array($kind, mb_substr((string)$provider, 0, 30), mb_substr((string)$model, 0, 80), max(0, (int)$in), max(0, (int)$out), $ok ? 1 : 0, mb_substr((string)$detail, 0, 40), mb_substr(preg_replace('/\D/', '', (string)$phone), 0, 30), date("Y-m-d H:i:s")));

		}catch(Throwable $e){

			/* Tabla aún no creada: nunca detener la respuesta al cliente */
		}

	}

	/* Respuesta JSON de OpenAI o Anthropic */

	static public function ai($provider, $model, $json, $ok){

		$u = $json->usage ?? null;

		$in = (int)($u->prompt_tokens ?? 0) + (int)($u->input_tokens ?? 0) + (int)($u->cache_read_input_tokens ?? 0) + (int)($u->cache_creation_input_tokens ?? 0);
		$out = (int)($u->completion_tokens ?? 0) + (int)($u->output_tokens ?? 0);

		self::log("ai", $provider, $json->model ?? $model, $in, $out, $ok, "respuesta");

	}

	static public function aiTokens($from){

		try{

			$stmt = BizStore::pdo()->prepare("SELECT COALESCE(SUM(input_usage + output_usage), 0) FROM usage_log WHERE kind_usage = 'ai' AND created_usage >= ?");
			$stmt->execute(array($from));

			return (int)$stmt->fetchColumn();

		}catch(Throwable $e){

			return 0;
		}

	}

}

class Limits{

	static private function n($key){

		return max(0, (int)cms_config($key, 0));

	}

	/* ¿Se agotó el cupo de tokens de IA del día o del mes? Devuelve el motivo */

	static public function aiBlocked(){

		$day = self::n("limit_ai_tokens_day");
		$month = self::n("limit_ai_tokens_month");

		if($day > 0 && Usage::aiTokens(date("Y-m-d 00:00:00")) >= $day){

			return "Se alcanzó el límite diario de tokens de IA (".number_format($day).").";
		}

		if($month > 0 && Usage::aiTokens(date("Y-m-01 00:00:00")) >= $month){

			return "Se alcanzó el límite mensual de tokens de IA (".number_format($month).").";
		}

		return "";

	}

	/* El cliente envía demasiados mensajes por minuto (spam o ráfaga) */

	static public function clientFlood($phone){

		$limit = self::n("limit_client_per_min");

		if($limit <= 0){

			return false;
		}

		try{

			$stmt = BizStore::pdo()->prepare("SELECT COUNT(*) FROM messages WHERE phone_message = ? AND type_message = 'client' AND date_updated_message >= ?");
			$stmt->execute(array(preg_replace('/\D/', '', (string)$phone), date("Y-m-d H:i:s", time() - 60)));

			return (int)$stmt->fetchColumn() > $limit;

		}catch(Throwable $e){

			return false;
		}

	}

	/* Envíos por minuto de todo el sistema */

	static public function sendBlocked(){

		$limit = self::n("limit_send_per_min");

		if($limit <= 0){

			return false;
		}

		try{

			$stmt = BizStore::pdo()->prepare("SELECT COUNT(*) FROM usage_log WHERE kind_usage = 'wa' AND created_usage >= ?");
			$stmt->execute(array(date("Y-m-d H:i:s", time() - 60)));

			return (int)$stmt->fetchColumn() >= $limit;

		}catch(Throwable $e){

			return false;
		}

	}

}

class Notify{

	const KINDS = array("pedido", "agenda", "aviso", "consulta");

	static private function clean($phone){

		return biz_clean_phone($phone);

	}

	/* Números del equipo (reciben avisos; la IA no les responde como clientes) */

	static public function staffPhones(){

		$list = array(
			self::mainPhone(),
			BizStore::get("agenda")["notify_phone"] ?? "",
			BizStore::get("delivery")["notify_phone"] ?? "",
			BizStore::get("inquiries")["phone"] ?? ""
		);

		foreach (BizGeo::branches() as $b) {

			$list[] = $b["phone"] ?? "";
		}

		foreach (self::areas() as $a) {

			$list[] = $a["phone"] ?? "";
		}

		return array_values(array_unique(array_filter(array_map(array(__CLASS__, "clean"), $list), function($p){ return strlen($p) >= 9; })));

	}

	/* Números por área (Mi negocio › Números por área) */

	static public function areas(){

		$out = array();

		foreach ((array)BizStore::get("contacts") as $c) {

			if(is_array($c) && self::clean($c["phone"] ?? "") !== ""){

				$out[] = $c;
			}
		}

		return $out;

	}

	static public function isStaff($phone){

		return in_array(self::clean($phone), self::staffPhones(), true);

	}

	/* ¿El mensaje responde (cita) a un aviso de orden/cita enviado al equipo? */

	static public function isReplyToNotice($contextId){

		if($contextId === ""){

			return false;
		}

		try{

			$stmt = BizStore::pdo()->prepare("SELECT template_message FROM messages WHERE wamid_message = ? LIMIT 1");
			$stmt->execute(array($contextId));
			$tpl = json_decode((string)$stmt->fetchColumn(), true);

			return is_array($tpl) && in_array($tpl["type"] ?? "", array("pedido", "agenda", "aviso", "staff"), true);

		}catch(Throwable $e){

			return false;
		}

	}

	/* Espera unos segundos y dice si el cliente ya escribió otro mensaje después de este
	   (entonces responde ese otro, que ve todo el historial) */

	static public function newerClientMessage($phone, $wamid, $seconds){

		$seconds = max(0, min(15, (int)$seconds));

		if($wamid === "" || $seconds === 0){

			return false;
		}

		sleep($seconds);

		$pdo = BizStore::pdo();
		$stmt = $pdo->prepare("SELECT id_message FROM messages WHERE wamid_message = ? LIMIT 1");
		$stmt->execute(array($wamid));
		$mine = (int)$stmt->fetchColumn();

		if(!$mine){

			return false;
		}

		$stmt = $pdo->prepare("SELECT COUNT(*) FROM messages WHERE phone_message = ? AND type_message = 'client' AND id_message > ?");
		$stmt->execute(array(self::clean($phone), $mine));

		return (int)$stmt->fetchColumn() > 0;

	}

	/* Último mensaje del cliente (para saber si llegó otro mientras la IA pensaba) */

	static public function lastClientId($phone){

		try{

			$stmt = BizStore::pdo()->prepare("SELECT MAX(id_message) FROM messages WHERE phone_message = ? AND type_message = 'client'");
			$stmt->execute(array(self::clean($phone)));

			return (int)$stmt->fetchColumn();

		}catch(Throwable $e){

			return 0;
		}

	}

	/* Quién recibe una orden o una cita: central + agenda + WhatsApp de la sucursal
	   + las áreas marcadas para ese tipo de aviso (orders | appts | cancel) */

	static public function recipients($branch = "", $topic = ""){

		$list = array(
			BizStore::get("delivery")["notify_phone"] ?? "",
			BizStore::get("agenda")["notify_phone"] ?? ""
		);

		if(trim((string)$branch) !== "" && ($b = BizGeo::findBranch($branch))){

			$list[] = $b["phone"] ?? "";
		}

		foreach (self::areas() as $a) {

			if($topic === "" ? (!empty($a["orders"]) || !empty($a["appts"]) || !empty($a["cancel"])) : !empty($a[$topic])){

				$list[] = $a["phone"];
			}
		}

		/* El número principal recibe siempre todo */

		$list[] = self::mainPhone();

		$out = array_values(array_unique(array_filter(array_map(array(__CLASS__, "clean"), $list), function($p){ return strlen($p) >= 9; })));

		/* Respaldo: nadie configurado para este aviso → todos los números por área
		   y el de consultas (un aviso nunca debe perderse en silencio) */

		if(empty($out)){

			$fallback = array(BizStore::get("inquiries")["phone"] ?? "");

			foreach (self::areas() as $a) {

				$fallback[] = $a["phone"] ?? "";
			}

			$out = array_values(array_unique(array_filter(array_map(array(__CLASS__, "clean"), $fallback), function($p){ return strlen($p) >= 9; })));
		}

		return $out;

	}

	/* Número principal (Mi negocio › Números por área); si no se configuró,
	   el WhatsApp central de órdenes de Cobertura */

	static public function mainPhone(){

		$main = self::clean(BizStore::get("main")["phone"] ?? "");

		return $main !== "" ? $main : self::clean(BizStore::get("delivery")["notify_phone"] ?? "");

	}

	/* WhatsApp solo deja enviar texto libre si ese número escribió en las últimas 24 h */

	static public function windowOpen($phone){

		try{

			$stmt = BizStore::pdo()->prepare("SELECT COUNT(*) FROM messages WHERE phone_message = ? AND type_message = 'client' AND date_updated_message >= ?");
			$stmt->execute(array(self::clean($phone), date("Y-m-d H:i:s", time() - 86000)));

			return (int)$stmt->fetchColumn() > 0;

		}catch(Throwable $e){

			return true;
		}

	}

	/* Plantilla aprobada para avisos fuera de las 24 h: se configura SOLO en
	   Mi negocio › Números por área (mientras no se guarde ahí, el valor por
	   defecto toma el que antes estaba en /setup/) */

	static public function template(){

		return trim((string)(BizStore::get("notices")["template"] ?? ""));

	}

	static public function templateLang(){

		return trim((string)(BizStore::get("notices")["lang"] ?? "")) ?: "es";

	}

	/* Las variables de plantilla no aceptan saltos de línea ni 4+ espacios */

	static public function flat($text){

		$text = str_replace(array("*", "_", "\t"), "", (string)$text);
		$text = preg_replace('/\s*\n+\s*/u', " · ", trim($text));
		$text = preg_replace('/ {2,}/', " ", $text);

		return mb_substr($text, 0, 1000);

	}

	static public function templatePayload($to, $text){

		return array(
			"messaging_product" => "whatsapp",
			"to" => self::clean($to),
			"type" => "template",
			"template" => array(
				"name" => self::template(),
				"language" => array("code" => self::templateLang()),
				"components" => array(array(
					"type" => "body",
					"parameters" => array(array("type" => "text", "text" => self::flat($text)))
				))
			)
		);

	}

	/* Enviar un aviso a un número. Devuelve el resultado con la vía usada */

	static public function deliver($to, $text, $kind = "aviso"){

		$api = ChatService::api();
		$to = self::clean($to);
		$open = self::windowOpen($to);
		$template = self::template();

		$entry = array("to" => $to, "ok" => false, "wamid" => null, "via" => "texto", "window" => $open, "error" => "", "at" => date("Y-m-d H:i:s"));

		if(!$api){

			$entry["error"] = "No hay una cuenta de WhatsApp activa.";
			return $entry;
		}

		/* WhatsApp no deja que el bot se escriba a sí mismo */

		if($to !== "" && $to === self::clean($api->number_whatsapp ?? "")){

			$entry["error"] = "Este es el mismo número del bot: WhatsApp no permite que el bot se envíe mensajes a sí mismo. En Mi negocio › Números por área pon como número principal OTRO celular (el del dueño o del encargado).";
			error_log("Avisos: ".$entry["error"]);
			return $entry;
		}

		if(!$open && $template !== ""){

			$entry["via"] = "plantilla";
			$result = ChatService::deliver($api, $to, self::templatePayload($to, $text), $text, json_encode(array("type" => $kind, "title" => "", "via" => "plantilla")));

		}else{

			$result = ChatService::deliver($api, $to, ChatService::textPayload($to, $text), $text, json_encode(array("type" => $kind, "title" => "")));
		}

		$entry["ok"] = (bool)$result["ok"];
		$entry["wamid"] = $result["wamid"];
		$entry["error"] = $result["ok"] ? "" : $result["error"];

		if($result["ok"] && !$open && $template === ""){

			$entry["error"] = "Ventana de 24 h cerrada: WhatsApp puede rechazarlo. Escribe al bot desde este número o configura la plantilla de avisos en Mi negocio › Números por área.";
		}

		if(!$result["ok"]){

			error_log("Avisos: no se pudo avisar a ".$to.": ".$result["error"]);
		}

		return $entry;

	}

	/* Enviar a todos los responsables; devuelve la lista de resultados */

	static public function send($text, $branch = "", $kind = "aviso", $topic = ""){

		$out = array();

		foreach (self::recipients($branch, $topic) as $to) {

			$out[] = self::deliver($to, $text, $kind);
		}

		return $out;

	}

	/* Meta rechazó el aviso por la ventana de 24 h (131047): reenviar con la plantilla */

	static public function retryFailed($wamid, $code){

		if((int)$code !== 131047 || self::template() === "" || !$wamid){

			return false;
		}

		$stmt = BizStore::pdo()->prepare("SELECT phone_message, business_message, template_message FROM messages WHERE wamid_message = ? LIMIT 1");
		$stmt->execute(array($wamid));
		$m = $stmt->fetch();

		if(!$m){

			return false;
		}

		$meta = json_decode((string)$m["template_message"], true);

		if(!in_array($meta["type"] ?? "", self::KINDS, true) || ($meta["via"] ?? "") === "plantilla"){

			return false;
		}

		$api = ChatService::api();
		$text = (string)$m["business_message"];
		$result = ChatService::deliver($api, $m["phone_message"], self::templatePayload($m["phone_message"], $text), $text, json_encode(array("type" => $meta["type"], "title" => "", "via" => "plantilla")));

		/* Actualizar el registro de avisos de la orden (si lo hay) */

		$find = BizStore::pdo()->prepare("SELECT id_order, notify_order FROM orders WHERE notify_order LIKE ? LIMIT 1");
		$find->execute(array("%".$wamid."%"));

		if($row = $find->fetch()){

			$log = json_decode((string)$row["notify_order"], true) ?: array();

			foreach ($log as &$entry) {

				if(($entry["wamid"] ?? "") === $wamid){

					$entry["via"] = "plantilla";
					$entry["wamid"] = $result["wamid"];
					$entry["ok"] = (bool)$result["ok"];
					$entry["error"] = $result["ok"] ? "" : $result["error"];
				}
			}

			BizStore::pdo()->prepare("UPDATE orders SET notify_order = ? WHERE id_order = ?")->execute(array(json_encode($log, JSON_UNESCAPED_UNICODE), (int)$row["id_order"]));
		}

		return $result["ok"];

	}

	/* WhatsApp rechazó un aviso al equipo y no se pudo reenviar: se registra en Inconvenientes */

	static public function reportFailed($wamid, $error){

		if(!$wamid){

			return;
		}

		$stmt = BizStore::pdo()->prepare("SELECT phone_message, business_message, template_message FROM messages WHERE wamid_message = ? LIMIT 1");
		$stmt->execute(array($wamid));
		$m = $stmt->fetch();

		if(!$m){

			return;
		}

		$meta = json_decode((string)$m["template_message"], true);

		if(!in_array($meta["type"] ?? "", self::KINDS, true)){

			return;
		}

		require_once __DIR__."/business.incidents.php";

		$order = preg_match('/(?:Orden|Pedido|orden|pedido)[^#\d]{0,15}#\s*(\d+)/u', (string)$m["business_message"], $o) ? (int)$o[1] : null;
		$hint = strpos((string)$error, "131047") !== false || stripos((string)$error, "24 horas") !== false
			? " Ese número no le escribió al bot en las últimas 24 h: configura la plantilla de avisos en Mi negocio › Números por área o escribe al bot desde ese número una vez al día."
			: "";

		BizIncidents::record(array(
			"phone" => "",
			"order" => $order,
			"kind" => "sistema",
			"detail" => "El aviso al equipo (+".$m["phone_message"].")".($order ? " de la orden #".$order : "")." no llegó: ".mb_substr((string)$error, 0, 300).".".$hint,
			"source" => "sistema",
			"dedupe" => 1800
		));

	}

	/* El número del equipo volvió a escribir (ventana de 24 h abierta): se le reenvían
	   los avisos de los últimos 3 días que WhatsApp rechazó o que no llegaron */

	static public function flushPending($phone){

		$phone = self::clean($phone);

		if($phone === "" || !in_array($phone, self::staffPhones(), true)){

			return 0;
		}

		$stmt = BizStore::pdo()->prepare("SELECT id_message, business_message, template_message FROM messages WHERE phone_message = ? AND type_message = 'business' AND status_message = 'failed' AND date_updated_message >= ? ORDER BY id_message ASC LIMIT 15");
		$stmt->execute(array($phone, date("Y-m-d H:i:s", time() - 3 * 86400)));

		$sent = 0;

		foreach ($stmt->fetchAll() as $m) {

			$meta = json_decode((string)$m["template_message"], true);

			if(!is_array($meta) || !in_array($meta["type"] ?? "", self::KINDS, true) || !empty($meta["resent"])){

				continue;
			}

			/* Marcar primero: si el reenvío vuelve a fallar no se repite en bucle */

			$meta["resent"] = 1;
			BizStore::pdo()->prepare("UPDATE messages SET template_message = ? WHERE id_message = ?")->execute(array(json_encode($meta, JSON_UNESCAPED_UNICODE), (int)$m["id_message"]));

			$text = "📬 *Aviso pendiente (no te llegó antes)*\n\n".(string)$m["business_message"];
			$entry = self::deliver($phone, $text, $meta["type"]);

			if($entry["ok"]){

				$sent++;

				/* Actualizar el registro de avisos de la orden */

				if(preg_match('/Orden[^#\d]{0,15}#\s*(\d+)/u', (string)$m["business_message"], $o)){

					$find = BizStore::pdo()->prepare("SELECT notify_order FROM orders WHERE id_order = ?");
					$find->execute(array((int)$o[1]));
					$log = json_decode((string)$find->fetchColumn(), true) ?: array();
					$log[] = $entry + array("event" => "reenvío");

					BizStore::pdo()->prepare("UPDATE orders SET notify_order = ? WHERE id_order = ?")->execute(array(json_encode(array_slice($log, -20), JSON_UNESCAPED_UNICODE), (int)$o[1]));
				}
			}
		}

		return $sent;

	}

	/* Estado real de cada aviso (WhatsApp confirma la entrega por el webhook) */

	static public function withStatus(array $log){

		$ids = array_filter(array_column($log, "wamid"));

		if(empty($ids)){

			return $log;
		}

		$stmt = BizStore::pdo()->prepare("SELECT wamid_message, status_message, error_message FROM messages WHERE wamid_message IN (".implode(",", array_fill(0, count($ids), "?")).")");
		$stmt->execute(array_values($ids));

		$map = array();

		foreach ($stmt->fetchAll() as $r) {

			$map[$r["wamid_message"]] = $r;
		}

		foreach ($log as &$entry) {

			$m = $map[$entry["wamid"] ?? ""] ?? null;

			if($m){

				$entry["status"] = (string)$m["status_message"];

				if($m["status_message"] === "failed"){

					$entry["ok"] = false;
					$entry["error"] = (string)$m["error_message"];
				}
			}
		}

		return $log;

	}

}

class Followup{

	static public function enabled(){

		return (bool)cms_config("followup_enabled", true);

	}

	/* El cliente volvió a escribir: el ciclo empieza de nuevo */

	static public function reset($phone){

		try{

			BizStore::pdo()->prepare("UPDATE followups SET stage_followup = 0, updated_followup = ? WHERE phone_followup = ?")->execute(array(date("Y-m-d H:i:s"), preg_replace('/\D/', '', (string)$phone)));

		}catch(Throwable $e){}

	}

	/*=============================================
	Después de cerrar una orden, si el cliente siguió
	conversando y de pronto deja de responder:
	  1) "¿Te puedo ayudar en algo más?"
	  2) Si sigue sin responder: "¡Gracias!" y no se
	     le vuelve a escribir.
	=============================================*/

	static public function run(){

		if(!self::enabled()){

			return 0;
		}

		$pdo = BizStore::pdo();
		$ask = max(1, (int)cms_config("followup_ask_min", 10)) * 60;
		$close = max(1, (int)cms_config("followup_close_min", 10)) * 60;
		$sent = 0;
		$staff = Notify::staffPhones();
		$api = ChatService::api();

		if(!$api){

			return 0;
		}

		/* Última orden activa o completada de cada cliente (últimas 24 h) */

		$rows = $pdo->prepare("SELECT o.contact_order phone, MAX(o.id_order) id, MAX(o.created_at_order) created FROM orders o WHERE o.created_at_order >= ? AND o.status_order <> 'Cancelado' AND o.contact_order <> '' GROUP BY o.contact_order");
		$rows->execute(array(date("Y-m-d H:i:s", time() - 86000)));

		foreach ($rows->fetchAll() as $r) {

			$phone = (string)$r["phone"];

			if(in_array($phone, $staff, true)){

				continue;
			}

			/* Tiene una consulta esperando respuesta del equipo: no se cierra la conversación */

			try{

				$waiting = $pdo->prepare("SELECT COUNT(*) FROM inquiries WHERE phone_inquiry = ? AND status_inquiry = 'pendiente'");
				$waiting->execute(array($phone));

				if((int)$waiting->fetchColumn() > 0){

					continue;
				}

			}catch(Throwable $e){}

			$state =$pdo->prepare("SELECT * FROM followups WHERE phone_followup = ?");
			$state->execute(array($phone));
			$f = $state->fetch();

			if(!$f || (int)$f["order_followup"] !== (int)$r["id"]){

				$pdo->prepare("REPLACE INTO followups (phone_followup, order_followup, stage_followup, updated_followup) VALUES (?, ?, 0, ?)")->execute(array($phone, (int)$r["id"], date("Y-m-d H:i:s")));
				$f = array("stage_followup" => 0, "updated_followup" => date("Y-m-d H:i:s"));
			}

			$stage = (int)$f["stage_followup"];

			if($stage >= 2){

				continue;
			}

			/* ¿Siguió hablando después de la orden? */

			$after = $pdo->prepare("SELECT COUNT(*) FROM messages WHERE phone_message = ? AND type_message = 'client' AND date_updated_message > ?");
			$after->execute(array($phone, $r["created"]));

			if((int)$after->fetchColumn() === 0){

				continue;
			}

			$last = $pdo->prepare("SELECT type_message, date_updated_message FROM messages WHERE phone_message = ? ORDER BY id_message DESC LIMIT 1");
			$last->execute(array($phone));
			$m = $last->fetch();

			/* Si el último mensaje es del cliente, aún se le está atendiendo */

			if(!$m || $m["type_message"] === "client"){

				continue;
			}

			$idle = time() - strtotime($m["date_updated_message"]);

			if($stage === 0 && $idle >= $ask){

				$text = trim((string)cms_config("followup_ask_text", "")) ?: "¿Te puedo ayudar en algo más? 😊";
				ChatService::deliver($api, $phone, ChatService::textPayload($phone, $text), $text, '{"type":"seguimiento","title":""}');
				$pdo->prepare("UPDATE followups SET stage_followup = 1, updated_followup = ? WHERE phone_followup = ?")->execute(array(date("Y-m-d H:i:s"), $phone));
				$sent++;

			}else if($stage === 1 && $idle >= $close){

				$text = trim((string)cms_config("followup_close_text", "")) ?: "¡Gracias por tu preferencia! 🙌";
				ChatService::deliver($api, $phone, ChatService::textPayload($phone, $text), $text, '{"type":"seguimiento","title":""}');
				$pdo->prepare("UPDATE followups SET stage_followup = 2, updated_followup = ? WHERE phone_followup = ?")->execute(array(date("Y-m-d H:i:s"), $phone));
				$sent++;
			}
		}

		return $sent;

	}

}

class Tasks{

	/* Se ejecuta como máximo una vez por minuto, con el tráfico del panel y del webhook */

	static public function maybeRun(){

		$lock = CMS_DIR."/config/tasks.lock";

		if(is_file($lock) && time() - (int)@filemtime($lock) < 60){

			return false;
		}

		@touch($lock);

		try{

			self::run();

		}catch(Throwable $e){

			error_log("Tareas automáticas: ".$e->getMessage());
		}

		return true;

	}

	static public function run(){

		require_once __DIR__."/business.orders.php";

		return array(
			"closed" => BizOrders::autoClose(),
			"followups" => Followup::run()
		);

	}

}
