<?php

require_once dirname(__DIR__)."/bootstrap.php";
require_once CMS_DIR."/controllers/curl.controller.php";
require_once CMS_DIR."/controllers/chat.controller.php";
require_once CMS_DIR."/controllers/chat.service.php";

header("Content-Type: application/json; charset=utf-8");
header("Cache-Control: no-store");

/*=============================================
Solo administradores con sesión iniciada.
read_and_close: no bloquea la sesión mientras se
consulta (las peticiones del panel no se forman en fila)
=============================================*/

session_start(array("read_and_close" => true));

$admin = $_SESSION["admin"] ?? null;

function chat_out($data, $status = 200){

	http_response_code($status);
	echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
	exit;

}

if(empty($admin->id_admin)){

	chat_out(array("ok" => false, "error" => "La sesión expiró. Vuelve a iniciar sesión.", "expired" => true), 401);
}

$action = $_POST["action"] ?? "";
$phone = ChatService::cleanPhone($_POST["phone"] ?? "");
$filter = in_array($_POST["filter"] ?? "", array("pending", "ai"), true) ? $_POST["filter"] : "";
$search = trim((string)($_POST["search"] ?? ""));

try{

	/*=============================================
	Actualización en vivo (una sola petición):
	mensajes nuevos del chat abierto, estado de
	entrega y lista de contactos si hubo cambios
	=============================================*/

	if($action === "poll"){

		/* Tareas automáticas (cierre de órdenes y seguimiento) con el panel abierto */

		try{

			require_once CMS_DIR."/controllers/business.ops.php";
			Tasks::maybeRun();

		}catch(Throwable $e){

			error_log("Tareas: ".$e->getMessage());
		}

		$lastId = (int)($_POST["lastId"] ?? 0);
		$globalId = (int)($_POST["globalId"] ?? 0);
		$lastDay = (string)($_POST["lastDay"] ?? "");

		$out = array("ok" => true, "html" => "", "lastId" => $lastId, "newClient" => false, "statuses" => array());

		if($phone !== ""){

			$recent = ChatController::messages($phone, 40);
			$new = array();

			foreach ($recent as $m) {

				if((int)$m->id_message > $lastId){

					$new[] = $m;
					$out["lastId"] = max($out["lastId"], (int)$m->id_message);

					if($m->type_message == "client"){
						$out["newClient"] = true;
					}

				}else if($m->type_message == "business"){

					$out["statuses"][(int)$m->id_message] = array(
						"icon" => ChatController::statusIcon($m),
						"failed" => ($m->status_message ?? "") === "failed",
						"error" => (string)($m->error_message ?? "")
					);
				}
			}

			if(!empty($new)){

				$out["html"] = ChatController::renderMessages($new, $lastDay);
				$out["lastDay"] = TemplateController::formatDate(8, end($new)->date_updated_message);
			}
		}

		/* Lista de contactos: solo si llegó algún mensaje nuevo */

		$currentGlobal = ChatController::lastGlobalId();
		$out["globalId"] = $currentGlobal;

		if($currentGlobal !== $globalId){

			$contacts = ChatController::contacts($search, ChatController::CONTACTS_PAGE, 0, $filter);
			$out["contacts"] = ChatController::renderContacts($contacts, $phone);
			$out["more"] = count($contacts) >= ChatController::CONTACTS_PAGE;
			$out["newChat"] = $globalId > 0 && !empty($contacts) && $contacts[0]->phone_contact !== $phone && ($contacts[0]->type_message ?? "") === "client";
		}

		chat_out($out);
	}

	/*=============================================
	Mensajes anteriores (el historial es permanente)
	=============================================*/

	if($action === "history"){

		$beforeId = (int)($_POST["beforeId"] ?? 0);

		if($phone === "" || $beforeId <= 0){

			chat_out(array("ok" => false, "error" => "Datos incompletos."), 400);
		}

		$older = ChatController::messages($phone, ChatController::PAGE, $beforeId);

		chat_out(array(
			"ok" => true,
			"html" => ChatController::renderMessages($older),
			"firstId" => !empty($older) ? (int)$older[0]->id_message : $beforeId,
			"more" => count($older) >= ChatController::PAGE
		));
	}

	/*=============================================
	Contactos: búsqueda (nombre, número o texto de
	los mensajes), filtros y "cargar más"
	=============================================*/

	if($action === "contacts"){

		$offset = max(0, (int)($_POST["offset"] ?? 0));
		$contacts = ChatController::contacts($search, ChatController::CONTACTS_PAGE, $offset, $filter);

		chat_out(array(
			"ok" => true,
			"contacts" => ChatController::renderContacts($contacts, $phone),
			"more" => count($contacts) >= ChatController::CONTACTS_PAGE
		));
	}

	/*=============================================
	Panel lateral: datos, pedidos y citas del contacto
	=============================================*/

	if($action === "info"){

		chat_out(array("ok" => true, "html" => ChatController::renderInfo(ChatController::contact($phone))));
	}

	/*=============================================
	Enviar mensaje manual (texto o archivo)
	=============================================*/

	if($action === "send"){

		$conversation = trim(str_replace("\r\n", "\n", (string)($_POST["conversation"] ?? "")));

		if($phone === "" || $conversation === ""){

			chat_out(array("ok" => false, "error" => "Escribe un mensaje y elige un contacto."), 400);
		}

		/* Archivos propios con ruta relativa: WhatsApp necesita la URL completa */

		if(preg_match('~^/views/~', $conversation)){

			$conversation = rtrim(cms_base_url(), "/").$conversation;
		}

		$getApiWS = ChatService::api();
		$extension = strtolower(pathinfo(parse_url($conversation, PHP_URL_PATH) ?: "", PATHINFO_EXTENSION));
		$isUrl = (bool)filter_var($conversation, FILTER_VALIDATE_URL) && !preg_match('/\s/', $conversation);

		$types = array(
			"jpg" => "image", "jpeg" => "image", "png" => "image", "webp" => "image",
			"mp4" => "video", "3gp" => "video",
			"mp3" => "audio", "ogg" => "audio", "aac" => "audio", "amr" => "audio", "m4a" => "audio",
			"pdf" => "document", "doc" => "document", "docx" => "document", "xls" => "document", "xlsx" => "document", "ppt" => "document", "pptx" => "document", "txt" => "document"
		);

		if($isUrl && isset($types[$extension])){

			/* Los archivos del administrador de archivos llegan como URL */

			$type = $types[$extension];
			$payload = ChatService::mediaPayload($phone, $type, $conversation);
			$business = json_encode(array("type" => $type, "title" => $conversation), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
			$template = json_encode(array("type" => $type, "title" => ""));

		}else{

			$payload = ChatService::textPayload($phone, $conversation);
			$business = $conversation;
			$template = '{"type":"manual","title":""}';
		}

		$result = ChatService::deliver($getApiWS, $phone, $payload, $business, $template);

		chat_out(array("ok" => $result["ok"], "error" => $result["error"], "id" => $result["id"]));
	}

	/*=============================================
	Activar / desactivar el asistente IA del contacto
	=============================================*/

	if($action === "ai"){

		$idContact = (int)($_POST["idContact"] ?? 0);
		$aiContact = !empty($_POST["aiContact"]) ? 1 : 0;

		$update = CurlController::request("contacts?id=".$idContact."&nameId=id_contact&token=no&except=id_contact", "PUT", http_build_query(array("ai_contact" => $aiContact)));

		chat_out(array("ok" => isset($update->status) && $update->status == 200));
	}

}catch(Throwable $e){

	error_log("chat.ajax ".$action.": ".$e->getMessage());

	chat_out(array("ok" => false, "error" => "Error del servidor. Revisa logs/php_error.log"), 500);
}

chat_out(array("ok" => false, "error" => "Acción no válida"), 400);
