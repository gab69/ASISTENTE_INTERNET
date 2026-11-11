<section class="chat-main" id="chatContainer">

	<?php if (!empty($currentContact)): ?>

		<!--======================================
		Encabezado de la conversación
		========================================-->

		<header class="chat-head">

			<button type="button" class="btn-icon chat-back" id="chatBack" title="Volver a la lista"><i class="bi bi-arrow-left"></i></button>

			<span class="ct-avatar" style="--av:<?php echo ChatController::avatarColor($phoneContact) ?>"><?php echo ChatController::initials($currentContact) ?></span>

			<div class="chat-head-name">
				<strong><?php echo ChatController::e(ChatController::displayName($currentContact)) ?></strong>
				<small>
					<?php echo ChatController::e(ChatController::formatPhone($phoneContact)) ?>
					<span class="ai-badge" id="aiBadge" <?php echo $currentContact->ai_contact == 1 ? "" : "hidden" ?>><i class="bi bi-stars"></i> IA activa</span>
				</small>
			</div>

			<div class="chat-head-tools">
				<span class="form-check form-switch m-0 me-1" title="Asistente IA para este contacto">
					<input type="checkbox" class="form-check-input changeAI" idContact="<?php echo (int)$currentContact->id_contact ?>" <?php echo $currentContact->ai_contact == 1 ? "checked" : "" ?>>
				</span>
				<button type="button" class="btn-icon" id="infoToggle" title="Pedidos, citas y datos del contacto"><i class="bi bi-layout-sidebar-reverse"></i></button>
			</div>

		</header>

		<!--======================================
		Mensajes (historial completo)
		========================================-->

		<div class="chat-body" id="chatBody">

			<div class="chat-older" id="olderBox" <?php echo count($messages) >= ChatController::PAGE ? "" : "hidden" ?>>
				<button type="button" class="chip" id="loadOlder"><i class="bi bi-clock-history"></i> Cargar mensajes anteriores</button>
			</div>

			<div class="chat-start" id="chatStart" <?php echo count($messages) >= ChatController::PAGE ? "hidden" : "" ?>>
				<i class="bi bi-shield-check"></i> Inicio de la conversación · todo el historial queda guardado
			</div>

			<div id="chatMessages">
				<?php echo ChatController::renderMessages($messages) ?>
			</div>

		</div>

		<!--======================================
		Enviar mensaje manual
		========================================-->

		<footer class="chat-compose">

			<span class="attach-wrap">
				<button type="button" class="btn-icon myFiles" title="Adjuntar archivo"><i class="bi bi-paperclip"></i></button>
				<input type="hidden" id="attachUrl">
			</span>

			<textarea id="userInput" rows="1" placeholder="Escribe un mensaje…  (Enter envía · Shift+Enter nueva línea)" autocomplete="off"></textarea>

			<button type="button" class="chat-send send" title="Enviar"><i class="bi bi-send-fill"></i></button>

		</footer>

		<p class="chat-note"><i class="bi bi-info-circle"></i> WhatsApp solo permite responder dentro de las 24 h posteriores al último mensaje del cliente.</p>

	<?php else: ?>

		<div class="chat-welcome">
			<span><i class="bi bi-chat-square-text"></i></span>
			<h2>Elige una conversación</h2>
			<p>Todos los mensajes quedan guardados de forma permanente para que puedas revisarlos cuando quieras.</p>
		</div>

	<?php endif ?>

</section>

<!--======================================
Panel lateral: datos, pedidos y citas
========================================-->

<aside class="chat-info" id="chatInfo" <?php echo empty($currentContact) ? "hidden" : "" ?>>
	<button type="button" class="btn-icon chat-info-close" id="infoClose" title="Cerrar"><i class="bi bi-x-lg"></i></button>
	<div id="chatInfoBody">
		<?php echo !empty($currentContact) ? ChatController::renderInfo($currentContact) : "" ?>
	</div>
</aside>

<?php

  include "views/modules/modals/files.php";

  require_once "controllers/clients.controller.php";
  $contact = new ClientsController();
  $contact -> updateContact();

  include "views/modules/modals/contact.php";

?>
