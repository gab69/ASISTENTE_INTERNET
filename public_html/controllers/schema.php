<?php

/*=============================================
Actualizaciones de la base de datos.
Se aplican solas una única vez (al instalar desde
/setup/ o en la primera visita después de subir
una versión nueva). La versión aplicada se guarda
en config/schema.lock
=============================================*/

const CMS_SCHEMA_VERSION = 14;

function cms_schema_marker(){

	return CMS_DIR."/config/schema.lock";

}

function cms_schema_pdo(){

	$c = $GLOBALS["CMS_CONFIG"];

	$pdo = new PDO(
		"mysql:host=".($c["db_host"] ?: "localhost").";port=".($c["db_port"] ?: 3306).";dbname=".$c["db_name"].";charset=utf8mb4",
		$c["db_user"],
		$c["db_pass"],
		array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION)
	);

	return $pdo;

}

function cms_schema_migrate(PDO $pdo){

	$tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);

	$addColumn = function($table, $column, $definition) use ($pdo, &$tables){

		if(!in_array($table, $tables, true)){
			return;
		}

		$exists = $pdo->query("SHOW COLUMNS FROM `".$table."` LIKE ".$pdo->quote($column))->fetch();

		if(!$exists){
			$pdo->exec("ALTER TABLE `".$table."` ADD `".$column."` ".$definition);
		}
	};

	$addIndex = function($table, $name, $columns) use ($pdo, &$tables){

		if(!in_array($table, $tables, true)){
			return;
		}

		$exists = $pdo->query("SHOW INDEX FROM `".$table."` WHERE Key_name = ".$pdo->quote($name))->fetch();

		if(!$exists){
			$pdo->exec("ALTER TABLE `".$table."` ADD INDEX `".$name."` (".$columns.")");
		}
	};

	/* Estado de entrega de cada mensaje enviado a WhatsApp */

	$addColumn("messages", "wamid_message", "VARCHAR(191) NULL DEFAULT NULL");
	$addColumn("messages", "status_message", "VARCHAR(20) NULL DEFAULT NULL");
	$addColumn("messages", "error_message", "TEXT NULL DEFAULT NULL");

	/* Índices: el chat filtra siempre por teléfono */

	$addIndex("messages", "idx_phone_message", "`phone_message`(32)");
	$addIndex("messages", "idx_conversation_message", "`id_conversation_message`(64)");
	$addIndex("messages", "idx_wamid_message", "`wamid_message`");
	$addIndex("contacts", "idx_phone_contact", "`phone_contact`(32)");

	/* v3: recuperación de contraseña por enlace temporal */

	$addColumn("admins", "reset_token_admin", "VARCHAR(128) NULL DEFAULT NULL");
	$addColumn("admins", "reset_exp_admin", "DATETIME NULL DEFAULT NULL");

	/* v3: datos del negocio (empresa, contactos, pagos, imágenes, agenda) */

	$pdo->exec("CREATE TABLE IF NOT EXISTS `settings` (
		`id_setting` INT(11) NOT NULL AUTO_INCREMENT,
		`key_setting` VARCHAR(64) NOT NULL,
		`value_setting` LONGTEXT NULL,
		`date_updated_setting` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
		PRIMARY KEY (`id_setting`),
		UNIQUE KEY `uk_key_setting` (`key_setting`)
	) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

	/* v3: citas agendadas (por la IA o desde el panel) */

	$pdo->exec("CREATE TABLE IF NOT EXISTS `appointments` (
		`id_appointment` INT(11) NOT NULL AUTO_INCREMENT,
		`phone_appointment` VARCHAR(32) NULL,
		`name_appointment` VARCHAR(191) NULL,
		`service_appointment` VARCHAR(191) NULL,
		`date_appointment` DATE NOT NULL,
		`time_appointment` TIME NOT NULL,
		`duration_appointment` INT(11) NOT NULL DEFAULT 30,
		`summary_appointment` TEXT NULL,
		`status_appointment` VARCHAR(20) NOT NULL DEFAULT 'confirmada',
		`source_appointment` VARCHAR(20) NOT NULL DEFAULT 'ia',
		`notified_appointment` TINYINT(1) NOT NULL DEFAULT 0,
		`date_created_appointment` DATETIME NULL,
		`date_updated_appointment` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
		PRIMARY KEY (`id_appointment`),
		KEY `idx_date_appointment` (`date_appointment`, `time_appointment`),
		KEY `idx_phone_appointment` (`phone_appointment`)
	) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

	/* v3: páginas nuevas del menú */

	if(in_array("pages", $tables, true)){

		$newPages = array(
			array("Agenda", "agenda", "bi bi-calendar2-week", 2),
			array("Mi negocio", "negocio", "bi bi-shop", 3)
		);

		foreach ($newPages as $p) {

			$exists = $pdo->prepare("SELECT COUNT(*) FROM `pages` WHERE `url_page` = ?");
			$exists->execute(array($p[1]));

			if(!(int)$exists->fetchColumn()){

				$insert = $pdo->prepare("INSERT INTO `pages` (`title_page`, `url_page`, `icon_page`, `type_page`, `order_page`, `date_created_page`) VALUES (?, ?, ?, 'custom', ?, ?)");
				$insert->execute(array($p[0], $p[1], $p[2], $p[3], date("Y-m-d")));
			}
		}
	}

	/* v4: consultas que la IA no pudo responder y respuestas aprendidas */

	$pdo->exec("CREATE TABLE IF NOT EXISTS `inquiries` (
		`id_inquiry` INT(11) NOT NULL AUTO_INCREMENT,
		`phone_inquiry` VARCHAR(32) NULL,
		`name_inquiry` VARCHAR(191) NULL,
		`question_inquiry` TEXT NOT NULL,
		`answer_inquiry` TEXT NULL,
		`status_inquiry` VARCHAR(20) NOT NULL DEFAULT 'pendiente',
		`learn_inquiry` TINYINT(1) NOT NULL DEFAULT 1,
		`delivered_inquiry` TINYINT(1) NOT NULL DEFAULT 0,
		`wamid_inquiry` VARCHAR(191) NULL,
		`date_created_inquiry` DATETIME NULL,
		`date_answered_inquiry` DATETIME NULL,
		`date_updated_inquiry` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
		PRIMARY KEY (`id_inquiry`),
		KEY `idx_status_inquiry` (`status_inquiry`),
		KEY `idx_phone_inquiry` (`phone_inquiry`)
	) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

	/* v4: Bots pasa a ser una página guiada y textos del sistema en español
	   (solo se cambian los valores originales, no los personalizados) */

	if(in_array("pages", $tables, true)){

		$pdo->exec("UPDATE `pages` SET `type_page` = 'custom' WHERE `url_page` = 'bots' AND `type_page` = 'modules'");

		$renames = array(
			array("title_page", "Admins", "Administradores"),
			array("title_page", "API-WS", "WhatsApp"),
			array("title_page", "Prompts", "Instrucciones IA"),
			array("icon_page", "bi bi-three-dots-vertical", "bi bi-diagram-3")
		);

		foreach ($renames as $r) {

			$stmt = $pdo->prepare("UPDATE `pages` SET `".$r[0]."` = ? WHERE `".$r[0]."` = ?");
			$stmt->execute(array($r[2], $r[1]));
		}
	}

	if(in_array("modules", $tables, true)){

		foreach (array("api whatsapp" => "WhatsApp", "prompts" => "Instrucciones de la IA", "bots" => "Bots") as $old => $new) {

			$stmt = $pdo->prepare("UPDATE `modules` SET `title_module` = ? WHERE `type_module` = 'breadcrumbs' AND `title_module` = ?");
			$stmt->execute(array($new, $old));
		}
	}

	if(in_array("columns", $tables, true)){

		$aliases = array(
			"rol_admin" => array("rol", "Rol"),
			"permissions_admin" => array("permisos", "Permisos"),
			"email_admin" => array("email", "Correo"),
			"password_admin" => array("pass", "Contraseña"),
			"token_admin" => array("token", "Token"),
			"token_exp_admin" => array("expiración", "Expiración"),
			"status_admin" => array("estado", "Estado"),
			"title_admin" => array("título", "Título"),
			"symbol_admin" => array("simbolo", "Símbolo"),
			"font_admin" => array("tipografía", "Tipografía"),
			"color_admin" => array("color", "Color"),
			"back_admin" => array("fondo", "Fondo"),
			"scode_admin" => array("seguridad", "Seguridad"),
			"chatgpt_admin" => array("chatgpt", "ChatGPT"),
			"id_number_whatsapp" => array("Id Número", "ID del número"),
			"id_app_whatsapp" => array("Id App", "ID de la app"),
			"id_whatsapp_message" => array("API-WS", "WhatsApp"),
			"header_text_bot" => array("Header Text", "Encabezado (texto)"),
			"header_image_bot" => array("Header Image", "Encabezado (imagen)"),
			"header_video_bot" => array("Header Video", "Encabezado (video)"),
			"body_text_bot" => array("Body Text", "Mensaje"),
			"footer_text_bot" => array("Footer Text", "Pie del mensaje"),
			"title_list_bot" => array("Título Lista", "Título de la lista"),
			"email_order" => array("Email", "Correo"),
			"content_prompt" => array("Contenido", "Instrucciones")
		);

		$stmt = $pdo->prepare("UPDATE `columns` SET `alias_column` = ? WHERE `title_column` = ? AND `alias_column` = ?");

		foreach ($aliases as $column => $pair) {

			$stmt->execute(array($pair[1], $column, $pair[0]));
		}
	}

	/*=============================================
	v5
	=============================================*/

	/* Releer: en una instalación nueva las tablas de arriba se acaban de crear */

	$tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);

	/* Historial permanente: la hora de cada mensaje ya no cambia
	   cuando WhatsApp actualiza su estado (enviado/entregado/leído) */

	if(in_array("messages", $tables, true)){

		$col = $pdo->query("SHOW COLUMNS FROM `messages` LIKE 'date_updated_message'")->fetch(PDO::FETCH_ASSOC);

		if($col && stripos((string)($col["Extra"] ?? ""), "on update") !== false){

			$pdo->exec("ALTER TABLE `messages` MODIFY `date_updated_message` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP");
		}
	}

	/* Pedidos: detalle, entrega, cobertura y pago */

	$addColumn("orders", "items_order", "LONGTEXT NULL DEFAULT NULL");
	$addColumn("orders", "delivery_order", "VARCHAR(20) NULL DEFAULT NULL");
	$addColumn("orders", "reference_order", "TEXT NULL DEFAULT NULL");
	$addColumn("orders", "payment_order", "VARCHAR(80) NULL DEFAULT NULL");
	$addColumn("orders", "notes_order", "TEXT NULL DEFAULT NULL");
	$addColumn("orders", "subtotal_order", "DOUBLE NULL DEFAULT NULL");
	$addColumn("orders", "fee_order", "DOUBLE NULL DEFAULT NULL");
	$addColumn("orders", "lat_order", "DOUBLE NULL DEFAULT NULL");
	$addColumn("orders", "lng_order", "DOUBLE NULL DEFAULT NULL");
	$addColumn("orders", "distance_order", "DOUBLE NULL DEFAULT NULL");
	$addColumn("orders", "coverage_order", "VARCHAR(20) NULL DEFAULT NULL");
	$addColumn("orders", "source_order", "VARCHAR(20) NULL DEFAULT NULL");
	$addColumn("orders", "created_at_order", "DATETIME NULL DEFAULT NULL");
	$addIndex("orders", "idx_contact_order", "`contact_order`(32)");

	if(in_array("orders", $tables, true)){

		$pdo->exec("UPDATE `orders` SET `created_at_order` = CONCAT(`date_created_order`, ' 12:00:00') WHERE `created_at_order` IS NULL AND `date_created_order` IS NOT NULL");
	}

	/* Google Calendar: id del evento sincronizado */

	$addColumn("appointments", "gcal_event_appointment", "VARCHAR(255) NULL DEFAULT NULL");
	$addColumn("appointments", "people_appointment", "INT(11) NULL DEFAULT NULL");

	/* Verificaciones de cobertura de delivery */

	$pdo->exec("CREATE TABLE IF NOT EXISTS `coverage_checks` (
		`id_check` INT(11) NOT NULL AUTO_INCREMENT,
		`phone_check` VARCHAR(32) NULL,
		`query_check` TEXT NULL,
		`label_check` TEXT NULL,
		`lat_check` DOUBLE NULL,
		`lng_check` DOUBLE NULL,
		`distance_check` DOUBLE NULL,
		`covered_check` VARCHAR(20) NOT NULL DEFAULT 'desconocido',
		`zone_check` VARCHAR(191) NULL,
		`fee_check` DOUBLE NULL,
		`date_check` DATETIME NULL,
		PRIMARY KEY (`id_check`),
		KEY `idx_phone_check` (`phone_check`)
	) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

	if(in_array("pages", $tables, true)){

		/* v6: sucursales, instalación vinculada a la orden y documento del titular */

		$tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);

		$addColumn("coverage_checks", "branch_check", "VARCHAR(120) NULL DEFAULT NULL");
		$addColumn("orders", "branch_order", "VARCHAR(120) NULL DEFAULT NULL");
		$addColumn("orders", "appointment_order", "INT(11) NULL DEFAULT NULL");
		$addColumn("orders", "document_order", "VARCHAR(30) NULL DEFAULT NULL");
		$addColumn("appointments", "branch_appointment", "VARCHAR(120) NULL DEFAULT NULL");

		/* v8: WhatsApp pasa a ser una página propia (verifica los datos con Meta al guardar) */

		$pdo->exec("UPDATE `pages` SET `type_page` = 'custom', `title_page` = IF(`title_page` = 'API-WS', 'WhatsApp', `title_page`) WHERE `url_page` = 'api-ws'");

		/* v6: íconos de venta de internet en lugar de cocina (solo si no se personalizaron) */

		$pdo->exec("UPDATE `pages` SET `icon_page` = 'bi bi-wifi' WHERE `url_page` = 'productos' AND `icon_page` = 'fas fa-utensils'");
		$pdo->exec("UPDATE `pages` SET `icon_page` = 'bi bi-collection' WHERE `url_page` = 'categorias' AND `icon_page` = 'bi bi-card-checklist'");
		$pdo->exec("UPDATE `pages` SET `icon_page` = 'bi bi-clipboard-check' WHERE `url_page` = 'ordenes' AND `icon_page` IN ('bi bi-bag-check', 'bi bi-receipt')");

		/* Órdenes pasa a ser una página propia (pedidos claros, no una tabla) */

		$pdo->exec("UPDATE `pages` SET `type_page` = 'custom', `icon_page` = 'bi bi-clipboard-check' WHERE `url_page` = 'ordenes'");

		$exists = (int)$pdo->query("SELECT COUNT(*) FROM `pages` WHERE `url_page` = 'ordenes'")->fetchColumn();

		if(!$exists){

			$insert = $pdo->prepare("INSERT INTO `pages` (`title_page`, `url_page`, `icon_page`, `type_page`, `order_page`, `date_created_page`) VALUES ('Órdenes', 'ordenes', 'bi bi-clipboard-check', 'custom', 4, ?)");
			$insert->execute(array(date("Y-m-d")));
		}

		/* La sección "Mensajes/Chats" (tabla cruda de mensajes) repetía el Chat.
		   Se quita del menú; los mensajes se conservan en la base de datos. */

		$old = $pdo->query("SELECT `id_page` FROM `pages` WHERE `url_page` IN ('mensajes', 'chats') AND `type_page` = 'modules'")->fetchAll(PDO::FETCH_COLUMN);

		foreach ($old as $idPage) {

			if(in_array("modules", $tables, true)){

				$mods = $pdo->prepare("SELECT `id_module` FROM `modules` WHERE `id_page_module` = ?");
				$mods->execute(array((int)$idPage));

				foreach ($mods->fetchAll(PDO::FETCH_COLUMN) as $idModule) {

					if(in_array("columns", $tables, true)){

						$pdo->prepare("DELETE FROM `columns` WHERE `id_module_column` = ?")->execute(array((int)$idModule));
					}
				}

				$pdo->prepare("DELETE FROM `modules` WHERE `id_page_module` = ?")->execute(array((int)$idPage));
			}

			$pdo->prepare("DELETE FROM `pages` WHERE `id_page` = ?")->execute(array((int)$idPage));
		}
	}

	/*=============================================
	v9: consumo (IA y WhatsApp), seguimiento de
	conversaciones, avisos de órdenes y página Inicio
	=============================================*/

	$pdo->exec("CREATE TABLE IF NOT EXISTS `usage_log` (
		`id_usage` INT(11) NOT NULL AUTO_INCREMENT,
		`kind_usage` VARCHAR(10) NOT NULL,
		`provider_usage` VARCHAR(30) NULL,
		`model_usage` VARCHAR(80) NULL,
		`input_usage` INT(11) NOT NULL DEFAULT 0,
		`output_usage` INT(11) NOT NULL DEFAULT 0,
		`ok_usage` TINYINT(1) NOT NULL DEFAULT 1,
		`detail_usage` VARCHAR(40) NULL,
		`phone_usage` VARCHAR(30) NULL,
		`created_usage` DATETIME NOT NULL,
		PRIMARY KEY (`id_usage`),
		KEY `idx_kind_created` (`kind_usage`, `created_usage`)
	) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

	$pdo->exec("CREATE TABLE IF NOT EXISTS `followups` (
		`phone_followup` VARCHAR(30) NOT NULL,
		`order_followup` INT(11) NULL,
		`stage_followup` TINYINT(1) NOT NULL DEFAULT 0,
		`updated_followup` DATETIME NOT NULL,
		PRIMARY KEY (`phone_followup`)
	) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

	$tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);

	$addColumn("orders", "notify_order", "TEXT NULL DEFAULT NULL");
	$addColumn("orders", "closed_at_order", "DATETIME NULL DEFAULT NULL");
	$addColumn("orders", "cancel_reason_order", "VARCHAR(255) NULL DEFAULT NULL");
	$addIndex("messages", "idx_date_message", "`date_updated_message`");

	/* Órdenes: ya no hay estados intermedios. Activa (Pendiente), Cancelado o Completado */

	if(in_array("orders", $tables, true)){

		$pdo->exec("UPDATE `orders` SET `status_order` = 'Pendiente' WHERE `status_order` IN ('Confirmado', 'En preparación', 'En camino')");
		$pdo->exec("UPDATE `orders` SET `status_order` = 'Completado' WHERE `status_order` = 'Entregado'");
	}

	if(in_array("pages", $tables, true)){

		$exists = (int)$pdo->query("SELECT COUNT(*) FROM `pages` WHERE `url_page` = 'inicio'")->fetchColumn();

		if(!$exists){

			$pdo->exec("UPDATE `pages` SET `order_page` = `order_page` + 1");
			$insert = $pdo->prepare("INSERT INTO `pages` (`title_page`, `url_page`, `icon_page`, `type_page`, `order_page`, `date_created_page`) VALUES ('Inicio', 'inicio', 'bi bi-speedometer2', 'custom', 1, ?)");
			$insert->execute(array(date("Y-m-d")));
		}
	}

	/* v10: ubicación (enlace de Maps) y fotos del cliente en la orden; páginas ocultas del menú */

	$addColumn("orders", "location_order", "VARCHAR(500) NULL DEFAULT NULL");
	$addColumn("orders", "photos_order", "TEXT NULL DEFAULT NULL");
	$addColumn("pages", "visible_page", "TINYINT(1) NOT NULL DEFAULT 1");

	/* v10: personalidad de la asesora como instrucción activa (la anterior queda guardada, desactivada) */

	$personality = CMS_DIR."/setup/prompt_anyela.html";

	if(in_array("prompts", $tables, true) && is_file($personality)){

		$title = "Anyela · Asesora de Fibra Demo";

		/* v11: la asesora se llama Anyela (antes Luz). Solo cambia el nombre propio:
		   REPLACE distingue mayúsculas, así que «recibo de luz» no se toca */

		$pdo->prepare("UPDATE `prompts` SET `title_prompt` = ?, `content_prompt` = REPLACE(`content_prompt`, 'Luz', 'Anyela') WHERE `title_prompt` = 'Luz · Asesora de Fibra Demo'")->execute(array($title));

		$exists = $pdo->prepare("SELECT COUNT(*) FROM `prompts` WHERE `title_prompt` = ?");
		$exists->execute(array($title));

		if(!(int)$exists->fetchColumn()){

			$pdo->exec("UPDATE `prompts` SET `status_prompt` = 0");
			$pdo->prepare("INSERT INTO `prompts` (`title_prompt`, `content_prompt`, `status_prompt`, `date_created_prompt`) VALUES (?, ?, 1, ?)")
				->execute(array($title, (string)file_get_contents($personality), date("Y-m-d")));
		}

		/* v12: saludo corto (una línea con nombre + pregunta, sin describir la empresa ni listar planes) */

		$pdo->prepare("UPDATE `prompts` SET `content_prompt` = REPLACE(REPLACE(`content_prompt`, ?, ?), ?, ?) WHERE `title_prompt` = ? AND `content_prompt` NOT LIKE '%<h4>Primer mensaje</h4>%'")->execute(array(
			"<li>Presentarte con tu nombre como asesora de Fibra Demo, empresa de internet, y generar confianza.</li><li>Preguntar si le interesa contratar internet o algún servicio en particular.</li>",
			"<li>Saludar en un mensaje corto (máximo 2 oraciones) con tu nombre como asesora de Fibra Demo y preguntar si le interesa contratar internet o algún servicio en particular.</li>",
			"<h4>Expresiones recomendadas</h4>",
			"<h4>Primer mensaje</h4><p>El saludo debe ser corto y directo, sin abrumar: una sola línea con tu nombre y una pregunta. Ejemplo: \"¡Hola! 👋 Soy Anyela, asesora de Fibra Demo. ¿Te interesa contratar internet o algún servicio en particular?\". En el saludo no describas la empresa, beneficios, planes ni precios, y no envíes listas.</p><h4>Expresiones recomendadas</h4>",
			$title
		));

		/* v13: el recibo puede ser de luz o de agua */

		$pdo->prepare("UPDATE `prompts` SET `content_prompt` = REPLACE(`content_prompt`, 'foto de su recibo de luz', 'foto de su recibo de luz o agua') WHERE `title_prompt` = ? AND `content_prompt` NOT LIKE '%recibo de luz o agua%'")->execute(array($title));
	}

	/* v14: inconvenientes de la atención (cancelaciones, reclamos, fallas) con la
	   retroalimentación del cliente, y su página en el menú */

	$pdo->exec("CREATE TABLE IF NOT EXISTS `incidents` (
		`id_incident` INT(11) NOT NULL AUTO_INCREMENT,
		`phone_incident` VARCHAR(32) NULL,
		`name_incident` VARCHAR(191) NULL,
		`order_incident` INT(11) NULL,
		`kind_incident` VARCHAR(20) NOT NULL DEFAULT 'otro',
		`detail_incident` TEXT NULL,
		`feedback_incident` TEXT NULL,
		`note_incident` TEXT NULL,
		`status_incident` VARCHAR(20) NOT NULL DEFAULT 'abierto',
		`source_incident` VARCHAR(20) NULL,
		`created_incident` DATETIME NOT NULL,
		`updated_incident` DATETIME NULL,
		`resolved_incident` DATETIME NULL,
		PRIMARY KEY (`id_incident`),
		KEY `idx_status_incident` (`status_incident`),
		KEY `idx_phone_incident` (`phone_incident`),
		KEY `idx_created_incident` (`created_incident`)
	) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

	$tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);

	if(in_array("pages", $tables, true)){

		$exists = (int)$pdo->query("SELECT COUNT(*) FROM `pages` WHERE `url_page` = 'inconvenientes'")->fetchColumn();

		if(!$exists){

			$after = (int)$pdo->query("SELECT COALESCE(MAX(`order_page`), 4) FROM `pages` WHERE `url_page` = 'ordenes'")->fetchColumn();

			$pdo->prepare("UPDATE `pages` SET `order_page` = `order_page` + 1 WHERE `order_page` > ?")->execute(array($after));
			$pdo->prepare("INSERT INTO `pages` (`title_page`, `url_page`, `icon_page`, `type_page`, `order_page`, `date_created_page`) VALUES ('Inconvenientes', 'inconvenientes', 'bi bi-exclamation-octagon', 'custom', ?, ?)")
				->execute(array($after + 1, date("Y-m-d")));
		}
	}

}

/*=============================================
Aplicar si hace falta (llamado desde bootstrap.php)
=============================================*/

function cms_schema_check($force = false){

	$marker = cms_schema_marker();
	$current = is_file($marker) ? (int)trim((string)@file_get_contents($marker)) : 1;

	if(!$force && $current >= CMS_SCHEMA_VERSION){
		return true;
	}

	try{

		cms_schema_migrate(cms_schema_pdo());

		@file_put_contents($marker, (string)CMS_SCHEMA_VERSION, LOCK_EX);

		return true;

	}catch(Throwable $e){

		error_log("Schema migration error: ".$e->getMessage());

		return false;
	}

}
