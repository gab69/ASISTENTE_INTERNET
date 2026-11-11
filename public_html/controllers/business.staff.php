<?php

require_once __DIR__."/business.store.php";
require_once __DIR__."/business.geo.php";
require_once __DIR__."/business.orders.php";
require_once __DIR__."/business.ops.php";
require_once __DIR__."/business.incidents.php";
require_once __DIR__."/business.inquiries.php";
require_once __DIR__."/chat.service.php";
require_once __DIR__."/ai.service.php";

/*=============================================
Asistente del equipo por WhatsApp (SOLO CONSULTA)

Cuando el número principal (central de órdenes,
agenda o áreas que reciben avisos) le escribe al
bot preguntando por el sistema, la IA le responde
EN EL CHAT con los datos reales: órdenes, ventas,
citas, consultas, inconvenientes, clientes, catálogo,
cobertura, pagos y la configuración de Mi negocio.

Es de solo lectura: NO cambia nada. Para cancelar,
editar o resolver algo, la IA le da el enlace de la
página web del panel donde hacerlo.

Una sucursal solo ve las órdenes de su sucursal.

El modo equipo sigue 30 minutos después de la última
respuesta; con "salir" o "modo cliente" vuelve a
atenderse como cliente (para probar el flujo de venta).
=============================================*/

class StaffAssistant{

	const SESSION_MIN = 30;
	const TYPE = "staff-ia";

	static private function pdo(){

		return BizStore::pdo();

	}

	static private function clean($phone){

		return biz_clean_phone($phone);

	}

	/* Números principales (ven todo el sistema) */

	static public function mainPhones(){

		$list = array(
			Notify::mainPhone(),
			BizStore::get("delivery")["notify_phone"] ?? "",
			BizStore::get("agenda")["notify_phone"] ?? ""
		);

		foreach (Notify::areas() as $a) {

			if(!empty($a["orders"]) || !empty($a["cancel"]) || !empty($a["appts"])){

				$list[] = $a["phone"] ?? "";
			}
		}

		return array_values(array_unique(array_filter(array_map(array(__CLASS__, "clean"), $list), function($p){ return strlen($p) >= 9; })));

	}

	/* null = no es del equipo · "" = ve todo · "Nombre" = solo esa sucursal */

	static public function scope($phone){

		$phone = self::clean($phone);

		if(in_array($phone, self::mainPhones(), true)){

			return "";
		}

		foreach (BizGeo::branches() as $b) {

			if($phone !== "" && self::clean($b["phone"] ?? "") === $phone){

				return (string)$b["name"];
			}
		}

		return null;

	}

	static private function text($message){

		$json = json_decode((string)$message);

		if(is_object($json)){

			return isset($json->text) ? (string)$json->text : "";
		}

		return (string)$message;

	}

	/* ¿El mensaje es una consulta del equipo sobre el sistema? */

	static public function asksOrders($text){

		$t = BizGeo::normalize($text);

		if($t === ""){

			return false;
		}

		$topics = '(ordenes|orden|pedidos|pedido|ventas|instalaciones|cancelaciones|canceladas|cancelados|inconvenientes|reclamos|citas|agenda|reservas|consultas|clientes|conversaciones|consumo|tokens|sucursales|reporte|resumen|estadisticas)';

		/* Frases de cliente ("tengo una consulta", "quiero hacer un pedido"): se atienden como venta */

		if(self::salesIntent($t) && !preg_match('/\b(ordenes|pedidos|ventas|inconvenientes|reclamos|cancelaciones|reporte|resumen|estadisticas)\b/u', $t)){

			return false;
		}

		return (bool)(
			preg_match('/^\/?(ordenes|pedidos|ventas|reporte|resumen|inconvenientes|reclamos|citas|agenda|consultas|clientes|estadisticas|panel|sistema|modo equipo|equipo)[\s!.?]*$/u', $t)
			|| preg_match('/\b(que|cuales|ver|veo|muestra|muestrame|mostrar|lista|listar|listame|cuant[oa]s|dame|pasame|consulta|consultar|resumen|reporte|estado|busca|buscar|revisa|revisar|hay|tengo|tenemos|ultim[oa]s|activ[oa]s|pendientes|de hoy|de ayer|de la semana)\b.*\b'.$topics.'\b/u', $t)
			|| preg_match('/\b'.$topics.'\b.*\b(hay|tenemos|de hoy|de ayer|de la semana|del mes|activ[oa]s|pendientes|registrad[oa]s|cancelad[oa]s|abiert[oa]s)\b/u', $t)
			|| preg_match('/\b(cancela|cancelar|cancelame|anula|anular|elimina|borra|cambia|modifica|edita|resuelve)\w*\b.*\b(orden|pedido|cita|inconveniente|consulta)\b\s*#?\s*\d+/u', $t)
			|| preg_match('/\b(orden|pedido|inconveniente|consulta)\s*(n(ro|°|o|umero)?\.?\s*)?#\s*\d+/u', $t)
			|| preg_match('/^#\s*\d+\s*$/u', $t)
		);

	}

	/* El número principal escribiendo como cliente (para probar la venta) */

	static public function salesIntent($t){

		return (bool)preg_match('/\b(quiero|quisiera|deseo|me interesa|necesito|contratar|contrato|instalar|instalacion en|cobertura|precio|precios|cuanto cuesta|cuanto es|promocion|promociones|planes|plan de|megas|mbps|internet|tengo una consulta|una consulta|una pregunta|informacion|info)\b/u', (string)$t);

	}

	static public function wantsExit($text){

		return (bool)preg_match('/^(salir|sal|modo cliente|terminar|listo,? gracias|fin)[\s!.]*$/u', BizGeo::normalize($text));

	}

	/* La última respuesta del asistente del equipo fue hace menos de 30 min */

	static public function inSession($phone){

		try{

			$stmt = self::pdo()->prepare("SELECT template_message FROM messages WHERE phone_message = ? AND type_message <> 'client' AND date_updated_message >= ? ORDER BY id_message DESC LIMIT 1");
			$stmt->execute(array(self::clean($phone), date("Y-m-d H:i:s", time() - self::SESSION_MIN * 60)));
			$tpl = json_decode((string)$stmt->fetchColumn(), true);

			return is_array($tpl) && ($tpl["type"] ?? "") === self::TYPE && empty($tpl["exit"]);

		}catch(Throwable $e){

			return false;
		}

	}

	/* Texto del mensaje citado (un aviso de orden, por ejemplo) */

	static private function quoted($contextWamid){

		if($contextWamid === ""){

			return "";
		}

		try{

			$stmt = self::pdo()->prepare("SELECT business_message, template_message FROM messages WHERE wamid_message = ? LIMIT 1");
			$stmt->execute(array($contextWamid));
			$m = $stmt->fetch();

			if(!$m){

				return "";
			}

			$tpl = json_decode((string)$m["template_message"], true);

			return in_array($tpl["type"] ?? "", array("pedido", "agenda", "aviso", "staff", "consulta", self::TYPE), true) ? (string)$m["business_message"] : "";

		}catch(Throwable $e){

			return "";
		}

	}

	/*=============================================
	Punto de entrada (webhook). Devuelve true si
	respondió como asistente del equipo.
	=============================================*/

	static public function handle($getApiWS, $phone, $message, $contextWamid = ""){

		$scope = self::scope($phone);

		if($scope === null){

			return false;
		}

		$text = trim(self::text($message));

		if($text === ""){

			return false;
		}

		$quoted = self::quoted($contextWamid);
		$session = self::inSession($phone);

		if($session && self::wantsExit($text)){

			$bye = "👋 Listo, salí del modo equipo. Desde ahora te atiendo como a un cliente (sirve para probar el flujo de venta). Escribe *órdenes* o *resumen* cuando quieras consultar de nuevo.";
			ChatService::deliver($getApiWS, $phone, ChatService::textPayload($phone, $bye), $bye, json_encode(array("type" => self::TYPE, "title" => "", "exit" => 1)));

			return true;
		}

		$asks = self::asksOrders($text) || ($quoted !== "" && preg_match('/\?|cancel|anula|estado|detalle|datos|cliente|quien|cuando|direccion|telefono/iu', BizGeo::normalize($text)));

		/* En modo equipo, un mensaje de venta ("quiero un plan…") sale del modo y se atiende como cliente */

		if($session && !$asks && $quoted === "" && self::salesIntent(BizGeo::normalize($text))){

			return false;
		}

		if(!$session && !$asks){

			return false;
		}

		$reply = self::answer($phone, $text, $quoted, $scope);
		$reply = trim($reply) !== "" ? $reply : "No encontré información para responder eso. Escribe *resumen* para ver el estado general.";

		if(mb_strlen($reply) > 4000){

			$reply = mb_substr($reply, 0, 3950)."\n\n… (ver todo en ".cms_base_url()."ordenes)";
		}

		ChatService::deliver($getApiWS, $phone, ChatService::textPayload($phone, $reply), $reply, json_encode(array("type" => self::TYPE, "title" => "")));

		return true;

	}

	/*=============================================
	Datos del sistema para el prompt
	=============================================*/

	static private function line(array $o){

		$items = str_replace("\n", "; ", BizOrders::itemsText($o["items"] ?? BizOrders::items($o)));
		$when = date("d/m H:i", strtotime($o["created"] ?? ($o["created_at_order"] ?: $o["date_created_order"])));
		$appt = !empty($o["appointment"]) ? " · ".($o["delivery_order"] === "instalacion" ? "instalación" : "cita").": ".$o["appointment"]["label"].($o["appointment"]["status"] === "cancelada" ? " (cancelada)" : "") : "";

		return "#".$o["id_order"]." · ".$when." · ".($o["name_order"] ?: "Sin nombre")." · WhatsApp +".$o["contact_order"]
			.(!empty($o["phone_order"]) && $o["phone_order"] !== $o["contact_order"] ? " · tel. ".$o["phone_order"] : "")
			.(!empty($o["document_order"]) ? " · DNI ".$o["document_order"] : "")
			." · ".$items." · total ".cms_money($o["total_order"])
			.(!empty($o["payment_order"]) ? " · pago ".$o["payment_order"] : "")
			.(!empty($o["address_order"]) ? " · ".$o["address_order"] : "")
			.(!empty($o["branch_order"]) ? " · sucursal ".$o["branch_order"] : "")
			.$appt
			.($o["status_order"] === "Cancelado" && !empty($o["cancel_reason_order"]) ? " · motivo: ".$o["cancel_reason_order"] : "");

	}

	static private function inScope(array $o, $scope){

		return $scope === "" || (string)($o["branch_order"] ?? "") === $scope;

	}

	static private function safe(callable $fn){

		try{

			return (array)$fn();

		}catch(Throwable $e){

			error_log("Asistente del equipo: ".$e->getMessage());
			return array();
		}

	}

	static public function data($text, $quoted, $scope){

		$lines = array();
		$labels = array("Pendiente" => "ACTIVAS", "Completado" => "COMPLETADAS", "Cancelado" => "CANCELADAS");

		try{ BizOrders::autoClose(); }catch(Throwable $e){}

		/* Resumen general */

		$lines = array_merge($lines, self::safe(function() use ($scope){

			$s = BizOrders::stats();
			$pdo = self::pdo();
			$out = array();

			$week = $pdo->prepare("SELECT COUNT(*) n, COALESCE(SUM(total_order), 0) t FROM orders WHERE COALESCE(created_at_order, date_created_order) >= ? AND status_order <> 'Cancelado'");
			$week->execute(array(date("Y-m-d 00:00:00", strtotime("-6 days"))));
			$w = $week->fetch();

			$month = $pdo->prepare("SELECT COUNT(*) n, COALESCE(SUM(total_order), 0) t FROM orders WHERE COALESCE(created_at_order, date_created_order) >= ? AND status_order <> 'Cancelado'");
			$month->execute(array(date("Y-m-01 00:00:00")));
			$mo = $month->fetch();

			$out[] = "RESUMEN DE ÓRDENES".($scope !== "" ? " (todas las sucursales)" : "").": hoy ".$s["today_orders"]." por ".cms_money($s["today_total"])."; últimos 7 días ".(int)$w["n"]." por ".cms_money($w["t"])."; este mes ".(int)$mo["n"]." por ".cms_money($mo["t"])."; activas ".(int)($s["counts"]["Pendiente"] ?? 0)."; completadas ".(int)($s["counts"]["Completado"] ?? 0)."; canceladas ".(int)($s["counts"]["Cancelado"] ?? 0)."; instalaciones/citas próximas ".$s["upcoming"].".";

			$chats = $pdo->prepare("SELECT COUNT(DISTINCT phone_message) FROM messages WHERE type_message = 'client' AND date_updated_message >= ?");
			$chats->execute(array(date("Y-m-d 00:00:00")));
			$chatsWeek = $pdo->prepare("SELECT COUNT(DISTINCT phone_message) FROM messages WHERE type_message = 'client' AND date_updated_message >= ?");
			$chatsWeek->execute(array(date("Y-m-d 00:00:00", strtotime("-6 days"))));
			$out[] = "CLIENTES QUE ESCRIBIERON: hoy ".(int)$chats->fetchColumn()."; últimos 7 días ".(int)$chatsWeek->fetchColumn().".";

			$usage = $pdo->prepare("SELECT kind_usage k, COUNT(*) n, COALESCE(SUM(input_usage + output_usage), 0) tk FROM usage_log WHERE created_usage >= ? GROUP BY kind_usage");
			$usage->execute(array(date("Y-m-d 00:00:00")));
			$u = array();

			foreach ($usage->fetchAll() as $r) {

				$u[] = $r["k"] === "ai" ? "IA ".(int)$r["n"]." respuestas (".number_format((int)$r["tk"])." tokens)" : "WhatsApp ".(int)$r["n"]." envíos";
			}

			if($u){

				$out[] = "CONSUMO DE HOY: ".implode("; ", $u).".";
			}

			return $out;
		}));

		/* Órdenes: activas y las de los últimos 30 días */

		$active = array_values(array_filter(BizOrders::listAll("Pendiente", "", "", 80), function($o) use ($scope){ return self::inScope($o, $scope); }));
		$lines[] = "";
		$lines[] = "ÓRDENES ACTIVAS (".count($active)."):";

		foreach ($active as $o) {

			$lines[] = "- ".self::line($o);
		}

		$from = date("Y-m-d", strtotime("-29 days"));

		foreach (array("Cancelado", "Completado") as $st) {

			$list = array_values(array_filter(BizOrders::listAll($st, $from, "", 60), function($o) use ($scope){ return self::inScope($o, $scope); }));
			$lines[] = "";
			$lines[] = "ÓRDENES ".$labels[$st]." (últimos 30 días, ".count($list)."):";

			foreach ($list as $o) {

				$lines[] = "- ".self::line($o);
			}
		}

		/* Órdenes mencionadas (#25), del mensaje citado o por teléfono/DNI/nombre */

		preg_match_all('/#\s*(\d{1,7})|\b(?:orden|pedido)\s*(?:n(?:ro|°|o|umero)?\.?\s*)?(\d{1,7})\b/iu', $text." ".$quoted, $m);
		$ids = array_unique(array_filter(array_map("intval", array_merge($m[1], $m[2]))));
		$found = array();

		foreach (array_slice($ids, 0, 5) as $id) {

			$o = BizOrders::find($id);

			if($o && self::inScope($o, $scope)){

				$found[$o["id_order"]] = $o;
			}
		}

		if(preg_match_all('/\b\d{7,15}\b/', $text, $nums)){

			foreach (array_slice($nums[0], 0, 2) as $n) {

				foreach (BizOrders::listAll("", "", $n, 5) as $o) {

					if(self::inScope($o, $scope)){

						$found[$o["id_order"]] = $o;
					}
				}
			}
		}

		if(!empty($found)){

			$lines[] = "";
			$lines[] = "DETALLE DE LAS ÓRDENES CONSULTADAS:";

			foreach ($found as $o) {

				$lines[] = "Estado: ".($o["status_order"] === "Pendiente" ? "Activa" : ($o["status_order"] === "Cancelado" ? "Cancelada".(!empty($o["cancel_reason_order"]) ? " (".$o["cancel_reason_order"].")" : "") : "Completada"));
				$lines[] = str_replace("*", "", BizOrders::summary($o, true));
				$lines[] = "---";
			}

		}else if(!empty($ids)){

			$lines[] = "";
			$lines[] = "No existe (o no pertenece a esta sucursal) la orden #".implode(", #", $ids).".";
		}

		/* Agenda: citas e instalaciones de los próximos 14 días y de los últimos 3 */

		$lines = array_merge($lines, self::safe(function() use ($scope){

			$out = array();
			$list = BizStore::appointments(date("Y-m-d", strtotime("-3 days")), date("Y-m-d", strtotime("+14 days")), true);
			$list = array_values(array_filter($list, function($a) use ($scope){ return $scope === "" || (string)($a["branch_appointment"] ?? "") === $scope; }));

			$out[] = "";
			$out[] = "AGENDA (citas/instalaciones de hace 3 días a 14 días adelante, ".count($list)."):";

			foreach (array_slice($list, 0, 80) as $a) {

				$time = substr($a["time_appointment"], 0, 5);
				$out[] = "- Cita #".$a["id_appointment"]." · ".BizStore::dayName($a["date_appointment"])." ".$a["date_appointment"].($time !== "00:00" ? " ".$time : " (hora por confirmar)")." · ".($a["name_appointment"] ?: "Sin nombre")." +".$a["phone_appointment"]." · ".($a["service_appointment"] ?: "Servicio")." · ".$a["status_appointment"].(!empty($a["branch_appointment"]) ? " · sucursal ".$a["branch_appointment"] : "").(!empty($a["summary_appointment"]) ? " · ".mb_substr($a["summary_appointment"], 0, 120) : "");
			}

			return $out;
		}));

		/* Consultas al equipo pendientes */

		$lines = array_merge($lines, self::safe(function(){

			$out = array();
			$pending = BizInquiries::pending();

			$out[] = "";
			$out[] = "CONSULTAS DE CLIENTES SIN RESPONDER (".count($pending)."):";

			foreach (array_slice($pending, 0, 20) as $q) {

				$out[] = "- Consulta #".$q["id_inquiry"]." · ".date("d/m H:i", strtotime($q["date_created_inquiry"]))." · ".($q["name_inquiry"] ?: "Cliente")." +".$q["phone_inquiry"].": ".mb_substr($q["question_inquiry"], 0, 200);
			}

			return $out;
		}));

		/* Inconvenientes abiertos */

		$open = BizIncidents::openSummary(15);
		$lines[] = "";
		$lines[] = "INCONVENIENTES SIN RESOLVER (".count($open)." más recientes):";
		$lines = array_merge($lines, $open);

		/* Configuración de Mi negocio (catálogo, pagos, cobertura, áreas, horario…) */

		$lines = array_merge($lines, self::safe(function(){

			$out = array("", "=== CONFIGURACIÓN DE MI NEGOCIO ===");
			$company = BizStore::get("company");

			foreach (array("name" => "Empresa", "description" => "Descripción", "address" => "Dirección", "hours" => "Horario", "website" => "Web", "email" => "Correo", "social" => "Redes", "extra" => "Info adicional") as $k => $label) {

				if(trim((string)($company[$k] ?? "")) !== ""){

					$out[] = $label.": ".mb_substr(trim($company[$k]), 0, 800);
				}
			}

			$catalogue = BizOrders::catalogue();

			if($catalogue){

				$out[] = "Catálogo (".count($catalogue)."): ".implode("; ", array_map(function($p){ return $p["name"]." ".cms_money($p["price"]); }, array_slice($catalogue, 0, 80)));
			}

			$payments = BizStore::get("payments");

			if($payments){

				$out[] = "Métodos de pago: ".implode("; ", array_map(function($p){ return trim(($p["name"] ?? "").(!empty($p["account"]) ? " ".$p["account"] : "").(!empty($p["holder"]) ? " (".$p["holder"].")" : "")); }, $payments));
			}

			$areas = Notify::areas();

			if($areas){

				$out[] = "Números por área: ".implode("; ", array_map(function($a){ return ($a["label"] ?? "Área")." +".$a["phone"]; }, $areas));
			}

			$delivery = BizStore::get("delivery");
			$out[] = "Número central de órdenes: ".(!empty($delivery["notify_phone"]) ? "+".$delivery["notify_phone"] : "no configurado")."; cobertura ".(!empty($delivery["enabled"]) ? "activa" : "desactivada").".";

			foreach (BizGeo::branches() as $b) {

				$out[] = "Sucursal ".$b["name"].(!empty($b["address"]) ? " (".$b["address"].")" : "").(!empty($b["phone"]) ? " +".$b["phone"] : "").((float)($b["radius_km"] ?? 0) > 0 ? " · radio ".$b["radius_km"]." km" : "").(trim((string)($b["zones"] ?? "")) !== "" ? " · zonas: ".preg_replace('/\s*[\r\n]+\s*/', ", ", trim($b["zones"])) : "");
			}

			$agenda = BizStore::get("agenda");
			$out[] = "Agenda: ".(!empty($agenda["enabled"]) ? "activa, de ".$agenda["open"]." a ".$agenda["close"].", turnos de ".(int)$agenda["slot_minutes"]." min" : "desactivada").".";

			$inq = BizStore::get("inquiries");
			$out[] = "Número de consultas: ".(!empty($inq["phone"]) ? "+".$inq["phone"] : "no configurado (llegan al número central)").".";

			$notices = BizStore::get("notices");
			$out[] = "Plantilla de avisos fuera de 24 h: ".(!empty($notices["template"]) ? $notices["template"]." (".$notices["lang"].")" : "no configurada").".";

			return $out;
		}));

		return implode("\n", $lines);

	}

	/*=============================================
	Respuesta (con IA o, si no hay, un listado simple)
	=============================================*/

	static public function answer($phone, $text, $quoted, $scope){

		$company = trim((string)(BizStore::get("company")["name"] ?? "")) ?: "el negocio";

		if(!AIService::enabled() || Limits::aiBlocked() !== ""){

			return self::plainList($scope);
		}

		$base = cms_base_url();
		$data = self::data($text, $quoted, $scope);

		$system = "Eres el asistente interno de ".$company." y hablas por WhatsApp con un miembro del EQUIPO (no es un cliente), dueño del número +".self::clean($phone).($scope !== "" ? ", encargado de la sucursal ".$scope." (solo ves las órdenes de esa sucursal)" : " (número principal: puede consultar TODA la información del sistema)").". "
			."Hoy es ".BizStore::dayName(date("Y-m-d")).", ".date("Y-m-d H:i").".\n"
			."TU TRABAJO: responder cualquier pregunta sobre el sistema (órdenes, ventas, clientes, citas, consultas, inconvenientes, consumo, catálogo, pagos, cobertura, sucursales y configuración) usando SOLO los datos de abajo, que son los datos reales en este momento. Nunca inventes datos; si algo no aparece, dilo y sugiere dónde verlo en el panel.\n"
			."SOLO CONSULTA (REGLA OBLIGATORIA): no puedes cambiar NADA (no cancelas, editas, reprogramas, resuelves, borras ni configuras). Si te pide un cambio, explícale amablemente que por WhatsApp solo puedes consultar y que debe hacerlo en la página web, dándole el enlace exacto:\n"
			."- Órdenes (cancelar o reprogramar): ".$base."ordenes\n"
			."- Agenda y citas: ".$base."agenda\n"
			."- Inconvenientes (resolver o anotar): ".$base."inconvenientes\n"
			."- Mi negocio (empresa, catálogo, pagos, cobertura, números, consultas pendientes): ".$base."negocio\n"
			."- Chat con los clientes: ".$base."chat\n"
			."FORMATO: mensajes de WhatsApp claros y breves; *negritas* para los números (ej.: \"*#25* · Ana Pérez · Fibra 400 · S/ 79.00 · instalación vie 10:00\"). Si hay muchos resultados, muestra los más relevantes (máximo 15) y di cuántos hay en total.\n"
			."Si quiere probar la atención como si fuera un cliente, dile que escriba *salir*.\n"
			.AIService::CREDITS."\n\n"
			."=== DATOS DEL SISTEMA ===\n".$data;

		if($quoted !== ""){

			$system .= "\n\nEl miembro del equipo está respondiendo a este mensaje: ".mb_substr(str_replace("*", "", $quoted), 0, 1500);
		}

		$answer = AIService::reply(self::history($phone, $text), $system);

		if(!$answer["ok"]){

			error_log("Asistente del equipo: ".$answer["error"]);

			return self::plainList($scope);
		}

		/* Solo consulta: cualquier etiqueta de acción se descarta */

		return trim(preg_replace(array('/\[\[[^\]]*\]\]/u', "/\n{3,}/"), array("", "\n\n"), (string)$answer["text"]));

	}

	/* Conversación reciente del modo equipo */

	static private function history($phone, $text){

		$history = array();

		try{

			$stmt = self::pdo()->prepare("SELECT type_message, client_message, business_message, template_message FROM messages WHERE phone_message = ? AND date_updated_message >= ? ORDER BY id_message DESC LIMIT 16");
			$stmt->execute(array(self::clean($phone), date("Y-m-d H:i:s", time() - self::SESSION_MIN * 60)));

			foreach (array_reverse($stmt->fetchAll()) as $r) {

				if($r["type_message"] === "client"){

					$history[] = array("role" => "user", "content" => self::text($r["client_message"]));

				}else{

					$tpl = json_decode((string)$r["template_message"], true);

					if(($tpl["type"] ?? "") === self::TYPE){

						$history[] = array("role" => "assistant", "content" => (string)$r["business_message"]);
					}
				}
			}

		}catch(Throwable $e){}

		if(empty($history) || end($history)["role"] !== "user"){

			$history[] = array("role" => "user", "content" => $text);
		}

		return $history;

	}

	/* Sin IA: listado directo de las órdenes activas */

	static public function plainList($scope){

		$active = array_values(array_filter(BizOrders::listAll("Pendiente", "", "", 50), function($o) use ($scope){ return self::inScope($o, $scope); }));

		if(empty($active)){

			return "📋 No hay órdenes activas en este momento.\n\nVer todas: ".cms_base_url()."ordenes";
		}

		$text = "📋 *Órdenes activas (".count($active).")*\n";

		foreach (array_slice($active, 0, 20) as $o) {

			$text .= "\n*#".$o["id_order"]."* · ".($o["name_order"] ?: "Sin nombre")." · +".$o["contact_order"]." · ".cms_money($o["total_order"]).(!empty($o["appointment"]) ? " · ".$o["appointment"]["label"] : "");
		}

		if(count($active) > 20){

			$text .= "\n\n… y ".(count($active) - 20)." más.";
		}

		return $text."\n\nPara hacer cambios entra a: ".cms_base_url()."ordenes";

	}

}
