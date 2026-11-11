<?php

/*=============================================
Agenda en formato .ics para suscribirse desde
Google Calendar, el calendario del iPhone u Outlook.
https://tudominio.com/ajax/calendar.php?token=...
(el token es secreto: se ve en Agenda → Google Calendar)
=============================================*/

require_once dirname(__DIR__)."/bootstrap.php";
require_once CMS_DIR."/controllers/business.calendar.php";

$token = (string)($_GET["token"] ?? "");
$valid = (string)(BizStore::get("gcal")["ics_token"] ?? "");

if($valid === "" || $token === "" || !hash_equals($valid, $token)){

	http_response_code(403);
	header("Content-Type: text/plain; charset=utf-8");
	echo "Enlace no válido.";
	exit;
}

header("Content-Type: text/calendar; charset=utf-8");
header("Content-Disposition: inline; filename=agenda.ics");
header("Cache-Control: no-cache, max-age=0");

echo BizCalendar::ics();
