<?php

require_once __DIR__."/business.store.php";
require_once __DIR__."/chat.service.php";

/*=============================================
Consultas al equipo

Cuando la IA no tiene la respuesta escribe
[[CONSULTA|pregunta]]. El sistema:
 1. Guarda la consulta (tabla inquiries).
 2. Le escribe por WhatsApp al número configurado
    en Mi negocio → Consultas.
 3. Cuando ese número responde (citando el mensaje
    o empezando con #número) le reenvía la respuesta
    al cliente y la guarda como "respuesta aprendida"
    para usarla en futuras conversaciones.
=============================================*/

class BizInquiries{

	const MAX_PENDING_PER_CLIENT = 3;
	const MAX_LEARNED_IN_PROMPT = 80;

	static private function pdo(){

		return BizStore::pdo();

	}

	static public function settings(){

		return BizStore::get("inquiries");

	}

	/* Las consultas están SIEMPRE activas: si el cliente pide algo que no está
	   en Mi negocio (una promoción, un plan, un producto…) la IA nunca cierra
	   la conversación, dice que lo va a consultar y se guarda la consulta. */

	static public function enabled(){

		return true;

	}

	/* Número propio de consultas (Mi negocio › Consultas) */

	static public function ownPhone(){

		return biz_clean_phone(self::settings()["phone"] ?? "");

	}

	/* Quién recibe el aviso: el número de consultas o, si no hay,
	   los números que reciben las órdenes (central, áreas) */

	static public function teamPhones(){

		$own = self::ownPhone();

		if($own !== ""){

			return array($own);
		}

		require_once __DIR__."/business.ops.php";

		return Notify::recipients("", "orders") ?: Notify::recipients();

	}

	/*=============================================
	Lecturas
	=============================================*/

	static public function find($id){

		$stmt = self::pdo()->prepare("SELECT * FROM inquiries WHERE id_inquiry = ?");
		$stmt->execute(array((int)$id));

		return $stmt->fetch() ?: null;

	}

	static public function listAll($status = "", $limit = 300){

		$sql = "SELECT * FROM inquiries";
		$args = array();

		if($status !== ""){

			$sql .= " WHERE status_inquiry = ?";
			$args[] = $status;
		}

		$sql .= " ORDER BY (status_inquiry = 'pendiente') DESC, id_inquiry DESC LIMIT ".max(1, (int)$limit);

		$stmt = self::pdo()->prepare($sql);
		$stmt->execute($args);

		return $stmt->fetchAll();

	}

	static public function pending(){

		$stmt = self::pdo()->query("SELECT * FROM inquiries WHERE status_inquiry = 'pendiente' ORDER BY id_inquiry DESC");

		return $stmt->fetchAll();

	}

	static public function learned($limit = self::MAX_LEARNED_IN_PROMPT){

		$stmt = self::pdo()->query("SELECT question_inquiry, answer_inquiry FROM inquiries WHERE status_inquiry = 'respondida' AND learn_inquiry = 1 AND answer_inquiry <> '' ORDER BY id_inquiry DESC LIMIT ".(int)$limit);

		return $stmt->fetchAll();

	}

	/*=============================================
	Crear una consulta (desde la IA)
	=============================================*/

	static public function create($phone, $name, $question){

		$phone = biz_clean_phone($phone);
		$question = trim(mb_substr(preg_replace('/\s+/u', ' ', (string)$question), 0, 1000));

		if($question === ""){

			return null;
		}

		/* No repetir la misma pregunta ni saturar al equipo */

		$stmt = self::pdo()->prepare("SELECT id_inquiry, question_inquiry FROM inquiries WHERE phone_inquiry = ? AND status_inquiry = 'pendiente' ORDER BY id_inquiry DESC");
		$stmt->execute(array($phone));
		$open = $stmt->fetchAll();

		foreach ($open as $row) {

			if(mb_strtolower($row["question_inquiry"]) === mb_strtolower($question)){

				return (int)$row["id_inquiry"];
			}
		}

		if(count($open) >= self::MAX_PENDING_PER_CLIENT){

			return (int)$open[0]["id_inquiry"];
		}

		$stmt = self::pdo()->prepare("INSERT INTO inquiries (phone_inquiry, name_inquiry, question_inquiry, status_inquiry, date_created_inquiry) VALUES (?, ?, ?, 'pendiente', ?)");
		$stmt->execute(array($phone, mb_substr((string)$name, 0, 191), $question, date("Y-m-d H:i:s")));

		$id = (int)self::pdo()->lastInsertId();

		self::notifyTeam($id);

		return $id;

	}

	/*=============================================
	Escribir al número del equipo
	=============================================*/

	static public function notifyTeam($id){

		$inq = self::find($id);
		$team = self::teamPhones();

		if(!$inq){

			return array("ok" => false, "error" => "Consulta no encontrada.");
		}

		if(empty($team)){

			return array("ok" => false, "error" => "La consulta quedó guardada en Mi negocio › Consultas, pero no hay ningún número para avisar (configura el número de consultas o el número central de órdenes).");
		}

		if(!ChatService::api()){

			return array("ok" => false, "error" => "No hay una cuenta de WhatsApp activa.");
		}

		require_once __DIR__."/business.ops.php";

		$text = "❓ *Consulta #".$inq["id_inquiry"]."*\n\n";
		$text .= "👤 ".($inq["name_inquiry"] ?: "Cliente").($inq["phone_inquiry"] ? " (+".$inq["phone_inquiry"].")" : "")."\n";
		$text .= "💬 ".$inq["question_inquiry"]."\n\n";
		$text .= "✍️ *Responde a este mensaje* (deslízalo y escribe) o empieza tu respuesta con *#".$inq["id_inquiry"]."*. ";
		$text .= "Se la enviaré al cliente y la recordaré para próximas preguntas.";

		/* Notify::deliver usa la plantilla aprobada si la ventana de 24 h está cerrada */

		$wamids = array();
		$errors = array();

		foreach ($team as $to) {

			$r = Notify::deliver($to, $text, "consulta");

			if($r["ok"] && !empty($r["wamid"])){

				$wamids[] = $r["wamid"];

			}else if(!$r["ok"]){

				$errors[] = "+".$to.": ".$r["error"];
				error_log("Consultas: no se pudo avisar a ".$to.": ".$r["error"]);
			}
		}

		if(!empty($wamids)){

			$stmt = self::pdo()->prepare("UPDATE inquiries SET wamid_inquiry = ? WHERE id_inquiry = ?");
			$stmt->execute(array(mb_substr(implode(",", $wamids), 0, 191), $inq["id_inquiry"]));
		}

		return array("ok" => !empty($wamids), "wamid" => $wamids[0] ?? null, "error" => implode(" · ", $errors));

	}

	/*=============================================
	¿El mensaje entrante es la respuesta del equipo?
	Devuelve true si se procesó (no responder con IA/bots).
	=============================================*/

	static public function handleReply($getApiWS, $phone, $message, $contextWamid = ""){

		$team = biz_clean_phone($phone);

		/* Número propio de consultas: todo lo que escriba se toma como respuesta.
		   Número de respaldo (central de órdenes): solo si cita la consulta o usa #número,
		   para no confundir sus otros mensajes con respuestas. */

		$own = self::ownPhone() !== "" && $team === self::ownPhone();

		if(!$own && !in_array($team, self::teamPhones(), true)){

			return false;
		}

		$json = json_decode((string)$message);

		if(is_object($json)){

			$message = isset($json->text) ? $json->text : "";
		}

		$text = trim((string)$message);

		if($text === ""){

			return false;
		}

		$pending = self::pending();

		if(empty($pending)){

			return false;
		}

		$target = null;

		/* 1) Respondió citando el mensaje de la consulta */

		if($contextWamid !== ""){

			foreach ($pending as $p) {

				if(in_array($contextWamid, explode(",", (string)$p["wamid_inquiry"]), true)){

					$target = $p;
				}
			}
		}

		if(!$own && !$target && !preg_match('/^\s*#\s*\d+/u', $text)){

			return false;
		}

		/* 2) Empezó con #número */

		if(!$target && preg_match('/^\s*#\s*(\d+)\s*[:\-–]?\s*(.*)$/su', $text, $m)){

			foreach ($pending as $p) {

				if((int)$p["id_inquiry"] === (int)$m[1]){

					$target = $p;
					$text = trim($m[2]);
				}
			}

			if(!$target){

				self::tell($getApiWS, $team, "⚠️ No hay ninguna consulta pendiente con el número #".(int)$m[1].".");

				return true;
			}
		}

		/* 3) Solo hay una pendiente: es para esa */

		if(!$target && count($pending) === 1){

			$target = $pending[0];
		}

		if(!$target){

			$list = "";

			foreach (array_slice($pending, 0, 5) as $p) {

				$list .= "\n• #".$p["id_inquiry"].": ".mb_substr($p["question_inquiry"], 0, 80);
			}

			self::tell($getApiWS, $team, "🤔 Hay varias consultas pendientes. Responde citando el mensaje o empieza con el número, por ejemplo *#".$pending[0]["id_inquiry"]." tu respuesta*.".$list);

			return true;
		}

		if($text === ""){

			self::tell($getApiWS, $team, "✍️ Escribe la respuesta después del número, por ejemplo *#".$target["id_inquiry"]." Sí, abrimos los domingos*.");

			return true;
		}

		$result = self::answer($target["id_inquiry"], $text, true);

		self::tell($getApiWS, $team, $result["delivered"]
			? "✅ Listo, le respondí al cliente y guardé la respuesta para próximas consultas (#".$target["id_inquiry"].")."
			: "💾 Guardé la respuesta (#".$target["id_inquiry"].") para próximas consultas, pero no se la pude enviar al cliente: ".$result["error"]);

		return true;

	}

	/*=============================================
	Responder una consulta (WhatsApp o panel)
	=============================================*/

	static public function answer($id, $answer, $send = true, $learn = null, $question = null){

		$inq = self::find($id);
		$answer = trim(str_replace("\r\n", "\n", (string)$answer));

		if(!$inq || $answer === ""){

			return array("ok" => false, "delivered" => false, "error" => "Consulta no encontrada o respuesta vacía.");
		}

		$fields = "answer_inquiry = ?, status_inquiry = 'respondida', date_answered_inquiry = COALESCE(date_answered_inquiry, ?)";
		$args = array($answer, date("Y-m-d H:i:s"));

		if($learn !== null){

			$fields .= ", learn_inquiry = ?";
			$args[] = $learn ? 1 : 0;
		}

		if($question !== null && trim($question) !== ""){

			$fields .= ", question_inquiry = ?";
			$args[] = trim(mb_substr($question, 0, 1000));
		}

		$args[] = (int)$id;

		$stmt = self::pdo()->prepare("UPDATE inquiries SET ".$fields." WHERE id_inquiry = ?");
		$stmt->execute($args);

		$delivered = false;
		$error = "";

		if($send && !empty($inq["phone_inquiry"]) && !(int)$inq["delivered_inquiry"]){

			$getApiWS = ChatService::api();

			if($getApiWS){

				$text = self::clientMessage($inq, $answer);

				$result = ChatService::deliver($getApiWS, $inq["phone_inquiry"], ChatService::textPayload($inq["phone_inquiry"], $text), $text, '{"type":"ia","title":""}');

				$delivered = $result["ok"];
				$error = $result["ok"] ? "" : $result["error"];

				if($delivered){

					self::pdo()->prepare("UPDATE inquiries SET delivered_inquiry = 1 WHERE id_inquiry = ?")->execute(array((int)$id));
				}

			}else{

				$error = "No hay una cuenta de WhatsApp activa.";
			}
		}

		return array("ok" => true, "delivered" => $delivered, "error" => $error);

	}

	/*=============================================
	Respuesta guardada manualmente (sin cliente)
	=============================================*/

	static public function saveKnowledge($id, $question, $answer, $learn = true){

		$question = trim(mb_substr((string)$question, 0, 1000));
		$answer = trim(str_replace("\r\n", "\n", (string)$answer));

		if($question === "" || $answer === ""){

			return false;
		}

		if((int)$id > 0){

			$stmt = self::pdo()->prepare("UPDATE inquiries SET question_inquiry = ?, answer_inquiry = ?, learn_inquiry = ?, status_inquiry = 'respondida', date_answered_inquiry = COALESCE(date_answered_inquiry, ?) WHERE id_inquiry = ?");

			return $stmt->execute(array($question, $answer, $learn ? 1 : 0, date("Y-m-d H:i:s"), (int)$id));
		}

		$stmt = self::pdo()->prepare("INSERT INTO inquiries (question_inquiry, answer_inquiry, status_inquiry, learn_inquiry, delivered_inquiry, date_created_inquiry, date_answered_inquiry) VALUES (?, ?, 'respondida', ?, 1, ?, ?)");

		return $stmt->execute(array($question, $answer, $learn ? 1 : 0, date("Y-m-d H:i:s"), date("Y-m-d H:i:s")));

	}

	static public function setStatus($id, $status){

		if(!in_array($status, array("pendiente", "descartada"), true)){

			return false;
		}

		$stmt = self::pdo()->prepare("UPDATE inquiries SET status_inquiry = ? WHERE id_inquiry = ?");

		return $stmt->execute(array($status, (int)$id));

	}

	static public function setLearn($id, $learn){

		$stmt = self::pdo()->prepare("UPDATE inquiries SET learn_inquiry = ? WHERE id_inquiry = ?");

		return $stmt->execute(array($learn ? 1 : 0, (int)$id));

	}

	static public function delete($id){

		$stmt = self::pdo()->prepare("DELETE FROM inquiries WHERE id_inquiry = ?");

		return $stmt->execute(array((int)$id));

	}

	/*=============================================
	Texto para el prompt de la IA
	=============================================*/

	static public function context($phone){

		$lines = array();

		/* Lo que el equipo ya le respondió a ESTE cliente: es información oficial
		   y permite vender aunque no esté en el catálogo */

		$mine = self::answeredFor($phone);

		if(!empty($mine)){

			$lines[] = "";
			$lines[] = "RESPUESTAS DEL EQUIPO A ESTE CLIENTE (OFICIALES, tienen prioridad sobre el catálogo y sobre cualquier otra regla):";

			foreach ($mine as $row) {

				$lines[] = "- Consulta: ".self::plainQuestion($row["question_inquiry"])." → Respuesta del equipo: ".str_replace("\n", " ", $row["answer_inquiry"]);
			}

			$lines[] = "CÓMO USAR ESTAS RESPUESTAS: aplícalas SOLO a lo que dicen textualmente.";
			$lines[] = "- Si el equipo confirmó un plan/producto concreto CON su precio (ej.: \"el plan 2000 Mbps cuesta 500\"), ese plan existe para este cliente: no digas que no existe, no ofrezcas otro en su lugar, no lo vuelvas a consultar y continúa la venta con ESE precio. En [[PEDIDO]] escríbelo así: 1 x Nombre del plan @ precio (ej.: 1 x Plan 2000 Mbps @ 500).";
			$lines[] = "- Si el equipo respondió algo GENERAL o SIN PRECIO (ej.: \"sí tenemos planes personalizados\"), NO tienes precio: pregúntale al cliente qué necesita exactamente (velocidad, TV u otros servicios) y, cuando te lo diga, consulta de nuevo con todos los detalles con [[CONSULTA|...]]. Nunca uses el precio de otro plan del catálogo ni lo calcules tú.";
			$lines[] = "- Si el cliente ahora pide algo DISTINTO a lo que respondió el equipo (otra velocidad, otro servicio u otra combinación), eso no está confirmado: se consulta.";
			$lines[] = "- Si el equipo dijo que NO se puede, explícaselo con amabilidad y ofrece lo que sí hay.";
		}

		$learned = self::learned();

		if(!empty($learned)){

			$lines[] = "";
			$lines[] = "RESPUESTAS CONFIRMADAS POR EL EQUIPO (información oficial; úsalas tal cual cuando pregunten algo parecido, incluso si no está en el catálogo, y vende con ese precio usando \"@ precio\" en [[PEDIDO]]):";

			$chars = 0;

			foreach ($learned as $row) {

				$line = "- P: ".mb_substr($row["question_inquiry"], 0, 300)." → R: ".mb_substr(str_replace("\n", " ", $row["answer_inquiry"]), 0, 600);
				$chars += mb_strlen($line);

				if($chars > 12000){

					break;
				}

				$lines[] = $line;
			}
		}

		$stmt = self::pdo()->prepare("SELECT question_inquiry FROM inquiries WHERE phone_inquiry = ? AND status_inquiry = 'pendiente'");
		$stmt->execute(array(biz_clean_phone($phone)));
		$open = $stmt->fetchAll(PDO::FETCH_COLUMN);

		$lines[] = "";
		$lines[] = "CONSULTAS AL EQUIPO (REGLA OBLIGATORIA): si el cliente pide o pregunta por algo que NO está en esta información (una promoción, descuento, oferta, plan, producto, servicio, precio, horario, zona, política o cualquier otro dato) o algo que solo una persona puede decidir, NUNCA respondas que no existe, que no se puede, ni des por terminada la conversación, y no inventes nada. Responde algo como \"Déjame consultarlo con el equipo y te escribo apenas tenga la respuesta 🙌\", agrega al final la etiqueta [[CONSULTA|la pregunta concreta, clara y completa, con lo que pidió el cliente]] y, en la misma respuesta, ofrece seguir ayudándolo con lo que sí está disponible (por ejemplo, las promociones o planes que sí aparecen aquí). Usa una sola etiqueta por pregunta.";

		$lines[] = "";
		$lines[] = "SECUENCIA OBLIGATORIA PARA RESOLVER CUALQUIER DUDA DEL CLIENTE (síguela en orden):";
		$lines[] = "1) ENTENDER: identifica exactamente qué pide (producto/plan, velocidad, servicios adicionales como TV, cantidad). Si falta un detalle para poder consultarlo bien, pregúntalo en una sola línea corta.";
		$lines[] = "2) BUSCAR: revisa el catálogo y las respuestas del equipo. Solo cuenta una coincidencia EXACTA (la misma velocidad, el mismo producto y la misma combinación). Algo parecido NO es lo mismo: 1000 Mbps no es 1200 Mbps.";
		$lines[] = "3) SI HAY COINCIDENCIA EXACTA: responde con ese dato y su precio tal como está escrito.";
		$lines[] = "4) SI NO LA HAY: dile que lo consultas con el equipo y agrega [[CONSULTA|pregunta con TODOS los detalles, ej.: ¿Precio mensual de un plan de 1200 Mbps con TV abierta?]]. NO des precio, NO digas que existe ni que no existe y NO pidas sus datos para contratar ese plan todavía.";
		$lines[] = "5) MIENTRAS ESPERA: puedes mencionar alternativas del catálogo dejando claro que son OTRAS opciones (ej.: \"mientras tanto, lo más cercano que tenemos es Fibra 1000 Mbps + TV a S/ 144.00\"), nunca como si fueran lo que pidió.";
		$lines[] = "6) CUANDO EL EQUIPO RESPONDA: si trae precio exacto, continúa la venta con ese precio. Si la respuesta es general o le falta el precio, vuelve al paso 1.";
		$lines[] = "Nunca prometas, confirmes ni registres un plan, producto o precio que no esté escrito textualmente en el catálogo o en una respuesta del equipo.";

		if(!empty($open)){

			$lines[] = "Este cliente ya tiene consultas en espera (no las repitas, dile que seguimos consultando y no le des precio de eso hasta que el equipo responda): ".implode(" / ", array_map(function($q){ return mb_substr($q, 0, 160); }, $open));
		}

		return implode("\n", $lines);

	}

	/* Consultas de este cliente que el equipo respondió (últimos 15 días) */

	static public function answeredFor($phone, $days = 15){

		try{

			$stmt = self::pdo()->prepare("SELECT id_inquiry, question_inquiry, answer_inquiry FROM inquiries WHERE phone_inquiry = ? AND status_inquiry = 'respondida' AND answer_inquiry <> '' AND COALESCE(date_answered_inquiry, date_created_inquiry) >= ? ORDER BY id_inquiry DESC LIMIT 10");
			$stmt->execute(array(biz_clean_phone($phone), date("Y-m-d H:i:s", time() - $days * 86400)));

			return array_reverse($stmt->fetchAll());

		}catch(Throwable $e){

			return array();
		}

	}

	/* Todo lo que el equipo confirmó (a este cliente y respuestas aprendidas) */

	static public function confirmedText($phone){

		$text = "";

		foreach (self::answeredFor($phone) as $r) {

			$text .= " ".$r["question_inquiry"]." ".$r["answer_inquiry"];
		}

		try{

			foreach (self::learned() as $r) {

				$text .= " ".$r["question_inquiry"]." ".$r["answer_inquiry"];
			}

		}catch(Throwable $e){}

		return $text;

	}

	/* ¿Ese precio lo dio el equipo (o es un precio del catálogo)? "1,200" = "1200" = "1200.00" */

	static public function priceConfirmed($price, $phone){

		$p = round((float)$price, 2);

		if($p <= 0){

			return false;
		}

		require_once __DIR__."/business.orders.php";

		foreach (BizOrders::catalogue() as $item) {

			if(abs((float)$item["price"] - $p) < 0.01){

				return true;
			}
		}

		$text = preg_replace('/(\d)[,\s](\d{3})(?!\d)/u', '$1$2', self::confirmedText($phone));

		preg_match_all('/\d+(?:[.,]\d{1,2})?/u', $text, $m);

		foreach ($m[0] as $n) {

			if(abs((float)str_replace(",", ".", $n) - $p) < 0.01){

				return true;
			}
		}

		return false;

	}

	/* "El cliente pregunta: Quiero 2000mbps" → "Quiero 2000mbps" */

	static public function plainQuestion($question){

		return trim(preg_replace('/^\s*(el cliente (pregunta|pide|quiere saber|consulta)|pregunta del cliente)\s*[:\-]\s*/iu', "", (string)$question));

	}

	/*=============================================
	Mensaje para el cliente con la respuesta del
	equipo: la IA lo redacta natural y sin cambiar
	ningún dato; si no hay IA, se usa una plantilla.
	=============================================*/

	static public function clientMessage(array $inq, $answer){

		$question = self::plainQuestion($inq["question_inquiry"]);
		$fallback = "✅ *¡Ya tengo la respuesta del equipo!*\n\n❓ *Tu consulta:* ".mb_substr($question, 0, 200)."\n💬 *Respuesta:* ".$answer."\n\n¿Te gustaría continuar? Estoy aquí para ayudarte 😊";

		try{

			require_once __DIR__."/ai.service.php";

			if(!AIService::enabled()){

				return $fallback;
			}

			$company = trim((string)(BizStore::get("company")["name"] ?? ""));
			$system = "Eres la asesora de ventas".($company !== "" ? " de ".$company : "")." por WhatsApp. Le escribes al cliente para darle la respuesta que el equipo dio a su consulta. "
				."Redacta UN mensaje breve (máximo 4 líneas), cálido y natural, en español, con 1 o 2 emojis. Empieza contándole que ya tienes la respuesta del equipo. "
				."Transmite la respuesta del equipo SIN cambiar, quitar ni agregar datos (precios, velocidades, condiciones, fechas); escribe los precios como \"".cms_currency()." 500.00\". "
				."Si la respuesta permite contratar o comprar, invítalo a continuar con la contratación en una pregunta corta. Si la respuesta es negativa, ofrécele ver las opciones disponibles. "
				."No saludes como si fuera una conversación nueva, no uses listas y no menciones etiquetas.";

			$r = AIService::reply(array(array("role" => "user", "content" => "Consulta del cliente: ".$question."\nRespuesta del equipo: ".$answer)), $system);

			if($r["ok"] && trim($r["text"]) !== "" && strpos($r["text"], "[[") === false){

				return trim($r["text"]);
			}

		}catch(Throwable $e){

			error_log("Consultas: no se pudo redactar la respuesta: ".$e->getMessage());
		}

		return $fallback;

	}

	/*=============================================
	Mensaje corto al equipo
	=============================================*/

	static private function tell($getApiWS, $to, $text){

		ChatService::deliver($getApiWS, $to, ChatService::textPayload($to, $text), $text, '{"type":"consulta","title":""}');

	}

}
