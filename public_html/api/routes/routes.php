<?php

require_once API_DIR."/models/connection.php";
require_once API_DIR."/controllers/get.controller.php";

/*=============================================
Obtener la ruta solicitada sin importar si la API
está en la raíz de un subdominio (api.dominio.com)
o dentro de una carpeta (dominio.com/api)
=============================================*/

$path = parse_url($_SERVER["REQUEST_URI"] ?? "/", PHP_URL_PATH) ?: "/";
$basePath = rtrim(str_replace("\\", "/", dirname($_SERVER["SCRIPT_NAME"] ?? "/")), "/");

if($basePath !== "" && stripos($path, $basePath) === 0){

	$path = substr($path, strlen($basePath));
}

$routesArray = array_values(array_filter(explode("/", $path), "strlen"));

/*=============================================
Cuando no se hace ninguna petición a la API
o la ruta no es válida
=============================================*/

if(count($routesArray) != 1 || $routesArray[0] === "index.php"){

	Connection::response(404, "Not Found");

	return;

}

$table = urldecode($routesArray[0]);

if(!Connection::validIdentifier($table)){

	Connection::response(404, "Not Found");

	return;

}

$requestMethod = strtoupper($_SERVER["REQUEST_METHOD"] ?? "GET");

/*=============================================
Validar llave secreta
=============================================*/

if(!hash_equals(Connection::apikey(), Connection::requestApiKey())){

	if(!in_array($table, Connection::publicAccess(), true) || $requestMethod !== "GET"){

		Connection::response(400, "You are not authorized to make this request");

		return;

	}

	/*=============================================
	Acceso público
	=============================================*/

	$response = new GetController();
	$response -> getData($table, "*", null, null, null, null);

	return;

}

/*=============================================
Permitir cuerpos JSON en POST
=============================================*/

if($requestMethod == "POST" && empty($_POST) && stripos($_SERVER["CONTENT_TYPE"] ?? "", "application/json") !== false){

	$jsonBody = json_decode(file_get_contents("php://input"), true);

	if(is_array($jsonBody)){

		$_POST = $jsonBody;
	}
}

/*=============================================
Despachar según el método
=============================================*/

switch($requestMethod){

	case "GET":
		include API_DIR."/routes/services/get.php";
		break;

	case "POST":
		include API_DIR."/routes/services/post.php";
		break;

	case "PUT":
		include API_DIR."/routes/services/put.php";
		break;

	case "DELETE":
		include API_DIR."/routes/services/delete.php";
		break;

	default:
		Connection::response(405, "Method Not Allowed");

}
