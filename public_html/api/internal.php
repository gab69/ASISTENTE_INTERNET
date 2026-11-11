<?php

/*=============================================
Ejecutar la API dentro del mismo proceso PHP.

El CMS, el webhook y los AJAX usaban cURL para llamar
a https://dominio/api/... en cada consulta: una conexión
HTTPS nueva y un segundo proceso PHP por consulta. En
hosting compartido eso agota los procesos disponibles y
vuelve lento todo el panel. Aquí se despacha la misma
ruta de la API directamente, con idéntico resultado.
=============================================*/

if(!defined("API_DIR")){

	define("API_DIR", __DIR__);
}

if(!isset($GLOBALS["API_CONFIG"])){

	$GLOBALS["API_CONFIG"] = $GLOBALS["CMS_CONFIG"] ?? require dirname(API_DIR)."/config/loader.php";
}

require_once API_DIR."/models/connection.php";

function api_internal_request($url, $method = "GET", $fields = array()){

	$method = strtoupper($method);
	$parts = parse_url("/".ltrim((string)$url, "/"));
	$path = $parts["path"] ?? "/";
	$queryString = $parts["query"] ?? "";

	$query = array();
	parse_str($queryString, $query);

	/*=============================================
	Guardar el estado de la petición real
	=============================================*/

	$backup = array($_GET, $_POST, $_REQUEST, $_SERVER);

	$_GET = $query;
	$_POST = array();
	$body = null;

	if($method === "POST"){

		if(is_array($fields)){

			// Igual que un envío por formulario: todos los valores como texto
			foreach ($fields as $key => $value) {

				$_POST[$key] = (is_scalar($value) || $value === null) ? (string)$value : $value;
			}

		}else{

			parse_str((string)$fields, $_POST);
		}

	}else if($method !== "GET"){

		$body = is_array($fields) ? http_build_query($fields) : (string)$fields;
	}

	$_REQUEST = array_merge($_GET, $_POST);
	$_SERVER["REQUEST_METHOD"] = $method;
	$_SERVER["REQUEST_URI"] = "/api".$path.($queryString !== "" ? "?".$queryString : "");
	$_SERVER["SCRIPT_NAME"] = "/api/index.php";
	$_SERVER["HTTP_AUTHORIZATION"] = Connection::apikey();
	unset($_SERVER["CONTENT_TYPE"], $_SERVER["HTTP_X_API_KEY"], $_SERVER["REDIRECT_HTTP_AUTHORIZATION"]);

	Connection::$internal = true;
	Connection::$internalBody = $body;

	$level = ob_get_level();
	ob_start();

	try{

		include API_DIR."/routes/routes.php";

		$output = ob_get_clean();

	}catch(Throwable $e){

		while(ob_get_level() > $level){

			ob_end_clean();
		}

		error_log("Internal API error [".$method." ".$url."]: ".$e);

		$output = json_encode(array("status" => 500, "results" => "Internal API error"));

	}finally{

		list($_GET, $_POST, $_REQUEST, $_SERVER) = $backup;

		Connection::$internal = false;
		Connection::$internalBody = null;
	}

	return $output;

}
