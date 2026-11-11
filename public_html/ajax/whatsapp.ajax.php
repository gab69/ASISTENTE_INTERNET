<?php

require_once dirname(__DIR__)."/bootstrap.php";
require_once CMS_DIR."/controllers/whatsapp.controller.php";

header("Content-Type: application/json; charset=utf-8");
header("Cache-Control: no-store");

session_start(array("read_and_close" => true));

function wa_out($data, $status = 200){

	http_response_code($status);
	echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
	exit;

}

$admin = $_SESSION["admin"] ?? null;

if(empty($admin->id_admin)){

	wa_out(array("ok" => false, "error" => "La sesión expiró. Vuelve a iniciar sesión."), 401);
}

if($_SERVER["REQUEST_METHOD"] !== "POST" || empty($_SESSION["biz_csrf"]) || !hash_equals($_SESSION["biz_csrf"], (string)($_SERVER["HTTP_X_CSRF_TOKEN"] ?? ""))){

	wa_out(array("ok" => false, "error" => "Solicitud no válida. Recarga la página."), 403);
}

$perms = json_decode(urldecode((string)($admin->permissions_admin ?? "")), true) ?: array();

if(!in_array($admin->rol_admin, array("superadmin", "admin"), true) && strtolower((string)($perms["api-ws"] ?? "")) !== "on"){

	wa_out(array("ok" => false, "error" => "No tienes permiso para la sección WhatsApp."), 403);
}

$action = (string)($_POST["action"] ?? "");

try{

	switch ($action) {

		case "list":

			wa_out(array("ok" => true, "items" => array_map(array("WhatsAppAccount", "publicRow"), WhatsAppAccount::all())));

		case "save":

			$result = WhatsAppAccount::save($_POST, !empty($_POST["force"]));

			wa_out($result, $result["ok"] ? 200 : 422);

		case "verify":

			$account = WhatsAppAccount::find((int)($_POST["id"] ?? 0));

			if(!$account){

				wa_out(array("ok" => false, "error" => "La cuenta no existe."), 404);
			}

			$check = WhatsAppAccount::verify($account["id_number_whatsapp"], $account["token_whatsapp"]);

			wa_out($check["ok"]
				? array("ok" => true, "message" => "Conexión correcta: ".$check["display"].($check["name"] !== "" ? " (".$check["name"].")" : "").($check["quality"] !== "" ? " · calidad ".$check["quality"] : ""))
				: array("ok" => false, "error" => $check["error"]));

		case "test":

			$result = WhatsAppAccount::test((int)($_POST["id"] ?? 0), (string)($_POST["to"] ?? ""));

			wa_out($result, $result["ok"] ? 200 : 422);

		case "delete":

			wa_out(array("ok" => WhatsAppAccount::delete((int)($_POST["id"] ?? 0))));

		default:

			wa_out(array("ok" => false, "error" => "Acción desconocida."), 400);
	}

}catch(Throwable $e){

	error_log("whatsapp.ajax ".$action.": ".$e->getMessage());

	wa_out(array("ok" => false, "error" => "Error del servidor. Revisa logs/php_error.log"), 500);
}
