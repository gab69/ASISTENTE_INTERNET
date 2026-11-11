<?php

require_once dirname(__DIR__)."/bootstrap.php";

/*=============================================
Controladores
=============================================*/

require_once CMS_DIR."/controllers/curl.controller.php";
require_once CMS_DIR."/controllers/chat.service.php";
require_once CMS_DIR."/controllers/clients.controller.php";
require_once CMS_DIR."/controllers/business.controller.php";
require_once CMS_DIR."/controllers/bots.controller.php";
require_once CMS_DIR."/controllers/ia.controller.php";
require_once CMS_DIR."/controllers/business.ops.php";

/*=============================================
Verificación del webhook (Meta for Developers)
=============================================*/

$token = (string)cms_config("webhook_verify_token", "");

if($_SERVER["REQUEST_METHOD"] == "GET"){

	if(isset($_GET["hub_verify_token"]) && $token !== "" && hash_equals($token, (string)$_GET["hub_verify_token"])){

		header("Content-Type: text/plain; charset=utf-8");
		echo preg_replace("/[^A-Za-z0-9_\-]/", "", (string)($_GET["hub_challenge"] ?? ""));
		exit;
	}

	http_response_code(403);
	echo "Token inválido";
	exit;
}

if($_SERVER["REQUEST_METHOD"] !== "POST"){

	http_response_code(405);
	exit;
}

/*=============================================
Responder 200 a Meta de inmediato y procesar después.
Si Meta espera demasiado, reintenta el mismo evento y
el cliente recibe respuestas duplicadas.
=============================================*/

$input = file_get_contents("php://input");

ignore_user_abort(true);
@set_time_limit(180);

http_response_code(200);
header("Content-Type: text/plain");
header("Content-Length: 2");
header("Connection: close");
echo "OK";

if(function_exists("fastcgi_finish_request")){

	fastcgi_finish_request();

}else if(function_exists("litespeed_finish_request")){

	litespeed_finish_request();

}else{

	while(ob_get_level() > 0){ ob_end_flush(); }
	flush();
}

/*=============================================
Registro opcional de eventos
=============================================*/

if(cms_config("webhook_log", false)){

	@file_put_contents(CMS_DIR."/logs/webhook_".date("Y-m-d").".log", date("H:i:s")." ".$input."\n\n", FILE_APPEND);
}

$data = json_decode($input, false, 512, JSON_INVALID_UTF8_SUBSTITUTE);
$value = $data->entry[0]->changes[0]->value ?? null;

if(!$value){

	exit;
}

/*=============================================
Cuenta de WhatsApp que recibió el evento
=============================================*/

$getApiWS = ChatService::api($value->metadata->phone_number_id ?? null) ?: ChatService::api();

if(!$getApiWS){

	error_log("Webhook: no hay una cuenta de WhatsApp registrada con phone_number_id ".($value->metadata->phone_number_id ?? "?"));
	exit;
}

/*=============================================
1) Estados de mensajes enviados por el negocio
   sent / delivered / read / failed
=============================================*/

foreach ((array)($value->statuses ?? array()) as $status) {

	$state = (string)($status->status ?? "");
	$error = null;

	if($state === "failed"){

		$error = ChatService::errorText((object)array("error" => $status->errors[0] ?? null));
	}

	ChatService::updateStatus($status->id ?? null, $state, $error);

	/* Aviso al negocio rechazado por la ventana de 24 h: reenviarlo con la plantilla */

	if($state === "failed"){

		try{

			if(!Notify::retryFailed($status->id ?? null, (int)($status->errors[0]->code ?? 0))){

				Notify::reportFailed($status->id ?? null, (string)$error);
			}

		}catch(Throwable $e){ error_log("Avisos: ".$e->getMessage()); }
	}

	/*=============================================
	Bots que continúan solos después de enviarse
	(pedir datos de domicilio, confirmar pedido)
	=============================================*/

	if($state === "sent"){

		/* El aviso puede llegar antes de que el mensaje termine de guardarse */

		for($try = 0; $try < 4; $try++){

			$get = CurlController::request("messages?linkTo=wamid_message&equalTo=".rawurlencode((string)($status->id ?? "")), "GET", array());

			if(isset($get->status) && $get->status == 200){
				break;
			}

			sleep(1);
		}

		if(isset($get->status) && $get->status == 200){

			BusinessController::responseBusiness($get->results[0], $getApiWS, ChatService::cleanPhone($status->recipient_id ?? $get->results[0]->phone_message));
		}
	}
}

/*=============================================
2) Mensajes del cliente
=============================================*/

foreach ((array)($value->messages ?? array()) as $incoming) {

	$wamid = (string)($incoming->id ?? "");
	$phone_message = ChatService::cleanPhone($incoming->from ?? "");

	if($phone_message === ""){

		continue;
	}

	/* Meta puede reenviar el mismo evento: procesarlo una sola vez */

	if($wamid !== ""){

		$dup = CurlController::request("messages?linkTo=wamid_message&equalTo=".rawurlencode($wamid)."&select=id_message", "GET", array());

		if(isset($dup->status) && $dup->status == 200){

			continue;
		}
	}

	/*=============================================
	Contenido según el tipo
	=============================================*/

	$client_message = null;
	$type_conversation = $incoming->type ?? "text";

	switch($type_conversation){

		case "text":

			$client_message = $incoming->text->body ?? "";
			break;

		case "image":
		case "video":
		case "audio":
		case "document":
		case "sticker":

			$media = $incoming->$type_conversation;

			$client_message = json_encode(array(
				"type" => $type_conversation === "sticker" ? "image" : $type_conversation,
				"mime" => $media->mime_type ?? "",
				"id" => $media->id ?? "",
				"caption" => $media->caption ?? ($media->filename ?? "")
			), JSON_UNESCAPED_UNICODE);

			if($type_conversation === "sticker"){

				$type_conversation = "image";
			}

			break;

		case "interactive":

			$reply = $incoming->interactive->button_reply ?? $incoming->interactive->list_reply ?? null;

			$client_message = json_encode(array("id" => (string)($reply->id ?? ""), "text" => (string)($reply->title ?? "")), JSON_UNESCAPED_UNICODE);
			break;

		case "button":

			/* Respuesta a un botón de plantilla */
			$client_message = $incoming->button->text ?? "";
			$type_conversation = "text";
			break;

		case "location":

			$loc = $incoming->location;
			$client_message = "📍 ".trim(($loc->name ?? "")." ".($loc->address ?? ""))." https://maps.google.com/?q=".($loc->latitude ?? "").",".($loc->longitude ?? "");
			$type_conversation = "text";
			break;

		case "reaction":

			continue 2;

		default:

			$client_message = "[Mensaje de tipo ".$type_conversation." no compatible]";
			$type_conversation = "text";
	}

	/* Nombre del perfil de WhatsApp para el contacto */

	$profileName = "";

	foreach ((array)($value->contacts ?? array()) as $contact) {

		if(ChatService::cleanPhone($contact->wa_id ?? "") === $phone_message){

			$profileName = (string)($contact->profile->name ?? "");
		}
	}

	/*=============================================
	Orden y conversación (ventana de 24 h)
	=============================================*/

	$last = ChatService::lastMessage($phone_message);

	if($last && !ChatService::expired($last)){

		$order_message = (int)$last->order_message + 1;
		$id_conversation_message = $last->id_conversation_message;
		$template_message = $last->template_message;

	}else{

		$order_message = 0;
		$id_conversation_message = "c_".bin2hex(random_bytes(12));
		$template_message = $last->template_message ?? null;
	}

	/*=============================================
	Guardar mensaje del cliente
	=============================================*/

	$saveMessage = CurlController::request("messages?token=no&except=id_message", "POST", array(
		"id_conversation_message" => $id_conversation_message,
		"type_message" => "client",
		"id_whatsapp_message" => $getApiWS->id_whatsapp,
		"client_message" => $client_message,
		"phone_message" => $phone_message,
		"template_message" => $template_message,
		"order_message" => $order_message,
		"expiration_message" => date("Y-m-d H:i:s", time() + 86400),
		"wamid_message" => $wamid,
		"date_created_message" => date("Y-m-d")
	));

	if(!isset($saveMessage->status) || $saveMessage->status != 200){

		error_log("Webhook: no se pudo guardar el mensaje de ".$phone_message.": ".json_encode($saveMessage));
		continue;
	}

	/* Un número del equipo escribió: se abre la ventana de 24 h y se le
	   reenvían los avisos de órdenes que WhatsApp no le había entregado */

	try{

		Notify::flushPending($phone_message);

	}catch(Throwable $e){

		error_log("Webhook: no se pudieron reenviar los avisos pendientes: ".$e->getMessage());
	}

	/*=============================================
	¿Es el equipo respondiendo una consulta de la IA?
	=============================================*/

	try{

		require_once CMS_DIR."/controllers/business.inquiries.php";

		if(BizInquiries::handleReply($getApiWS, $phone_message, $client_message, (string)($incoming->context->id ?? ""))){

			continue;
		}

	}catch(Throwable $e){

		error_log("Webhook: error al procesar la respuesta del equipo: ".$e->getMessage());
	}

	/*=============================================
	Número principal consultando sus órdenes: la IA
	le responde en el chat con los datos reales
	(y puede cancelar una orden si lo confirma)
	=============================================*/

	try{

		require_once CMS_DIR."/controllers/business.staff.php";

		if(StaffAssistant::handle($getApiWS, $phone_message, $client_message, (string)($incoming->context->id ?? ""))){

			continue;
		}

	}catch(Throwable $e){

		error_log("Webhook: error en el asistente del equipo: ".$e->getMessage());
	}

	/*=============================================
	Números del equipo (central, agenda, sucursales):
	reciben avisos, no son clientes. Su mensaje abre la
	ventana de 24 h para que los avisos lleguen siempre.
	=============================================*/

	try{

		/* Solo se ignora la respuesta del equipo a un aviso (ej.: "ok" sobre la orden);
		   cualquier otro mensaje de esos números se atiende normal (así el dueño puede
		   probar el flujo con su propio celular) */

		if(Notify::isStaff($phone_message) && Notify::isReplyToNotice((string)($incoming->context->id ?? ""))){

			continue;
		}

		/* Volvió a escribir: el cierre automático empieza de nuevo */

		Followup::reset($phone_message);

	}catch(Throwable $e){

		error_log("Webhook: ".$e->getMessage());
	}

	/*=============================================
	Varios mensajes seguidos (nombre, DNI, correo…):
	se espera un momento y responde solo el último,
	con todo lo que el cliente escribió. Así no se
	pierde ninguno y no salen respuestas cruzadas.
	=============================================*/

	try{

		if(Notify::newerClientMessage($phone_message, $wamid, Limits::clientFlood($phone_message) ? 8 : (int)cms_config("reply_wait_seconds", 4))){

			continue;
		}

	}catch(Throwable $e){

		error_log("Webhook: ".$e->getMessage());
	}

	/*=============================================
	Responder al cliente (bots o IA)
	=============================================*/

	try{

		ClientsController::responseClients($getApiWS,$phone_message,$order_message,$type_conversation,$client_message,$profileName);

	}catch(Throwable $e){

		error_log("Webhook: error al responder a ".$phone_message.": ".$e);
	}
}

/*=============================================
Tareas automáticas (cierre de órdenes y seguimiento),
como máximo una vez por minuto
=============================================*/

try{ Tasks::maybeRun(); }catch(Throwable $e){ error_log("Tareas: ".$e->getMessage()); }
