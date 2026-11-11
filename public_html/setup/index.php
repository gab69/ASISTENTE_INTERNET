<?php

/*=============================================
ASISTENTE DE CONFIGURACIÓN WEB
https://tudominio.com/setup/
- Primera vez: configura la base de datos e instala
- Después: solo el superadministrador puede editar
=============================================*/

define("CMS_SETUP", true);

require_once dirname(__DIR__)."/bootstrap.php";

session_start();

header("Cache-Control: no-store");
header("X-Frame-Options: DENY");

$configFile = CMS_DIR."/config/config.php";
$sqlFile    = __DIR__."/database.sql";
$config     = $GLOBALS["CMS_CONFIG"];
$installed  = cms_installed();

/*=============================================
Funciones auxiliares
=============================================*/

function setup_e($value){
	return htmlspecialchars((string)$value, ENT_QUOTES, "UTF-8");
}

function setup_json($data){
	header("Content-Type: application/json; charset=utf-8");
	echo json_encode($data);
	exit;
}

/* Monedas disponibles: código => [símbolo, nombre] */

function setup_currencies(){

	return array(
		"PEN" => array("S/", "Soles peruanos (S/)"),
		"USD" => array("$", "Dólares estadounidenses ($)"),
		"EUR" => array("€", "Euros (€)"),
		"COP" => array("$", "Pesos colombianos ($)"),
		"MXN" => array("$", "Pesos mexicanos ($)"),
		"CLP" => array("$", "Pesos chilenos ($)"),
		"ARS" => array("$", "Pesos argentinos ($)"),
		"BOB" => array("Bs", "Bolivianos (Bs)")
	);

}

function setup_random($bytes = 24){
	return bin2hex(random_bytes($bytes));
}

function setup_connect($db){

	return new PDO(
		"mysql:host=".$db["db_host"].";port=".(int)$db["db_port"].";dbname=".$db["db_name"].";charset=utf8mb4",
		$db["db_user"],
		$db["db_pass"],
		array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 10)
	);

}

function setup_table_count(PDO $pdo){

	return (int)$pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()")->fetchColumn();

}

function setup_has_admins(PDO $pdo){

	return (int)$pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'admins'")->fetchColumn() > 0;

}

/*---------------------------------------------
Importar la base de datos incluida (setup/database.sql)
---------------------------------------------*/

function setup_import_sql(PDO $pdo, $file){

	$sql = file_get_contents($file);

	if($sql === false){
		throw new Exception("No se encontró el archivo setup/database.sql");
	}

	$sql = str_replace("\r\n", "\n", $sql);

	/* Quitar comentarios de línea del volcado */
	$sql = preg_replace('/^--.*$/m', "", $sql);

	/* Separar sentencias (phpMyAdmin escapa los saltos de línea dentro de los textos) */
	$statements = preg_split('/;\s*\n/', $sql);

	foreach ($statements as $statement) {

		$statement = trim($statement);

		if($statement === "" || preg_match('/^(START TRANSACTION|COMMIT)$/i', $statement)){
			continue;
		}

		$pdo->exec($statement);
	}

}

/*---------------------------------------------
Guardar config/config.php
---------------------------------------------*/

function setup_write_config($file, $values){

	$keys = array("db_host","db_port","db_name","db_user","db_pass","api_key","jwt_secret","password_salt","token_ttl",
	              "api_url","api_verify_ssl","api_timeout","public_tables","cors_origins",
	              "webhook_verify_token","webhook_log","graph_version","openai_model","timezone","debug",
	              "ai_provider","ai_api_key","ai_model","ai_prompt","ai_history","currency_code","currency_symbol",
	              "graph_base","notify_template","notify_template_lang",
	              "limit_client_per_min","limit_send_per_min","limit_ai_tokens_day","limit_ai_tokens_month","limit_ai_message","reply_wait_seconds","ai_error_message",
	              "ai_price_in","ai_price_out","wa_price_template",
	              "followup_enabled","followup_ask_min","followup_close_min","followup_ask_text","followup_close_text","cron_key");

	$data = array();

	foreach ($keys as $key) {
		if(array_key_exists($key, $values)){
			$data[$key] = $values[$key];
		}
	}

	$php = "<?php\n\n/*\n * Configuración de ChatCenter (CMS + API)\n * Generado por el asistente web el ".date("Y-m-d H:i:s")."\n * Edítalo desde https://tudominio.com/setup/\n */\n\nreturn ".var_export($data, true).";\n";

	if(!is_dir(dirname($file))){
		@mkdir(dirname($file), 0755, true);
	}

	if(@file_put_contents($file, $php, LOCK_EX) === false){
		throw new Exception("No se pudo escribir config/config.php. Da permisos de escritura (755) a la carpeta config/ desde el Administrador de archivos de cPanel.");
	}

	if(function_exists("opcache_invalidate")){
		@opcache_invalidate($file, true);
	}

}

/*---------------------------------------------
Probar que la API responde (petición HTTP real)
---------------------------------------------*/

function setup_test_api($config){

	$url = trim((string)$config["api_url"]);
	$url = ($url === "" ? cms_base_url()."api/" : rtrim($url, "/")."/")."admins?select=id_admin&startAt=0&endAt=1";

	$curl = curl_init($url);

	curl_setopt_array($curl, array(
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_CONNECTTIMEOUT => 8,
		CURLOPT_TIMEOUT => 15,
		CURLOPT_SSL_VERIFYPEER => (bool)$config["api_verify_ssl"],
		CURLOPT_SSL_VERIFYHOST => $config["api_verify_ssl"] ? 2 : 0,
		CURLOPT_HTTPHEADER => array("Authorization: ".$config["api_key"], "X-Api-Key: ".$config["api_key"])
	));

	$body = curl_exec($curl);
	$error = curl_error($curl);
	curl_close($curl);

	if($body === false){
		return array(false, "No se pudo conectar con la API (".$url."): ".$error);
	}

	$json = json_decode($body);

	if(isset($json->status) && in_array((int)$json->status, array(200, 404), true)){
		return array(true, "La API responde correctamente.");
	}

	return array(false, "La API respondió algo inesperado: ".substr(strip_tags((string)$body), 0, 200));

}

/*=============================================
Control de acceso
=============================================*/

$isSuperadmin = isset($_SESSION["admin"]->rol_admin) && $_SESSION["admin"]->rol_admin === "superadmin";

if($installed && !$isSuperadmin){

	http_response_code(403);
	$view = "denied";

}else{

	$view = "form";
}

if(empty($_SESSION["setup_csrf"])){
	$_SESSION["setup_csrf"] = setup_random(16);
}

$csrf = $_SESSION["setup_csrf"];

/*=============================================
Acciones (solo si tiene acceso)
=============================================*/

$errors = array();
$notices = array();
$done = false;

if($view === "form" && $_SERVER["REQUEST_METHOD"] === "POST"){

	if(!hash_equals($csrf, (string)($_POST["csrf"] ?? ""))){

		if(($_POST["action"] ?? "") === "test_db"){
			setup_json(array("ok" => false, "message" => "La sesión expiró, recarga la página."));
		}

		$errors[] = "La sesión expiró, recarga la página e inténtalo de nuevo.";

	}else{

		/*---------------------------------------------
		Datos de base de datos enviados
		(contraseña vacía en modo edición = conservar la actual)
		---------------------------------------------*/

		$db = array(
			"db_host" => trim($_POST["db_host"] ?? "localhost") ?: "localhost",
			"db_port" => (int)($_POST["db_port"] ?? 3306) ?: 3306,
			"db_name" => trim($_POST["db_name"] ?? ""),
			"db_user" => trim($_POST["db_user"] ?? ""),
			"db_pass" => (string)($_POST["db_pass"] ?? "")
		);

		if($installed && $db["db_pass"] === "" && empty($_POST["db_pass_empty"])){
			$db["db_pass"] = $config["db_pass"];
		}

		/* En cPanel la base siempre tiene contraseña: no guardarla vacía por descuido */

		$missingPass = !$installed && $db["db_pass"] === "" && empty($_POST["db_pass_empty"]);

		if($missingPass && ($_POST["action"] ?? "") === "test_db"){
			setup_json(array("ok" => false, "message" => "Escribe la contraseña del usuario de la base de datos (la que creaste en cPanel → Bases de datos MySQL)."));
		}

		/*---------------------------------------------
		Probar conexión (AJAX)
		---------------------------------------------*/

		if(($_POST["action"] ?? "") === "test_db"){

			try{

				$pdo = setup_connect($db);
				$tables = setup_table_count($pdo);

				setup_json(array(
					"ok" => true,
					"tables" => $tables,
					"message" => $tables > 0
						? "Conexión correcta. La base de datos ya tiene ".$tables." tablas."
						: "Conexión correcta. La base de datos está vacía."
				));

			}catch(Exception $e){

				setup_json(array("ok" => false, "message" => "No se pudo conectar: ".$e->getMessage()));
			}
		}

		/*---------------------------------------------
		Probar la IA (AJAX)
		---------------------------------------------*/

		/*---------------------------------------------
		Menú del panel: orden, nombre, ícono y visibilidad
		---------------------------------------------*/

		if($installed && in_array($_POST["action"] ?? "", array("menu_save", "menu_delete"), true)){

			require_once CMS_DIR."/controllers/schema.php";

			$pdo = cms_schema_pdo();

			if($_POST["action"] === "menu_save"){

				$items = json_decode((string)($_POST["items"] ?? ""), true);

				if(!is_array($items) || empty($items)){
					setup_json(array("ok" => false, "message" => "No llegó la lista del menú."));
				}

				$visible = 0;

				foreach ($items as $it) {
					$visible += !empty($it["visible"]) ? 1 : 0;
				}

				if($visible === 0){
					setup_json(array("ok" => false, "message" => "Deja al menos una página visible en el menú."));
				}

				$stmt = $pdo->prepare("UPDATE `pages` SET `title_page` = ?, `icon_page` = ?, `visible_page` = ?, `order_page` = ? WHERE `id_page` = ?");

				foreach (array_values($items) as $i => $it) {

					$title = mb_substr(trim(strip_tags((string)($it["title"] ?? ""))), 0, 60);
					$icon = trim(preg_replace('/\s+/', " ", (string)($it["icon"] ?? "")));

					if($title === ""){
						setup_json(array("ok" => false, "message" => "Cada página del menú necesita un nombre."));
					}

					if(!preg_match('/^(bi|fa[srlbd]?|fas|far|fab) [a-z0-9 -]{2,60}$/i', $icon)){
						$icon = "bi bi-file-earmark-text";
					}

					$stmt->execute(array($title, $icon, !empty($it["visible"]) ? 1 : 0, $i + 1, (int)($it["id"] ?? 0)));
				}

				setup_json(array("ok" => true, "message" => "Menú guardado. Recarga el panel para verlo."));
			}

			/* Borrar: las páginas base y las que tienen módulos no se borran */

			$id = (int)($_POST["id"] ?? 0);
			$page = $pdo->prepare("SELECT `url_page` FROM `pages` WHERE `id_page` = ?");
			$page->execute(array($id));
			$url = (string)$page->fetchColumn();

			if($url === ""){
				setup_json(array("ok" => false, "message" => "La página ya no existe."));
			}

			if(in_array($id, array(1, 2), true) || in_array($url, array("chat", "admins", "inicio", "ordenes", "inconvenientes", "negocio"), true)){
				setup_json(array("ok" => false, "message" => "Esta página es parte del sistema: puedes ocultarla, pero no borrarla."));
			}

			$mods = $pdo->prepare("SELECT COUNT(*) FROM `modules` WHERE `id_page_module` = ?");
			$mods->execute(array($id));

			if((int)$mods->fetchColumn() > 0){
				setup_json(array("ok" => false, "message" => "No se puede borrar: la página tiene módulos (tablas). Ocúltala del menú o borra primero sus módulos."));
			}

			$pdo->prepare("DELETE FROM `pages` WHERE `id_page` = ?")->execute(array($id));

			setup_json(array("ok" => true, "message" => "Página borrada del menú."));
		}

		if(($_POST["action"] ?? "") === "test_ai"){

			require_once CMS_DIR."/controllers/ai.service.php";

			$provider = in_array($_POST["ai_provider"] ?? "", array("openai", "anthropic"), true) ? $_POST["ai_provider"] : "openai";
			$key = trim((string)($_POST["ai_api_key"] ?? ""));

			if($key === "" && $provider === ($config["ai_provider"] ?? "")){
				$key = (string)$config["ai_api_key"];
			}

			if($key === ""){
				setup_json(array("ok" => false, "message" => "Escribe la llave (API key) para probar."));
			}

			$model = trim((string)($_POST["ai_model"] ?? "")) ?: ($provider === "anthropic" ? "claude-opus-5-5" : "gpt-4o-mini");

			$result = AIService::reply(
				array(array("role" => "user", "content" => "Hola, ¿estás funcionando?")),
				"Responde en español con una sola frase corta.",
				array("provider" => $provider, "key" => $key, "model" => $model)
			);

			setup_json($result["ok"]
				? array("ok" => true, "message" => "Funciona. Respuesta de prueba: ".mb_substr($result["text"], 0, 160))
				: array("ok" => false, "message" => $result["error"]));
		}

		/*---------------------------------------------
		Guardar configuración
		---------------------------------------------*/

		if(($_POST["action"] ?? "") === "save"){

			$new = $config;
			unset($new["installed"]);

			$new = array_merge($new, $db);

			$new["webhook_verify_token"] = trim($_POST["webhook_verify_token"] ?? "") ?: setup_random(8);
			$new["graph_version"]        = trim($_POST["graph_version"] ?? "") ?: "v22.0";
			$new["ai_provider"]          = in_array($_POST["ai_provider"] ?? "", array("openai", "anthropic", "none"), true) ? $_POST["ai_provider"] : "openai";
			$new["ai_model"]             = trim($_POST["ai_model"] ?? "");
			$new["ai_prompt"]            = trim(str_replace("\r\n", "\n", (string)($_POST["ai_prompt"] ?? "")));
			$new["ai_history"]           = max(2, min(60, (int)($_POST["ai_history"] ?? 20)));

			/* Llave de IA: vacío = conservar la actual (si no cambió el proveedor) */

			$aiKey = trim((string)($_POST["ai_api_key"] ?? ""));

			if($aiKey !== ""){
				$new["ai_api_key"] = $aiKey;
			}else if(!empty($_POST["ai_key_clear"]) || ($config["ai_provider"] ?? "openai") !== $new["ai_provider"]){
				$new["ai_api_key"] = "";
			}

			if($new["ai_provider"] === "openai" && $new["ai_model"] !== ""){
				$new["openai_model"] = $new["ai_model"];
			}
			$new["timezone"]             = in_array($_POST["timezone"] ?? "", timezone_identifiers_list(), true) ? $_POST["timezone"] : "America/Lima";

				/* Moneda (Soles por defecto) */

				$currencies = setup_currencies();
				$new["currency_code"]        = isset($currencies[$_POST["currency_code"] ?? ""]) ? $_POST["currency_code"] : "PEN";
				$symbol                      = trim(strip_tags((string)($_POST["currency_symbol"] ?? "")));
				$new["currency_symbol"]      = $symbol !== "" ? mb_substr($symbol, 0, 6) : $currencies[$new["currency_code"]][0];
			/* Plantilla de avisos: ahora se configura SOLO en Mi negocio › Números por área.
			   Se conserva el valor antiguo solo para migrarlo. */

			$new["notify_template"]      = (string)($config["notify_template"] ?? "");
			$new["notify_template_lang"] = (string)($config["notify_template_lang"] ?? "es");

			/* Límites y consumo */

			$int = function($key, $max){ return max(0, min($max, (int)preg_replace('/\D/', '', (string)($_POST[$key] ?? 0)))); };
			$money = function($key){ return max(0, min(1000, round((float)str_replace(",", ".", (string)($_POST[$key] ?? 0)), 4))); };

			$new["limit_client_per_min"]  = $int("limit_client_per_min", 1000);
			$new["limit_send_per_min"]    = $int("limit_send_per_min", 100000);
			$new["limit_ai_tokens_day"]   = $int("limit_ai_tokens_day", 2000000000);
			$new["limit_ai_tokens_month"] = $int("limit_ai_tokens_month", 2000000000);
			$new["limit_ai_message"]      = mb_substr(trim(strip_tags((string)($_POST["limit_ai_message"] ?? ""))), 0, 500) ?: "En este momento un asesor continuará la atención. ¡Gracias por tu paciencia! 🙌";
			$new["reply_wait_seconds"]    = min(15, $int("reply_wait_seconds", 15));
			$new["ai_error_message"]      = mb_substr(trim(strip_tags((string)($_POST["ai_error_message"] ?? ""))), 0, 500) ?: "Disculpa, tuve un pequeño inconveniente 🙏 ¿Me repites tu último mensaje, por favor? Si prefieres, un asesor te escribe en breve.";
			$new["ai_price_in"]           = $money("ai_price_in");
			$new["ai_price_out"]          = $money("ai_price_out");
			$new["wa_price_template"]     = $money("wa_price_template");

			$new["followup_enabled"]      = !empty($_POST["followup_enabled"]);
			$new["followup_ask_min"]      = max(1, min(1440, $int("followup_ask_min", 1440)));
			$new["followup_close_min"]    = max(1, min(1440, $int("followup_close_min", 1440)));
			$new["followup_ask_text"]     = mb_substr(trim(strip_tags((string)($_POST["followup_ask_text"] ?? ""))), 0, 500) ?: "¿Te puedo ayudar en algo más? 😊";
			$new["followup_close_text"]   = mb_substr(trim(strip_tags((string)($_POST["followup_close_text"] ?? ""))), 0, 500) ?: "¡Gracias por tu preferencia! 🙌";

			if(empty($new["cron_key"]) || !empty($_POST["regenerate_keys"])){
				$new["cron_key"] = setup_random(16);
			}

			$new["api_url"]              = trim($_POST["api_url"] ?? "");
			$new["api_verify_ssl"]       = !empty($_POST["api_verify_ssl"]);
			$new["webhook_log"]          = !empty($_POST["webhook_log"]);
			$new["debug"]                = !empty($_POST["debug"]);

			if($new["api_url"] !== "" && !filter_var($new["api_url"], FILTER_VALIDATE_URL)){
				$errors[] = "La URL de la API no es válida. Déjala vacía para usar la automática.";
			}

			if(!preg_match('/^v\d+\.\d+$/', $new["graph_version"])){
				$errors[] = "La versión de Graph API debe tener el formato v22.0";
			}

			if($db["db_name"] === "" || $db["db_user"] === ""){
				$errors[] = "El nombre de la base de datos y el usuario son obligatorios.";
			}

			/* Llaves de seguridad: se generan solas */

			if(empty($new["api_key"]) || !empty($_POST["regenerate_keys"])){
				$new["api_key"] = setup_random(24);
				$new["jwt_secret"] = setup_random(32);
			}

			if(empty($new["jwt_secret"])){
				$new["jwt_secret"] = setup_random(32);
			}

			$mode = $_POST["install_mode"] ?? "keep";

			if($missingPass){
				$errors[] = "Escribe la contraseña de la base de datos. Si de verdad no usa contraseña, marca la casilla debajo del campo.";
			}

			/* Reinstalar: borra todas las tablas y deja los datos de ejemplo */

			$reset = !$installed && in_array($mode, array("reset", "reset_clean"), true);
			$confirm = $mode === "reset_clean" ? ($_POST["reset_clean_confirm"] ?? "") : ($_POST["reset_confirm"] ?? "");

			if($reset && strtoupper(trim((string)$confirm)) !== "BORRAR"){
				$errors[] = "Para reinstalar desde cero escribe BORRAR en la casilla de confirmación.";
			}

			/* Reinstalar limpio: sin empresa, sucursales ni conversaciones de ejemplo */

			if($reset){
				$mode = $mode === "reset_clean" ? "clean" : "demo";
			}

			if(!$installed && in_array($mode, array("clean", "demo"), true)){

				$adminEmail = trim($_POST["admin_email"] ?? "");
				$adminPass  = (string)($_POST["admin_password"] ?? "");

				if(!filter_var($adminEmail, FILTER_VALIDATE_EMAIL)){
					$errors[] = "Escribe un correo válido para el superadministrador.";
				}

				if(strlen($adminPass) < 8){
					$errors[] = "La contraseña del superadministrador debe tener al menos 8 caracteres.";
				}
			}

			if(empty($errors)){

				try{

					$pdo = setup_connect($db);

					/*---------------------------------------------
					Primera instalación: importar la base incluida
					---------------------------------------------*/

					if(!$installed && in_array($mode, array("clean", "demo"), true)){

						if($reset){

							$pdo->exec("SET FOREIGN_KEY_CHECKS = 0");

							foreach ($pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN) as $table) {
								$pdo->exec("DROP TABLE `".str_replace("`", "", $table)."`");
							}

							$pdo->exec("SET FOREIGN_KEY_CHECKS = 1");
							@unlink(CMS_DIR."/config/schema.lock");
						}

						if(setup_has_admins($pdo)){

							throw new Exception("La base de datos ya tiene las tablas del sistema. Elige \"Conservar la base de datos actual\" o usa una base vacía.");
						}

						setup_import_sql($pdo, $sqlFile);

						/* Corregir URLs del entorno local */

						$base = rtrim(cms_base_url(), "/");

						foreach (array(array("folders","url_folder"), array("files","link_file"), array("admins","back_admin")) as $item) {

							$stmt = $pdo->prepare("UPDATE ".$item[0]." SET ".$item[1]." = REPLACE(".$item[1].", :old, :new)");
							$stmt->execute(array(":old" => "http://cms-chatcenter.com", ":new" => $base));
						}

						/* Datos limpios: sin conversaciones, contactos, pedidos ni usuarios de prueba */

						$superId = (int)$pdo->query("SELECT id_admin FROM admins WHERE rol_admin = 'superadmin' ORDER BY id_admin ASC LIMIT 1")->fetchColumn();

						if($mode === "clean"){

							$pdo->exec("DELETE FROM messages");
							$pdo->exec("DELETE FROM contacts");
							$pdo->exec("DELETE FROM orders");
							$pdo->exec("ALTER TABLE messages AUTO_INCREMENT = 1");
							$pdo->exec("ALTER TABLE contacts AUTO_INCREMENT = 1");
							$pdo->exec("ALTER TABLE orders AUTO_INCREMENT = 1");
						}

						/* Nunca dejar usuarios de prueba con contraseñas conocidas */

						$stmt = $pdo->prepare("DELETE FROM admins WHERE id_admin <> :id");
						$stmt->execute(array(":id" => $superId));

						/* Credenciales del superadministrador */

						$hash = crypt($adminPass, $new["password_salt"]);

						$stmt = $pdo->prepare("UPDATE admins SET email_admin = :email, password_admin = :pass WHERE id_admin = :id");
						$stmt->execute(array(":email" => $adminEmail, ":pass" => $hash, ":id" => $superId));

						$pdo->exec("UPDATE admins SET token_admin = NULL, token_exp_admin = NULL");

						$notices[] = "Base de datos importada correctamente.";

					}

					/*---------------------------------------------
					Guardar archivo de configuración
					---------------------------------------------*/

					setup_write_config($configFile, $new);

					$GLOBALS["CMS_CONFIG"] = require CMS_DIR."/config/loader.php";
					$config = $GLOBALS["CMS_CONFIG"];

					$notices[] = "Configuración guardada.";

					/* Actualizaciones de la base de datos (columnas e índices nuevos) */

					require_once CMS_DIR."/controllers/schema.php";

					if(!cms_schema_check(true)){
						$errors[] = "No se pudieron aplicar las actualizaciones de la base de datos. Revisa logs/php_error.log";

					}else if(isset($superId)){

						/* Primera instalación: internet y TV (y datos de ejemplo si se eligió) */

						require_once __DIR__."/demo.php";

						setup_seed($pdo, $mode);

						if($mode === "demo"){
							$notices[] = "Datos de ejemplo creados: planes de internet, 3 sucursales y conversaciones de prueba (una con la instalación agendada).";
						}
					}

					/* Si se regeneraron las llaves, cerrar la sesión actual */

					if(!empty($_POST["regenerate_keys"])){
						$notices[] = "Llaves regeneradas.";
					}

					/* Comprobar la API */

					list($apiOk, $apiMessage) = setup_test_api($config);

					if($apiOk){
						$notices[] = $apiMessage;
					}else if(trim((string)$config["api_url"]) === ""){
						/* El panel usa la API interna: esto solo afecta a aplicaciones externas (Postman) */
						$notices[] = "El panel usa la API interna y funciona. Aviso: la API pública /api/ no respondió por HTTP (solo afecta a aplicaciones externas): ".$apiMessage;
					}else{
						$errors[] = $apiMessage." Revisa la sección \"Avanzado\" (URL de la API / verificación SSL).";
					}

					$done = true;
					$installed = true;

				}catch(Exception $e){

					error_log("Setup error: ".$e->getMessage());
					$errors[] = $e->getMessage();
				}
			}
		}
	}
}

/*=============================================
Valores del formulario
=============================================*/

$v = array_merge($config, array_intersect_key($_POST, array_flip(array(
	"db_host","db_port","db_name","db_user","webhook_verify_token","graph_version","timezone","api_url","admin_email","ai_provider","ai_model","ai_prompt","ai_history","currency_code","currency_symbol"
))));

if(empty($v["webhook_verify_token"])){
	$v["webhook_verify_token"] = setup_random(8);
}

$configWritable = is_writable(CMS_DIR."/config");
$sqlAvailable = is_file($sqlFile);

/*=============================================
Color de marca del panel (si ya está instalado)
=============================================*/

$brandColor = "#4f46e5";

if($installed){

	try{

		require_once CMS_DIR."/controllers/schema.php";

		$color = (string)cms_schema_pdo()->query("SELECT color_admin FROM admins WHERE rol_admin = 'superadmin' ORDER BY id_admin ASC LIMIT 1")->fetchColumn();

		if(preg_match('/^#[0-9a-f]{3,8}$/i', $color)){

			$brandColor = $color;
		}

	}catch(Throwable $e){}
}


$extensions = array("pdo_mysql" => "MySQL (PDO)", "curl" => "cURL", "mbstring" => "mbstring", "openssl" => "OpenSSL", "intl" => "intl");
$missingExt = array_filter(array_keys($extensions), function($ext){ return !extension_loaded($ext); });

/*=============================================
Estado de cada módulo (para el menú)
=============================================*/

$aiProvider = $v["ai_provider"] ?? "openai";
$aiNames = array("openai" => "ChatGPT", "anthropic" => "Claude", "none" => "Desactivado");
$currencies = setup_currencies();
$currencyCode = isset($currencies[$v["currency_code"] ?? ""]) ? $v["currency_code"] : "PEN";

$waStatus = array("warn", "Falta conectar el número");

if($installed){

	try{

		require_once CMS_DIR."/controllers/schema.php";

		$wa = cms_schema_pdo()->query("SELECT number_whatsapp, id_number_whatsapp, token_whatsapp FROM whatsapps WHERE status_whatsapp = 1 ORDER BY id_whatsapp DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);

		if($wa && !empty($wa["id_number_whatsapp"]) && !empty($wa["token_whatsapp"])){

			$waStatus = array("ok", "Número conectado".(!empty($wa["number_whatsapp"]) ? " · ".$wa["number_whatsapp"] : ""));
		}

	}catch(Throwable $e){}
}

$modules = array();

$modules["db"] = array(
	"icon" => "bi-database", "title" => "Base de datos",
	"desc" => "Conexión a MySQL",
	"status" => $installed ? array("ok", "Conectada") : array("warn", "Sin configurar")
);

if(!$installed){

	$modules["install"] = array(
		"icon" => "bi-box-seam", "title" => "Instalación",
		"desc" => "Datos iniciales y superadministrador",
		"status" => array("warn", "Pendiente")
	);
}

$modules["whatsapp"] = array(
	"icon" => "bi-whatsapp", "title" => "WhatsApp",
	"desc" => "Webhook de Meta",
	"status" => $waStatus
);

$modules["ai"] = array(
	"icon" => "bi-robot", "title" => "Asistente de IA",
	"desc" => "ChatGPT o Claude",
	"status" => $aiProvider === "none" ? array("off", "Desactivado") : (empty($config["ai_api_key"]) ? array("warn", "Falta la llave") : array("ok", $aiNames[$aiProvider]." activo"))
);

$modules["general"] = array(
	"icon" => "bi-sliders", "title" => "General",
	"desc" => "Moneda y zona horaria",
	"status" => array("ok", ($v["currency_symbol"] ?? "S/")." · ".str_replace("_", " ", preg_replace('~^.*/~', "", (string)$v["timezone"])))
);

$limitsOn = (int)($config["limit_ai_tokens_month"] ?? 0) > 0 || (int)($config["limit_ai_tokens_day"] ?? 0) > 0;

$modules["limits"] = array(
	"icon" => "bi-speedometer", "title" => "Límites y consumo",
	"desc" => "Tokens, mensajes por minuto y cierre de chats",
	"status" => array("ok", ($limitsOn ? "Tope de tokens activo" : "Sin tope de tokens")." · ".(int)($config["limit_send_per_min"] ?? 60)."/min")
);

if($installed){

	$menuPages = array();

	try{

		require_once CMS_DIR."/controllers/schema.php";
		$menuPages = cms_schema_pdo()->query("SELECT * FROM `pages` ORDER BY `order_page` ASC, `id_page` ASC")->fetchAll(PDO::FETCH_ASSOC);

	}catch(Throwable $e){}

	$shown = count(array_filter($menuPages, function($p){ return (int)($p["visible_page"] ?? 1) === 1; }));

	$modules["menu"] = array(
		"icon" => "bi-list-ul", "title" => "Menú",
		"desc" => "Orden, nombres, íconos y páginas visibles",
		"status" => array("ok", $shown." de ".count($menuPages)." visibles")
	);
}

$modules["advanced"] = array(
	"icon" => "bi-gear", "title" => "Avanzado",
	"desc" => "API, seguridad y diagnóstico",
	"status" => !empty($config["debug"]) ? array("warn", "Depuración activa") : array("ok", "Automático")
);

/* Módulo abierto: el que se estaba editando o el primero con errores */

$activeModule = isset($modules[$_POST["module"] ?? ""]) ? $_POST["module"] : "db";

?>
<!DOCTYPE html>
<html lang="es">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<meta name="robots" content="noindex, nofollow">
	<title><?php echo $installed ? "Configuración del sistema" : "Instalación" ?> · ChatCenter</title>
	<link rel="preconnect" href="https://fonts.googleapis.com">
	<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
	<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
	<link rel="stylesheet" href="/views/assets/plugins/bootstrap5/bootstrap.min.css">
	<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap-icons/1.10.3/font/bootstrap-icons.min.css">
	<link rel="stylesheet" href="/views/assets/css/modern/modern.css?v=4">
	<style>
		:root{ --brand: <?php echo setup_e($brandColor) ?>; }
		body{ min-height: 100vh; }

		.setup-top{ position: sticky; top: 0; z-index: 20; display: flex; align-items: center; gap: 14px; padding: 12px 24px; background: rgba(255,255,255,.9); backdrop-filter: blur(8px); border-bottom: 1px solid var(--line); }
		.setup-logo{ width: 38px; height: 38px; border-radius: 11px; background: var(--brand); color: #fff; display: inline-flex; align-items: center; justify-content: center; font-size: 1.15rem; }
		.setup-top h1{ font-size: 1.05rem; font-weight: 700; margin: 0; }
		.setup-top small{ color: var(--muted); display: block; font-size: 12px; }

		.setup-shell{ display: grid; grid-template-columns: 280px minmax(0, 1fr); gap: 28px; max-width: 1180px; margin: 0 auto; padding: 28px 24px 120px; }

		/* Menú de módulos */
		.mod-nav{ position: sticky; top: 86px; align-self: start; display: grid; gap: 6px; }
		.mod-link{ display: flex; align-items: center; gap: 12px; padding: 12px; border-radius: 14px; border: 1px solid transparent; background: transparent; text-align: left; color: var(--ink); width: 100%; transition: all .15s; }
		.mod-link:hover{ background: #eef0f5; }
		.mod-link.active{ background: var(--surface); border-color: var(--line); box-shadow: var(--shadow); }
		.mod-ico{ width: 40px; height: 40px; border-radius: 11px; background: #eef0f5; color: var(--ink-2); display: inline-flex; align-items: center; justify-content: center; font-size: 1.15rem; flex-shrink: 0; position: relative; }
		.mod-link.active .mod-ico{ background: var(--brand); color: #fff; }
		.mod-txt{ flex: 1; min-width: 0; display: grid; }
		.mod-txt strong{ font-size: 14px; }
		.mod-txt small{ font-size: 12px; color: var(--muted); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
		.mod-dot{ width: 9px; height: 9px; border-radius: 50%; flex-shrink: 0; }
		.mod-dot.ok{ background: var(--ok); }
		.mod-dot.warn{ background: var(--warn); }
		.mod-dot.off{ background: #cbd5e1; }
		.mod-dirty{ position: absolute; top: -3px; right: -3px; width: 11px; height: 11px; border-radius: 50%; background: var(--warn); border: 2px solid var(--bg); display: none; }
		.mod-link.dirty .mod-dirty{ display: block; }
		.nav-foot{ margin-top: 14px; padding: 14px 6px 0; border-top: 1px solid var(--line); font-size: 12.5px; color: var(--muted); }
		.nav-foot div{ display: flex; justify-content: space-between; padding: 3px 4px; }

		/* Módulo */
		.mod{ display: none; }
		.mod.active{ display: block; animation: modIn .2s ease; }
		@keyframes modIn{ from{ opacity: 0; transform: translateY(6px); } to{ opacity: 1; transform: none; } }
		.mod-head{ display: flex; gap: 16px; align-items: flex-start; margin-bottom: 20px; }
		.mod-head .mod-ico{ width: 52px; height: 52px; border-radius: 14px; font-size: 1.5rem; background: var(--brand-soft); color: var(--brand); }
		.mod-head h2{ font-size: 1.35rem; font-weight: 700; margin: 0 0 4px; display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
		.mod-head p{ margin: 0; color: var(--ink-2); }
		.mod-badge{ font-size: 12px; font-weight: 600; padding: 3px 10px; border-radius: 999px; }
		.mod-badge.ok{ background: #ecfdf5; color: #047857; }
		.mod-badge.warn{ background: #fffbeb; color: #b45309; }
		.mod-badge.off{ background: #f1f5f9; color: #64748b; }
		.block{ margin-bottom: 18px; }
		.block-title{ font-size: 13px; font-weight: 700; text-transform: uppercase; letter-spacing: .06em; color: var(--muted); margin-bottom: 14px; display: flex; align-items: center; gap: 8px; }
		.menu-list{ display: grid; gap: 8px; }
		.menu-row{ display: flex; align-items: center; gap: 10px; padding: 8px 10px; border: 1px solid var(--line, #e5e7eb); border-radius: 12px; background: #fff; }
		.menu-row.off{ opacity: .55; background: #f8fafc; }
		.menu-ico{ width: 34px; height: 34px; border-radius: 10px; display: inline-flex; align-items: center; justify-content: center; background: #eef2ff; color: #4f46e5; flex-shrink: 0; }
		.menu-fields{ flex: 1; display: grid; grid-template-columns: minmax(0, 1.2fr) minmax(0, 1fr) auto; gap: 8px; align-items: center; min-width: 0; }
		.menu-fields small{ white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 140px; }
		.menu-actions{ display: flex; align-items: center; gap: 2px; flex-shrink: 0; }
		@media (max-width: 640px){
			.menu-row{ flex-wrap: wrap; }
			.menu-fields{ grid-template-columns: 1fr; flex-basis: calc(100% - 50px); }
			.menu-actions{ width: 100%; justify-content: flex-end; }
		}
		.tpl-sample{ background: #f8fafc; border: 1px dashed var(--line, #e5e7eb); border-radius: 10px; padding: 10px 12px; font-size: 13px; white-space: pre-wrap; margin-bottom: 12px; }
		.mod-foot{ display: flex; justify-content: space-between; gap: 10px; margin-top: 6px; }

		.mode-card{ display: flex; gap: 12px; padding: 14px; border: 1.5px solid var(--line); border-radius: 12px; cursor: pointer; margin-bottom: 10px; background: var(--surface); }
		.mode-card:has(input:checked){ border-color: var(--brand); background: var(--brand-soft); }
		.mode-card input{ margin-top: 3px; accent-color: var(--brand); }
		.mode-card strong{ display: block; }
		.mode-card span:not(.badge){ font-size: 13px; color: var(--ink-2); }
		.provider-cards{ display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap: 10px; }
		.provider-cards .mode-card{ margin: 0; }
		.copy-field{ display: flex; gap: 6px; }
		.copy-field code, .copy-field input{ flex: 1; }
		.money-preview{ display: flex; align-items: center; justify-content: space-between; gap: 10px; padding: 14px 16px; border-radius: 12px; background: #f8fafc; border: 1px dashed var(--line); }
		.money-preview strong{ font-size: 1.4rem; font-variant-numeric: tabular-nums; }

		.test-result{ font-size: 13px; padding: 8px 12px; border-radius: 10px; margin-top: 10px; display: none; }
		.test-result.show{ display: block; }
		.test-result.ok{ background: #ecfdf5; color: #047857; }
		.test-result.bad{ background: #fef2f2; color: #b91c1c; }
		.test-result.wait{ background: #f1f5f9; color: var(--ink-2); }

		.save-bar{ position: fixed; left: 0; right: 0; bottom: 0; z-index: 30; background: rgba(255,255,255,.95); backdrop-filter: blur(8px); border-top: 1px solid var(--line); }
		.save-bar-inner{ max-width: 1180px; margin: 0 auto; padding: 12px 24px; display: flex; align-items: center; gap: 12px; }
		.save-bar .hint{ color: var(--muted); font-size: 13px; }
		.setup-alert{ display: flex; gap: 10px; align-items: flex-start; padding: 12px 14px; border-radius: 12px; margin-bottom: 14px; font-size: 14px; }
		.setup-alert.error{ background: #fef2f2; color: #b91c1c; border: 1px solid #fecaca; }
		.setup-alert.success{ background: #ecfdf5; color: #047857; border: 1px solid #a7f3d0; }
		.setup-alert.warn{ background: #fffbeb; color: #92400e; border: 1px solid #fde68a; }
		.done-card{ text-align: center; padding: 28px 20px; }
		.done-card .big{ width: 60px; height: 60px; border-radius: 50%; background: #ecfdf5; color: #059669; display: inline-flex; align-items: center; justify-content: center; font-size: 1.9rem; margin-bottom: 10px; }
		.denied{ max-width: 460px; margin: 10vh auto; text-align: center; }

		@media (max-width: 991.98px){
			.setup-shell{ grid-template-columns: 1fr; padding-top: 12px; gap: 16px; }
			.mod-nav{ position: sticky; top: 63px; z-index: 10; display: flex; overflow-x: auto; gap: 6px; background: var(--bg); padding: 8px 16px; margin: 0 -24px; }
			.mod-link{ width: auto; flex-shrink: 0; padding: 8px 12px; }
			.mod-link .mod-ico{ width: 30px; height: 30px; font-size: .95rem; border-radius: 9px; }
			.mod-txt small, .mod-dot{ display: none; }
			.nav-foot{ display: none; }
			.mod-head .mod-ico{ display: none; }
		}
	</style>
</head>
<body>

<header class="setup-top">
	<span class="setup-logo"><i class="bi bi-chat-dots-fill"></i></span>
	<div>
		<h1><?php echo $installed ? "Configuración del sistema" : "Instalación de ChatCenter" ?></h1>
		<small><?php echo $installed ? "Cada módulo se configura por separado" : "Completa cada módulo para dejar tu panel listo" ?></small>
	</div>
	<?php if ($installed && $view !== "denied"): ?>
		<a href="/" class="btn btn-ghost btn-sm ms-auto"><i class="bi bi-arrow-left"></i> <span class="d-none d-sm-inline">Volver al panel</span></a>
	<?php endif ?>
</header>

<?php if ($view === "denied"): ?>

	<div class="denied px-3">
		<div class="card-soft">
			<div class="mod-ico mb-3" style="margin:0 auto;background:var(--brand-soft);color:var(--brand)"><i class="bi bi-shield-lock"></i></div>
			<h2 class="h5 fw-bold">Acceso restringido</h2>
			<p class="text-muted">Inicia sesión en el panel como <strong>superadministrador</strong> para cambiar la configuración.</p>
			<a href="/" class="btn btn-brand px-4">Ir al panel</a>
			<p class="small text-muted mt-4 mb-0">¿Perdiste el acceso? Desde el Administrador de archivos de cPanel elimina <code>config/config.php</code> y vuelve a abrir esta página.</p>
		</div>
	</div>

<?php else: ?>

<div class="setup-shell">

	<!-- Menú de módulos -->

	<nav class="mod-nav" id="modNav" role="tablist">
		<?php foreach ($modules as $key => $m): ?>
			<button type="button" class="mod-link <?php echo $key === $activeModule ? "active" : "" ?>" data-module="<?php echo $key ?>">
				<span class="mod-ico"><i class="bi <?php echo $m["icon"] ?>"></i><span class="mod-dirty" title="Cambios sin guardar"></span></span>
				<span class="mod-txt"><strong><?php echo $m["title"] ?></strong><small><?php echo setup_e($m["status"][1]) ?></small></span>
				<span class="mod-dot <?php echo $m["status"][0] ?>"></span>
			</button>
		<?php endforeach ?>
		<div class="nav-foot">
			<div><span>PHP</span><strong><?php echo PHP_VERSION ?></strong></div>
			<?php foreach ($extensions as $ext => $label): ?>
				<div><span><?php echo $label ?></span><?php echo extension_loaded($ext) ? '<i class="bi bi-check-circle-fill text-success"></i>' : '<i class="bi bi-x-circle-fill text-danger"></i>' ?></div>
			<?php endforeach ?>
		</div>
	</nav>

	<main>

		<?php foreach ($errors as $error): ?>
			<div class="setup-alert error"><i class="bi bi-exclamation-triangle"></i> <span><?php echo setup_e($error) ?></span></div>
		<?php endforeach ?>

		<?php foreach ($notices as $notice): ?>
			<div class="setup-alert success"><i class="bi bi-check-circle"></i> <span><?php echo setup_e($notice) ?></span></div>
		<?php endforeach ?>

		<?php if (!empty($missingExt)): ?>
			<div class="setup-alert warn"><i class="bi bi-puzzle"></i> <span>Faltan extensiones de PHP: <strong><?php echo setup_e(implode(", ", $missingExt)) ?></strong>. Actívalas en cPanel → <em>Seleccionar versión de PHP</em>.</span></div>
		<?php endif ?>

		<?php if (!$configWritable): ?>
			<div class="setup-alert warn"><i class="bi bi-folder-x"></i> <span>La carpeta <code>config/</code> no tiene permisos de escritura. En cPanel → Administrador de archivos, asígnale permisos <strong>755</strong>.</span></div>
		<?php endif ?>

		<?php if ($done): ?>
			<div class="card-soft done-card mb-4">
				<div class="big"><i class="bi bi-check-lg"></i></div>
				<h2 class="h5 fw-bold">¡Listo! La configuración quedó guardada</h2>
				<p class="text-muted mb-3">Ya puedes entrar al panel.</p>
				<a href="/" class="btn btn-brand px-4">Ir al panel</a>
			</div>
		<?php endif ?>

		<form method="post" id="setupForm" autocomplete="off">

			<input type="hidden" name="csrf" value="<?php echo setup_e($csrf) ?>">
			<input type="hidden" name="action" value="save">
			<input type="hidden" name="module" id="moduleInput" value="<?php echo setup_e($activeModule) ?>">

			<!-- ================= BASE DE DATOS ================= -->

			<section class="mod <?php echo $activeModule === "db" ? "active" : "" ?>" id="mod-db" data-module="db">

				<div class="mod-head">
					<span class="mod-ico"><i class="bi bi-database"></i></span>
					<div>
						<h2>Base de datos <span class="mod-badge <?php echo $modules["db"]["status"][0] ?>"><?php echo setup_e($modules["db"]["status"][1]) ?></span></h2>
						<p>Dónde se guardan los chats, contactos, pedidos y citas.</p>
					</div>
				</div>

				<div class="card-soft block">
					<div class="block-title"><i class="bi bi-hdd-network"></i> Conexión MySQL</div>
					<div class="row g-3">
						<div class="col-md-8">
							<label class="form-label">Servidor</label>
							<input type="text" class="form-control" name="db_host" value="<?php echo setup_e($v["db_host"]) ?>" required>
							<div class="form-text">Casi siempre es <code>localhost</code>.</div>
						</div>
						<div class="col-md-4">
							<label class="form-label">Puerto</label>
							<input type="number" class="form-control" name="db_port" value="<?php echo setup_e($v["db_port"]) ?>" required>
						</div>
						<div class="col-md-6">
							<label class="form-label">Nombre de la base de datos</label>
							<input type="text" class="form-control" name="db_name" value="<?php echo setup_e($v["db_name"]) ?>" required>
						</div>
						<div class="col-md-6">
							<label class="form-label">Usuario</label>
							<input type="text" class="form-control" name="db_user" value="<?php echo setup_e($v["db_user"]) ?>" required>
						</div>
						<div class="col-12">
							<label class="form-label">Contraseña</label>
							<input type="password" class="form-control" name="db_pass" placeholder="<?php echo $installed ? "Déjala vacía para conservar la actual" : "" ?>" autocomplete="new-password">
							<div class="form-check mt-2">
								<input class="form-check-input" type="checkbox" name="db_pass_empty" value="1" id="db_pass_empty">
								<label class="form-check-label small text-muted" for="db_pass_empty">La base de datos no usa contraseña <span class="text-muted">(solo en servidores locales; en cPanel siempre tiene)</span></label>
							</div>
						</div>
					</div>
					<button type="button" class="btn btn-ghost btn-sm mt-3" id="btnTestDb"><i class="bi bi-plug"></i> Probar conexión</button>
					<div class="test-result" id="testDbResult"></div>
				</div>

				<div class="callout">
					<i class="bi bi-info-circle"></i>
					<div>Créala en cPanel → <em>Bases de datos MySQL</em> y asígnale el usuario con <strong>todos los privilegios</strong>. En cPanel el nombre y el usuario llevan el prefijo de tu cuenta (ej.: <code>micuenta_chat</code>).</div>
				</div>

			</section>

			<?php if (!$installed): ?>

			<!-- ================= INSTALACIÓN ================= -->

			<section class="mod <?php echo $activeModule === "install" ? "active" : "" ?>" id="mod-install" data-module="install">

				<div class="mod-head">
					<span class="mod-ico"><i class="bi bi-box-seam"></i></span>
					<div>
						<h2>Instalación</h2>
						<p>Elige cómo preparar la base de datos y crea tu usuario de superadministrador.</p>
					</div>
				</div>

				<div class="card-soft block">

					<?php if (!$sqlAvailable): ?>
						<div class="setup-alert warn"><i class="bi bi-file-earmark-x"></i> <span>No se encontró <code>setup/database.sql</code>. Solo podrás usar una base de datos ya existente.</span></div>
					<?php endif ?>

					<label class="mode-card">
						<input type="radio" name="install_mode" value="clean" id="mode_clean" <?php echo ($_POST["install_mode"] ?? "clean") === "clean" ? "checked" : "" ?> <?php echo $sqlAvailable ? "" : "disabled" ?>>
						<div><strong>Instalación nueva <span class="badge bg-success">Recomendada</span></strong>
						<span>Crea todas las tablas con los planes de internet, los bots y los menús, sin conversaciones, contactos, órdenes ni usuarios de prueba.</span></div>
					</label>
					<label class="mode-card">
						<input type="radio" name="install_mode" value="demo" id="mode_demo" <?php echo ($_POST["install_mode"] ?? "") === "demo" ? "checked" : "" ?> <?php echo $sqlAvailable ? "" : "disabled" ?>>
						<div><strong>Instalación con datos de ejemplo</strong>
						<span>Empresa de internet de prueba: planes en soles, 3 sucursales en Lima y conversaciones con fechas actuales (un cliente con la instalación agendada, uno sin cobertura y uno por responder).</span></div>
					</label>
					<label class="mode-card">
						<input type="radio" name="install_mode" value="keep" id="mode_keep" <?php echo ($_POST["install_mode"] ?? "") === "keep" || !$sqlAvailable ? "checked" : "" ?>>
						<div><strong>Conservar la base de datos actual</strong>
						<span>Ya la importaste antes (por ejemplo desde phpMyAdmin). Solo se guarda la configuración.</span></div>
					</label>
					<label class="mode-card">
						<input type="radio" name="install_mode" value="reset_clean" id="mode_reset_clean" <?php echo ($_POST["install_mode"] ?? "") === "reset_clean" ? "checked" : "" ?> <?php echo $sqlAvailable ? "" : "disabled" ?>>
						<div><strong class="text-danger">Reinstalar desde cero con datos limpios</strong>
						<span><strong>Borra todas las tablas</strong> de esta base de datos (chats, contactos, órdenes, citas, consultas, inconvenientes y usuarios) y deja el sistema nuevo, solo con los planes de internet, sin datos de ejemplo.</span>
						<input type="text" class="form-control form-control-sm mt-2" name="reset_clean_confirm" placeholder="Escribe BORRAR para confirmar" autocomplete="off" style="max-width:260px"></div>
					</label>
					<label class="mode-card">
						<input type="radio" name="install_mode" value="reset" id="mode_reset" <?php echo ($_POST["install_mode"] ?? "") === "reset" ? "checked" : "" ?> <?php echo $sqlAvailable ? "" : "disabled" ?>>
						<div><strong class="text-danger">Reinstalar desde cero con datos de ejemplo</strong>
						<span><strong>Borra todas las tablas</strong> de esta base de datos (chats, contactos, órdenes, usuarios) y la deja como una instalación de ejemplo de internet.</span>
						<input type="text" class="form-control form-control-sm mt-2" name="reset_confirm" placeholder="Escribe BORRAR para confirmar" autocomplete="off" style="max-width:260px"></div>
					</label>

					<div class="row g-3 mt-1" id="adminFields">
						<div class="col-md-6">
							<label class="form-label">Correo del superadministrador</label>
							<input type="email" class="form-control" name="admin_email" value="<?php echo setup_e($v["admin_email"] ?? "") ?>" placeholder="tu@correo.com">
						</div>
						<div class="col-md-6">
							<label class="form-label">Contraseña del superadministrador</label>
							<input type="password" class="form-control" name="admin_password" minlength="8" autocomplete="new-password" placeholder="Mínimo 8 caracteres">
						</div>
					</div>

				</div>

			</section>

			<?php endif ?>

			<!-- ================= WHATSAPP ================= -->

			<section class="mod <?php echo $activeModule === "whatsapp" ? "active" : "" ?>" id="mod-whatsapp" data-module="whatsapp">

				<div class="mod-head">
					<span class="mod-ico"><i class="bi bi-whatsapp"></i></span>
					<div>
						<h2>WhatsApp <span class="mod-badge <?php echo $waStatus[0] ?>"><?php echo setup_e($waStatus[1]) ?></span></h2>
						<p>Conexión con la API de WhatsApp Cloud de Meta.</p>
					</div>
				</div>

				<div class="card-soft block">
					<div class="block-title"><i class="bi bi-1-circle"></i> Webhook en Meta for Developers</div>
					<p class="small text-muted">En <a href="https://developers.facebook.com/apps" target="_blank" rel="noopener">Meta for Developers</a> → tu app → WhatsApp → <em>Configuración</em> → Webhook, pega estos dos datos y suscríbete al campo <strong>messages</strong>.</p>
					<div class="row g-3">
						<div class="col-12">
							<label class="form-label">URL de devolución de llamada</label>
							<div class="copy-field">
								<input type="text" class="form-control font-monospace" value="<?php echo setup_e(cms_base_url()) ?>webhook/" readonly id="webhookUrl">
								<button class="btn btn-ghost" type="button" data-copy="webhookUrl" title="Copiar"><i class="bi bi-clipboard"></i></button>
							</div>
						</div>
						<div class="col-md-8">
							<label class="form-label">Token de verificación</label>
							<div class="copy-field">
								<input type="text" class="form-control font-monospace" name="webhook_verify_token" id="webhookToken" value="<?php echo setup_e($v["webhook_verify_token"]) ?>">
								<button class="btn btn-ghost" type="button" data-copy="webhookToken" title="Copiar"><i class="bi bi-clipboard"></i></button>
							</div>
							<div class="form-text">Pega este mismo texto en Meta. Puedes cambiarlo por cualquier palabra secreta.</div>
						</div>
						<div class="col-md-4">
							<label class="form-label">Versión de Graph API</label>
							<input type="text" class="form-control" name="graph_version" value="<?php echo setup_e($v["graph_version"]) ?>" placeholder="v22.0">
						</div>
					</div>
				</div>

				<div class="card-soft block">
					<div class="block-title"><i class="bi bi-2-circle"></i> Número y token</div>
					<p class="small text-muted mb-3">El ID del número de teléfono, el ID de la app y el token permanente se cargan en la sección <strong>WhatsApp</strong> del panel.</p>
					<?php if ($installed): ?>
						<a href="/api-ws" class="btn btn-ghost btn-sm"><i class="bi bi-box-arrow-up-right"></i> Abrir WhatsApp en el panel</a>
					<?php endif ?>
				</div>

				<div class="card-soft block">
					<div class="block-title"><i class="bi bi-3-circle"></i> Plantilla para avisos de órdenes y citas</div>
					<p class="small text-muted mb-0">Se configura en el panel, en <strong>Mi negocio › Números por área</strong>, junto con los números que reciben los avisos. Los datos y el nombre de la empresa también se editan solo en <strong>Mi negocio</strong>.</p>
				</div>

			</section>

			<!-- ================= ASISTENTE DE IA ================= -->

			<section class="mod <?php echo $activeModule === "ai" ? "active" : "" ?>" id="mod-ai" data-module="ai">

				<div class="mod-head">
					<span class="mod-ico"><i class="bi bi-robot"></i></span>
					<div>
						<h2>Asistente de IA <span class="mod-badge <?php echo $modules["ai"]["status"][0] ?>"><?php echo setup_e($modules["ai"]["status"][1]) ?></span></h2>
						<p>Responde solo a los contactos que tienen activada la IA en el Chat. Los datos del negocio (catálogo, pagos, delivery, agenda) se cargan en <em>Mi negocio</em>.</p>
					</div>
				</div>

				<div class="card-soft block">
					<div class="block-title"><i class="bi bi-cpu"></i> Proveedor</div>
					<div class="provider-cards">
						<label class="mode-card"><input type="radio" name="ai_provider" value="anthropic" <?php echo $aiProvider === "anthropic" ? "checked" : "" ?>><div><strong>Claude</strong><span>Anthropic</span></div></label>
						<label class="mode-card"><input type="radio" name="ai_provider" value="openai" <?php echo $aiProvider === "openai" ? "checked" : "" ?>><div><strong>ChatGPT</strong><span>OpenAI</span></div></label>
						<label class="mode-card"><input type="radio" name="ai_provider" value="none" <?php echo $aiProvider === "none" ? "checked" : "" ?>><div><strong>Desactivado</strong><span>Solo bots</span></div></label>
					</div>
				</div>

				<div class="card-soft block ai-field">
					<div class="block-title"><i class="bi bi-key"></i> Cuenta</div>
					<div class="row g-3">
						<div class="col-md-6">
							<label class="form-label">Modelo</label>
							<input type="text" class="form-control" name="ai_model" id="ai_model" value="<?php echo setup_e($v["ai_model"]) ?>" list="ai_models">
							<datalist id="ai_models"></datalist>
						</div>
						<div class="col-md-6">
							<label class="form-label">Mensajes que recuerda</label>
							<input type="number" class="form-control" name="ai_history" min="2" max="60" value="<?php echo (int)$v["ai_history"] ?>">
							<div class="form-text">Más mensajes = mejor contexto, pero mayor costo.</div>
						</div>
						<div class="col-12">
							<label class="form-label">Llave de la API (API key)</label>
							<input type="password" class="form-control font-monospace" name="ai_api_key" id="ai_api_key" autocomplete="new-password"
								placeholder="<?php echo !empty($config["ai_api_key"]) ? "Guardada (".setup_e(substr($config["ai_api_key"], 0, 7))."…). Déjala vacía para conservarla" : "Pega aquí tu llave" ?>">
							<div class="form-text" id="ai_key_help"></div>
							<?php if (!empty($config["ai_api_key"])): ?>
							<div class="form-check mt-1">
								<input class="form-check-input" type="checkbox" name="ai_key_clear" value="1" id="ai_key_clear">
								<label class="form-check-label small" for="ai_key_clear">Borrar la llave guardada</label>
							</div>
							<?php endif ?>
						</div>
					</div>
					<button type="button" class="btn btn-ghost btn-sm mt-3" id="btnTestAi"><i class="bi bi-lightning-charge"></i> Probar la IA</button>
					<div class="test-result" id="testAiResult"></div>
				</div>

				<div class="card-soft block ai-field">
					<div class="block-title"><i class="bi bi-chat-quote"></i> Personalidad</div>
					<label class="form-label">Instrucciones del asistente <span class="text-muted fw-normal">(opcional)</span></label>
					<textarea class="form-control" name="ai_prompt" rows="6" placeholder="Ej.: Eres Sofía, asesora de ventas. Responde breve, amable y con emojis moderados."><?php echo setup_e($v["ai_prompt"]) ?></textarea>
					<div class="form-text">Solo cómo debe hablar la IA: <strong>no escribas aquí el nombre ni los datos de la empresa</strong>, la IA los toma de <em>Mi negocio</em>. Si lo dejas vacío se usan las instrucciones activas de la sección <em>Instrucciones IA</em> del panel. Los precios, pagos, cobertura y la agenda los agrega el sistema solo.</div>
				</div>

			</section>

			<!-- ================= GENERAL ================= -->

			<section class="mod <?php echo $activeModule === "general" ? "active" : "" ?>" id="mod-general" data-module="general">

				<div class="mod-head">
					<span class="mod-ico"><i class="bi bi-sliders"></i></span>
					<div>
						<h2>General</h2>
						<p>Moneda de los precios y pedidos, y la hora que usan la agenda y los mensajes.</p>
					</div>
				</div>

				<div class="card-soft block">
					<div class="block-title"><i class="bi bi-cash-coin"></i> Moneda</div>
					<div class="row g-3 align-items-end">
						<div class="col-md-6">
							<label class="form-label">Moneda</label>
							<select class="form-select" name="currency_code" id="currency_code">
								<?php foreach ($currencies as $code => $c): ?>
									<option value="<?php echo $code ?>" data-symbol="<?php echo setup_e($c[0]) ?>" <?php echo $code === $currencyCode ? "selected" : "" ?>><?php echo setup_e($c[1]) ?><?php echo $code === "PEN" ? " · recomendada" : "" ?></option>
								<?php endforeach ?>
							</select>
						</div>
						<div class="col-md-2">
							<label class="form-label">Símbolo</label>
							<input type="text" class="form-control" name="currency_symbol" id="currency_symbol" maxlength="6" value="<?php echo setup_e($v["currency_symbol"] ?? "S/") ?>">
						</div>
						<div class="col-md-4">
							<div class="money-preview"><span class="small text-muted">Así se verá</span><strong id="moneyPreview"></strong></div>
						</div>
					</div>
					<div class="form-text mt-2">Se usa en Órdenes, en el catálogo que ve la IA, en los mensajes de pago (QR) y en los bots.</div>
				</div>

				<div class="card-soft block">
					<div class="block-title"><i class="bi bi-clock"></i> Zona horaria</div>
					<select class="form-select" name="timezone">
						<?php foreach (timezone_identifiers_list() as $tz): ?>
							<option value="<?php echo setup_e($tz) ?>" <?php echo $tz === $v["timezone"] ? "selected" : "" ?>><?php echo setup_e(str_replace("_", " ", $tz)) ?></option>
						<?php endforeach ?>
					</select>
					<div class="form-text">Hora actual del servidor con esta zona: <strong><?php echo date("d/m/Y h:i a") ?></strong>. Para Perú elige <em>America/Lima</em>.</div>
				</div>

			</section>

			<!-- ================= LÍMITES Y CONSUMO ================= -->

			<section class="mod <?php echo $activeModule === "limits" ? "active" : "" ?>" id="mod-limits" data-module="limits">

				<div class="mod-head">
					<span class="mod-ico"><i class="bi bi-speedometer"></i></span>
					<div>
						<h2>Límites y consumo</h2>
						<p>Controla el gasto de la IA y de WhatsApp, y cómo se cierran las conversaciones. El consumo se ve en <strong>Inicio</strong>.</p>
					</div>
				</div>

				<div class="card-soft block">
					<div class="block-title"><i class="bi bi-stopwatch"></i> Mensajes por minuto</div>
					<div class="row g-3">
						<div class="col-md-6">
							<label class="form-label">Mensajes de un mismo cliente que la IA responde por minuto</label>
							<input type="number" min="0" class="form-control" name="limit_client_per_min" value="<?php echo (int)($config["limit_client_per_min"] ?? 8) ?>">
							<div class="form-text">Si alguien supera este número (spam), se espera un poco más y se responde una sola vez a todo lo que escribió. 0 = sin límite.</div>
						</div>
						<div class="col-md-6">
							<label class="form-label">Mensajes que el sistema envía por minuto (en total)</label>
							<input type="number" min="0" class="form-control" name="limit_send_per_min" value="<?php echo (int)($config["limit_send_per_min"] ?? 60) ?>">
							<div class="form-text">Protege tu número de bloqueos por envío masivo. 0 = sin límite.</div>
						</div>
						<div class="col-md-6">
							<label class="form-label">Espera antes de responder (segundos)</label>
							<input type="number" min="0" max="15" class="form-control" name="reply_wait_seconds" value="<?php echo (int)($config["reply_wait_seconds"] ?? 4) ?>">
							<div class="form-text">Si el cliente manda varios mensajes seguidos (nombre, DNI, correo…), la asistente espera este tiempo y responde todo junto en un solo mensaje. Recomendado: 3 a 5.</div>
						</div>
						<div class="col-md-6">
							<label class="form-label">Mensaje si la IA no responde</label>
							<input type="text" class="form-control" name="ai_error_message" maxlength="500" value="<?php echo setup_e($config["ai_error_message"] ?? "") ?>">
							<div class="form-text">Solo se envía si el proveedor de IA falla incluso tras 2 reintentos (máximo una vez cada 10 minutos por cliente), para que nadie quede sin respuesta.</div>
						</div>
					</div>
				</div>

				<div class="card-soft block">
					<div class="block-title"><i class="bi bi-cpu"></i> Tokens de IA</div>
					<div class="row g-3">
						<div class="col-md-6">
							<label class="form-label">Máximo por día</label>
							<input type="number" min="0" step="1000" class="form-control" name="limit_ai_tokens_day" value="<?php echo (int)($config["limit_ai_tokens_day"] ?? 0) ?>">
						</div>
						<div class="col-md-6">
							<label class="form-label">Máximo por mes</label>
							<input type="number" min="0" step="10000" class="form-control" name="limit_ai_tokens_month" value="<?php echo (int)($config["limit_ai_tokens_month"] ?? 0) ?>">
						</div>
						<div class="col-12">
							<label class="form-label">Mensaje al cliente cuando se llega al límite</label>
							<input type="text" class="form-control" name="limit_ai_message" maxlength="500" value="<?php echo setup_e($config["limit_ai_message"] ?? "") ?>">
							<div class="form-text">0 = sin tope. Al llegar al tope la IA se pausa, el cliente recibe este mensaje una sola vez y puedes seguir atendiendo desde el Chat. Una respuesta típica usa entre 2 000 y 5 000 tokens.</div>
						</div>
					</div>
				</div>

				<div class="card-soft block">
					<div class="block-title"><i class="bi bi-currency-dollar"></i> Precios para estimar el gasto (US$)</div>
					<div class="row g-3">
						<div class="col-md-4">
							<label class="form-label">IA: 1 millón de tokens de entrada</label>
							<input type="text" inputmode="decimal" class="form-control" name="ai_price_in" value="<?php echo setup_e($config["ai_price_in"] ?? 0.15) ?>">
						</div>
						<div class="col-md-4">
							<label class="form-label">IA: 1 millón de tokens de salida</label>
							<input type="text" inputmode="decimal" class="form-control" name="ai_price_out" value="<?php echo setup_e($config["ai_price_out"] ?? 0.60) ?>">
						</div>
						<div class="col-md-4">
							<label class="form-label">WhatsApp: cada plantilla</label>
							<input type="text" inputmode="decimal" class="form-control" name="wa_price_template" value="<?php echo setup_e($config["wa_price_template"] ?? 0.02) ?>">
						</div>
					</div>
					<div class="form-text">Copia los precios de tu modelo en la web de OpenAI o Anthropic, y el de las plantillas de Meta para Perú. Las respuestas dentro de las 24 h no tienen costo en WhatsApp.</div>
				</div>

				<div class="card-soft block">
					<div class="block-title"><i class="bi bi-chat-square-heart"></i> Cierre de la conversación después de una orden</div>
					<div class="form-check form-switch mb-3">
						<input class="form-check-input" type="checkbox" name="followup_enabled" value="1" id="followup_enabled" <?php echo !empty($config["followup_enabled"]) ? "checked" : "" ?>>
						<label class="form-check-label" for="followup_enabled">Si el cliente ya cerró su orden, siguió conversando y de pronto deja de responder: preguntarle si necesita algo más y, si no responde, agradecerle y dejar de escribirle</label>
					</div>
					<div class="row g-3">
						<div class="col-md-3">
							<label class="form-label">Preguntar después de</label>
							<div class="input-group"><input type="number" min="1" class="form-control" name="followup_ask_min" value="<?php echo (int)($config["followup_ask_min"] ?? 10) ?>"><span class="input-group-text">min</span></div>
						</div>
						<div class="col-md-9">
							<label class="form-label">Pregunta</label>
							<input type="text" class="form-control" name="followup_ask_text" maxlength="500" value="<?php echo setup_e($config["followup_ask_text"] ?? "") ?>">
						</div>
						<div class="col-md-3">
							<label class="form-label">Despedirse después de</label>
							<div class="input-group"><input type="number" min="1" class="form-control" name="followup_close_min" value="<?php echo (int)($config["followup_close_min"] ?? 10) ?>"><span class="input-group-text">min</span></div>
						</div>
						<div class="col-md-9">
							<label class="form-label">Despedida</label>
							<input type="text" class="form-control" name="followup_close_text" maxlength="500" value="<?php echo setup_e($config["followup_close_text"] ?? "") ?>">
						</div>
					</div>
				</div>

				<?php if ($installed): ?>
				<div class="card-soft block">
					<div class="block-title"><i class="bi bi-clock-history"></i> Tareas automáticas (cron)</div>
					<p class="small text-muted">El cierre de órdenes pasada la instalación y los mensajes de cierre se ejecutan solos con el movimiento del sistema. Para que sean puntuales aunque nadie use el panel, crea en <strong>cPanel → Trabajos de cron</strong> uno cada 5 minutos con:</p>
					<div class="copy-field">
						<input type="text" class="form-control font-monospace" readonly id="cronCmd" value="<?php echo setup_e(!empty($config["cron_key"]) ? "curl -s \"".cms_base_url()."cron/run.php?key=".$config["cron_key"]."\" > /dev/null" : "Guarda la configuración para generar la llave del cron") ?>">
						<button class="btn btn-ghost" type="button" data-copy="cronCmd" title="Copiar"><i class="bi bi-clipboard"></i></button>
					</div>
				</div>
				<?php endif ?>

			</section>

			<?php if ($installed): ?>

			<!-- ================= MENÚ ================= -->

			<section class="mod <?php echo $activeModule === "menu" ? "active" : "" ?>" id="mod-menu" data-module="menu">

				<div class="mod-head">
					<span class="mod-ico"><i class="bi bi-list-ul"></i></span>
					<div>
						<h2>Menú del panel</h2>
						<p>Ordena, renombra y cambia el ícono de cada página. Desactiva las que no uses para que el menú quede más limpio (siguen existiendo y puedes volver a activarlas).</p>
					</div>
				</div>

				<div class="card-soft block">
					<div class="menu-list" id="menuList">
						<?php foreach ($menuPages as $mp): ?>
							<div class="menu-row <?php echo (int)($mp["visible_page"] ?? 1) === 1 ? "" : "off" ?>" data-id="<?php echo (int)$mp["id_page"] ?>">
								<span class="menu-ico"><i class="<?php echo setup_e($mp["icon_page"]) ?>"></i></span>
								<div class="menu-fields">
									<input type="text" class="form-control form-control-sm" data-f="title" maxlength="60" value="<?php echo setup_e($mp["title_page"]) ?>" aria-label="Nombre en el menú">
									<input type="text" class="form-control form-control-sm font-monospace" data-f="icon" value="<?php echo setup_e($mp["icon_page"]) ?>" aria-label="Ícono" title="Clase de Bootstrap Icons, por ejemplo: bi bi-wifi">
									<small class="text-muted">/<?php echo setup_e(urldecode((string)$mp["url_page"])) ?></small>
								</div>
								<div class="menu-actions">
									<div class="form-check form-switch m-0" title="Mostrar en el menú">
										<input class="form-check-input" type="checkbox" data-f="visible" <?php echo (int)($mp["visible_page"] ?? 1) === 1 ? "checked" : "" ?> aria-label="Mostrar en el menú">
									</div>
									<button type="button" class="btn btn-ghost btn-sm" data-move="up" title="Subir" aria-label="Subir"><i class="bi bi-arrow-up"></i></button>
									<button type="button" class="btn btn-ghost btn-sm" data-move="down" title="Bajar" aria-label="Bajar"><i class="bi bi-arrow-down"></i></button>
									<button type="button" class="btn btn-ghost btn-sm text-danger" data-del title="Borrar" aria-label="Borrar"><i class="bi bi-trash3"></i></button>
								</div>
							</div>
						<?php endforeach ?>
					</div>
					<div class="test-result" id="menuResult"></div>
					<div class="d-flex flex-wrap gap-2 justify-content-between mt-3">
						<a href="/?nueva-pagina=1" class="btn btn-ghost btn-sm"><i class="bi bi-plus-lg"></i> Agregar página</a>
						<button type="button" class="btn btn-brand btn-sm" id="menuSave"><i class="bi bi-check2"></i> Guardar menú</button>
					</div>
					<div class="form-text mt-2">Íconos: usa los nombres de <a href="https://icons.getbootstrap.com/" target="_blank" rel="noopener">Bootstrap Icons</a> con el prefijo <code>bi bi-</code> (ej.: <code>bi bi-wifi</code>). El menú se guarda con su propio botón.</div>
				</div>

			</section>

			<?php endif ?>

			<!-- ================= AVANZADO ================= -->

			<section class="mod <?php echo $activeModule === "advanced" ? "active" : "" ?>" id="mod-advanced" data-module="advanced">

				<div class="mod-head">
					<span class="mod-ico"><i class="bi bi-gear"></i></span>
					<div>
						<h2>Avanzado</h2>
						<p>Solo cámbialo si sabes lo que haces. Los valores automáticos funcionan en la mayoría de los hostings.</p>
					</div>
				</div>

				<div class="card-soft block">
					<div class="block-title"><i class="bi bi-diagram-2"></i> API</div>
					<div class="row g-3">
						<div class="col-12">
							<label class="form-label">URL de la API</label>
							<input type="url" class="form-control" name="api_url" value="<?php echo setup_e($v["api_url"]) ?>" placeholder="Vacío = automática (<?php echo setup_e(cms_base_url()) ?>api/)">
						</div>
						<div class="col-12">
							<div class="form-check form-switch">
								<input class="form-check-input" type="checkbox" name="api_verify_ssl" value="1" id="api_verify_ssl" <?php echo !empty($config["api_verify_ssl"]) ? "checked" : "" ?>>
								<label class="form-check-label" for="api_verify_ssl">Verificar el certificado SSL al llamar a la API <span class="text-muted small">(desactívalo solo si el SSL aún no está emitido)</span></label>
							</div>
						</div>
					</div>
				</div>

				<?php if ($installed): ?>
				<div class="card-soft block">
					<div class="block-title"><i class="bi bi-shield-lock"></i> Seguridad</div>
					<label class="form-label">Llave de la API <span class="text-muted fw-normal">(para Postman u otras aplicaciones, encabezado <code>Authorization</code>)</span></label>
					<div class="copy-field">
						<input type="text" class="form-control font-monospace" value="<?php echo setup_e($config["api_key"]) ?>" readonly id="apiKey">
						<button class="btn btn-ghost" type="button" data-copy="apiKey" title="Copiar"><i class="bi bi-clipboard"></i></button>
					</div>
					<div class="form-check mt-2">
						<input class="form-check-input" type="checkbox" name="regenerate_keys" value="1" id="regenerate_keys">
						<label class="form-check-label small" for="regenerate_keys">Regenerar las llaves de seguridad <span class="text-muted">(las aplicaciones externas deberán usar la nueva llave)</span></label>
					</div>
				</div>
				<?php endif ?>

				<div class="card-soft block">
					<div class="block-title"><i class="bi bi-bug"></i> Diagnóstico</div>
					<div class="d-grid gap-2">
						<div class="form-check form-switch">
							<input class="form-check-input" type="checkbox" name="debug" value="1" id="debug" <?php echo !empty($config["debug"]) ? "checked" : "" ?>>
							<label class="form-check-label" for="debug">Mostrar errores en pantalla <span class="text-muted small">(solo para depurar)</span></label>
						</div>
						<div class="form-check form-switch">
							<input class="form-check-input" type="checkbox" name="webhook_log" value="1" id="webhook_log" <?php echo !empty($config["webhook_log"]) ? "checked" : "" ?>>
							<label class="form-check-label" for="webhook_log">Guardar los mensajes de WhatsApp recibidos en <code>logs/</code></label>
						</div>
					</div>
					<div class="form-text mt-2">Errores: <code>logs/php_error.log</code> (panel) y <code>logs/api_error.log</code> (API).</div>
				</div>

			</section>

			<div class="save-bar">
				<div class="save-bar-inner">
					<span class="hint d-none d-md-inline" id="saveHint"><i class="bi bi-info-circle"></i> Se guardan todos los módulos a la vez.</span>
					<?php if (!$installed): ?>
						<button type="button" class="btn btn-ghost ms-auto" id="btnNext">Siguiente <i class="bi bi-arrow-right"></i></button>
					<?php else: ?>
						<a href="/" class="btn btn-ghost ms-md-auto">Volver al panel</a>
					<?php endif ?>
					<button type="submit" class="btn btn-brand px-4" <?php echo $configWritable ? "" : "disabled" ?>>
						<i class="bi bi-check2"></i> <?php echo $installed ? "Guardar configuración" : "Instalar" ?>
					</button>
				</div>
			</div>

		</form>

	</main>

</div>

<?php endif ?>

<script src="/views/assets/plugins/bootstrap5/bootstrap.bundle.min.js"></script>
<script>
(function(){

	var form = document.getElementById("setupForm");

	if(!form){ return; }

	function result(el, state, text){
		el.className = "test-result show " + state;
		el.textContent = text;
	}

	/*=============================================
	Módulos: uno visible a la vez (#db, #ai, ...)
	=============================================*/

	var links = Array.prototype.slice.call(document.querySelectorAll(".mod-link"));
	var order = links.map(function(l){ return l.getAttribute("data-module"); });

	function show(name, push){

		if(order.indexOf(name) === -1){ return; }

		links.forEach(function(l){ l.classList.toggle("active", l.getAttribute("data-module") === name); });
		document.querySelectorAll(".mod").forEach(function(m){ m.classList.toggle("active", m.getAttribute("data-module") === name); });
		document.getElementById("moduleInput").value = name;

		var next = document.getElementById("btnNext");
		if(next){ next.style.display = order.indexOf(name) === order.length - 1 ? "none" : ""; }

		if(push !== false){ history.replaceState(null, "", "#" + name); }

		window.scrollTo({top: 0, behavior: "smooth"});
	}

	links.forEach(function(l){
		l.addEventListener("click", function(){ show(l.getAttribute("data-module")); });
	});

	if(location.hash && <?php echo json_encode(empty($_POST)) ?>){ show(location.hash.slice(1), false); }
	else{ show(document.getElementById("moduleInput").value, false); }

	var nextBtn = document.getElementById("btnNext");

	if(nextBtn){
		nextBtn.addEventListener("click", function(){
			var i = order.indexOf(document.getElementById("moduleInput").value);
			if(i < order.length - 1){ show(order[i + 1]); }
		});
	}

	/* Un campo requerido vacío en otro módulo: abrir ese módulo */

	form.addEventListener("invalid", function(e){

		var mod = e.target.closest(".mod");
		if(mod && !mod.classList.contains("active")){ show(mod.getAttribute("data-module")); }

	}, true);

	/* Copiar al portapapeles */

	document.querySelectorAll("[data-copy]").forEach(function(b){
		b.addEventListener("click", function(){
			var input = document.getElementById(b.getAttribute("data-copy"));
			navigator.clipboard.writeText(input.value).then(function(){
				b.innerHTML = '<i class="bi bi-check2"></i>';
				setTimeout(function(){ b.innerHTML = '<i class="bi bi-clipboard"></i>'; }, 1500);
			});
		});
	});

	/* Cambios sin guardar (se marca el módulo) */

	var dirty = false;

	form.addEventListener("input", function(e){

		var mod = e.target.closest(".mod");

		if(mod){
			links.forEach(function(l){ if(l.getAttribute("data-module") === mod.getAttribute("data-module")){ l.classList.add("dirty"); } });
		}

		if(!dirty){
			dirty = true;
			var hint = document.getElementById("saveHint");
			if(hint){ hint.innerHTML = '<i class="bi bi-exclamation-circle text-warning"></i> Tienes cambios sin guardar.'; }
		}
	});

	window.addEventListener("beforeunload", function(e){
		if(dirty){ e.preventDefault(); e.returnValue = ""; }
	});

	/* Mostrar los campos del superadministrador solo al importar */

	function toggleAdmin(){
		var fields = document.getElementById("adminFields");
		var keep = document.getElementById("mode_keep");
		if(!fields || !keep){ return; }
		var showFields = !keep.checked;
		fields.style.display = showFields ? "" : "none";
		fields.querySelectorAll("input").forEach(function(i){ i.required = showFields; });
	}

	document.querySelectorAll("input[name=install_mode]").forEach(function(r){ r.addEventListener("change", toggleAdmin); });
	toggleAdmin();

	/* Probar conexión */

	document.getElementById("btnTestDb").addEventListener("click", function(){

		var out = document.getElementById("testDbResult");
		var data = new FormData(form);
		data.set("action", "test_db");

		result(out, "wait", "Probando la conexión…");

		fetch("/setup/index.php", {method: "POST", body: data, credentials: "same-origin"})
		.then(function(r){ return r.json(); })
		.then(function(res){ result(out, res.ok ? "ok" : "bad", res.message); })
		.catch(function(){ result(out, "bad", "No se pudo probar la conexión."); });

	});

	/* Moneda: símbolo y vista previa */

	var currency = document.getElementById("currency_code");
	var symbol = document.getElementById("currency_symbol");

	function money(){
		document.getElementById("moneyPreview").textContent = (symbol.value.trim() || "S/") + " 1,250.50";
	}

	currency.addEventListener("change", function(){
		symbol.value = currency.options[currency.selectedIndex].getAttribute("data-symbol");
		money();
	});

	symbol.addEventListener("input", money);
	money();

	/* Asistente de IA: modelos sugeridos y prueba */

	var aiInfo = {
		openai: {models: ["gpt-4o-mini", "gpt-4o", "gpt-4.1-mini", "gpt-4.1"], def: "gpt-4o-mini",
			help: 'Créala en <a href="https://platform.openai.com/api-keys" target="_blank" rel="noopener">platform.openai.com/api-keys</a> (la cuenta necesita saldo).'},
		anthropic: {models: ["claude-opus-5-5", "claude-sonnet-5-5", "claude-haiku-5-5"], def: "claude-opus-5-5",
			help: 'Créala en <a href="https://console.anthropic.com/settings/keys" target="_blank" rel="noopener">console.anthropic.com</a> (la cuenta necesita saldo). Opus es el más capaz; Haiku, el más rápido y económico.'}
	};

	function provider(){
		var checked = form.querySelector("input[name=ai_provider]:checked");
		return checked ? checked.value : "none";
	}

	function updateAi(){
		var info = aiInfo[provider()];
		document.querySelectorAll(".ai-field").forEach(function(el){ el.style.display = info ? "" : "none"; });
		if(!info){ return; }
		document.getElementById("ai_models").innerHTML = info.models.map(function(m){ return '<option value="' + m + '">'; }).join("");
		document.getElementById("ai_model").placeholder = info.def + " (predeterminado)";
		document.getElementById("ai_key_help").innerHTML = info.help;
	}

	form.querySelectorAll("input[name=ai_provider]").forEach(function(r){
		r.addEventListener("change", function(){
			document.getElementById("ai_model").value = "";
			updateAi();
		});
	});

	updateAi();

	document.getElementById("btnTestAi").addEventListener("click", function(){

		var out = document.getElementById("testAiResult");
		var data = new FormData(form);
		data.set("action", "test_ai");

		result(out, "wait", "Probando la IA…");

		fetch("/setup/index.php", {method: "POST", body: data, credentials: "same-origin"})
		.then(function(r){ return r.json(); })
		.then(function(res){ result(out, res.ok ? "ok" : "bad", res.message); })
		.catch(function(){ result(out, "bad", "No se pudo probar la IA."); });
	});

	/*=============================================
	Menú del panel
	=============================================*/

	var menuList = document.getElementById("menuList");

	if(menuList){

		var menuOut = document.getElementById("menuResult");

		/* Sus cambios se guardan con su propio botón (no marcan el formulario general) */

		["input", "change"].forEach(function(ev){
			menuList.addEventListener(ev, function(e){

				e.stopPropagation();

				var row = e.target.closest(".menu-row");

				if(e.target.getAttribute("data-f") === "icon"){
					row.querySelector(".menu-ico i").className = e.target.value.trim() || "bi bi-file-earmark-text";
				}

				if(e.target.getAttribute("data-f") === "visible"){
					row.classList.toggle("off", !e.target.checked);
				}
			});
		});

		menuList.addEventListener("click", function(e){

			var btn = e.target.closest("button");
			if(!btn){ return; }

			var row = btn.closest(".menu-row");
			var move = btn.getAttribute("data-move");

			if(move === "up" && row.previousElementSibling){ menuList.insertBefore(row, row.previousElementSibling); return; }
			if(move === "down" && row.nextElementSibling){ menuList.insertBefore(row.nextElementSibling, row); return; }

			if(btn.hasAttribute("data-del")){

				var name = row.querySelector("[data-f=title]").value;

				if(!confirm("¿Borrar «" + name + "» del menú? Esta acción no se puede deshacer. Si solo quieres esconderla, desactívala con el interruptor.")){ return; }

				var data = new FormData();
				data.set("csrf", form.querySelector("[name=csrf]").value);
				data.set("action", "menu_delete");
				data.set("id", row.getAttribute("data-id"));

				fetch("/setup/index.php", {method: "POST", body: data, credentials: "same-origin"})
				.then(function(r){ return r.json(); })
				.then(function(res){
					result(menuOut, res.ok ? "ok" : "bad", res.message);
					if(res.ok){ row.remove(); }
				})
				.catch(function(){ result(menuOut, "bad", "No se pudo borrar."); });
			}
		});

		document.getElementById("menuSave").addEventListener("click", function(){

			var btn = this;
			var items = Array.prototype.map.call(menuList.querySelectorAll(".menu-row"), function(row){
				return {
					id: row.getAttribute("data-id"),
					title: row.querySelector("[data-f=title]").value,
					icon: row.querySelector("[data-f=icon]").value,
					visible: row.querySelector("[data-f=visible]").checked ? 1 : 0
				};
			});

			var data = new FormData();
			data.set("csrf", form.querySelector("[name=csrf]").value);
			data.set("action", "menu_save");
			data.set("items", JSON.stringify(items));

			btn.disabled = true;
			result(menuOut, "wait", "Guardando el menú…");

			fetch("/setup/index.php", {method: "POST", body: data, credentials: "same-origin"})
			.then(function(r){ return r.json(); })
			.then(function(res){ result(menuOut, res.ok ? "ok" : "bad", res.message); })
			.catch(function(){ result(menuOut, "bad", "No se pudo guardar el menú."); })
			.finally(function(){ btn.disabled = false; });
		});
	}

	/* Evitar doble envío */

	form.addEventListener("submit", function(){
		dirty = false;
		var btn = form.querySelector("button[type=submit]");
		btn.disabled = true;
		btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Guardando…';
	});

})();
</script>
</body>
</html>
