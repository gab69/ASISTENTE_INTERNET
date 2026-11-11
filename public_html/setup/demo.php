<?php

/*=============================================
Datos iniciales para una empresa de internet y TV.

- Instalación nueva: modo "Internet y TV con
  instalación" y horarios de instalación.
- Con datos de ejemplo: además empresa, sucursales,
  métodos de pago y conversaciones de prueba con
  fechas actuales (un cliente con la instalación
  agendada, uno sin cobertura y uno por responder).
=============================================*/

function setup_setting(PDO $pdo, $key, array $value){

	$stmt = $pdo->prepare("INSERT INTO settings (key_setting, value_setting) VALUES (?, ?) ON DUPLICATE KEY UPDATE value_setting = VALUES(value_setting)");
	$stmt->execute(array($key, json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)));

}

/* Próximo día hábil (lunes a sábado) a partir de N días */

function setup_business_day($days){

	$ts = strtotime("+".$days." day");

	while((int)date("N", $ts) === 7){

		$ts = strtotime("+1 day", $ts);
	}

	return date("Y-m-d", $ts);

}

function setup_seed(PDO $pdo, $mode){

	/* Misma zona horaria que el sistema (las horas de los mensajes) */

	try{ $pdo->exec("SET time_zone = '".date("P")."'"); }catch(Throwable $e){}

	/* ---------- Siempre: venta de internet con instalación ---------- */

	setup_setting($pdo, "service", array(
		"mode" => "installation",
		"install_fee" => 0,
		"tv_enabled" => true,
		"tv_price" => 15,
		"tvbox_price" => 120,
		"tv_notes" => "Más de 30 canales nacionales en HD. Funciona con cualquier TV box Android.",
		"requirements" => "DNI del titular"
	));

	setup_setting($pdo, "agenda", array(
		"enabled" => true,
		"notify_phone" => "",
		"slot_minutes" => 120,
		"capacity" => 1,
		"times" => "08:00, 10:00, 12:00, 14:00, 16:00, 17:00",
		"days" => array(1, 2, 3, 4, 5, 6),
		"open" => "08:00",
		"close" => "18:00",
		"break_start" => "13:00",
		"break_end" => "14:00",
		"max_days" => 21,
		"min_hours" => 12,
		"services" => "Instalación de internet, Instalación de internet + TV, Visita técnica"
	));

	if($mode !== "demo"){

		return;
	}

	/* ---------- Empresa, pagos y sucursales de ejemplo ---------- */

	setup_setting($pdo, "company", array(
		"name" => "Fibra Demo",
		"description" => "Internet de fibra óptica para hogares y negocios, con TV abierta opcional. Planes sin plazo forzoso, instalación en 24 a 72 horas y soporte técnico todos los días.",
		"address" => "Av. Universitaria 1200, Los Olivos, Lima",
		"hours" => "Lunes a sábado de 8:00 a. m. a 6:00 p. m. Soporte técnico 24/7.",
		"website" => "",
		"email" => "ventas@fibrademo.pe",
		"social" => "Facebook /fibrademo · Instagram @fibrademo",
		"extra" => "La instalación es gratis. El primer pago se realiza el día de la instalación. Se puede cambiar de plan en cualquier momento."
	));

	setup_setting($pdo, "contacts", array(
		array("label" => "Ventas", "name" => "", "phone" => "51911111111", "purpose" => "Contratar un plan nuevo, cambiar de plan o consultar precios y promociones.", "share" => true, "orders" => false, "cancel" => false, "appts" => false),
		array("label" => "Soporte técnico", "name" => "", "phone" => "51922222222", "purpose" => "Clientes que ya tienen el servicio: internet lento o sin conexión, problemas con el router o el TV box. Atiende 24/7.", "share" => true, "orders" => false, "cancel" => false, "appts" => false),
		array("label" => "Pagos y facturación", "name" => "", "phone" => "51933333333", "purpose" => "Enviar comprobantes de pago, consultar deudas, recibos o cortes por falta de pago.", "share" => true, "orders" => false, "cancel" => false, "appts" => false)
	));

	setup_setting($pdo, "payments", array(
		array("id" => "yape01", "name" => "Yape", "holder" => "Fibra Demo SAC", "account" => "987654321", "qr" => "", "notes" => ""),
		array("id" => "plin01", "name" => "Plin", "holder" => "Fibra Demo SAC", "account" => "987654321", "qr" => "", "notes" => ""),
		array("id" => "bcp01", "name" => "Transferencia BCP", "holder" => "Fibra Demo SAC", "account" => "191-1234567-0-89", "qr" => "", "notes" => "CCI 002-191-001234567089-55")
	));

	setup_setting($pdo, "delivery", array(
		"enabled" => true,
		"origin_address" => "", "lat" => "", "lng" => "", "radius_km" => 0, "zones" => "", "excluded" => "",
		"fee" => 0, "fee_per_km" => 0, "free_from" => 0, "min_order" => 0, "pickup" => false,
		"city_hint" => "Lima, Perú",
		"maps_key" => "",
		"notify_phone" => "",
		"branches" => array(
			array("id" => "norte", "name" => "Sede Norte", "address" => "Av. Universitaria 1200, Los Olivos", "lat" => -11.9907, "lng" => -77.0745, "radius_km" => 6, "zones" => "Los Olivos\nComas\nIndependencia\nSan Martín de Porres", "excluded" => "", "phone" => ""),
			array("id" => "centro", "name" => "Sede Centro", "address" => "Av. Arequipa 2450, Lince", "lat" => -12.0866, "lng" => -77.0345, "radius_km" => 5, "zones" => "Lince\nJesús María\nSan Isidro\nMiraflores", "excluded" => "", "phone" => ""),
			array("id" => "sur", "name" => "Sede Sur", "address" => "Av. Defensores del Morro 1500, Chorrillos", "lat" => -12.1690, "lng" => -77.0244, "radius_km" => 4, "zones" => "Chorrillos\nBarranco\nSurco", "excluded" => "", "phone" => "")
		)
	));

	/* ---------- Conversaciones de ejemplo ---------- */

	$pdo->exec("DELETE FROM messages");
	$pdo->exec("DELETE FROM contacts");
	$pdo->exec("DELETE FROM orders");
	$pdo->exec("DELETE FROM appointments");
	$pdo->exec("DELETE FROM coverage_checks");

	/* Historial de 30 días primero: la orden de Carlos queda como la más reciente */

	setup_demo_history($pdo);

	$nextOrder = (int)$pdo->query("SELECT COALESCE(MAX(id_order), 0) + 1 FROM orders")->fetchColumn();

	$contact = $pdo->prepare("INSERT INTO contacts (phone_contact, name_contact, ai_contact, date_created_contact, date_updated_contact) VALUES (?, ?, 1, ?, ?)");
	$message = $pdo->prepare("INSERT INTO messages (id_conversation_message, type_message, id_whatsapp_message, client_message, business_message, template_message, expiration_message, order_message, phone_message, status_message, date_created_message, date_updated_message) VALUES (?, ?, 1, ?, ?, '{\"type\":\"ia\",\"title\":\"\"}', ?, ?, ?, ?, ?, ?)");

	$chat = function($phone, $start, array $lines) use ($message){

		$conv = "c_demo".substr($phone, -6);
		$ts = $start;

		foreach ($lines as $i => $l) {

			$ts += $l[2] * 60;
			$client = $l[0] === "c";

			$message->execute(array($conv, $client ? "client" : "business", $client ? $l[1] : null, $client ? null : $l[1], date("Y-m-d H:i:s", $start + 86400), $i, $phone, $client ? null : "read", date("Y-m-d", $ts), date("Y-m-d H:i:s", $ts)));
		}

		return array($conv, $ts);
	};

	/* 1) Carlos: eligió plan, tiene cobertura y agendó la instalación */

	$carlos = "51987654321";
	$install = setup_business_day(3);
	$installLabel = setup_day_label($install);
	$start = strtotime(date("Y-m-d", strtotime("-1 day"))." 19:05:00");

	list($conv, $last) = $chat($carlos, $start, array(
		array("c", "Hola, buenas noches. Quiero información de sus planes de internet 🙂", 0),
		array("b", "¡Hola Carlos! 👋 Soy Anyela, asesora de Fibra Demo. ¿Cuántas personas usan internet en casa y para qué lo usan más?", 1),
		array("c", "Somos 4, mis hijos estudian en línea y yo hago home office", 2),
		array("b", "¡Perfecto! Para clases y teletrabajo te recomiendo *Fibra 400 Mbps* (S/ 79.00 al mes) 🚀\n\nAntes de continuar, ¿me compartes la dirección de instalación (calle, número y distrito) o tu ubicación 📍 para verificar la cobertura?", 1),
		array("c", "Av. Naranjal 845, Los Olivos", 3),
		array("b", "✅ ¡Buenas noticias! Sí tenemos cobertura en tu dirección. Te atendería nuestra *Sede Norte* (a 2.1 km).\n\nTambién ofrecemos *TV abierta* por S/ 15.00 al mes. ¿Te interesa? Si es así, ¿ya tienes un TV box?", 1),
		array("c", "Sí quiero la TV, y ya tengo un TV box", 2),
		array("b", "¡Genial! Entonces no pagas el equipo 🙌\nPara registrar la instalación necesito el *nombre completo del titular* y su *DNI*.", 1),
		array("c", "Carlos Mendoza Ríos, DNI 45871236", 2),
		array("b", "Gracias, Carlos. Tengo estos horarios libres para la instalación:\n\n• ".$installLabel." a las 10:00 a. m.\n• ".$installLabel." a las 2:00 p. m.\n\n¿Cuál prefieres?", 1),
		array("c", "El de las 10 de la mañana", 2),
		array("b", "Te resumo tu orden 📋\n\n• Fibra 400 Mbps · S/ 79.00 al mes\n• TV abierta · S/ 15.00 al mes (con tu TV box)\n• Instalación gratis el *".$installLabel." a las 10:00 a. m.*\n• Dirección: Av. Naranjal 845, Los Olivos\n\n¿Confirmas para dejarla agendada?", 1),
		array("c", "Sí, confirmo 👍", 1),
		array("b", "¡Listo, Carlos! 🎉 Tu instalación quedó agendada. El técnico de la Sede Norte te llamará antes de llegar. El primer pago se realiza el día de la instalación.\n\n🧾 *Orden #".$nextOrder." registrada*\nMensualidad: *S/ 94.00*\nPago inicial: *S/ 0.00*", 1),
		array("c", "Muchas gracias 🙌", 2)
	));

	$contact->execute(array($carlos, "Carlos Mendoza", date("Y-m-d", $start), date("Y-m-d H:i:s", $last)));

	$pdo->prepare("INSERT INTO coverage_checks (phone_check, query_check, label_check, lat_check, lng_check, distance_check, covered_check, zone_check, fee_check, branch_check, date_check) VALUES (?, ?, ?, ?, ?, ?, 'si', 'los olivos', 0, 'Sede Norte', ?)")
		->execute(array($carlos, "Av. Naranjal 845, Los Olivos", "Avenida Naranjal, Los Olivos, Lima, Perú", -11.9805, -77.0697, 2.1, date("Y-m-d H:i:s", $start + 600)));

	$pdo->prepare("INSERT INTO appointments (phone_appointment, name_appointment, service_appointment, date_appointment, time_appointment, duration_appointment, summary_appointment, status_appointment, source_appointment, notified_appointment, date_created_appointment, branch_appointment) VALUES (?, ?, 'Instalación', ?, '10:00:00', 120, ?, 'confirmada', 'ia', 1, ?, 'Sede Norte')")
		->execute(array($carlos, "Carlos Mendoza Ríos", $install, "Fibra 400 Mbps + TV abierta (TV box propio) | Orden #".$nextOrder, date("Y-m-d H:i:s", $last)));

	$appointment = (int)$pdo->lastInsertId();

	$items = array(
		array("qty" => 1, "name" => "Fibra 400 Mbps", "price" => 79, "subtotal" => 79, "matched" => true),
		array("qty" => 1, "name" => "TV abierta", "price" => 15, "subtotal" => 15, "matched" => true),
		array("qty" => 1, "name" => "TV box (propio del cliente)", "price" => 0, "subtotal" => 0, "matched" => true)
	);

	$pdo->prepare("INSERT INTO orders (conversation_order, products_order, items_order, phone_order, name_order, email_order, address_order, reference_order, contact_order, subtotal_order, fee_order, total_order, status_order, delivery_order, payment_order, notes_order, lat_order, lng_order, distance_order, coverage_order, source_order, date_created_order, created_at_order, branch_order, appointment_order, document_order) VALUES (?, ?, ?, ?, ?, '', ?, '', ?, 94, 0, 94, 'Pendiente', 'instalacion', 'Yape', 'Llamar antes de llegar', -11.9805, -77.0697, 2.1, 'si', 'ia', ?, ?, 'Sede Norte', ?, '45871236')")
		->execute(array($conv, "1 x Fibra 400 Mbps - S/ 79.00\n1 x TV abierta - S/ 15.00\n1 x TV box (propio del cliente) - S/ 0.00", json_encode($items, JSON_UNESCAPED_UNICODE), $carlos, "Carlos Mendoza Ríos", "Av. Naranjal 845, Los Olivos", $carlos, date("Y-m-d", $last), date("Y-m-d H:i:s", $last), $appointment));

	/* 2) María: dirección sin cobertura (no se le pidieron más datos) */

	$maria = "51955512345";
	$start = strtotime(date("Y-m-d")." 09:40:00");

	list(, $last) = $chat($maria, $start, array(
		array("c", "Hola, ¿tienen internet en Ate? Vivo por la Av. Separadora Industrial", 0),
		array("b", "¡Hola! 👋 Gracias por escribirnos. Para verificarlo, ¿me compartes tu dirección exacta (calle, número y distrito) o tu ubicación 📍?", 1),
		array("c", "Av. Separadora Industrial 2350, Ate", 2),
		array("b", "😕 Lo siento, por ahora no tenemos cobertura en esa dirección: la sede más cercana (Sede Centro) está a 9.8 km y llega hasta 5 km.\n\nEstamos ampliando la red. ¿Quieres que te avisemos cuando lleguemos a tu zona?", 1),
		array("c", "Sí, avísenme por favor", 3)
	));

	$contact->execute(array($maria, "María Torres", date("Y-m-d", $start), date("Y-m-d H:i:s", $last)));

	$pdo->prepare("INSERT INTO coverage_checks (phone_check, query_check, label_check, lat_check, lng_check, distance_check, covered_check, zone_check, fee_check, branch_check, date_check) VALUES (?, ?, ?, ?, ?, 9.8, 'no', '', NULL, 'Sede Centro', ?)")
		->execute(array($maria, "Av. Separadora Industrial 2350, Ate", "Avenida Separadora Industrial, Ate, Lima, Perú", -12.0441, -76.9561, date("Y-m-d H:i:s", $start + 180)));

	/* 3) Rosa: consulta reciente, esperando respuesta */

	$rosa = "51912345678";
	$start = time() - 900;

	list(, $last) = $chat($rosa, $start, array(
		array("c", "Buenas, ¿el plan de 1000 Mbps incluye la TV?", 0),
		array("b", "¡Hola Rosa! 😊 El plan *Fibra 1000 Mbps* cuesta S/ 129.00 al mes. Si quieres internet y TV juntos, te conviene el *Dúo 1000 Mbps + TV* por S/ 144.00 al mes.\n\n¿Me compartes tu dirección para verificar la cobertura?", 1),
		array("c", "Estoy en Surco, déjame confirmar el número de la casa", 10)
	));

	$contact->execute(array($rosa, "Rosa Quispe", date("Y-m-d", $start), date("Y-m-d H:i:s", $last)));


}

/*=============================================
Historial de 30 días para que Inicio muestre
gráficos y consumo desde el primer momento
=============================================*/

function setup_demo_history(PDO $pdo){

	try{ $pdo->exec("DELETE FROM usage_log"); }catch(Throwable $e){ return; }

	mt_srand(2026);

	$message = $pdo->prepare("INSERT INTO messages (id_conversation_message, type_message, id_whatsapp_message, client_message, business_message, template_message, expiration_message, order_message, phone_message, status_message, date_created_message, date_updated_message) VALUES (?, ?, 1, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
	$usage = $pdo->prepare("INSERT INTO usage_log (kind_usage, provider_usage, model_usage, input_usage, output_usage, ok_usage, detail_usage, phone_usage, created_usage) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
	$order = $pdo->prepare("INSERT INTO orders (conversation_order, products_order, items_order, phone_order, name_order, email_order, address_order, reference_order, contact_order, subtotal_order, fee_order, total_order, status_order, delivery_order, payment_order, notes_order, coverage_order, source_order, date_created_order, created_at_order, closed_at_order, cancel_reason_order, branch_order) VALUES (?, ?, ?, ?, ?, '', ?, '', ?, ?, 0, ?, ?, 'instalacion', 'Yape', '', 'si', 'ia', ?, ?, ?, ?, ?)");
	$check = $pdo->prepare("INSERT INTO coverage_checks (phone_check, query_check, label_check, covered_check, branch_check, date_check) VALUES (?, ?, ?, ?, ?, ?)");

	$plans = array(array("Fibra 200 Mbps", 59), array("Fibra 400 Mbps", 79), array("Fibra 600 Mbps", 99), array("Dúo 400 Mbps + TV", 94), array("Fibra 1000 Mbps", 129));
	$branches = array("Sede Norte", "Sede Centro", "Sede Sur");
	$names = array("Luis", "Ana", "Jorge", "Lucía", "Pedro", "Carmen", "Diego", "Sofía", "Miguel", "Elena", "Raúl", "Paola");

	$pdo->beginTransaction();

	for($day = 29; $day >= 1; $day--){

		$date = date("Y-m-d", strtotime("-".$day." days"));
		$weekend = (int)date("N", strtotime($date)) >= 6;
		$chats = mt_rand($weekend ? 4 : 8, $weekend ? 10 : 18);

		for($c = 0; $c < $chats; $c++){

			$phone = "519".str_pad((string)mt_rand(10000000, 99999999), 8, "0", STR_PAD_LEFT);
			$conv = "c_hist".bin2hex(random_bytes(6));
			$ts = strtotime($date." ".sprintf("%02d:%02d:00", mt_rand(8, 21), mt_rand(0, 59)));
			$turns = mt_rand(2, 6);
			$outcome = mt_rand(1, 100);

			for($t = 0; $t < $turns; $t++){

				$message->execute(array($conv, "client", "Mensaje del cliente", null, '{"type":"ia","title":""}', date("Y-m-d H:i:s", $ts + 86400), $t * 2, $phone, null, $date, date("Y-m-d H:i:s", $ts)));
				$ts += mt_rand(20, 90);

				/* Casi siempre se responde (algunos quedan sin respuesta) */

				if(mt_rand(1, 100) <= 94){

					$failed = mt_rand(1, 100) <= 2;
					$message->execute(array($conv, "business", null, "Respuesta del asistente", '{"type":"ia","title":""}', date("Y-m-d H:i:s", $ts + 86400), $t * 2 + 1, $phone, $failed ? "failed" : "read", $date, date("Y-m-d H:i:s", $ts)));
					$usage->execute(array("ai", "openai", "gpt-4o-mini", mt_rand(2400, 4200), mt_rand(60, 220), 1, "respuesta", "", date("Y-m-d H:i:s", $ts - 5)));
					$usage->execute(array("wa", "whatsapp", "text", 0, 0, $failed ? 0 : 1, "text", $phone, date("Y-m-d H:i:s", $ts)));
				}

				$ts += mt_rand(60, 600);
			}

			$branch = $branches[mt_rand(0, 2)];

			if($outcome <= 28){

				/* Orden de instalación (la mayoría ya instalada; algunas canceladas) */

				$plan = $plans[mt_rand(0, count($plans) - 1)];
				$cancel = mt_rand(1, 100) <= 15;
				$items = json_encode(array(array("qty" => 1, "name" => $plan[0], "price" => $plan[1], "subtotal" => $plan[1], "matched" => true)), JSON_UNESCAPED_UNICODE);

				$order->execute(array($conv, "1 x ".$plan[0]." - S/ ".number_format($plan[1], 2), $items, $phone, $names[mt_rand(0, count($names) - 1)]." (ejemplo)", "Dirección de ejemplo", $phone, $plan[1], $plan[1], $cancel ? "Cancelado" : "Completado", $date, date("Y-m-d H:i:s", $ts), date("Y-m-d H:i:s", min(time() - 3600, $ts + 172800)), $cancel ? "El cliente desistió" : null, $branch));

				$usage->execute(array("wa", "whatsapp", "text", 0, 0, 1, "text", "", date("Y-m-d H:i:s", $ts + 5)));

			}else if($outcome <= 40){

				$check->execute(array($phone, "Dirección fuera de zona", "Dirección fuera de zona", "no", $branch, date("Y-m-d H:i:s", $ts)));
			}

			/* Cierre amable por plantilla (algunas conversaciones fuera de las 24 h) */

			if(mt_rand(1, 100) <= 4){

				$usage->execute(array("wa", "whatsapp", "template", 0, 0, 1, "template", $phone, date("Y-m-d H:i:s", $ts + 60)));
			}
		}
	}

	$pdo->commit();

	mt_srand();

}

function setup_day_label($date){

	$days = array(1 => "lunes", "martes", "miércoles", "jueves", "viernes", "sábado", "domingo");
	$months = array(1 => "enero", "febrero", "marzo", "abril", "mayo", "junio", "julio", "agosto", "septiembre", "octubre", "noviembre", "diciembre");
	$ts = strtotime($date);

	return $days[(int)date("N", $ts)]." ".(int)date("j", $ts)." de ".$months[(int)date("n", $ts)];

}
