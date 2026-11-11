<?php

require_once "controllers/business.store.php";
require_once "controllers/ai.service.php";

if(empty($_SESSION["biz_csrf"])){

	$_SESSION["biz_csrf"] = bin2hex(random_bytes(24));
}

/*=============================================
Leer los bots con el mismo formato que usa
BotsController al enviarlos por WhatsApp
=============================================*/

$botText = function($value){

	return str_replace(array("\r\n", "\r", "\\n"), "\n", trim(urldecode((string)$value)));

};

$bots = array();
$categories = array();
$stats = array("ai_off" => 0, "total" => 0);

try{

	$pdo = BizStore::pdo();

	foreach ($pdo->query("SELECT * FROM bots ORDER BY id_bot ASC")->fetchAll() as $b) {

		$buttons = array();

		foreach ((array)json_decode(urldecode((string)$b["buttons_bot"]), true) as $id => $title) {

			$buttons[] = array("id" => (string)$id, "title" => $botText($title));
		}

		$rows = array();

		foreach ((array)json_decode(urldecode((string)$b["list_bot"]), true) as $r) {

			if(is_array($r)){

				$rows[] = array("id" => (string)($r["id"] ?? ""), "title" => $botText($r["title"] ?? ""), "description" => $botText($r["description"] ?? ""));
			}
		}

		$bots[] = array(
			"id" => (int)$b["id_bot"],
			"key" => (string)$b["title_bot"],
			"type" => (string)$b["type_bot"],
			"interactive" => (string)$b["interactive_bot"],
			"header_text" => $botText($b["header_text_bot"]),
			"header_image" => trim(urldecode((string)$b["header_image_bot"])),
			"header_video" => trim(urldecode((string)$b["header_video_bot"])),
			"body" => $botText($b["body_text_bot"]),
			"footer" => $botText($b["footer_text_bot"]),
			"buttons" => $buttons,
			"rows" => $rows,
			"list_title" => $botText($b["title_list_bot"]),
			"updated" => (string)$b["date_updated_bot"]
		);
	}

	try{

		foreach ($pdo->query("SELECT id_category, title_category FROM categories ORDER BY id_category ASC")->fetchAll() as $c) {

			$categories[] = array("id" => (string)$c["id_category"], "title" => $botText($c["title_category"]));
		}

	}catch(Throwable $e){}

	try{

		$stats["total"] = (int)$pdo->query("SELECT COUNT(*) FROM contacts")->fetchColumn();
		$stats["ai_off"] = (int)$pdo->query("SELECT COUNT(*) FROM contacts WHERE ai_contact = 0 OR ai_contact IS NULL")->fetchColumn();

	}catch(Throwable $e){}

}catch(Throwable $e){

	error_log("Bots: ".$e->getMessage());
}

$aiReady = AIService::enabled();

?>

<div class="page-wrap">

	<div class="page-head">
		<div>
			<h1 class="page-title"><i class="bi bi-diagram-3"></i> Bots</h1>
			<p class="page-sub">Mensajes automáticos con botones y listas. Guían al cliente paso a paso cuando la IA está apagada.</p>
		</div>
		<div class="d-flex gap-2">
			<button type="button" class="btn btn-ghost" data-bs-toggle="collapse" data-bs-target="#botsHelp"><i class="bi bi-question-circle"></i> ¿Cómo funciona?</button>
		</div>
	</div>

	<!-- ¿Cuándo responden los bots? -->

	<div class="bots-status">
		<div class="bots-status-item">
			<span class="bots-status-icon <?php echo $aiReady ? "ok" : "off" ?>"><i class="bi bi-robot"></i></span>
			<div>
				<strong><?php echo $aiReady ? "La IA está configurada" : "La IA no está configurada" ?></strong>
				<span><?php echo $aiReady
					? "Responde la IA a los contactos que tienen el robot activado en el chat."
					: "Todos los contactos reciben estos bots." ?></span>
			</div>
		</div>
		<div class="bots-status-item">
			<span class="bots-status-icon brand"><i class="bi bi-diagram-3"></i></span>
			<div>
				<strong><?php echo $aiReady ? number_format($stats["ai_off"], 0, ",", ".")." de ".number_format($stats["total"], 0, ",", ".")." contactos" : "Todos los contactos" ?></strong>
				<span>reciben estos bots (los que tienen la IA apagada).</span>
			</div>
		</div>
		<div class="bots-status-item">
			<span class="bots-status-icon warn"><i class="bi bi-paperclip"></i></span>
			<div>
				<strong>Archivos</strong>
				<span>Si un cliente envía una foto, audio o documento, siempre se responde con el bot «Archivo recibido».</span>
			</div>
		</div>
	</div>

	<div class="collapse" id="botsHelp">
		<div class="card-soft mb-4">
			<div class="row g-4">
				<div class="col-md-4">
					<h3 class="help-title"><i class="bi bi-1-circle"></i> Cada tarjeta es un mensaje</h3>
					<p class="text-muted mb-0">El sistema los envía en orden según lo que el cliente toque. Tú solo cambias el texto, la imagen y las opciones: el orden ya está armado.</p>
				</div>
				<div class="col-md-4">
					<h3 class="help-title"><i class="bi bi-2-circle"></i> Botones y listas</h3>
					<p class="text-muted mb-0">WhatsApp permite hasta <strong>3 botones</strong> (20 letras cada uno) o una <strong>lista de 10 opciones</strong> (24 letras de título). Los contadores te avisan si te pasas.</p>
				</div>
				<div class="col-md-4">
					<h3 class="help-title"><i class="bi bi-3-circle"></i> Formato del texto</h3>
					<p class="text-muted mb-0">Usa <code>*negrita*</code>, <code>_cursiva_</code> y <code>~tachado~</code>, como en WhatsApp. Los emojis también funcionan 😉.</p>
				</div>
			</div>
		</div>
	</div>

	<!-- Recorrido del cliente -->

	<div class="flow-strip" id="flowStrip"></div>

	<!-- Pasos -->

	<div id="botsBoard"></div>

</div>

<!-- Editor -->

<div class="modal fade" id="botModal" tabindex="-1">
	<div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
		<div class="modal-content">

			<div class="modal-header">
				<div>
					<h5 class="modal-title d-flex align-items-center gap-2"><span class="bot-icon sm" id="botModalIcon"></span> <span id="botModalTitle"></span></h5>
					<div class="small text-muted mt-1" id="botModalWhen"></div>
				</div>
				<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
			</div>

			<div class="modal-body">
				<div class="row g-4">
					<div class="col-lg-7" id="botForm"></div>
					<div class="col-lg-5">
						<div class="wa-sticky">
							<div class="form-label">Vista previa</div>
							<div class="wa-phone">
								<div class="wa-top"><i class="bi bi-arrow-left"></i><span class="wa-avatar"><i class="bi bi-shop"></i></span><div><strong>Tu negocio</strong><small>en línea</small></div></div>
								<div class="wa-chat" id="botPreview"></div>
							</div>
						</div>
					</div>
				</div>
			</div>

			<div class="modal-footer d-flex justify-content-between">
				<button type="button" class="btn btn-ghost" data-bs-dismiss="modal">Cancelar</button>
				<button type="button" class="btn btn-brand" id="botSave"><i class="bi bi-check2"></i> Guardar cambios</button>
			</div>

		</div>
	</div>
</div>

<script>
	window.botsData = <?php echo json_encode($bots, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
	window.botsCategories = <?php echo json_encode($categories, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
	window.bizCsrf = <?php echo json_encode($_SESSION["biz_csrf"]) ?>;
</script>

<script src="/views/assets/js/business/bots.js?v=2"></script>
