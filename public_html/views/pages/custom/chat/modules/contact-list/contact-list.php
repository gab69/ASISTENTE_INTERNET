<aside class="chat-list">

	<div class="chat-list-head">
		<h1><i class="bi bi-chat-dots"></i> Chat</h1>
		<span class="chat-live" title="Actualización en vivo"><span></span> En vivo</span>
	</div>

	<label class="chat-search">
		<i class="bi bi-search"></i>
		<input type="search" class="searchContact" placeholder="Buscar nombre, número o mensaje…" autocomplete="off">
	</label>

	<div class="chat-filters" role="tablist">
		<button type="button" class="active" data-filter="">Todos</button>
		<button type="button" data-filter="pending">Sin responder</button>
		<button type="button" data-filter="ai"><i class="bi bi-stars"></i> Con IA</button>
	</div>

	<div class="chat-contacts" id="contactList">
		<?php if (empty($contacts)): ?>
			<div class="chat-empty-list"><i class="bi bi-inbox"></i><p>Aún no hay conversaciones. Cuando un cliente escriba a tu WhatsApp aparecerá aquí.</p></div>
		<?php else: ?>
			<?php echo ChatController::renderContacts($contacts, $phoneContact) ?>
		<?php endif ?>
	</div>

	<button type="button" class="chat-more" id="moreContacts" <?php echo count($contacts) >= ChatController::CONTACTS_PAGE ? "" : "hidden" ?>>Ver más conversaciones</button>

</aside>
