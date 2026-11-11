<?php

/*=============================================
Tareas automáticas: cierre de órdenes pasada la
instalación y cierre amable de conversaciones.

En cPanel → Trabajos de cron (cada 5 minutos):
  php /home/USUARIO/public_html/cron/run.php
o por URL (la llave está en Configuración → Límites):
  https://tudominio.com/cron/run.php?key=LLAVE

Sin cron también se ejecutan solas con el tráfico
(webhook, chat e inicio), como máximo una vez por minuto.
=============================================*/

require_once dirname(__DIR__)."/bootstrap.php";
require_once CMS_DIR."/controllers/business.ops.php";

$cli = PHP_SAPI === "cli";

if(!$cli){

	header("Content-Type: application/json; charset=utf-8");

	$key = (string)cms_config("cron_key", "");

	if($key === "" || !hash_equals($key, (string)($_GET["key"] ?? ""))){

		http_response_code(403);
		echo json_encode(array("ok" => false, "error" => "Llave inválida"));
		exit;
	}
}

@touch(CMS_DIR."/config/tasks.lock");

try{

	$result = Tasks::run();
	echo json_encode(array("ok" => true, "at" => date("Y-m-d H:i:s")) + $result).($cli ? "\n" : "");

}catch(Throwable $e){

	error_log("Cron: ".$e->getMessage());
	http_response_code(500);
	echo json_encode(array("ok" => false, "error" => "Error del servidor"));
}
