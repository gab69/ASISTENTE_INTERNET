<?php

/*=============================================
Arranque común del CMS
(lo cargan index.php, ajax/*, webhook/* y los controladores)
=============================================*/

if(defined("CMS_DIR")){
	return;
}

define("CMS_DIR", __DIR__);

$GLOBALS["CMS_CONFIG"] = require CMS_DIR."/config/loader.php";

/*=============================================
Manejo de errores: nunca mostrar errores en producción
=============================================*/

if(!is_dir(CMS_DIR."/logs")){
	@mkdir(CMS_DIR."/logs", 0755, true);
}

error_reporting(E_ALL);
ini_set("display_errors", !empty($GLOBALS["CMS_CONFIG"]["debug"]) ? "1" : "0");
ini_set("log_errors", "1");
ini_set("error_log", CMS_DIR."/logs/php_error.log");

date_default_timezone_set($GLOBALS["CMS_CONFIG"]["timezone"] ?? "America/Lima");

/*=============================================
Versión instalada. Al subir un zip nuevo, la caché de
PHP (OPcache) puede seguir ejecutando el código viejo:
si la versión cambió, se limpia la caché una vez.
=============================================*/

define("CMS_BUILD", trim((string)@file_get_contents(CMS_DIR."/build.txt")) ?: "dev");

(function(){

	$applied = CMS_DIR."/logs/build.applied";

	if(CMS_BUILD === "dev" || trim((string)@file_get_contents($applied)) === CMS_BUILD){

		return;
	}

	if(function_exists("opcache_reset")){

		@opcache_reset();
	}

	@file_put_contents($applied, CMS_BUILD);

})();

/*=============================================
Compatibilidad con PHP 7.x
=============================================*/

if(!function_exists("str_contains")){
	function str_contains($haystack, $needle){
		return $needle === "" || strpos((string)$haystack, (string)$needle) !== false;
	}
}

if(!function_exists("str_starts_with")){
	function str_starts_with($haystack, $needle){
		return strncmp((string)$haystack, (string)$needle, strlen((string)$needle)) === 0;
	}
}

/*=============================================
Leer un valor de configuración
=============================================*/

function cms_config($key, $default = null){

	return $GLOBALS["CMS_CONFIG"][$key] ?? $default;

}

/*=============================================
Moneda (Soles peruanos por defecto): S/ 1,250.50
=============================================*/

function cms_currency(){

	$symbol = trim((string)cms_config("currency_symbol", "S/"));

	return $symbol !== "" ? $symbol : "S/";

}

function cms_currency_name(){

	$names = array("PEN" => "Soles peruanos", "USD" => "Dólares estadounidenses", "COP" => "Pesos colombianos", "MXN" => "Pesos mexicanos", "CLP" => "Pesos chilenos", "ARS" => "Pesos argentinos", "BOB" => "Bolivianos", "EUR" => "Euros");

	return $names[strtoupper((string)cms_config("currency_code", "PEN"))] ?? strtoupper((string)cms_config("currency_code", "PEN"));

}

function cms_money($amount, $withSymbol = true){

	$amount = (float)$amount;
	$text = number_format($amount, 2, ".", ",");

	return $withSymbol ? cms_currency()." ".$text : $text;

}

/*=============================================
Esquema real (https detrás de proxies / Cloudflare / LiteSpeed)
=============================================*/

function cms_scheme(){

	if((!empty($_SERVER["HTTPS"]) && strtolower($_SERVER["HTTPS"]) !== "off")
	|| ($_SERVER["SERVER_PORT"] ?? null) == 443
	|| strtolower($_SERVER["HTTP_X_FORWARDED_PROTO"] ?? "") === "https"
	|| strtolower($_SERVER["REQUEST_SCHEME"] ?? "") === "https"){

		return "https";
	}

	return "http";

}

/*=============================================
URL base del CMS: https://cms.dominio.com/
=============================================*/

function cms_base_url(){

	$host = $_SERVER["HTTP_HOST"] ?? ($_SERVER["SERVER_NAME"] ?? "localhost");

	return cms_scheme()."://".$host."/";

}

/*=============================================
URL de la API terminada en "/"
=============================================*/

function cms_api_url(){

	$url = trim((string)cms_config("api_url", ""));

	if($url === ""){

		$url = cms_base_url()."api/";
	}

	return rtrim($url, "/")."/";

}

/*=============================================
¿El sistema ya fue configurado desde /setup/?
=============================================*/

function cms_installed(){

	return !empty($GLOBALS["CMS_CONFIG"]["installed"]);

}

/*=============================================
Si no está configurado, enviar al asistente web
=============================================*/

if(!cms_installed() && !defined("CMS_SETUP") && PHP_SAPI !== "cli"){

	$script = str_replace("\\", "/", $_SERVER["SCRIPT_FILENAME"] ?? "");

	if($script === str_replace("\\", "/", CMS_DIR."/index.php")){

		header("Location: /setup/");

	}else{

		http_response_code(503);
		header("Content-Type: application/json; charset=utf-8");
		echo json_encode(array("status" => 503, "results" => "Sistema sin configurar. Abre /setup/"));
	}

	exit;

}

/*=============================================
Actualizar la base de datos si hay cambios pendientes
(una sola vez; después solo se lee un archivo pequeño)
=============================================*/

if(cms_installed()){

	require_once CMS_DIR."/controllers/schema.php";

	cms_schema_check();
}
