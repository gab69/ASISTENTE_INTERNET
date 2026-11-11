<?php

/*=============================================
Archivos que envían los clientes por WhatsApp.
Se descargan de Meta la primera vez que alguien los
abre en el panel (no al cargar la página) y quedan
guardados en views/assets/ws/
=============================================*/

require_once dirname(__DIR__)."/bootstrap.php";
require_once CMS_DIR."/controllers/curl.controller.php";
require_once CMS_DIR."/controllers/chat.service.php";

session_start(array("read_and_close" => true));

if(empty($_SESSION["admin"]->id_admin)){

	http_response_code(401);
	exit;
}

$id = preg_replace("/[^A-Za-z0-9_\-]/", "", (string)($_GET["id"] ?? ""));

if($id === ""){

	http_response_code(404);
	exit;
}

@set_time_limit(150);

$path = CurlController::apiWS(ChatService::api(), $id);

if(!$path){

	http_response_code(404);
	header("Content-Type: text/plain; charset=utf-8");
	echo "Archivo no disponible (WhatsApp solo conserva los archivos por 30 días).";
	exit;
}

header("Cache-Control: private, max-age=86400");
header("Location: ".$path, true, 302);
