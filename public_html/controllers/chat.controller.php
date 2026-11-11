<?php

require_once dirname(__DIR__)."/bootstrap.php";
require_once __DIR__."/curl.controller.php";
require_once __DIR__."/template.controller.php";
require_once __DIR__."/business.store.php";

/*=============================================
Presentación del chat (panel): un único lugar para
dibujar mensajes y contactos, usado por la página y
por las actualizaciones en vivo (ajax/chat.ajax.php).

El historial es permanente: todos los mensajes quedan
guardados y se pueden revisar hacia atrás sin límite.
=============================================*/

class ChatController{

	const PAGE = 60;
	const CONTACTS_PAGE = 40;

	private static $bots = array();

	static public function e($value){

		return htmlspecialchars((string)$value, ENT_QUOTES, "UTF-8");

	}

	static private function pdo(){

		return BizStore::pdo();

	}

	/* Texto seguro: escapa HTML, *negrita*, _cursiva_, saltos de línea y enlaces */

	static public function format($text){

		$html = self::e($text);
		$html = preg_replace('~(https?://[^\s<]+)~', '<a href="$1" target="_blank" rel="noopener">$1</a>', $html);
		$html = preg_replace('/\*([^*\n]+)\*/', '<strong>$1</strong>', $html);
		$html = preg_replace('/(^|\s)_([^_\n]+)_(?=\s|$)/', '$1<em>$2</em>', $html);

		return nl2br($html);

	}

	static private function bot($title){

		if(!array_key_exists($title, self::$bots)){

			$get = CurlController::request("bots?linkTo=title_bot&equalTo=".rawurlencode($title), "GET", array());
			self::$bots[$title] = (isset($get->status) && $get->status == 200) ? $get->results[0] : null;
		}

		return self::$bots[$title];

	}

	static private function decodeText($value){

		return str_replace(array("\r\n", "\\n"), "\n", urldecode((string)$value));

	}

	/* Archivo multimedia: se descarga de Meta solo cuando se ve */

	static private function media($type, $url, $caption = ""){

		$url = self::e($url);

		if($type === "image"){
			$html = '<a href="'.$url.'" target="_blank" class="msg-media"><img src="'.$url.'" loading="lazy" alt="Imagen"></a>';
		}else if($type === "video"){
			$html = '<video src="'.$url.'" controls preload="none" class="msg-media"></video>';
		}else if($type === "audio"){
			$html = '<audio src="'.$url.'" controls preload="none"></audio>';
		}else{
			$html = '<a href="'.$url.'" target="_blank" class="msg-doc"><i class="bi bi-file-earmark-text"></i><span>Ver documento</span></a>';
		}

		return $html.($caption !== "" ? '<div class="msg-caption">'.self::format($caption).'</div>' : "");

	}

	/*=============================================
	Contenido de un mensaje del cliente
	=============================================*/

	static private function clientContent($m){

		$json = json_decode((string)$m->client_message);

		if(is_object($json) && isset($json->text)){

			return '<span class="msg-reply"><i class="bi bi-hand-index"></i></span> '.self::format($json->text);
		}

		if(is_object($json) && isset($json->type, $json->id)){

			$url = "/ajax/media.php?id=".rawurlencode($json->id);

			return self::media($json->type, $url, urldecode((string)($json->caption ?? "")));
		}

		$text = (string)$m->client_message;

		/* Ubicación enviada desde WhatsApp */

		if(preg_match('~^📍(.*?)(https://maps\.google\.com/\?q=([-\d.]+),([-\d.]+))~u', $text, $loc)){

			return '<a class="msg-location" href="'.self::e($loc[2]).'" target="_blank" rel="noopener"><i class="bi bi-geo-alt-fill"></i><span><strong>Ubicación</strong><small>'.self::e(trim($loc[1]) ?: $loc[3].", ".$loc[4]).'</small></span></a>';
		}

		return self::format($text);

	}

	/*=============================================
	Contenido de un mensaje del negocio
	=============================================*/

	static private function businessContent($m){

		$template = json_decode((string)$m->template_message);
		$type = $template->type ?? "";

		/* Archivos enviados */

		$file = json_decode((string)$m->business_message);

		if(is_object($file) && isset($file->type, $file->title) && in_array($file->type, array("image", "video", "audio", "document"), true)){

			return self::media($file->type, urldecode($file->title));
		}

		/* Respuestas de IA, manuales y avisos: texto tal cual */

		if(in_array($type, array("ia", "manual", "consulta", "agenda", "pedido", "staff-ia", ""), true)){

			return self::format($m->business_message);
		}

		/* Plantillas de bot */

		$html = "";
		$bot = $type === "bot" ? self::bot($template->title ?? "") : null;

		if($bot){

			if(!empty($bot->header_image_bot)){
				$html .= self::media("image", urldecode($bot->header_image_bot));
			}

			if(!empty($bot->header_video_bot)){
				$html .= self::media("video", urldecode($bot->header_video_bot));
			}

			if(!empty($bot->header_text_bot)){
				$html .= '<div><strong>'.self::format(self::decodeText($bot->header_text_bot)).'</strong></div>';
			}

			$body = ($template->title ?? "") === "confirmation" || $bot->type_bot === "text" || empty($bot->body_text_bot)
				? $m->business_message
				: $bot->body_text_bot;

			$html .= self::format(self::decodeText($body));

			if(!empty($bot->footer_text_bot)){
				$html .= '<div class="msg-foot">'.self::format(self::decodeText($bot->footer_text_bot)).'</div>';
			}

			$options = "";

			if($bot->type_bot == "interactive" && $bot->interactive_bot == "button"){

				foreach ((array)json_decode(urldecode((string)$bot->buttons_bot)) as $item) {
					$options .= '<span class="msg-opt"><i class="bi bi-reply"></i> '.self::e(urldecode($item)).'</span>';
				}
			}

			if($bot->type_bot == "interactive" && $bot->interactive_bot == "list"){

				foreach ((array)json_decode(urldecode((string)$bot->list_bot)) as $item) {
					$options .= '<span class="msg-opt"><i class="bi bi-list-ul"></i> <strong>'.self::e($item->title ?? "").'</strong>'.(!empty($item->description) ? ' · '.self::e($item->description) : '').'</span>';
				}
			}

			return $html.($options !== "" ? '<div class="msg-opts">'.$options.'</div>' : "");
		}

		return self::format(self::decodeText($m->business_message));

	}

	/*=============================================
	Icono de estado de entrega
	=============================================*/

	static public function statusIcon($m){

		$status = (string)($m->status_message ?? "");

		if($status === "failed"){
			return '<i class="bi bi-exclamation-circle-fill text-danger" title="No entregado"></i>';
		}
		if($status === "read"){
			return '<i class="bi bi-check2-all is-read" title="Leído"></i>';
		}
		if($status === "delivered"){
			return '<i class="bi bi-check2-all" title="Entregado"></i>';
		}
		if($status === "sent"){
			return '<i class="bi bi-check2" title="Enviado"></i>';
		}

		/* Mensajes antiguos (antes de registrar el estado) */
		return "";

	}

	/* Quién respondió: IA, bot, equipo, aviso */

	static private function origin($m){

		$template = json_decode((string)$m->template_message);
		$type = $template->type ?? "";

		$labels = array(
			"ia" => array("bi-stars", "IA"),
			"bot" => array("bi-diagram-3", "Bot"),
			"manual" => array("bi-person", "Equipo"),
			"agenda" => array("bi-calendar2-check", "Agenda"),
			"pedido" => array("bi-clipboard-check", "Orden"),
			"consulta" => array("bi-patch-question", "Consulta"),
			"seguimiento" => array("bi-chat-square-heart", "Cierre automático"),
			"limite" => array("bi-pause-circle", "Límite de IA"),
			"staff" => array("bi-bell", "Aviso"),
			"staff-ia" => array("bi-person-badge", "Asistente del equipo"),
			"aviso" => array("bi-bell", "Aviso"),
			"image" => array("bi-image", "Archivo"),
			"video" => array("bi-camera-video", "Archivo"),
			"audio" => array("bi-mic", "Archivo"),
			"document" => array("bi-paperclip", "Archivo")
		);

		if(!isset($labels[$type])){

			return "";
		}

		return '<span class="msg-origin"><i class="bi '.$labels[$type][0].'"></i> '.$labels[$type][1].'</span>';

	}

	/*=============================================
	Burbuja completa de un mensaje
	=============================================*/

	static public function renderMessage($m){

		$id = (int)$m->id_message;
		$time = TemplateController::formatDate(6, $m->date_updated_message);

		if($m->type_message == "client"){

			return '<div class="msg in" data-id="'.$id.'">
				<div class="msg-body">'.self::clientContent($m).'</div>
				<div class="msg-meta"><span>'.self::e($time).'</span></div>
			</div>';
		}

		$failed = ($m->status_message ?? "") === "failed";
		$error = "";

		if($failed){

			$error = '<div class="msg-error"><i class="bi bi-exclamation-triangle"></i> '.self::e($m->error_message ?: "No se pudo enviar a WhatsApp").'</div>';
		}

		return '<div class="msg out'.($failed ? " failed" : "").'" data-id="'.$id.'">
			<div class="msg-body">'.self::businessContent($m).'</div>'.$error.'
			<div class="msg-meta">'.self::origin($m).'<span>'.self::e($time).'</span> <span class="msg-status">'.self::statusIcon($m).'</span></div>
		</div>';

	}

	/*=============================================
	Etiqueta de fecha entre mensajes
	=============================================*/

	static public function renderDate($date){

		$day = TemplateController::formatDate(8, $date);

		if($day == date("Y-m-d")){
			$label = "Hoy";
		}else if($day == date("Y-m-d", strtotime("-1 day"))){
			$label = "Ayer";
		}else{
			$label = TemplateController::formatDate(7, $date);
		}

		return '<div class="chat-date" data-day="'.self::e($day).'"><span>'.self::e($label).'</span></div>';

	}

	/* Mensajes con separadores de fecha. $prevDay: último día ya dibujado */

	static public function renderMessages(array $messages, $prevDay = ""){

		$html = "";

		foreach ($messages as $m) {

			$day = TemplateController::formatDate(8, $m->date_updated_message);

			if($day !== $prevDay){

				$html .= self::renderDate($m->date_updated_message);
				$prevDay = $day;
			}

			$html .= self::renderMessage($m);
		}

		return $html;

	}

	/*=============================================
	Mensajes de un teléfono (más antiguos primero)
	$beforeId: para cargar la página anterior
	=============================================*/

	static public function messages($phone, $limit = self::PAGE, $beforeId = 0){

		$sql = "SELECT * FROM messages WHERE phone_message = ?".($beforeId > 0 ? " AND id_message < ?" : "")." ORDER BY id_message DESC LIMIT ".max(1, min(500, (int)$limit));
		$args = array((string)$phone);

		if($beforeId > 0){

			$args[] = (int)$beforeId;
		}

		$stmt = self::pdo()->prepare($sql);
		$stmt->execute($args);

		$rows = array_map(function($r){ return (object)$r; }, $stmt->fetchAll(PDO::FETCH_ASSOC));

		return array_reverse($rows);

	}

	static public function countMessages($phone){

		$stmt = self::pdo()->prepare("SELECT COUNT(*) total, MIN(date_updated_message) first FROM messages WHERE phone_message = ?");
		$stmt->execute(array((string)$phone));

		return $stmt->fetch(PDO::FETCH_ASSOC);

	}

	static public function formatPhone($phone){

		$phone = (string)$phone;

		/* Perú: +51 987 654 321 */

		if(preg_match('/^51(9\d{2})(\d{3})(\d{3})$/', $phone, $m)){

			return "+51 ".$m[1]." ".$m[2]." ".$m[3];
		}

		return "+".mb_substr($phone, 0, 2)." ".mb_substr($phone, 2, 3)." ".mb_substr($phone, 5, 3)." ".mb_substr($phone, 8);

	}

	static public function displayName($c){

		return !empty($c->name_contact) ? (string)$c->name_contact : self::formatPhone($c->phone_contact);

	}

	static public function initials($c){

		if(empty($c->name_contact)){

			return '<i class="bi bi-person-fill"></i>';
		}

		$words = preg_split('/\s+/u', trim((string)$c->name_contact));
		$text = mb_strtoupper(mb_substr($words[0], 0, 1).(isset($words[1]) ? mb_substr($words[1], 0, 1) : ""));

		return self::e($text);

	}

	static public function avatarColor($phone){

		$colors = array("#0ea5e9", "#8b5cf6", "#f59e0b", "#10b981", "#ef4444", "#ec4899", "#14b8a6", "#6366f1", "#84cc16", "#f97316");

		return $colors[crc32((string)$phone) % count($colors)];

	}

	/*=============================================
	Lista de contactos con su último mensaje
	(una sola consulta, ordenada por actividad)
	$filter: "" | "pending" (sin responder) | "ai"
	=============================================*/

	static public function contacts($search = null, $limit = self::CONTACTS_PAGE, $offset = 0, $filter = ""){

		$where = array();
		$args = array();

		if($search !== null && trim($search) !== ""){

			$like = "%".trim($search)."%";
			$where[] = "(c.phone_contact LIKE ? OR c.name_contact LIKE ? OR c.phone_contact IN (SELECT DISTINCT phone_message FROM messages WHERE client_message LIKE ? OR business_message LIKE ?))";
			array_push($args, $like, $like, $like, $like);
		}

		if($filter === "pending"){

			$where[] = "m.type_message = 'client'";
		}

		if($filter === "ai"){

			$where[] = "c.ai_contact = 1";
		}

		$sql = "SELECT c.id_contact, c.phone_contact, c.name_contact, c.ai_contact, c.date_updated_contact,
				m.id_message, m.type_message, m.client_message, m.business_message, m.template_message, m.status_message, m.date_updated_message
			FROM contacts c
			LEFT JOIN messages m ON m.id_message = (SELECT MAX(m2.id_message) FROM messages m2 WHERE m2.phone_message = c.phone_contact)
			".(!empty($where) ? "WHERE ".implode(" AND ", $where) : "")."
			ORDER BY COALESCE(m.id_message, 0) DESC, c.date_updated_contact DESC
			LIMIT ".max(1, min(200, (int)$limit))." OFFSET ".max(0, (int)$offset);

		$stmt = self::pdo()->prepare($sql);
		$stmt->execute($args);

		return array_map(function($r){ return (object)$r; }, $stmt->fetchAll(PDO::FETCH_ASSOC));

	}

	static public function contact($phone){

		$stmt = self::pdo()->prepare("SELECT * FROM contacts WHERE phone_contact = ? LIMIT 1");
		$stmt->execute(array((string)$phone));
		$row = $stmt->fetch(PDO::FETCH_ASSOC);

		return $row ? (object)$row : null;

	}

	/* Vista previa del último mensaje */

	static private function preview($c){

		if(empty($c->id_message)){

			return "";
		}

		if($c->type_message == "client"){

			$json = json_decode((string)$c->client_message);

			if(is_object($json) && isset($json->text)){
				return (string)$json->text;
			}

			if(is_object($json) && isset($json->type)){
				$icons = array("image" => "📷 Foto", "video" => "🎥 Video", "audio" => "🎤 Audio", "document" => "📄 Documento");
				return $icons[$json->type] ?? "📎 Archivo";
			}

			return strpos((string)$c->client_message, "📍") === 0 ? "📍 Ubicación" : (string)$c->client_message;
		}

		if(($c->status_message ?? "") === "failed"){

			return "⚠ No enviado";
		}

		$file = json_decode((string)$c->business_message);

		if(is_object($file) && isset($file->type)){

			return "Tú: 📎 Archivo";
		}

		$text = (string)$c->business_message;

		if(strpos($text, " ") === false && preg_match('/\+|%[0-9A-Fa-f]{2}/', $text)){

			$text = urldecode($text);
		}

		return "Tú: ".str_replace(array("\\n", "\n", "*"), array(" ", " ", ""), $text);

	}

	static public function renderContact($c, $activePhone){

		$date = !empty($c->date_updated_message) ? $c->date_updated_message : $c->date_updated_contact;
		$day = TemplateController::formatDate(8, $date);

		if($day == date("Y-m-d")){
			$time = TemplateController::formatDate(6, $date);
		}else if($day == date("Y-m-d", strtotime("-1 day"))){
			$time = "Ayer";
		}else{
			$time = TemplateController::formatDate(5, $date);
		}

		$preview = trim(preg_replace('/\s+/u', " ", self::preview($c)));
		$preview = mb_strlen($preview) > 48 ? mb_substr($preview, 0, 48)."…" : $preview;
		$pending = ($c->type_message ?? "") === "client";
		$active = $activePhone !== "" && $activePhone == $c->phone_contact;

		return '<a href="/chat?phone='.rawurlencode($c->phone_contact).'" class="ct'.($active ? " active" : "").($pending ? " pending" : "").'" data-phone="'.self::e($c->phone_contact).'">
			<span class="ct-avatar" style="--av:'.self::avatarColor($c->phone_contact).'">'.self::initials($c).($c->ai_contact == 1 ? '<span class="ct-ai" title="Asistente IA activo"><i class="bi bi-stars"></i></span>' : '').'</span>
			<span class="ct-main">
				<span class="ct-top"><strong class="ct-name">'.self::e(self::displayName($c)).'</strong><span class="ct-time">'.self::e($time).'</span></span>
				<span class="ct-bottom"><span class="ct-preview">'.self::e($preview).'</span>'.($pending ? '<span class="ct-dot" title="Esperando respuesta"></span>' : '').'</span>
			</span>
		</a>';

	}

	static public function renderContacts(array $contacts, $activePhone){

		$html = "";

		foreach ($contacts as $c) {
			$html .= self::renderContact($c, $activePhone);
		}

		return $html;

	}

	/*=============================================
	Panel de información del contacto:
	pedidos y citas del cliente
	=============================================*/

	static public function renderInfo($c){

		if(!$c){

			return "";
		}

		$phone = (string)$c->phone_contact;
		$stats = self::countMessages($phone);
		$html = '<div class="info-head">
			<span class="ct-avatar lg" style="--av:'.self::avatarColor($phone).'">'.self::initials($c).'</span>
			<h3>'.self::e(self::displayName($c)).'</h3>
			<p>'.self::e(self::formatPhone($phone)).'</p>
			<div class="info-actions">
				<a class="chip" href="https://wa.me/'.self::e($phone).'" target="_blank" rel="noopener"><i class="bi bi-whatsapp"></i> WhatsApp</a>
				<button type="button" class="chip changeContact" infoContact=\''.self::e(json_encode(array("id_contact" => $c->id_contact, "phone_contact" => $phone, "name_contact" => $c->name_contact))).'\'><i class="bi bi-pencil"></i> Editar</button>
			</div>
		</div>';

		$html .= '<label class="info-ai">
			<span><i class="bi bi-stars"></i> <strong>Asistente IA</strong><small>Responde solo a este contacto</small></span>
			<span class="form-check form-switch m-0"><input type="checkbox" class="form-check-input changeAI" idContact="'.(int)$c->id_contact.'"'.($c->ai_contact == 1 ? " checked" : "").'></span>
		</label>';

		$html .= '<div class="info-stats"><div><strong>'.number_format((int)$stats["total"]).'</strong><span>mensajes guardados</span></div>'.
			(!empty($stats["first"]) ? '<div><strong>'.self::e(TemplateController::formatDate(5, $stats["first"])).'</strong><span>primer contacto</span></div>' : '').'</div>';

		/* Pedidos */

		try{

			require_once __DIR__."/business.orders.php";

			$orders = BizOrders::forPhone($phone, 5);

			$html .= '<div class="info-sec"><div class="info-sec-head"><span><i class="bi bi-clipboard-check"></i> Órdenes</span><a href="/ordenes?q='.rawurlencode($phone).'">Ver todos</a></div>';

			if(empty($orders)){

				$html .= '<p class="info-empty">Sin pedidos</p>';
			}

			foreach ($orders as $o) {

				$html .= '<a class="info-item" href="/ordenes?q='.rawurlencode("#".$o["id_order"]).'">
					<span><strong>#'.(int)$o["id_order"].' · '.self::e(cms_money($o["total_order"])).'</strong><small>'.self::e(TemplateController::formatDate(5, $o["created_at_order"] ?: $o["date_created_order"])).'</small></span>
					<span class="st st-'.self::e(preg_replace('/[^a-z]/', '', strtolower(strtr((string)$o["status_order"], array("ó" => "o", "é" => "e"))))).'">'.self::e(array("Pendiente" => "Activa", "Completado" => "Completada", "Cancelado" => "Cancelada")[$o["status_order"] ?: "Pendiente"] ?? $o["status_order"]).'</span>
				</a>';
			}

			$html .= '</div>';

		}catch(Throwable $e){

			error_log("Chat info pedidos: ".$e->getMessage());
		}

		/* Citas */

		try{

			$stmt = self::pdo()->prepare("SELECT * FROM appointments WHERE phone_appointment = ? ORDER BY date_appointment DESC, time_appointment DESC LIMIT 5");
			$stmt->execute(array($phone));
			$appts = $stmt->fetchAll(PDO::FETCH_ASSOC);

			$html .= '<div class="info-sec"><div class="info-sec-head"><span><i class="bi bi-calendar2-week"></i> Citas y reservas</span><a href="/agenda">Agenda</a></div>';

			if(empty($appts)){

				$html .= '<p class="info-empty">Sin citas</p>';
			}

			foreach ($appts as $a) {

				$time = substr($a["time_appointment"], 0, 5);

				$html .= '<a class="info-item" href="/agenda?date='.self::e($a["date_appointment"]).'">
					<span><strong>'.self::e(ucfirst(BizStore::dayName($a["date_appointment"]))).'</strong><small>'.($time !== "00:00" ? self::e(BizStore::time12($time))." · " : "").self::e($a["service_appointment"] ?: "Cita").(!empty($a["people_appointment"]) ? " · ".(int)$a["people_appointment"]." pers." : "").'</small></span>
					<span class="st st-'.self::e($a["status_appointment"]).'">'.self::e(ucfirst($a["status_appointment"])).'</span>
				</a>';
			}

			$html .= '</div>';

		}catch(Throwable $e){

			error_log("Chat info citas: ".$e->getMessage());
		}

		return $html;

	}

	/* Id del último mensaje del sistema (detectar cambios) */

	static public function lastGlobalId(){

		return (int)self::pdo()->query("SELECT COALESCE(MAX(id_message), 0) FROM messages")->fetchColumn();

	}

}
