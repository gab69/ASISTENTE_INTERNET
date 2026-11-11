<?php

require_once "controllers/chat.controller.php";
require_once "controllers/chat.service.php";

/*=============================================
Contactos recientes (ordenados por el último mensaje)
=============================================*/

$contacts = ChatController::contacts();

/*=============================================
Contacto abierto: ?phone=51987654321
(también acepta el formato antiguo ?phone=51987654321_4)
=============================================*/

$phoneContact = isset($_GET["phone"]) ? ChatService::cleanPhone(explode("_", (string)$_GET["phone"])[0]) : "";
$currentContact = $phoneContact !== "" ? ChatController::contact($phoneContact) : null;

/* Sin teléfono en la URL: abrir el chat más reciente solo en pantallas grandes (lo decide el JS) */

$phoneContact = $currentContact ? $currentContact->phone_contact : "";
$messages = $phoneContact !== "" ? ChatController::messages($phoneContact) : array();

$firstIdMessage = !empty($messages) ? (int)$messages[0]->id_message : 0;
$lastIdMessage = !empty($messages) ? (int)end($messages)->id_message : 0;
$lastDay = !empty($messages) ? TemplateController::formatDate(8, end($messages)->date_updated_message) : "";

?>

<div class="chat-app <?php echo $currentContact ? "has-chat" : "" ?>" id="chatApp">

	<audio id="chatSound" src="/views/assets/files/68143a7deacde37.mp3" preload="auto"></audio>
	<audio id="messageSound" src="/views/assets/files/68143bbc3cafe56.mp3" preload="auto"></audio>

	<?php

		include "modules/contact-list/contact-list.php";
		include "modules/chat-container/chat-container.php";

	?>

</div>

<script>
	window.chatState = {
		phone: <?php echo json_encode($phoneContact) ?>,
		firstId: <?php echo (int)$firstIdMessage ?>,
		lastId: <?php echo (int)$lastIdMessage ?>,
		lastDay: <?php echo json_encode($lastDay) ?>,
		globalId: <?php echo (int)ChatController::lastGlobalId() ?>,
		hasMore: <?php echo count($messages) >= ChatController::PAGE ? "true" : "false" ?>,
		moreContacts: <?php echo count($contacts) >= ChatController::CONTACTS_PAGE ? "true" : "false" ?>
	};
</script>

<script src="/views/assets/js/chat/chat.js?v=4"></script>
