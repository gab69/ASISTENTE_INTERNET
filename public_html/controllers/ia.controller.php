<?php

require_once __DIR__."/chat.service.php";
require_once __DIR__."/ai.service.php";
require_once __DIR__."/business.actions.php";

Class IAController{

	/*=============================================
	Texto legible de un mensaje guardado
	=============================================*/

	static private function plain($value, $who = "El cliente envió"){

		$value = (string)$value;
		$json = json_decode($value);

		if(is_object($json)){

			if(isset($json->text)){

				return $json->text;
			}

			if(isset($json->type)){

				$caption = trim(urldecode((string)($json->caption ?? "")));

				return "[".$who." un archivo: ".$json->type."]".($caption !== "" ? " ".$caption : "");
			}
		}

		/* Solo los textos de bots se guardan codificados (sin espacios, con + o %XX) */

		if(strpos($value, " ") === false && preg_match('/\+|%[0-9A-Fa-f]{2}/', $value)){

			$value = urldecode($value);
		}

		return str_replace("\\n", "\n", $value);

	}

	/*=============================================
	La IA no respondió ni con los reintentos: el
	cliente no se queda en silencio (máximo un aviso
	cada 10 minutos) y el equipo lo ve en el chat
	=============================================*/

	static private function fallback($getApiWS, $phone_message, $answer){

		error_log("IA sin respuesta para ".$phone_message.": ".($answer["error"] ?? ""));

		BizIncidents::record(array("phone" => $phone_message, "kind" => "sistema", "detail" => "La IA no pudo responder al cliente: ".mb_substr((string)($answer["error"] ?? "sin detalle"), 0, 300), "source" => "sistema", "dedupe" => 3600));

		try{

			$check = BizStore::pdo()->prepare("SELECT COUNT(*) FROM messages WHERE phone_message = ? AND template_message LIKE '%\"ia-error\"%' AND date_updated_message >= ?");
			$check->execute(array($phone_message, date("Y-m-d H:i:s", time() - 600)));

			if((int)$check->fetchColumn() > 0){

				return $answer;
			}

		}catch(Throwable $e){}

		$text = trim((string)cms_config("ai_error_message", "")) ?: "Disculpa, tuve un pequeño inconveniente 🙏 ¿Me repites tu último mensaje, por favor? Si prefieres, un asesor te escribe en breve.";

		return ChatService::deliver($getApiWS, $phone_message, ChatService::textPayload($phone_message, $text), $text, '{"type":"ia-error","title":""}');

	}

	/*=============================================
	Respaldo de las consultas al equipo
	=============================================*/

	/* "No contamos con…", "lamentablemente no…", "no tengo esa información" */

	static public function deniesUnknown($text){

		$t = mb_strtolower((string)$text);

		/* Cobertura, audios y plazos de cancelación los decide el sistema: no son dudas */

		if(preg_match('/cobertura|zona de reparto|nota(s)? de voz|audio|plazo|ya no se puede cancelar/u', $t)){

			return false;
		}

		return (bool)preg_match('/\b(lamentablemente,? no|no (contamos|tenemos|ofrecemos|manejamos|disponemos|trabajamos|vendemos|hacemos|brindamos|realizamos|aceptamos|incluimos|damos|existe|existen)\b|no hay (planes?|promoci|descuent|ofertas?|paquetes?|servicios?|productos?|stock|disponib|ese|esa|eso)|no (es posible|est[aá] disponible|se puede|podemos ofrecer|podr[ií]amos)|no (tengo|cuento con|dispongo de) (esa |ese |la |el |dicha )?(informaci[oó]n|dato|detalle)|no (s[eé]|sabr[ií]a)\b|no estoy segur[oa]|desconozco)/u', $t);

	}

	static public function promisedInquiry($text){

		return (bool)preg_match('/(d[eé]jame|perm[ií]teme|voy a|lo voy a|vamos a|te lo) ?(consultar|consultarlo|verificar con|confirmar con)|consult(o|ar[eé]|arlo) con (el|mi|nuestro) equipo|lo consulto/iu', (string)$text);

	}

	/*=============================================
	Precios sin confirmar: la IA no puede dar precio
	ni registrar un plan que no esté textualmente en
	el catálogo o en una respuesta del equipo.
	=============================================*/

	/* Velocidades mencionadas: "1200 Mbps", "2 Gbps", "1200 megas" → [1200, 2000] */

	static public function speeds($text){

		preg_match_all('/(\d+(?:[.,]\d+)?)\s*(gbps|gb\/s|gigas?|mbps|mb\/s|mgbs|mbs|mb|megas?)\b/iu', (string)$text, $m, PREG_SET_ORDER);

		$out = array();

		foreach ($m as $x) {

			$n = (float)str_replace(",", ".", $x[1]);
			$out[] = (int)round(preg_match('/^g/i', $x[2]) ? $n * 1000 : $n);
		}

		return array_values(array_unique(array_filter($out)));

	}

	/* Velocidades con precio conocido: catálogo + lo que confirmó el equipo */

	static private function knownSpeeds($phone){

		$text = "";

		foreach (BizOrders::catalogue() as $p) {

			$text .= " ".$p["name"];
		}

		return self::speeds($text." ".BizInquiries::confirmedText($phone));

	}

	static private function hasPrice($text){

		return (bool)preg_match('/(S\/|soles|\$|USD)\s*\.?\s*\d|\d+(?:[.,]\d{2})?\s*(soles|al mes|mensual)/iu', (string)$text);

	}

	/* ¿Algún producto del [[PEDIDO]] lleva un precio que nadie confirmó? */

	static private function unconfirmedOrderItems(array $actions, $phone){

		$tag = BizActions::has($actions, "PEDIDO");

		if(!$tag){

			return array();
		}

		$bad = array();

		foreach (BizOrders::parseItems(BizOrders::orderFields($tag["args"])["items"]) as $i) {

			if(empty($i["matched"]) && $i["price"] !== null && !BizInquiries::priceConfirmed($i["price"], $phone)){

				$bad[] = $i["name"]." @ ".$i["price"];
			}
		}

		return $bad;

	}

	/*=============================================
	Orden prometida pero no registrada: se extraen
	los datos de la conversación y se registra. Si
	faltan datos, se le piden al cliente en lugar de
	decirle que ya quedó registrada.
	=============================================*/

	static private function recoverOrder(array $actions, array $history, $phone, $profileName, $conversation){

		$sells = BizGeo::installation() || !empty(BizOrders::catalogue());

		/* Internet: el cliente eligió la fecha y se agendó, pero la IA no agregó el
		   [[PEDIDO]] → la orden se genera ahora con los datos de la conversación */

		$justBooked = BizGeo::installation() && !empty($actions["booked"]);

		if(!$sells || !empty($actions["orders"]) || BizActions::has($actions["actions"], "PEDIDO") || (!$justBooked && !BizActions::looksLikeOrder($actions["text"]))){

			return $actions;
		}

		/* Ya tiene una orden activa creada hace poco: habla de esa
		   (salvo que acabe de elegir la fecha: entonces se genera/vincula la orden) */

		if(!$justBooked && self::recentOrder($phone)){

			return $actions;
		}

		$found = BizActions::extractOrder($history, $actions["text"]);

		if(!$found || trim((string)($found["plan"] ?? "")) === ""){

			return $actions;
		}

		/* La instalación ya quedó agendada en esta respuesta: no se vuelve a agendar */

		if($justBooked){

			unset($found["fecha"], $found["hora"]);
		}

		$tags = BizActions::orderTags($found);
		$args = BizActions::parse($tags)[1][0]["args"] ?? array();

		/* Faltan datos obligatorios: pedirlos (no decir que está registrada) */

		if(BizGeo::installation() && ($missing = BizOrders::missingFields(BizOrders::orderFields($args)))){

			$field = BizOrders::fieldsIn($missing[0]);
			$actions["text"] = !empty($field) && $field[0] !== "bill" ? "📝 Para continuar con tu orden:\n\n".BizOrders::fieldQuestion($field[0]) : "📝 Para continuar con tu orden, compárteme este dato, por favor: *".$missing[0]."* 😊";
			$actions["notes"][] = "order_recover_missing:".implode(", ", $missing);

			return $actions;
		}

		$retry = BizActions::process($actions["text"]."\n".$tags, $phone, $profileName, "", $conversation);

		if(empty($retry["orders"])){

			/* Falta la fecha de instalación: primero se agenda */

			if(in_array("order_needs_date", (array)($retry["notes"] ?? array()), true)){

				$actions["notes"][] = "order_needs_date";
			}

			return $actions;
		}

		error_log("IA: orden #".$retry["orders"][0]." registrada por el respaldo (la IA no puso [[PEDIDO]]) para ".$phone);

		$retry["actions"] = array_merge((array)$actions["actions"], (array)$retry["actions"]);
		$retry["media"] = array_merge((array)$actions["media"], (array)$retry["media"]);
		$retry["booked"] = array_values(array_unique(array_merge((array)$actions["booked"], (array)$retry["booked"])));
		$retry["linked"] = array_values(array_unique(array_merge((array)($actions["linked"] ?? array()), (array)($retry["linked"] ?? array()))));

		return $retry;

	}

	/* ¿El último mensaje del bot le ofreció horarios de instalación? */

	static private function offeredSlots($phone){

		try{

			$stmt = BizStore::pdo()->prepare("SELECT business_message FROM messages WHERE phone_message = ? AND type_message = 'business' ORDER BY id_message DESC LIMIT 1");
			$stmt->execute(array(biz_clean_phone($phone)));
			$last = mb_strtolower((string)$stmt->fetchColumn());

			return (bool)preg_match('/(horarios?|turnos?|fecha de (tu )?instalaci|qu[eé] d[ií]a|a\.\s*m\.|p\.\s*m\.|\d{1,2}:\d{2})/u', $last);

		}catch(Throwable $e){

			return false;
		}

	}

	/* ¿El cliente ya tiene una orden activa creada en las últimas 3 horas? */

	static private function recentOrder($phone){

		foreach (BizOrders::forPhone($phone, 1) as $o) {

			if($o["status_order"] === "Pendiente" && strtotime((string)$o["created_at_order"]) >= time() - 3 * 3600){

				return (int)$o["id_order"];
			}
		}

		return false;

	}

	static private function guardPrices(array $answer, array $history, $system, $clientText, $phone){

		list($clean, $actions) = BizActions::parse($answer["text"]);

		$known = self::knownSpeeds($phone);
		/* Velocidades sin precio que menciona la IA o que pidió el cliente
		   (si el cliente pidió 1200 Mbps y la IA responde con el precio de otro plan
		   sin consultar, está reemplazando lo que pidió) */

		$unknownSpeeds = array_values(array_unique(array_merge(array_diff(self::speeds($clean), $known), array_diff(self::speeds($clientText), $known))));
		$badItems = self::unconfirmedOrderItems($actions, $phone);

		/* Ya consulta: solo se controla que no registre una orden con precio inventado */

		$consulting = (bool)BizActions::has($actions, "CONSULTA");
		$priceProblem = !$consulting && !empty($unknownSpeeds) && self::hasPrice($clean);

		if(!$priceProblem && empty($badItems)){

			return $answer;
		}

		$what = !empty($unknownSpeeds) ? implode(", ", array_map(function($s){ return $s." Mbps"; }, $unknownSpeeds)) : implode(", ", $badItems);

		$retry = AIService::reply($history, $system."\n\nCORRECCIÓN OBLIGATORIA: ibas a responder esto: \"".mb_substr($clean, 0, 1200)."\". Ahí das o usas un precio para algo que NO tiene precio confirmado (".$what."): no está en el catálogo ni en ninguna respuesta del equipo. Reescribe tu respuesta siguiendo la SECUENCIA PARA RESOLVER DUDAS: no des ni asumas ningún precio para eso, no lo registres con [[PEDIDO]] ni pidas sus datos para contratarlo; dile que lo consultas con el equipo y agrega [[CONSULTA|pregunta con todos los detalles que pidió el cliente]]. Si quieres, menciona una alternativa del catálogo dejando claro que es OTRA opción.");

		if($retry["ok"]){

			list($rClean, $rActions) = BizActions::parse($retry["text"]);
			$stillBad = self::unconfirmedOrderItems($rActions, $phone);
			$stillPrice = !BizActions::has($rActions, "CONSULTA") && array_diff(self::speeds($rClean), $known) && self::hasPrice($rClean);

			if(empty($stillBad) && !$stillPrice){

				if(!BizActions::has($rActions, "CONSULTA") && !BizActions::has($rActions, "PEDIDO")){

					$retry["text"] .= "\n[[CONSULTA|".self::inquiryQuestion($clientText, $what)."]]";
				}

				return $retry;
			}
		}

		/* Respaldo final: nunca se envía un precio inventado */

		$safe = "Para ".(!empty($unknownSpeeds) ? "el plan de ".implode(" / ", array_map(function($s){ return $s." Mbps"; }, $unknownSpeeds)) : "eso")." necesito confirmar el precio exacto con el equipo 🙌 Déjame consultarlo y te escribo apenas tenga la respuesta. Mientras tanto, ¿te ayudo con algo más?";

		return array("ok" => true, "text" => $safe."\n[[CONSULTA|".self::inquiryQuestion($clientText, $what)."]]", "error" => "");

	}

	static private function inquiryQuestion($clientText, $what){

		$client = trim(mb_substr(preg_replace('/\s+/u', ' ', str_replace(array("[", "]", "|"), " ", (string)$clientText)), 0, 300));

		return "¿Precio mensual y disponibilidad de: ".$what."? (el cliente escribió: ".$client.")";

	}

	static private function ensureInquiry(array $answer, array $history, $system, $clientText, $phone = ""){

		list($clean, $actions) = BizActions::parse($answer["text"]);

		if(BizActions::has($actions, "CONSULTA")){

			return $answer;
		}

		/* Prometió consultar pero sin etiqueta: se agrega con lo que pidió el cliente */

		if(self::promisedInquiry($clean)){

			$question = trim(mb_substr(preg_replace('/\s+/u', ' ', str_replace(array("[", "]", "|"), " ", (string)$clientText)), 0, 500));

			if($question !== ""){

				$answer["text"] .= "\n[[CONSULTA|El cliente pregunta: ".$question."]]";
			}

			return $answer;
		}

		if(!self::deniesUnknown($clean)){

			return $answer;
		}

		$retry = AIService::reply($history, $system."\n\nREVISIÓN OBLIGATORIA DE TU RESPUESTA: ibas a responder esto: \"".mb_substr($clean, 0, 1200)."\". Esa respuesta NIEGA algo o dice que no sabes. Revisa la INFORMACIÓN DEL NEGOCIO: si lo que pidió el cliente NO aparece escrito ahí de forma explícita (por ejemplo un plan personalizado, una promoción, un descuento, un producto, un horario o cualquier otra cosa), NO lo niegues ni supongas: dile que lo vas a consultar con el equipo y que le escribirás apenas tengas la respuesta, agrega [[CONSULTA|la pregunta concreta del cliente]] y ofrécele seguir con lo que sí está disponible. Solo si la información del negocio dice EXPLÍCITAMENTE que no existe, mantén la negación.");

		if($retry["ok"]){

			list($retryClean, $retryActions) = BizActions::parse($retry["text"]);

			$question = trim(mb_substr(preg_replace('/\s+/u', ' ', str_replace(array("[", "]", "|"), " ", (string)$clientText)), 0, 500));

			if(!BizActions::has($retryActions, "CONSULTA") && $question !== ""){

				if(self::promisedInquiry($retryClean)){

					/* Ya le dijo que lo consulta: solo falta la etiqueta (sin repetir el texto) */

					$retry["text"] .= "\n[[CONSULTA|El cliente pregunta: ".$question."]]";

				}else if(self::deniesUnknown($retryClean) && empty(BizInquiries::answeredFor($phone, 1))){

					/* Sigue negando sin consultar y el equipo no le respondió nada hoy: se consulta igual */

					$retry["text"] .= "\n\nIgual lo consulto con el equipo para confirmarte 🙌\n[[CONSULTA|El cliente pregunta: ".$question."]]";
				}
			}

			return $retry;
		}

		return $answer;

	}

	/*=============================================
	Responder al cliente con el asistente de IA
	=============================================*/

	static public function responseIA($client_message,$getApiWS,$phone_message,$order_message,$profileName = ""){

		if(!AIService::enabled()){

			error_log("Asistente IA activo para ".$phone_message." pero no hay llave de IA configurada en /setup/.");

			/* Sin IA configurada: al iniciar la conversación usar el bot de bienvenida */

			if((int)$order_message === 0){

				return BotsController::responseBots("welcome",$getApiWS,$phone_message,$order_message,null);
			}

			return null;
		}

		/*=============================================
		Cupo de tokens agotado (Configuración → Límites):
		se avisa una sola vez y un asesor continúa
		=============================================*/

		require_once __DIR__."/business.ops.php";

		if(($blocked = Limits::aiBlocked()) !== ""){

			error_log("IA pausada: ".$blocked);

			$check = BizStore::pdo()->prepare("SELECT COUNT(*) FROM messages WHERE phone_message = ? AND template_message LIKE '%\"limite\"%' AND date_updated_message >= ?");
			$check->execute(array($phone_message, date("Y-m-d H:i:s", time() - 86400)));

			if((int)$check->fetchColumn() === 0){

				$text = trim((string)cms_config("limit_ai_message", "")) ?: "En este momento un asesor continuará la atención. ¡Gracias por tu paciencia!";

				return ChatService::deliver($getApiWS, $phone_message, ChatService::textPayload($phone_message, $text), $text, '{"type":"limite","title":""}');
			}

			return array("ok" => false, "error" => $blocked);
		}

		/*=============================================
		Historial reciente de la conversación
		=============================================*/

		$limit = max(2, min(60, (int)cms_config("ai_history", 20)));

		$get = CurlController::request("messages?linkTo=phone_message&equalTo=".rawurlencode($phone_message)."&orderBy=id_message&orderMode=DESC&startAt=0&endAt=".$limit."&select=client_message,business_message,type_message,status_message", "GET", array());

		$history = array();

		if(isset($get->status) && $get->status == 200){

			foreach (array_reverse($get->results) as $value) {

				if($value->type_message == "client"){

					$history[] = array("role" => "user", "content" => self::plain($value->client_message));

				}else if($value->status_message !== "failed"){

					$history[] = array("role" => "assistant", "content" => self::plain($value->business_message, "Se envió"));
				}
			}
		}

		if(empty($history)){

			$history[] = array("role" => "user", "content" => self::plain($client_message));
		}

		/*=============================================
		Instrucciones + datos del negocio (empresa,
		catálogo, pagos, delivery y turnos libres)
		=============================================*/

		$clientText = self::plain($client_message);
		$system = AIService::systemPrompt();

		try{

			$system .= "\n\n".BizActions::context($phone_message);

		}catch(Throwable $e){

			error_log("IA: no se pudo cargar la información del negocio: ".$e->getMessage());
		}

		/*=============================================
		El cliente envió su ubicación 📍: verificar la
		cobertura antes de que la IA responda
		=============================================*/

		$deliveryOn = false;

		try{

			$deliveryOn = BizGeo::enabled();

			/* Pin de WhatsApp o enlace de Google Maps (también los cortos maps.app.goo.gl) */

			if($deliveryOn && ($coords = BizGeo::coordsFromText(BizGeo::expandLinks($clientText)))){

				$check = BizGeo::check($clientText, $phone_message, $coords);
				$system .= "\n\nRESULTADO DE COBERTURA de la ubicación que acaba de enviar el cliente: ".BizGeo::describe($check)." Responde con este resultado y no uses [[COBERTURA]].";
			}

		}catch(Throwable $e){

			error_log("IA: error al verificar la ubicación: ".$e->getMessage());
		}

		$startId = Notify::lastClientId($phone_message);

		$answer = AIService::reply($history, $system);

		/* Llegó otro mensaje del cliente mientras la IA pensaba: responde ese otro (ve todo) */

		if(Notify::lastClientId($phone_message) > $startId){

			return array("ok" => true, "error" => "", "skipped" => "newer");
		}

		if(!$answer["ok"]){

			return self::fallback($getApiWS, $phone_message, $answer);
		}

		/*=============================================
		La IA pidió verificar una dirección: se consulta
		el mapa y se le vuelve a preguntar con el resultado
		(así no pide datos en vano si no hay reparto)
		=============================================*/

		list(, $firstActions) = BizActions::parse($answer["text"]);
		$coverageTag = BizActions::has($firstActions, "COBERTURA");

		if($coverageTag && $deliveryOn && trim(implode(" ", $coverageTag["args"])) !== ""){

			try{

				$query = implode(", ", array_filter($coverageTag["args"], "strlen"));
				$check = BizGeo::check($query, $phone_message, BizGeo::coordsFromText(BizGeo::expandLinks($query)));

				$second = AIService::reply($history, $system."\n\nRESULTADO DE LA VERIFICACIÓN DE COBERTURA (recién hecha en el mapa): ".BizGeo::describe($check)." Responde ahora al cliente según este resultado. No vuelvas a usar [[COBERTURA]]. Si hay cobertura continúa con el pedido; si no, no pidas más datos.");

				if($second["ok"]){

					$answer = $second;
				}

			}catch(Throwable $e){

				error_log("IA: error al verificar la cobertura: ".$e->getMessage());
			}
		}

		/*=============================================
		Orden de internet con datos incompletos: no se
		registra y la IA pide lo que falta (sin decir
		que la orden quedó registrada)
		=============================================*/

		try{

			list(, $nextActions) = BizActions::parse($answer["text"]);
			$orderTag = BizActions::has($nextActions, "PEDIDO");

			if($orderTag && BizGeo::installation() && ($missing = BizOrders::missingFields(BizOrders::orderFields($orderTag["args"])))){

				$retry = AIService::reply($history, $system."\n\nATENCIÓN: el sistema NO registró la orden ni agendó la instalación porque faltan o no son válidos estos datos obligatorios: ".implode(", ", $missing).". Pídele al cliente SOLO el primero de esa lista (".$missing[0]."), de forma amable y breve, con UNA sola pregunta; los demás se piden después, de uno en uno. No digas que la orden está registrada ni que la instalación está agendada, y no uses [[PEDIDO]] ni [[CITA]] en esta respuesta.");

				if($retry["ok"]){

					$answer = $retry;
				}
			}

		}catch(Throwable $e){

			error_log("IA: error al validar la orden: ".$e->getMessage());
		}

		/*=============================================
		Nunca negar lo que no está en los datos: si la IA
		respondió "no contamos con…", "no ofrecemos…",
		"no tengo esa información…" sin consultar, se le
		pide que corrija y consulte al equipo. Si dijo
		"déjame consultarlo" pero olvidó la etiqueta, la
		consulta se crea igual.
		=============================================*/

		try{

			$answer = self::ensureInquiry($answer, $history, $system, $clientText, $phone_message);

		}catch(Throwable $e){

			error_log("IA: error al revisar la consulta al equipo: ".$e->getMessage());
		}

		/* Ningún precio ni plan sin confirmar (ni en el texto ni en la orden) */

		try{

			$answer = self::guardPrices($answer, $history, $system, $clientText, $phone_message);

		}catch(Throwable $e){

			error_log("IA: error al revisar los precios: ".$e->getMessage());
		}

		/*=============================================
		Ejecutar etiquetas: citas, pedidos, imágenes y pagos
		=============================================*/

		/* Mientras se verificaba la cobertura o los datos, el cliente pudo escribir otra vez */

		if(Notify::lastClientId($phone_message) > $startId){

			return array("ok" => true, "error" => "", "skipped" => "newer");
		}

		$conversation = "";
		$last = ChatService::lastMessage($phone_message);

		if($last){

			$conversation = (string)$last->id_conversation_message;
		}

		try{

			if($profileName === ""){

				$contact = CurlController::request("contacts?linkTo=phone_contact&equalTo=".rawurlencode($phone_message)."&select=name_contact", "GET", array());
				$profileName = (isset($contact->status) && $contact->status == 200) ? (string)$contact->results[0]->name_contact : "";
			}

			$actions = BizActions::process($answer["text"], $phone_message, $profileName, $clientText, $conversation);

			/* Respaldo: dijo que registró/agendó la orden sin la etiqueta [[PEDIDO]] */

			try{

				$actions = self::recoverOrder($actions, $history, $phone_message, $profileName, $conversation);

			}catch(Throwable $e){

				error_log("IA: error al recuperar la orden: ".$e->getMessage());
			}

			/* Respaldo: confirmó una reserva sin la etiqueta [[CITA]] */

			$agenda = BizStore::get("agenda");

			if(!empty($agenda["enabled"]) && empty($actions["booked"]) && !BizActions::has($actions["actions"], "CITA") && BizActions::looksLikeBooking($actions["text"])){

				$found = BizActions::extractBooking($history, $actions["text"]);

				if($found){

					$tag = "[[CITA|".implode("|", array_map(function($v){ return str_replace(array("|", "]"), " ", (string)$v); }, array(
						$found["date"] ?? "", $found["time"] ?? "", $found["name"] ?? "", $found["service"] ?? "Reserva", $found["summary"] ?? "", (int)($found["people"] ?? 0) ?: ""
					)))."]]";

					$retry = BizActions::process($actions["text"]."\n".$tag, $phone_message, $profileName, "", $conversation);

					$actions["text"] = $retry["text"];
					$actions["booked"] = array_merge($actions["booked"], $retry["booked"]);
					$actions["media"] = array_merge($actions["media"], $retry["media"]);
					$actions["linked"] = array_merge((array)($actions["linked"] ?? array()), (array)($retry["linked"] ?? array()));
					$actions["scheduled"] = array_merge((array)($actions["scheduled"] ?? array()), (array)($retry["scheduled"] ?? array()));

					/* Instalación recuperada: vincularla con la orden de esta misma respuesta */

					foreach ((array)($actions["orders"] ?? array()) as $orderId) {

						if(!empty($retry["booked"]) && BizOrders::linkAppointment($orderId, $retry["booked"][0])){

							$actions["linked"][] = $retry["booked"][0];
						}
					}

					/* Se agendó primero: ahora se genera la orden con esa fecha */

					if(!empty($retry["booked"]) && empty($actions["orders"])){

						try{

							$actions = self::recoverOrder($actions, $history, $phone_message, $profileName, $conversation);

						}catch(Throwable $e){

							error_log("IA: error al generar la orden después de agendar: ".$e->getMessage());
						}
					}
				}
			}

			/* Respaldo: el cliente eligió uno de los horarios ofrecidos y la IA no lo agendó
			   → se agenda ese turno y se genera la orden automáticamente */

			try{

				if(BizGeo::installation() && !empty($agenda["enabled"]) && empty($actions["booked"]) && empty($actions["orders"]) && !BizActions::has($actions["actions"], "CITA") && !BizOrders::openOrderWithoutAppointment($phone_message) && self::offeredSlots($phone_message) && ($slot = BizStore::matchSlot($clientText))){

					$tag = "[[CITA|".$slot["date"]."|".$slot["time"]."|".str_replace(array("|", "]"), " ", $profileName !== "" ? $profileName : "Cliente")."|Instalación|Instalación de internet]]";
					$retry = BizActions::process($tag, $phone_message, $profileName, "", $conversation);

					if(!empty($retry["booked"])){

						$actions["booked"] = array_merge($actions["booked"], $retry["booked"]);
						$actions = self::recoverOrder($actions, $history, $phone_message, $profileName, $conversation);

						$missingNote = preg_grep('/^order_recover_missing/', (array)($actions["notes"] ?? array()));

						if(empty($actions["orders"]) && !empty($missingNote)){

							$actions["text"] = "🗓️ Separé tu instalación para el *".BizStore::dayName($slot["date"])." a las ".BizStore::time12($slot["time"])."*.\n\n".$actions["text"];

						}else if(empty($actions["orders"])){

							$actions["text"] = "🗓️ ¡Listo! Separé tu instalación para el *".BizStore::dayName($slot["date"])." a las ".BizStore::time12($slot["time"])."*. Para generar tu orden solo confírmame tus datos (nombres, DNI, correo, dirección, referencia y teléfono). 😊";
						}
					}
				}

			}catch(Throwable $e){

				error_log("IA: error al agendar el turno elegido: ".$e->getMessage());
			}

		}catch(Throwable $e){

			error_log("IA: error al procesar acciones: ".$e->getMessage()." en ".$e->getFile().":".$e->getLine());

			/* Lo ya procesado no se pierde (la orden pudo crearse antes del error) */

			if(!isset($actions) || !is_array($actions)){

				$actions = array("text" => BizActions::parse($answer["text"])[0], "media" => array(), "booked" => array(), "orders" => array(), "new_orders" => array(), "actions" => array());
			}
		}

		/* Orden creada en este mensaje que no quedó en la lista (por un error a mitad
		   de camino): se recupera para mostrarle el número al cliente y avisar al equipo */

		try{

			$stmt = BizStore::pdo()->prepare("SELECT id_order FROM orders WHERE contact_order = ? AND created_at_order >= ? AND (notify_order IS NULL OR notify_order = '') AND status_order = 'Pendiente'");
			$stmt->execute(array(biz_clean_phone($phone_message), date("Y-m-d H:i:s", time() - 180)));

			foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $lost) {

				if(!in_array((int)$lost, array_map("intval", (array)($actions["orders"] ?? array())), true)){

					$actions["orders"][] = (int)$lost;
					$actions["new_orders"][] = (int)$lost;
				}
			}

		}catch(Throwable $e){

			error_log("IA: no se pudo revisar las órdenes recientes: ".$e->getMessage());
		}

		/*=============================================
		Orden recién registrada: el cliente ve la ficha
		completa en el chat (número, plan, montos,
		dirección, instalación y pago). Reemplaza lo que
		escribió la IA, que podía prometer correos o
		datos que no son reales.
		=============================================*/

		try{

			if(empty($actions["new_orders"]) && in_array("order_needs_date", (array)($actions["notes"] ?? array()), true)){

				/* Quiso registrar la orden sin fecha de instalación: primero se agenda */

				$actions["text"] = BizOrders::askInstallDate("📅 *Antes de generar tu orden, elige la fecha de tu instalación.* Estos son los próximos horarios disponibles:");

			}else if(!empty($actions["new_orders"])){

				$confirm = array();

				foreach (array_unique($actions["new_orders"]) as $id) {

					$confirm[] = BizOrders::confirmation($id);
				}

				$confirm = array_filter($confirm);

				if(!empty($confirm)){

					$actions["text"] = implode("\n\n", $confirm);
				}

			}else if(empty($actions["orders"]) && BizActions::claimsRegistered($actions["text"]) && ($recent = self::recentOrder($phone_message))){

				/* Dice que la orden está registrada y sí existe una reciente: se muestra la
				   ficha real (con su número) y se asegura el aviso al equipo */

				$actions["text"] = BizOrders::confirmation($recent) ?: $actions["text"];
				$actions["orders"][] = $recent;

			}else if(BizActions::claimsRegistered($actions["text"]) && empty($actions["orders"]) && !self::recentOrder($phone_message)){

				/* Dice "registrada" pero no hay ninguna orden: no se envía esa mentira */

				$fix = AIService::reply($history, $system."\n\nATENCIÓN: ibas a decir que la orden está registrada, pero el sistema NO tiene ninguna orden registrada para este cliente. No digas que está registrada ni prometas enviar el número por correo. Si ya tienes TODOS los datos y el cliente confirmó, agrega ahora [[PEDIDO|...]] (y [[CITA|...]] si eligió turno) en esta respuesta; si falta algún dato, pídeselo en una línea.");

				if($fix["ok"]){

					$retry = BizActions::process($fix["text"], $phone_message, $profileName, $clientText, $conversation);

					if(!empty($retry["new_orders"])){

						$actions = $retry;
						$actions["text"] = implode("\n\n", array_filter(array_map(array("BizOrders", "confirmation"), $retry["new_orders"])));

					}else if(!BizActions::claimsRegistered($retry["text"])){

						$actions = $retry;

					}else{

						$actions["text"] = "📝 Estoy terminando de registrar tu orden. ¿Me confirmas que todos tus datos son correctos para generarla ahora mismo? 😊";
					}

				}else{

					$actions["text"] = "📝 Estoy terminando de registrar tu orden. ¿Me confirmas que todos tus datos son correctos para generarla ahora mismo? 😊";
				}
			}

		}catch(Throwable $e){

			error_log("IA: error al armar la confirmación de la orden: ".$e->getMessage());
		}

		/* Datos de la orden uno por uno y horarios con el formato fijo */

		try{

			if(BizGeo::installation() && empty($actions["orders"]) && empty($actions["new_orders"])){

				$actions["text"] = BizOrders::tidyReply($actions["text"]);
			}

		}catch(Throwable $e){

			error_log("IA: no se pudo ordenar la respuesta: ".$e->getMessage());
		}

		$result = array("ok" => true, "error" => "");

		/*=============================================
		Enviar y guardar la respuesta de texto
		=============================================*/

		if($actions["text"] !== ""){

			$result = ChatService::deliver(
				$getApiWS,
				$phone_message,
				ChatService::textPayload($phone_message, $actions["text"]),
				$actions["text"],
				'{"type":"ia","title":""}'
			);
		}

		/*=============================================
		Imágenes y códigos QR de pago
		=============================================*/

		foreach ($actions["media"] as $media) {

			if($media["type"] === "text"){

				ChatService::deliver($getApiWS, $phone_message, ChatService::textPayload($phone_message, $media["text"]), $media["text"], '{"type":"ia","title":""}');
				continue;
			}

			ChatService::deliver(
				$getApiWS,
				$phone_message,
				ChatService::mediaPayload($phone_message, "image", $media["link"], $media["caption"]),
				json_encode(array("type" => "image", "title" => $media["link"]), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
				'{"type":"image","title":""}'
			);
		}

		/*=============================================
		Avisar al negocio de cada cita y pedido nuevo
		=============================================*/

		foreach ($actions["booked"] as $id) {

			/* Va incluida en el aviso de la orden (un solo mensaje con todo) */

			if(in_array($id, (array)($actions["linked"] ?? array()), true)){

				BizStore::markNotified($id);
				continue;
			}

			$appt = BizStore::appointment($id);

			if($appt && empty($appt["notified_appointment"]) && BizActions::notifyOwner($appt, "nueva")){

				BizStore::markNotified($id);
			}
		}

		foreach ((array)($actions["orders"] ?? array()) as $id) {

			try{

				BizOrders::notifyOwner($id);

			}catch(Throwable $e){

				error_log("Pedidos: no se pudo avisar al negocio: ".$e->getMessage());
			}
		}

		/* Instalación agendada para una orden de un mensaje anterior */

		foreach (array_unique(array_diff((array)($actions["scheduled"] ?? array()), (array)($actions["orders"] ?? array()))) as $id) {

			try{

				BizOrders::notifyOwner($id, "agendada");

			}catch(Throwable $e){

				error_log("Pedidos: no se pudo avisar la instalación: ".$e->getMessage());
			}
		}

		return $result;

	}

}
