<?php

/*=============================================
Arranque (configuración, errores, zona horaria)
=============================================*/

define('DIR', __DIR__);

require_once DIR."/bootstrap.php";

chdir(DIR);

/*=============================================
Requerimientos
=============================================*/

require_once DIR."/controllers/template.controller.php";
require_once DIR."/controllers/curl.controller.php";
require_once DIR."/controllers/install.controller.php";
require_once DIR."/extensions/vendor/autoload.php";

/*=============================================
Plantilla
=============================================*/

$index = new TemplateController();
$index -> index();
