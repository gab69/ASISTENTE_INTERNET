<?php

require_once dirname(__DIR__)."/bootstrap.php";
require_once CMS_DIR."/controllers/curl.controller.php";
require_once CMS_DIR."/controllers/business.actions.php";
require_once CMS_DIR."/controllers/business.orders.php";
require_once CMS_DIR."/controllers/business.ops.php";
require_once CMS_DIR."/controllers/business.geo.php";
require_once CMS_DIR."/controllers/business.calendar.php";
require_once CMS_DIR."/controllers/business.incidents.php";

header("Content-Type: application/json; charset=utf-8");
header("Cache-Control: no-store");

session_start(array("read_and_close" => true));

function biz_out($data, $status = 200){

	http_response_code($status);
	echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
	exit;

}

/*=============================================
Sesión, permisos y protección CSRF
=============================================*/

$admin = $_SESSION["admin"] ?? null;

if(empty($admin->id_admin)){

	biz_out(array("ok" => false, "error" => "La sesión expiró. Vuelve a iniciar sesión."), 401);
}

if($_SERVER["REQUEST_METHOD"] !== "POST" || empty($_SESSION["biz_csrf"]) || !hash_equals($_SESSION["biz_csrf"], (string)($_SERVER["HTTP_X_CSRF_TOKEN"] ?? ""))){

	biz_out(array("ok" => false, "error" => "Solicitud no válida. Recarga la página."), 403);
}

function biz_can($page){

	global $admin;

	if(in_array($admin->rol_admin, array("superadmin", "admin"), true)){

		return true;
	}

	$perms = json_decode(urldecode((string)$admin->permissions_admin), true);

	return isset($perms[$page]) && strtolower($perms[$page]) === "on";

}

$action = (string)($_POST["action"] ?? "");

try{

	switch ($action) {

		/*=============================================
		Configuración del negocio
		=============================================*/

		case "save_section":

			if(!biz_can("negocio")){

				biz_out(array("ok" => false, "error" => "No tienes permiso para editar el negocio."), 403);
			}

			$key = (string)($_POST["key"] ?? "");
			$value = json_decode((string)($_POST["value"] ?? ""), true);

			if(!is_array($value) || !array_key_exists($key, BizStore::defaults()) || $key === "gcal"){

				biz_out(array("ok" => false, "error" => "Datos inválidos."), 422);
			}

			$value = biz_sanitize($key, $value);

			BizStore::set($key, $value);

			biz_out(array("ok" => true, "value" => BizStore::get($key)));

		case "upload":

			if(!biz_can("negocio")){

				biz_out(array("ok" => false, "error" => "No tienes permiso para subir archivos."), 403);
			}

			$file = $_FILES["file"] ?? null;

			if(!$file || $file["error"] !== UPLOAD_ERR_OK){

				biz_out(array("ok" => false, "error" => "No se recibió la imagen."), 422);
			}

			if($file["size"] > 5 * 1024 * 1024){

				biz_out(array("ok" => false, "error" => "La imagen supera 5 MB."), 422);
			}

			/* WhatsApp solo acepta JPG y PNG como imagen */

			$info = @getimagesize($file["tmp_name"]);
			$types = array(IMAGETYPE_JPEG => "jpg", IMAGETYPE_PNG => "png");

			if(!$info || !isset($types[$info[2]])){

				biz_out(array("ok" => false, "error" => "Formato no permitido. Usa JPG o PNG."), 422);
			}

			$dir = CMS_DIR."/views/assets/files/negocio";

			if(!is_dir($dir)){

				@mkdir($dir, 0755, true);
			}

			$name = date("Ymd")."_".bin2hex(random_bytes(8)).".".$types[$info[2]];

			if(!move_uploaded_file($file["tmp_name"], $dir."/".$name)){

				biz_out(array("ok" => false, "error" => "No se pudo guardar la imagen (revisa permisos de views/assets/files)."), 500);
			}

			biz_out(array("ok" => true, "url" => "/views/assets/files/negocio/".$name));

		/*=============================================
		Agenda
		=============================================*/

		case "appointments":

			if(!biz_can("agenda")){

				biz_out(array("ok" => false, "error" => "Sin permiso."), 403);
			}

			$from = (string)($_POST["from"] ?? date("Y-m-01"));
			$to = (string)($_POST["to"] ?? date("Y-m-t"));

			if(!BizStore::validDate($from) || !BizStore::validDate($to)){

				biz_out(array("ok" => false, "error" => "Rango inválido."), 422);
			}

			biz_out(array("ok" => true, "items" => BizStore::appointments($from, $to, !empty($_POST["all"]))));

		case "slots":

			if(!biz_can("agenda")){

				biz_out(array("ok" => false, "error" => "Sin permiso."), 403);
			}

			$date = (string)($_POST["date"] ?? "");

			biz_out(array("ok" => true, "all" => BizStore::daySlots($date), "free" => BizStore::freeSlots($date, (int)($_POST["exclude"] ?? 0))));

		case "save_appointment":

			if(!biz_can("agenda")){

				biz_out(array("ok" => false, "error" => "Sin permiso."), 403);
			}

			$id = (int)($_POST["id"] ?? 0);

			$result = BizStore::book(array(
				"date" => $_POST["date"] ?? "",
				"time" => $_POST["time"] ?? "",
				"name" => $_POST["name"] ?? "",
				"service" => $_POST["service"] ?? "",
				"summary" => $_POST["summary"] ?? "",
				"phone" => $_POST["phone"] ?? "",
				"people" => $_POST["people"] ?? null,
				"source" => "panel",
				"force" => !empty($_POST["force"])
			), $id);

			if($result["ok"] && !$id && !empty($_POST["notify"])){

				$appt = BizStore::appointment($result["id"]);

				if($appt && BizActions::notifyOwner($appt, "nueva")){

					BizStore::markNotified($result["id"]);
				}
			}

			biz_out($result, $result["ok"] ? 200 : 409);

		case "status":

			if(!biz_can("agenda")){

				biz_out(array("ok" => false, "error" => "Sin permiso."), 403);
			}

			$id = (int)($_POST["id"] ?? 0);
			$status = (string)($_POST["status"] ?? "");
			$ok = BizStore::setStatus($id, $status);

			if($ok && $status === "cancelada" && !empty($_POST["notify"])){

				BizActions::notifyOwner(BizStore::appointment($id), "cancelada");
			}

			biz_out(array("ok" => $ok));

		case "notify":

			if(!biz_can("agenda")){

				biz_out(array("ok" => false, "error" => "Sin permiso."), 403);
			}

			$appt = BizStore::appointment((int)($_POST["id"] ?? 0));

			if(!$appt){

				biz_out(array("ok" => false, "error" => "La cita no existe."), 404);
			}

			if(trim((string)BizStore::get("agenda")["notify_phone"]) === ""){

				biz_out(array("ok" => false, "error" => "Configura primero el número que recibe las citas en Mi negocio → Agenda."), 422);
			}

			$ok = BizActions::notifyOwner($appt, $appt["status_appointment"] === "cancelada" ? "cancelada" : "nueva");

			if($ok){

				BizStore::markNotified($appt["id_appointment"]);
			}

			biz_out(array("ok" => $ok, "error" => $ok ? "" : "WhatsApp no aceptó el mensaje. Revisa el chat de ese número para ver el motivo."));

		/*=============================================
		Google Calendar
		=============================================*/

		case "gcal_get":

			if(!biz_can("agenda")){

				biz_out(array("ok" => false, "error" => "Sin permiso."), 403);
			}

			$g = BizCalendar::settings();
			$cred = BizCalendar::credentials();

			biz_out(array(
				"ok" => true,
				"enabled" => BizCalendar::enabled(),
				"calendar_id" => (string)$g["calendar_id"],
				"client_email" => $cred["client_email"] ?? "",
				"has_credentials" => $cred !== null,
				"ics_url" => BizCalendar::icsUrl()
			));

		case "gcal_save":

			if(!biz_can("agenda")){

				biz_out(array("ok" => false, "error" => "Sin permiso."), 403);
			}

			$g = BizCalendar::settings();
			$calendarId = trim((string)($_POST["calendar_id"] ?? ""));
			$raw = trim((string)($_POST["credentials"] ?? ""));

			if($raw === ""){

				$raw = (string)$g["credentials"];
			}

			if($calendarId === "" || $raw === ""){

				biz_out(array("ok" => false, "error" => "Escribe el ID del calendario y pega el archivo JSON de la cuenta de servicio."), 422);
			}

			$test = BizCalendar::test($calendarId, $raw);

			if(!$test["ok"]){

				biz_out($test, 422);
			}

			$g["enabled"] = true;
			$g["calendar_id"] = $calendarId;
			$g["credentials"] = $raw;
			BizStore::set("gcal", $g);

			$sync = BizCalendar::syncUpcoming();

			biz_out(array("ok" => true, "message" => $test["message"]." Se enviaron ".$sync["ok"]." citas próximas".($sync["fail"] ? " (".$sync["fail"]." con error)" : "")."."));

		case "gcal_sync":

			if(!biz_can("agenda")){

				biz_out(array("ok" => false, "error" => "Sin permiso."), 403);
			}

			if(!BizCalendar::enabled()){

				biz_out(array("ok" => false, "error" => "Primero conecta Google Calendar."), 422);
			}

			$sync = BizCalendar::syncUpcoming();

			biz_out(array("ok" => true, "message" => "Sincronizadas ".$sync["ok"]." citas".($sync["fail"] ? " (".$sync["fail"]." con error, revisa logs/php_error.log)" : "")."."));

		case "gcal_disconnect":

			if(!biz_can("agenda")){

				biz_out(array("ok" => false, "error" => "Sin permiso."), 403);
			}

			$g = BizCalendar::settings();
			$g["enabled"] = false;
			$g["credentials"] = "";
			BizStore::set("gcal", $g);
			@unlink(CMS_DIR."/config/gcal_token.json");

			biz_out(array("ok" => true));

		case "ics_reset":

			if(!biz_can("agenda")){

				biz_out(array("ok" => false, "error" => "Sin permiso."), 403);
			}

			$g = BizCalendar::settings();
			$g["ics_token"] = "";
			BizStore::set("gcal", $g);

			biz_out(array("ok" => true, "ics_url" => BizCalendar::icsUrl()));

		/*=============================================
		Delivery: ubicar el local y probar direcciones
		=============================================*/

		case "geo_locate":

			if(!biz_can("negocio")){

				biz_out(array("ok" => false, "error" => "Sin permiso."), 403);
			}

			$geo = BizGeo::geocode((string)($_POST["address"] ?? ""));

			biz_out($geo
				? array("ok" => true, "lat" => round($geo[0], 7), "lng" => round($geo[1], 7), "label" => $geo[2])
				: array("ok" => false, "error" => "No se encontró esa dirección en el mapa. Agrega el distrito y la ciudad, o escribe las coordenadas."), $geo ? 200 : 422);

		case "geo_test":

			if(!biz_can("negocio")){

				biz_out(array("ok" => false, "error" => "Sin permiso."), 403);
			}

			/* Probar con lo que está escrito en el formulario (aunque no se haya guardado) */

			if(isset($_POST["branches"])){

				$geoNum = function($v, $min, $max){ return max($min, min($max, round((float)str_replace(",", ".", (string)$v), 2))); };
				$geoCoord = function($v, $limit){ $v = trim(str_replace(",", ".", (string)$v)); return is_numeric($v) && abs((float)$v) <= $limit ? round((float)$v, 7) : ""; };

				BizGeo::$override = biz_branches((array)json_decode((string)$_POST["branches"], true), $geoNum, $geoCoord);
			}

			$point = is_numeric($_POST["lat"] ?? null) && is_numeric($_POST["lng"] ?? null) ? array((float)$_POST["lat"], (float)$_POST["lng"]) : null;

			$check = BizGeo::check($point ? "" : (string)($_POST["address"] ?? ""), "", $point);

			if($point){

				$check["label"] = "ese punto del mapa";
			}

			biz_out(array("ok" => true, "result" => $check, "text" => BizGeo::describe($check)));

		/* Ubicar un distrito o zona escrita en una sucursal */

		case "geo_zone":

			if(!biz_can("negocio")){

				biz_out(array("ok" => false, "error" => "Sin permiso."), 403);
			}

			$zone = trim((string)($_POST["zone"] ?? ""));
			$geo = $zone !== "" ? BizGeo::geocode($zone) : null;

			if(!$geo){

				biz_out(array("ok" => true, "found" => false));
			}

			$distance = null;

			if(is_numeric($_POST["lat"] ?? null) && is_numeric($_POST["lng"] ?? null)){

				$distance = round(BizGeo::distance((float)$_POST["lat"], (float)$_POST["lng"], $geo[0], $geo[1]), 1);
			}

			biz_out(array("ok" => true, "found" => true, "lat" => $geo[0], "lng" => $geo[1], "label" => $geo[2], "distance" => $distance));

		/* Dejar constancia de que las zonas guardadas fueron revisadas */

		case "zones_confirm":

			if(!biz_can("negocio")){

				biz_out(array("ok" => false, "error" => "Sin permiso."), 403);
			}

			$d = BizStore::get("delivery");

			if(empty($d["branches"])){

				biz_out(array("ok" => false, "error" => "Primero agrega y guarda al menos una sucursal."), 422);
			}

			$d["zones_confirmed"] = array(
				"at" => date("Y-m-d H:i:s"),
				"by" => (string)($admin->email_admin ?? ""),
				"hash" => BizGeo::zonesHash(),
				"zones" => (int)($_POST["zones"] ?? 0),
				"warnings" => (int)($_POST["warnings"] ?? 0)
			);

			BizStore::set("delivery", $d);

			biz_out(array("ok" => true, "confirmed" => $d["zones_confirmed"], "label" => "Confirmadas el ".date("d/m/Y \a \l\a\s h:i a")));

		/*=============================================
		Pedidos
		=============================================*/

		case "orders":

			if(!biz_can("ordenes")){

				biz_out(array("ok" => false, "error" => "Sin permiso."), 403);
			}

			/* Cerrar las órdenes cuya instalación ya pasó antes de listarlas */

			try{ BizOrders::autoClose(); }catch(Throwable $e){ error_log("Cierre automático: ".$e->getMessage()); }

			biz_out(array(
				"ok" => true,
				"items" => BizOrders::listAll((string)($_POST["status"] ?? ""), (string)($_POST["from"] ?? ""), (string)($_POST["search"] ?? "")),
				"stats" => BizOrders::stats(),
				"recipients" => Notify::recipients()
			));

		/*=============================================
		Inconvenientes (cancelaciones, reclamos, fallas)
		=============================================*/

		case "incidents":

			if(!biz_can("inconvenientes") && !biz_can("ordenes")){

				biz_out(array("ok" => false, "error" => "Sin permiso."), 403);
			}

			biz_out(array(
				"ok" => true,
				"items" => BizIncidents::listAll((string)($_POST["status"] ?? ""), (string)($_POST["kind"] ?? ""), (string)($_POST["from"] ?? ""), (string)($_POST["search"] ?? "")),
				"stats" => BizIncidents::stats(),
				"kinds" => BizIncidents::KINDS
			));

		case "incident_update":

			if(!biz_can("inconvenientes") && !biz_can("ordenes")){

				biz_out(array("ok" => false, "error" => "Sin permiso."), 403);
			}

			$ok = BizIncidents::update((int)($_POST["id"] ?? 0), (string)($_POST["status"] ?? ""), biz_text($_POST["note"] ?? "", 2000));

			biz_out(array("ok" => (bool)$ok, "error" => $ok ? "" : "No se pudo guardar."), $ok ? 200 : 422);

		case "incident_delete":

			if(!in_array($admin->rol_admin, array("superadmin", "admin"), true)){

				biz_out(array("ok" => false, "error" => "Solo un administrador puede eliminar registros."), 403);
			}

			biz_out(array("ok" => BizIncidents::delete((int)($_POST["id"] ?? 0))));

		/*=============================================
		Inicio: consumo y actividad
		=============================================*/

		case "dashboard":

			if(!biz_can("inicio")){

				biz_out(array("ok" => false, "error" => "Sin permiso."), 403);
			}

			require_once CMS_DIR."/controllers/business.dashboard.php";

			Tasks::maybeRun();

			biz_out(array("ok" => true, "data" => BizDashboard::build((int)($_POST["days"] ?? 14))));

		case "order_cancel":

			if(!biz_can("ordenes")){

				biz_out(array("ok" => false, "error" => "Sin permiso."), 403);
			}

			$result = BizOrders::cancel((int)($_POST["id"] ?? 0), biz_text($_POST["reason"] ?? "", 255), !empty($_POST["notify"]));

			if($result["ok"] && !empty($result["client"]) && !$result["client"]["ok"]){

				$result["error"] = "Orden cancelada, pero WhatsApp no envió el aviso al cliente: ".$result["client"]["error"];
			}

			biz_out($result, $result["ok"] ? 200 : 422);

		case "order_reschedule":

			if(!biz_can("ordenes")){

				biz_out(array("ok" => false, "error" => "Sin permiso."), 403);
			}

			$result = BizOrders::reschedule((int)($_POST["id"] ?? 0), (string)($_POST["date"] ?? ""), (string)($_POST["time"] ?? ""), !empty($_POST["notify"]));

			if($result["ok"] && !empty($result["client"]) && !$result["client"]["ok"]){

				$result["error"] = "Fecha guardada, pero WhatsApp no envió el aviso al cliente: ".$result["client"]["error"];
			}

			biz_out($result, $result["ok"] ? 200 : 422);

		/* Reenviar el aviso de la orden al equipo (central, agenda y sucursal) */

		case "order_resend":

			if(!biz_can("ordenes")){

				biz_out(array("ok" => false, "error" => "Sin permiso."), 403);
			}

			if(empty(Notify::recipients())){

				biz_out(array("ok" => false, "error" => "No hay ningún número para avisos. Escríbelo en Mi negocio → Cobertura (WhatsApp central) o en Agenda."), 422);
			}

			$ok = BizOrders::notifyOwner((int)($_POST["id"] ?? 0), "nueva");
			$order = BizOrders::find((int)($_POST["id"] ?? 0));
			$log = Notify::withStatus(json_decode((string)($order["notify_order"] ?? ""), true) ?: array());

			biz_out(array("ok" => $ok, "notify" => $log, "error" => $ok ? "" : "WhatsApp no aceptó el aviso. Revisa el detalle en la orden."), $ok ? 200 : 422);

		/*=============================================
		Consultas al equipo y respuestas aprendidas
		=============================================*/

		case "inquiries":

			if(!biz_can("negocio")){

				biz_out(array("ok" => false, "error" => "Sin permiso."), 403);
			}

			biz_out(array("ok" => true, "items" => BizInquiries::listAll()));

		case "inquiry_answer":

			if(!biz_can("negocio")){

				biz_out(array("ok" => false, "error" => "Sin permiso."), 403);
			}

			$result = BizInquiries::answer((int)($_POST["id"] ?? 0), (string)($_POST["answer"] ?? ""), !empty($_POST["send"]), !empty($_POST["learn"]), (string)($_POST["question"] ?? ""));

			biz_out($result, $result["ok"] ? 200 : 422);

		case "inquiry_save":

			if(!biz_can("negocio")){

				biz_out(array("ok" => false, "error" => "Sin permiso."), 403);
			}

			$ok = BizInquiries::saveKnowledge((int)($_POST["id"] ?? 0), (string)($_POST["question"] ?? ""), (string)($_POST["answer"] ?? ""), !empty($_POST["learn"]));

			biz_out(array("ok" => $ok, "error" => $ok ? "" : "Escribe la pregunta y la respuesta."), $ok ? 200 : 422);

		case "inquiry_status":

			if(!biz_can("negocio")){

				biz_out(array("ok" => false, "error" => "Sin permiso."), 403);
			}

			biz_out(array("ok" => BizInquiries::setStatus((int)($_POST["id"] ?? 0), (string)($_POST["status"] ?? ""))));

		case "inquiry_learn":

			if(!biz_can("negocio")){

				biz_out(array("ok" => false, "error" => "Sin permiso."), 403);
			}

			biz_out(array("ok" => BizInquiries::setLearn((int)($_POST["id"] ?? 0), !empty($_POST["learn"]))));

		case "inquiry_delete":

			if(!biz_can("negocio")){

				biz_out(array("ok" => false, "error" => "Sin permiso."), 403);
			}

			biz_out(array("ok" => BizInquiries::delete((int)($_POST["id"] ?? 0))));

		case "inquiry_notify":

			if(!biz_can("negocio")){

				biz_out(array("ok" => false, "error" => "Sin permiso."), 403);
			}

			$result = BizInquiries::notifyTeam((int)($_POST["id"] ?? 0));

			biz_out(array("ok" => $result["ok"], "error" => $result["ok"] ? "" : ($result["error"] ?: "WhatsApp no aceptó el mensaje.")));

		/*=============================================
		Bots (respuestas por menú)
		=============================================*/

		case "bot_save":

			if(!biz_can("bots")){

				biz_out(array("ok" => false, "error" => "No tienes permiso para editar los bots."), 403);
			}

			$result = biz_save_bot((int)($_POST["id"] ?? 0), json_decode((string)($_POST["data"] ?? ""), true));

			biz_out($result, $result["ok"] ? 200 : 422);

		default:

			biz_out(array("ok" => false, "error" => "Acción desconocida."), 400);
	}

}catch(Throwable $e){

	error_log("business.ajax ".$action.": ".$e->getMessage());

	biz_out(array("ok" => false, "error" => "Error del servidor. Revisa logs/php_error.log"), 500);
}

/*=============================================
Limpiar y validar cada sección
=============================================*/

function biz_text($value, $max = 2000){

	return mb_substr(trim(strip_tags((string)$value)), 0, $max);

}

function biz_url($value){

	$value = trim((string)$value);

	return (preg_match('#^(https?://|/views/assets/files/)#i', $value)) ? $value : "";

}

/* Sucursales: nombre, dirección, coordenadas, radio, distritos y su WhatsApp */

function biz_branches(array $list, $num, $coord){

	$out = array();
	$names = array();

	foreach (array_slice($list, 0, 50) as $b) {

		if(!is_array($b)){

			continue;
		}

		$name = biz_text($b["name"] ?? "", 120);
		$address = biz_text($b["address"] ?? "", 300);

		if($name === "" && $address === ""){

			continue;
		}

		$name = $name !== "" ? $name : "Sucursal ".(count($out) + 1);

		/* Nombres únicos (se usan para identificar la sucursal en las órdenes) */

		while(isset($names[mb_strtolower($name)])){

			$name .= " 2";
		}

		$names[mb_strtolower($name)] = true;

		$out[] = array(
			"id" => preg_replace('/[^a-z0-9]/i', '', (string)($b["id"] ?? "")) ?: bin2hex(random_bytes(4)),
			"name" => $name,
			"address" => $address,
			"lat" => $coord($b["lat"] ?? "", 90),
			"lng" => $coord($b["lng"] ?? "", 180),
			"radius_km" => $num($b["radius_km"] ?? 0, 0, 200),
			"zones" => biz_text($b["zones"] ?? "", 3000),
			"excluded" => biz_text($b["excluded"] ?? "", 3000),
			"phone" => biz_clean_phone($b["phone"] ?? ""),
			/* Planes y servicios que se venden en esta sucursal (vacío = todos) */
			"plans" => array_values(array_slice(array_unique(array_filter(array_map(function($v){ return biz_text($v, 160); }, (array)($b["plans"] ?? array())), "strlen")), 0, 200))
		);
	}

	return $out;

}

function biz_sanitize($key, array $value){

	switch ($key) {

		case "company":

			$out = array();

			foreach (BizStore::defaults()["company"] as $field => $default) {

				$out[$field] = biz_text($value[$field] ?? "", $field === "extra" || $field === "description" ? 6000 : 500);
			}

			return $out;

		case "contacts":

			$out = array();

			foreach (array_slice($value, 0, 50) as $c) {

				$phone = biz_clean_phone($c["phone"] ?? "");

				if($phone !== ""){

					$flag = function($k, $default = false) use ($c){ return isset($c[$k]) ? filter_var($c[$k], FILTER_VALIDATE_BOOLEAN) : $default; };

					$out[] = array(
						"label" => biz_text($c["label"] ?? "", 80),
						"name" => biz_text($c["name"] ?? "", 120),
						"phone" => $phone,
						"purpose" => biz_text($c["purpose"] ?? "", 500),
						"share" => $flag("share", true),
						"orders" => $flag("orders"),
						"appts" => $flag("appts"),
						"cancel" => $flag("cancel")
					);
				}
			}

			return $out;

		case "payments":

			$out = array();

			foreach (array_slice($value, 0, 20) as $p) {

				if(biz_text($p["name"] ?? "") !== ""){

					$out[] = array(
						"id" => preg_replace('/[^a-z0-9]/i', '', (string)($p["id"] ?? "")) ?: bin2hex(random_bytes(4)),
						"name" => biz_text($p["name"], 120),
						"holder" => biz_text($p["holder"] ?? "", 120),
						"account" => biz_text($p["account"] ?? "", 120),
						"qr" => biz_url($p["qr"] ?? ""),
						"notes" => biz_text($p["notes"] ?? "", 300)
					);
				}
			}

			return $out;

		case "images":

			$out = array();
			$seen = array();

			foreach (array_slice($value, 0, 60) as $img) {

				$keyword = mb_strtolower(preg_replace('/[^\p{L}\p{N}_-]+/u', '-', biz_text($img["keyword"] ?? "", 40)));
				$keyword = trim($keyword, "-");
				$url = biz_url($img["url"] ?? "");

				if($keyword !== "" && $url !== "" && !isset($seen[$keyword])){

					$seen[$keyword] = true;
					$out[] = array("id" => bin2hex(random_bytes(4)), "keyword" => $keyword, "description" => biz_text($img["description"] ?? "", 300), "url" => $url);
				}
			}

			return $out;

		case "inquiries":

			return array(
				"enabled" => !empty($value["enabled"]),
				"phone" => biz_clean_phone($value["phone"] ?? ""),
				"name" => biz_text($value["name"] ?? "", 120)
			);

		case "main":

			return array("phone" => biz_clean_phone($value["phone"] ?? ""));

		case "notices":

			$template = trim((string)($value["template"] ?? ""));
			$lang = trim((string)($value["lang"] ?? ""));

			return array(
				"template" => preg_match('/^[a-z0-9_]{1,512}$/', $template) ? $template : "",
				"lang" => preg_match('/^[a-z]{2,3}(_[A-Z]{2})?$/', $lang) ? $lang : "es"
			);

		case "delivery":

			$num = function($v, $min, $max){ return max($min, min($max, round((float)str_replace(",", ".", (string)$v), 2))); };
			$coord = function($v, $limit){ $v = trim(str_replace(",", ".", (string)$v)); return is_numeric($v) && abs((float)$v) <= $limit ? round((float)$v, 7) : ""; };

			return array(
				"enabled" => !empty($value["enabled"]),
				"origin_address" => biz_text($value["origin_address"] ?? "", 300),
				"lat" => $coord($value["lat"] ?? "", 90),
				"lng" => $coord($value["lng"] ?? "", 180),
				"radius_km" => $num($value["radius_km"] ?? 0, 0, 200),
				"zones" => biz_text($value["zones"] ?? "", 3000),
				"excluded" => biz_text($value["excluded"] ?? "", 3000),
				"fee" => $num($value["fee"] ?? 0, 0, 10000),
				"fee_per_km" => $num($value["fee_per_km"] ?? 0, 0, 1000),
				"free_from" => $num($value["free_from"] ?? 0, 0, 100000),
				"min_order" => $num($value["min_order"] ?? 0, 0, 100000),
				"pickup" => !empty($value["pickup"]),
				"city_hint" => biz_text($value["city_hint"] ?? "", 120),
				"maps_key" => preg_replace('/[^A-Za-z0-9_\-]/', '', (string)($value["maps_key"] ?? "")),
				"notify_phone" => biz_clean_phone($value["notify_phone"] ?? ""),
				"branches" => biz_branches((array)($value["branches"] ?? array()), $num, $coord),
				"zones_confirmed" => BizStore::get("delivery")["zones_confirmed"] ?? null
			);

		case "service":

			return array(
				"mode" => ($value["mode"] ?? "") === "installation" ? "installation" : "delivery",
				"install_fee" => max(0, min(100000, round((float)str_replace(",", ".", (string)($value["install_fee"] ?? 0)), 2))),
				"tv_enabled" => !empty($value["tv_enabled"]),
				"tv_price" => max(0, min(100000, round((float)str_replace(",", ".", (string)($value["tv_price"] ?? 0)), 2))),
				"tvbox_price" => max(0, min(100000, round((float)str_replace(",", ".", (string)($value["tvbox_price"] ?? 0)), 2))),
				"tv_notes" => biz_text($value["tv_notes"] ?? "", 1000),
				"requirements" => biz_text($value["requirements"] ?? "", 500),
				"pay_on_install" => !empty($value["pay_on_install"]),
				"cancel_hours" => max(0, min(168, (int)($value["cancel_hours"] ?? 4)))
			);

		case "agenda":

			$d = BizStore::defaults()["agenda"];
			$time = function($v, $allowEmpty = false) { $v = trim((string)$v); return preg_match('/^\d{2}:\d{2}$/', $v) ? $v : ($allowEmpty ? "" : null); };

			$days = array_values(array_unique(array_filter(array_map("intval", (array)($value["days"] ?? array())), function($x){ return $x >= 1 && $x <= 7; })));
			sort($days);

			return array(
				"enabled" => !empty($value["enabled"]),
				"notify_phone" => biz_clean_phone($value["notify_phone"] ?? ""),
				"slot_minutes" => max(5, min(480, (int)($value["slot_minutes"] ?? $d["slot_minutes"]))),
				"capacity" => max(1, min(50, (int)($value["capacity"] ?? 1))),
				"days" => $days,
				"open" => $time($value["open"] ?? "") ?? $d["open"],
				"close" => $time($value["close"] ?? "") ?? $d["close"],
				"break_start" => $time($value["break_start"] ?? "", true),
				"break_end" => $time($value["break_end"] ?? "", true),
				"max_days" => max(1, min(120, (int)($value["max_days"] ?? 30))),
				"min_hours" => max(0, min(72, (float)($value["min_hours"] ?? 1))),
				"services" => biz_text($value["services"] ?? "", 1000),
				"times" => implode(", ", BizStore::parseTimes($value["times"] ?? ""))
			);
	}

	return array();

}

/*=============================================
Guardar un bot (se respeta el formato que usa
BotsController: textos codificados con urlencode)
=============================================*/

function biz_save_bot($id, $data){

	if($id <= 0 || !is_array($data)){

		return array("ok" => false, "error" => "Datos inválidos.");
	}

	$pdo = BizStore::pdo();
	$stmt = $pdo->prepare("SELECT * FROM bots WHERE id_bot = ?");
	$stmt->execute(array($id));
	$bot = $stmt->fetch();

	if(!$bot){

		return array("ok" => false, "error" => "El bot no existe.");
	}

	$clip = function($value, $max){ return mb_substr(trim(str_replace("\r\n", "\n", strip_tags((string)$value))), 0, $max); };

	$type = in_array($data["type"] ?? "", array("text", "interactive"), true) ? $data["type"] : $bot["type_bot"];
	$interactive = in_array($data["interactive"] ?? "", array("none", "button", "list"), true) ? $data["interactive"] : $bot["interactive_bot"];

	/* Los pasos del flujo tienen un formato fijo */

	$fixed = array(
		"welcome" => array("interactive", "button"),
		"menu" => array("interactive", "list"),
		"reset" => array("interactive", "list"),
		"listMenu" => array("interactive", "none"),
		"confirmation" => array("interactive", "button")
	);

	if(isset($fixed[$bot["title_bot"]])){

		list($type, $interactive) = $fixed[$bot["title_bot"]];

	}else if($type === "text"){

		$interactive = "none";
	}

	$body = $clip($data["body"] ?? "", $type === "text" ? 4000 : 1024);

	if($body === "" && $bot["title_bot"] !== "confirmation"){

		return array("ok" => false, "error" => "Escribe el mensaje.");
	}

	$headerImage = trim((string)($data["header_image"] ?? ""));
	$headerVideo = trim((string)($data["header_video"] ?? ""));

	foreach (array($headerImage, $headerVideo) as $url) {

		if($url !== "" && !preg_match('#^https?://#i', $url)){

			return array("ok" => false, "error" => "La imagen o el video deben ser un enlace que empiece con https://");
		}
	}

	/* Botones: {"1":"texto", ...} (máx. 3, 20 caracteres) */

	$buttons = array();

	foreach (array_slice((array)($data["buttons"] ?? array()), 0, 3) as $b) {

		$bid = preg_replace('/[^\w-]/u', '', (string)($b["id"] ?? ""));
		$title = $clip($b["title"] ?? "", 20);

		if($bid !== "" && $title !== ""){

			$buttons[$bid] = $title;
		}
	}

	if($type === "interactive" && $interactive === "button" && empty($buttons)){

		return array("ok" => false, "error" => "Agrega al menos un botón.");
	}

	/* Lista: [{id,title,description}] (máx. 10) */

	$rows = array();
	$ids = array();

	foreach (array_slice((array)($data["rows"] ?? array()), 0, 10) as $r) {

		$rid = preg_replace('/[^\w-]/u', '', (string)($r["id"] ?? ""));
		$title = $clip($r["title"] ?? "", 24);

		if($rid === "" || $title === ""){

			continue;
		}

		if(isset($ids[$rid])){

			return array("ok" => false, "error" => "Dos opciones de la lista hacen lo mismo («".$title."»). Cambia una de ellas.");
		}

		$ids[$rid] = true;
		$row = array("id" => $rid, "title" => $title);
		$desc = $clip($r["description"] ?? "", 72);

		if($desc !== ""){

			$row["description"] = $desc;
		}

		$rows[] = $row;
	}

	if($type === "interactive" && $interactive === "list" && empty($rows)){

		return array("ok" => false, "error" => "Agrega al menos una opción a la lista.");
	}

	$enc = function($v){ return $v === "" ? "" : urlencode($v); };

	$stmt = $pdo->prepare("UPDATE bots SET type_bot = ?, interactive_bot = ?, header_text_bot = ?, header_image_bot = ?, header_video_bot = ?, body_text_bot = ?, footer_text_bot = ?, buttons_bot = ?, list_bot = ?, title_list_bot = ? WHERE id_bot = ?");

	$stmt->execute(array(
		$type,
		$interactive,
		$enc($clip($data["header_text"] ?? "", 60)),
		$enc($type === "interactive" ? $headerImage : ""),
		$enc($type === "interactive" ? $headerVideo : ""),
		$enc($body),
		$enc($type === "interactive" ? $clip($data["footer"] ?? "", 60) : ""),
		urlencode(json_encode((object)$buttons, JSON_UNESCAPED_UNICODE)),
		urlencode(json_encode($rows, JSON_UNESCAPED_UNICODE)),
		$enc($clip($data["list_title"] ?? "", 24)),
		$id
	));

	return array("ok" => true);

}
