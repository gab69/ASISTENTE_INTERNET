<?php

/*=============================================
Rutas base y configuración
=============================================*/

define('API_DIR', __DIR__);

$GLOBALS["API_CONFIG"] = require dirname(API_DIR)."/config/loader.php";

chdir(API_DIR);

/*=============================================
Manejo de errores
=============================================*/

if(!is_dir(dirname(API_DIR)."/logs")){
	@mkdir(dirname(API_DIR)."/logs", 0755, true);
}

error_reporting(E_ALL);
ini_set("display_errors", $GLOBALS["API_CONFIG"]["debug"] ? "1" : "0");
ini_set("log_errors", "1");
ini_set("error_log", dirname(API_DIR)."/logs/api_error.log");

date_default_timezone_set($GLOBALS["API_CONFIG"]["timezone"] ?? "America/Lima");

/*=============================================
CORS
=============================================*/

$allowedOrigins = $GLOBALS["API_CONFIG"]["cors_origins"] ?? ["*"];
$origin = $_SERVER["HTTP_ORIGIN"] ?? "";

if(in_array("*", $allowedOrigins, true)){

	header("Access-Control-Allow-Origin: *");

}else if($origin !== "" && in_array($origin, $allowedOrigins, true)){

	header("Access-Control-Allow-Origin: ".$origin);
	header("Vary: Origin");
}

header("Access-Control-Allow-Headers: Origin, X-Requested-With, Content-Type, Accept, Authorization, X-Api-Key");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Content-Type: application/json; charset=utf-8");

/*=============================================
Pre-flight de navegadores
=============================================*/

if(($_SERVER["REQUEST_METHOD"] ?? "") === "OPTIONS"){

	http_response_code(204);
	exit;
}

/*=============================================
Sistema aún no configurado desde /setup/
=============================================*/

if(empty($GLOBALS["API_CONFIG"]["installed"])){

	http_response_code(503);
	echo json_encode(["status" => 503, "results" => "System not configured. Open /setup/"]);
	exit;
}

/*=============================================
Errores fatales siempre en formato JSON
=============================================*/

set_exception_handler(function($e){

	error_log($e);

	if(!headers_sent()){
		http_response_code(500);
	}

	echo json_encode([
		"status"  => 500,
		"results" => $GLOBALS["API_CONFIG"]["debug"] ? $e->getMessage() : "Internal Server Error"
	]);

});

/*=============================================
Requerimientos
=============================================*/

require_once API_DIR."/controllers/routes.controller.php";

$index = new RoutesController();
$index -> index();
