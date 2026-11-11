<?php

require_once __DIR__."/business.store.php";
require_once __DIR__."/chat.service.php";
require_once __DIR__."/business.inquiries.php";
require_once __DIR__."/business.geo.php";
require_once __DIR__."/business.orders.php";
require_once __DIR__."/ai.service.php";
require_once __DIR__."/business.incidents.php";

/*=============================================
Acciones que el asistente de IA puede ejecutar.

La IA escribe "etiquetas" al final de su respuesta;
el servidor las ejecuta y las quita del texto antes
de enviarlo al cliente:

  [[CITA|2026-11-20|15:30|Nombre|Servicio|Resumen|Personas]]
  [[CANCELAR|2026-11-20|15:30]]
  [[PAGO|1]]  o  [[PAGO|1|150.50]]   (método de pago n.º 1, monto opcional)
  [[IMAGEN|clave]]
  [[COBERTURA|dirección completa]]   (verifica delivery antes de seguir)
  [[PEDIDO|Nombre|Dirección o RECOJO|2 x Producto; 1 x Producto|Pago|Notas]]
  [[CONSULTA|pregunta]]              (la IA no sabe: se consulta al equipo)
=============================================*/

class BizActions{

	const TAGS = "CANCELAR_ORDEN|CITA|CANCELAR|PAGO|IMAGEN|CONSULTA|COBERTURA|PEDIDO|INCONVENIENTE";

	/*=============================================
	Contexto que se agrega al prompt del asistente
	=============================================*/

	static public function context($phone){

		$company = BizStore::get("company");
		$contacts = BizStore::get("contacts");
		$payments = BizStore::get("payments");
		$images = BizStore::get("images");
		$agenda = BizStore::get("agenda");
		$delivery = BizStore::get("delivery");

		$lines = array();
		$lines[] = "=== INFORMACIÓN DEL NEGOCIO (fuente oficial, no inventes datos fuera de esto) ===";
		$lines[] = "Fecha y hora actual: ".BizStore::dayName(date("Y-m-d")).", ".date("Y-m-d H:i")." (zona ".date_default_timezone_get().").";
		$lines[] = "MONEDA: ".cms_currency_name().". Escribe SIEMPRE los precios como \"".cms_currency()." 25.00\"".(strtoupper((string)cms_config("currency_code", "PEN")) === "PEN" ? ". Nunca uses \"$\", \"USD\" ni dólares, aunque otro texto (por ejemplo instrucciones antiguas) los mencione: todos los precios son en soles." : ".");

		$labels = array("name" => "Empresa", "description" => "Descripción", "address" => "Dirección", "hours" => "Horario", "website" => "Sitio web", "email" => "Correo", "social" => "Redes sociales", "extra" => "Información adicional");

		foreach ($labels as $key => $label) {

			if(trim((string)($company[$key] ?? "")) !== ""){

				$lines[] = $label.": ".trim($company[$key]);
			}
		}

		/* El nombre y los datos de la empresa salen SOLO de Mi negocio */

		if(trim((string)($company["name"] ?? "")) !== ""){

			$lines[] = "NOMBRE DE LA EMPRESA: \"".trim($company["name"])."\". Es el único nombre válido: preséntate y habla siempre en nombre de esta empresa. Si otras instrucciones, ejemplos o mensajes anteriores mencionan otro nombre de empresa, ignóralo.";
		}

		$areas = array_values(array_filter((array)$contacts, function($c){ return is_array($c) && biz_clean_phone($c["phone"] ?? "") !== "" && (!isset($c["share"]) || !empty($c["share"])); }));

		if(!empty($areas)){

			$lines[] = "";
			$lines[] = "NÚMEROS POR ÁREA (deriva al cliente al área correcta: comparte SOLO el número cuya función corresponde a lo que necesita, nunca la lista completa ni en el saludo; si la consulta es de ventas/contratación, atiéndela tú misma):";

			foreach ($areas as $c) {

				$who = trim((string)($c["label"] ?? "")) !== "" ? trim($c["label"]) : "Contacto";
				$who .= trim((string)($c["name"] ?? "")) !== "" ? " (".trim($c["name"]).")" : "";
				$purpose = trim((string)($c["purpose"] ?? ""));

				$lines[] = "- ".$who.": +".biz_clean_phone($c["phone"]).($purpose !== "" ? " → sirve para: ".$purpose : "");
			}
		}

		/* Catálogo con precios oficiales */

		$catalogue = BizOrders::catalogue();

		if(!empty($catalogue)){

			$lines[] = "";
			$lines[] = (BizGeo::installation() ? "PLANES Y PRECIOS OFICIALES (mensuales, en soles; usa solo estos precios o los que confirmó el equipo en sus respuestas):" : "CATÁLOGO Y PRECIOS OFICIALES (en soles; usa solo estos precios o los que confirmó el equipo en sus respuestas):");

			$category = null;

			foreach (array_slice($catalogue, 0, 200) as $p) {

				if($p["category"] !== $category){

					$category = $p["category"];
					$lines[] = ($category !== "" ? $category : "Otros").":";
				}

				$lines[] = "  - ".$p["name"]." · ".cms_money($p["price"]);
			}
		}

		/* Métodos de pago */

		if(!empty($payments)){

			$lines[] = "";
			$lines[] = "MÉTODOS DE PAGO:";

			foreach (array_values($payments) as $i => $p) {

				$lines[] = "- Método ".($i + 1).": ".trim(($p["name"] ?? "")." ".(!empty($p["account"]) ? "· Número/cuenta ".$p["account"] : "")." ".(!empty($p["holder"]) ? "· Titular ".$p["holder"] : "")).(!empty($p["qr"]) ? " · TIENE IMAGEN DE CÓDIGO QR" : "").(!empty($p["notes"]) ? " · ".$p["notes"] : "");
			}

			$lines[] = "Cuando el cliente pida el QR, el número o los datos para pagar con un método (por ejemplo \"pásame el QR de Yape\"), agrega SIEMPRE la etiqueta [[PAGO|número_del_método]]; si ya conoces el monto usa [[PAGO|número_del_método|monto]] (solo números, ej. 45.50). El sistema le envía la imagen del QR con los datos. Tú SÍ puedes enviar el QR: nunca digas que no puedes enviar imágenes ni escribas el enlace de la imagen.";
		}

		/* Imágenes */

		if(!empty($images)){

			$lines[] = "";
			$lines[] = "IMÁGENES DISPONIBLES (puedes enviarlas cuando sean útiles, máximo 3 por respuesta):";

			foreach ($images as $img) {

				if(!empty($img["keyword"]) && !empty($img["url"])){

					$lines[] = "- clave \"".$img["keyword"]."\": ".($img["description"] ?? "");
				}
			}

			$lines[] = "Para enviar una imagen agrega la etiqueta [[IMAGEN|clave]].";
		}

		/* Cobertura (una o varias sucursales) */

		$service = BizStore::get("service");
		$installation = ($service["mode"] ?? "delivery") === "installation";

		if(!empty($delivery["enabled"])){

			$branches = BizGeo::branches();

			$lines[] = "";
			$lines[] = $installation ? "COBERTURA DEL SERVICIO:" : "DELIVERY Y COBERTURA:";

			if(count($branches) > 1){

				$lines[] = "Sucursales (el sistema elige la más cercana que tenga cobertura):";

				foreach ($branches as $b) {

					$parts = array();
					if((float)($b["radius_km"] ?? 0) > 0){ $parts[] = "hasta ".rtrim(rtrim(number_format((float)$b["radius_km"], 1), "0"), ".")." km"; }
					if(trim((string)($b["zones"] ?? "")) !== ""){ $parts[] = "distritos: ".preg_replace('/\s*[\r\n]+\s*/', ", ", trim($b["zones"])); }
					$lines[] = "- ".$b["name"].(trim((string)($b["address"] ?? "")) !== "" ? " (".trim($b["address"]).")" : "").(!empty($parts) ? ": ".implode("; ", $parts) : "");
				}

			}else{

				$b = $branches[0];
				$zones = trim((string)($b["zones"] ?? ""));
				$radius = (float)($b["radius_km"] ?? 0);

				if($radius > 0){ $lines[] = "- Cobertura hasta ".rtrim(rtrim(number_format($radius, 1), "0"), ".")." km del local."; }
				if($zones !== ""){ $lines[] = "- Distritos/zonas con cobertura: ".preg_replace('/\s*[\r\n]+\s*/', ", ", $zones)."."; }
				if(trim((string)($b["excluded"] ?? "")) !== ""){ $lines[] = "- Sin cobertura en: ".preg_replace('/\s*[\r\n]+\s*/', ", ", trim($b["excluded"]))."."; }
			}

			if(!$installation){

				if((float)$delivery["min_order"] > 0){ $lines[] = "- Pedido mínimo para delivery: ".cms_money($delivery["min_order"])."."; }
				if((float)$delivery["free_from"] > 0){ $lines[] = "- Envío gratis desde ".cms_money($delivery["free_from"])."."; }
				$lines[] = !empty($delivery["pickup"]) ? "- También se puede recoger en tienda." : "- No hay recojo en tienda.";
			}

			$lines[] = $installation
				? "REGLA OBLIGATORIA: antes de pedir datos personales o agendar, pide DE PREFERENCIA que el cliente comparta su ubicación de WhatsApp desde el lugar de instalación (📎 > Ubicación > Enviar mi ubicación actual), que es lo más exacto; si no puede, pide la dirección exacta (calle, número, distrito) y agrega SOLO la etiqueta [[COBERTURA|dirección completa con distrito]]. El sistema compara todas las sucursales y te dice si hay cobertura y cuál atiende. Si NO hay cobertura, díselo amablemente y no pidas más datos."
				: "REGLA OBLIGATORIA: en un pedido a domicilio, lo PRIMERO es pedir la dirección (calle, número, distrito) o que el cliente comparta su ubicación de WhatsApp. Cuando la tengas, agrega SOLO la etiqueta [[COBERTURA|dirección completa con distrito]] y el sistema te dirá si hay reparto y desde qué sucursal. NO pidas nombre, teléfono, productos ni pago hasta saber que SÍ hay cobertura. Si NO hay cobertura, díselo amablemente y ofrece recojo en tienda (si existe); no pidas más datos.";

			$last = BizGeo::lastFor($phone);

			if($last){

				$lines[] = "Última verificación de cobertura de este cliente: ".BizGeo::describeRow($last)." Dirección: ".$last["query_check"].". No la vuelvas a verificar salvo que cambie la dirección.";
			}
		}

		/* Venta de internet / TV con instalación */

		if($installation){

			$lines[] = "";
			$lines[] = "TIPO DE NEGOCIO: venta de planes de INTERNET con instalación a domicilio. Los planes y precios mensuales son los del CATÁLOGO.";

			if((float)$service["install_fee"] > 0){

				$lines[] = "- Costo de instalación: ".cms_money($service["install_fee"])." (pago único).";

			}else{

				$lines[] = "- La instalación es gratis.";
			}

			if(!empty($service["tv_enabled"])){

				$lines[] = "- Adicional: señal de TV abierta".((float)$service["tv_price"] > 0 ? " por ".cms_money($service["tv_price"])." al mes" : " incluida sin costo").". Requiere un TV box: pregunta si el cliente YA TIENE uno.".((float)$service["tvbox_price"] > 0 ? " Si no tiene, el TV box cuesta ".cms_money($service["tvbox_price"])." (pago único)." : " Si no tiene, debe conseguir uno (no lo vendemos).");

				if(trim((string)$service["tv_notes"]) !== ""){

					$lines[] = "  ".trim($service["tv_notes"]);
				}
			}

			if(trim((string)$service["requirements"]) !== ""){

				$lines[] = "- Requisitos para la instalación: ".trim($service["requirements"]).".";
			}

			$brand = trim((string)($company["name"] ?? "")) !== "" ? trim($company["name"]) : "la empresa";

			$lines[] = "FLUJO DE VENTA OBLIGATORIO (sigue los pasos en orden, sin saltarte ninguno y sin repetir lo que el cliente ya respondió):";
			$lines[] = "1) SALUDO CORTO: el primer mensaje es UNA sola línea de máximo 2 oraciones, por ejemplo: \"¡Hola! 👋 Soy (tu nombre), asesora de ".$brand.". ¿Te interesa contratar internet o algún servicio en particular?\". En el saludo NO describas la empresa, beneficios, planes, precios ni requisitos, y no uses listas.";
			$lines[] = "2) INTERÉS: si el cliente ya dijo qué quiere (por ejemplo \"quiero internet\" o pidió los planes), salúdalo en media línea y pasa DIRECTO al paso 3 sin mostrar planes todavía.";
			$lines[] = "3) UBICACIÓN: pide que comparta su ubicación en tiempo real o actual de WhatsApp (📎 > Ubicación) desde el lugar de instalación, o un enlace de Google Maps o la dirección exacta. Con una dirección o enlace agrega [[COBERTURA|dirección o enlace]]; con la ubicación de WhatsApp el sistema la verifica solo. No muestres la lista de planes antes de verificar la cobertura.";
			$lines[] = "4) PLANES DE LA ZONA: si hay cobertura, muestra SOLO los planes y servicios disponibles en esa zona (vienen en el resultado de la cobertura; también valen los que el equipo le confirmó a este cliente) y ayúdale a elegir según cuántas personas lo usan y para qué".(!empty($service["tv_enabled"]) ? "; ofrece la TV abierta y pregunta si ya tiene TV box" : "").". Si NO hay cobertura, díselo con empatía y no pidas más datos.";
			$hasBill = BizOrders::clientPhotos($phone, false);

			$lines[] = "5) DATOS OBLIGATORIOS — UNO POR UNO: cuando elija su plan, pide los datos de UNO EN UNO, nunca todos juntos ni en lista. Haz UNA sola pregunta por mensaje, en este orden: 1° nombres y apellidos del titular, 2° DNI, 3° correo electrónico, 4° dirección de instalación (si ya la dio al verificar la cobertura, solo confírmala), 5° referencia de la dirección, 6° su número de celular para llamarle el día de la instalación (puede dar más de uno), y 7° al final, una foto de su recibo de luz o de agua, diciendo claramente que es OPCIONAL (ayuda al técnico a ubicar la vivienda; si no la tiene, puede escribir \"continuar\"). Cada dato es una pregunta distinta: el nombre es una pregunta, el DNI otra, el correo otra, la dirección otra, la referencia otra y el celular otra. Primero pregunta solo: \"¿Cuáles son los nombres y apellidos del titular del servicio?\". Cuando el cliente responda, agradece en pocas palabras y pide SOLO el siguiente dato que falte. Si el cliente ya dio varios datos por su cuenta, no se los vuelvas a pedir: pasa al siguiente que falte. Si un dato es inválido, pide solo ese dato otra vez.";
			$lines[] = "FOTO DEL RECIBO (luz o agua): ".($hasBill ? "el cliente YA la envió ✅ (se adjunta sola a la orden); no la vuelvas a pedir." : "el cliente AÚN NO la envía. Si en el paso 5 no la mandó, recuérdasela UNA sola vez (en un mensaje aparte, sin mezclarla con otros datos) de forma amable antes del resumen del paso 6 (ej.: \"¿Tienes a la mano una foto de tu recibo de luz o agua? Nos ayuda a ubicar tu vivienda 🏠\"). Si dice que no la tiene o no responde a eso, continúa sin ella: nunca detengas la orden por la foto.");
			$lines[] = "6) FECHA DE INSTALACIÓN (ANTES de generar la orden): con los datos completos, muestra un resumen corto (plan, precio y datos) y ofrece SOLO los turnos libres de la agenda (horarios permitidos) para que el cliente ELIJA día y hora. Primero se agenda y después se genera la orden: NUNCA agregues [[PEDIDO]] sin la [[CITA]] de la fecha elegida (el sistema no registra una orden de instalación sin fecha).";
			$lines[] = "7-A) GENERAR LA ORDEN AL ELEGIR LA FECHA: apenas el cliente elija un día y una hora disponibles, en ESA MISMA respuesta agrega [[CITA|...]] y [[PEDIDO|...]] con todos sus datos. NO le vuelvas a pedir confirmación ni repitas el resumen: elegir la fecha ES su confirmación. El sistema le muestra la orden con su número.";
			$lines[] = "7) REGISTRO: cuando el cliente ELIJA la fecha y hora de instalación (eso es su confirmación), agrega en la MISMA respuesta las DOS etiquetas con los datos reales: [[PEDIDO|nombre=Nombres y apellidos|dni=12345678|correo=correo@ejemplo.com|direccion=Dirección de instalación|referencia=Referencia|telefonos=987654321, 912345678|plan=1 x Nombre exacto del plan".(!empty($service["tv_enabled"]) ? "; 1 x TV abierta; 1 x TV box (si lo compra) o 1 x TV box propio (si ya tiene)" : "")."|pago=Forma de pago|notas=Observaciones]] y [[CITA|AAAA-MM-DD|HH:MM|Nombres y apellidos|Instalación|Plan contratado]]. Si falta un dato obligatorio el sistema NO registra la orden: nunca digas que está registrada sin tener todos los datos.";
			$lines[] = "PLAN PERSONALIZADO (confirmado por el equipo, fuera del catálogo): en plan= escribe el nombre claro con el precio que dio el equipo, así: plan=1 x Plan 3000 Mbps @ 1200 (si el equipo dio un solo precio que ya incluye la TV, escribe 1 x Plan 1200 Mbps + TV @ 160 en una sola línea, sin agregar 1 x TV abierta aparte).";
			$lines[] = "REGISTRAR ES INMEDIATO: las etiquetas se ejecutan al enviar tu respuesta. Nunca escribas \"voy a registrar tu orden\", \"un momento\" ni \"procederé a agendar\": cuando el cliente elige el turno y ya tienes todos los datos, agrega [[PEDIDO|...]] y [[CITA|...]] en ESA MISMA respuesta. El sistema le confirma al cliente el número de orden; si no ves ese número en la conversación, la orden NO está registrada y no debes decir que lo está. Nunca digas que el número de orden o el detalle se enviarán por correo: todo se muestra aquí mismo en el chat.";
			$lines[] = "Fotos y archivos: si el cliente envía una imagen o un PDF durante la contratación, considéralo la foto de su recibo de luz o agua (se adjunta sola a la orden); agradécela en una línea y continúa con el paso que sigue, sin repetir lo ya pedido.";
			$lines[] = "Audios: no puedes escuchar notas de voz. Si el cliente envía un audio, pídele con amabilidad que te lo escriba, y continúa desde el paso en que iban.";
			$lines[] = "CONTINUIDAD: nunca reinicies la conversación ni vuelvas a saludar si ya hay historial; si el cliente escribió varios mensajes seguidos, respóndelos todos juntos en una sola respuesta y sigue en el paso pendiente del flujo.";

			/* Pago presencial */

			if(!empty($service["pay_on_install"])){

				$lines[] = "FORMA DE PAGO: el pago se realiza de manera PRESENCIAL el día de la instalación (al técnico). Menciónalo al mostrar los planes o al resumir la orden y no pidas pagos por adelantado. En la etiqueta usa pago=Presencial en la instalación. SOLO si el cliente pregunta por otros medios de pago o quiere pagar antes, muéstrale los MÉTODOS DE PAGO disponibles (con su QR usando [[PAGO|número_del_método]]) y en la etiqueta usa pago=el método que elija.";
			}

		}else if(!empty($catalogue) || !empty($delivery["enabled"])){

			$lines[] = "";
			$lines[] = "PEDIDOS: cuando el cliente CONFIRME su pedido (productos, entrega y forma de pago claros), agrega al final UNA sola vez la etiqueta [[PEDIDO|Nombre del cliente|Dirección de entrega (o RECOJO)|cantidad x producto; cantidad x producto|Forma de pago|Notas]]. Usa los nombres exactos del catálogo. El sistema calcula el total con los precios oficiales y el envío, registra el pedido y le confirma el número de pedido al cliente.";
		}

		/* Cancelación de órdenes/pedidos por el cliente (cualquier tipo de negocio) */

		$active = array_values(array_filter(BizOrders::forPhone($phone, 10), function($o){ return $o["status_order"] === "Pendiente"; }));
		$word = $installation ? "orden" : "pedido";

		if(!empty($active)){

			$hours = max(0, (int)($service["cancel_hours"] ?? 4));

			$lines[] = "";
			$lines[] = "CANCELAR ".mb_strtoupper($word)."S: tú SÍ puedes cancelar las ".$word."s activas de este cliente.".($installation ? " Puede cancelar ".($hours > 0 ? "hasta ".$hours." horas antes de la instalación" : "hasta la hora de la instalación")."." : "")." Si pide cancelar: confirma cuál (si tiene varias) y pregúntale con amabilidad el motivo para mejorar. Cuando confirme, agrega en esa misma respuesta [[CANCELAR_ORDEN|número|motivo que dio el cliente (vacío si no quiso decirlo)]]: el sistema la cancela, avisa al equipo y lo registra. Nunca digas que está cancelada sin la etiqueta. Si ya pasó el plazo, explícale que por este medio ya no se puede y que avisaste al equipo para que lo contacte (igual agrega la etiqueta).".($installation ? " Si solo quiere cambiar la fecha, no canceles: agenda otra fecha." : "");
			$lines[] = "Si el cliente pregunta por su ".$word.", su número o su detalle, muéstraselo aquí mismo con los datos de esta lista (número, plan o productos, total y fecha), sin decir que se envía por correo.";
			$lines[] = ucfirst($word)."s activ".($installation ? "a" : "o")."s de este cliente:";

			foreach ($active as $mine) {

				$limit = BizOrders::cancelDeadline($mine);
				$lines[] = "- #".$mine["id_order"]." (".str_replace("\n", "; ", BizOrders::itemsText(BizOrders::items($mine))).", total ".cms_money($mine["total_order"]).")".($limit ? ": instalación ".BizStore::dayName(date("Y-m-d", $limit["install"]))." ".date("H:i", $limit["install"])."; ".($limit["allowed"] ? "SÍ puede cancelarla (hasta ".date("Y-m-d H:i", $limit["until"]).")" : "YA NO puede cancelarla por este medio (el plazo venció ".date("Y-m-d H:i", $limit["until"]).")") : ": SÍ puede cancelarla").".";
			}

			/* Orden registrada sin fecha de instalación: lo siguiente es agendarla */

			$noDate = $installation ? BizOrders::openOrderWithoutAppointment($phone) : null;

			if($noDate){

				$lines[] = "";
				$lines[] = "PASO PENDIENTE — FECHA DE INSTALACIÓN: la orden #".$noDate." YA está registrada pero NO tiene fecha de instalación. Lo siguiente es que el cliente elija día y hora: ofrécele los turnos libres de la agenda y, apenas elija uno, agrega en esa misma respuesta [[CITA|AAAA-MM-DD|HH:MM|Nombres y apellidos|Instalación|Plan contratado]] (el sistema la vincula a la orden #".$noDate." y avisa al equipo). NO vuelvas a pedir sus datos ni agregues otro [[PEDIDO]]: la orden ya existe. Nunca digas que la instalación está agendada sin la etiqueta.";
			}
		}

		/* Retroalimentación e inconvenientes */

		$lines[] = BizIncidents::context($phone);

		/* Agenda */

		if(!empty($agenda["enabled"])){

			$names = array(1 => "lunes", "martes", "miércoles", "jueves", "viernes", "sábado", "domingo");
			$days = array();

			foreach ((array)$agenda["days"] as $d) {

				$days[] = $names[(int)$d] ?? "";
			}

			$lines[] = "";
			$lines[] = "AGENDA DE CITAS Y RESERVAS:";
			$lines[] = "Atención: ".implode(", ", array_filter($days))." de ".$agenda["open"]." a ".$agenda["close"].(!empty($agenda["break_start"]) && !empty($agenda["break_end"]) ? " (descanso de ".$agenda["break_start"]." a ".$agenda["break_end"].")" : "").". Cada turno dura ".(int)$agenda["slot_minutes"]." minutos.";

			if(trim((string)$agenda["services"]) !== ""){

				$lines[] = "Servicios que se pueden agendar: ".trim($agenda["services"]);
			}

			/* Horarios fijos: el cliente solo puede elegir esas horas */

			$fixed = BizStore::fixedTimes();

			if(!empty($fixed)){

				$lines[] = "HORARIOS: solo se agenda en los horarios de la lista de TURNOS LIBRES de abajo (los que no aparecen ya están ocupados o no existen). Ofrece ÚNICAMENTE horarios de esa lista, nunca uno que no figure ahí ni uno que hayas mencionado antes en la conversación. Si el cliente pide otro, ofrécele el libre más cercano de la lista.";
			}

			$free = BizStore::nextFree(!empty($fixed) ? count($fixed) * 4 : 12);

			if(!empty($free)){

				$byDay = array();

				foreach ($free as $f) {

					$byDay[$f["date"]][] = $f["time"];
				}

				$lines[] = "Próximos turnos LIBRES (fecha AAAA-MM-DD: horas 24h):";

				foreach ($byDay as $date => $times) {

					$lines[] = "- ".$date." (".BizStore::dayName($date)."): ".implode(", ", $times);
				}

				/* Formato fijo para mostrarlos al cliente */

				$lines[] = "Al ofrecer horarios al cliente, muéstralos EXACTAMENTE con este formato (un día por línea, horas sin a. m./p. m.):";

				foreach ($byDay as $date => $times) {

					$lines[] = "• ".BizStore::slotLine($date, $times);
				}

				$lines[] = "Si el cliente pide otro día u hora, igual puedes intentar agendarla: el sistema verificará si está libre.";

			}else{

				$lines[] = "No hay turnos libres en los próximos días. Ofrece dejar el contacto para llamarlo.";
			}

			$mine = BizStore::upcomingFor($phone);

			if(!empty($mine)){

				$lines[] = "Este cliente ya tiene estas citas/reservas: ";

				foreach ($mine as $a) {

					$lines[] = "- ".$a["date_appointment"]." ".substr($a["time_appointment"], 0, 5)." ".$a["service_appointment"]." (".$a["status_appointment"].")";
				}
			}

			$lines[] = "Para agendar una cita o reservar (mesa, turno, servicio): pide nombre, servicio, día, hora y número de personas si aplica (uno a uno, sin abrumar).";
			$lines[] = "REGLA OBLIGATORIA: en la misma respuesta en que confirmes la reserva agrega la etiqueta [[CITA|AAAA-MM-DD|HH:MM|Nombre|Servicio|Resumen breve|Personas]] (fecha con año y hora en 24 h, ej. [[CITA|".date("Y-m-d", strtotime("+1 day"))."|20:00|Ana Pérez|Reserva de mesa|Cumpleaños, mesa cerca a la ventana|4]]). Sin esa etiqueta la reserva NO se guarda: nunca digas \"reservado\", \"agendado\" o \"confirmado\" sin agregarla. Nunca agendes dos veces la misma cita.";
			$lines[] = "Si el cliente quiere cancelar una de sus citas, agrega [[CANCELAR|AAAA-MM-DD|HH:MM]]. Para cambiar una cita: cancela la anterior y agenda la nueva en la misma respuesta.";
		}

		/* Respuestas aprendidas y consultas al equipo */

		$lines[] = BizInquiries::context($phone);

		$lines[] = "";
		$lines[] = AIService::CREDITS;

		/* Al final, donde la IA le da más peso: nunca negar lo que no está en los datos */

		$lines[] = "";
		$lines[] = "REGLA MÁS IMPORTANTE — SI NO LO SABES, SE CONSULTA: todo lo que no esté escrito arriba NO lo sabes. Nunca respondas \"no contamos con\", \"no tenemos\", \"no ofrecemos\", \"lamentablemente no\" ni \"no tengo esa información\" sobre algo que no aparece explícitamente en la información del negocio. Ejemplo: si preguntan \"¿tienes un plan personalizado?\" y eso no figura arriba, responde \"¡Buena pregunta! Déjame consultarlo con el equipo y te escribo apenas tenga la respuesta 🙌\", agrega [[CONSULTA|¿Tienen planes personalizados? (el cliente lo pidió)]] y ofrece mientras tanto los planes que sí existen. EXCEPCIÓN: si el equipo YA respondió exactamente eso CON precio (ver RESPUESTAS DEL EQUIPO), usa esa respuesta y continúa la venta sin volver a consultar; si respondió algo general o sin precio, pide los detalles y consulta de nuevo.";

		$lines[] = "";
		$lines[] = "Las etiquetas [[...]] no se muestran al cliente: no las expliques ni las menciones.";

		return implode("\n", $lines);

	}

	/*=============================================
	Separar etiquetas del texto
	=============================================*/

	static public function parse($text){

		$actions = array();

		$clean = preg_replace_callback('/\[\[\s*('.self::TAGS.')\s*(?:\|([^\]]*))?\]\]/iu', function($m) use (&$actions){

			$actions[] = array("type" => strtoupper($m[1]), "args" => array_map("trim", explode("|", (string)($m[2] ?? ""))));

			return "";

		}, (string)$text);

		$clean = preg_replace("/[ \t]+\n/", "\n", $clean);
		$clean = preg_replace("/\n{3,}/", "\n\n", $clean);

		return array(trim($clean), $actions);

	}

	static public function has(array $actions, $type){

		foreach ($actions as $a) {

			if($a["type"] === $type){

				return $a;
			}
		}

		return null;

	}

	/*=============================================
	Procesar la respuesta de la IA: agenda, pedidos,
	imágenes y pagos. Devuelve el texto final para el
	cliente y la lista de archivos a enviar después.
	=============================================*/

	static public function process($text, $phone, $profileName = "", $clientText = "", $conversation = ""){

		list($clean, $actions) = self::parse($text);

		$media = array();
		$booked = array();
		$orders = array();
		$newOrders = array();
		$notes = array();

		/* Orden incompleta: tampoco se agenda la instalación (quedaría sin orden) */

		$pedido = self::has($actions, "PEDIDO");

		if($pedido && BizGeo::installation() && BizOrders::missingFields(BizOrders::orderFields($pedido["args"]))){

			$actions = array_values(array_filter($actions, function($x){ return $x["type"] !== "CITA"; }));
		}

		/* Primero se agenda y después se genera la orden: la CITA se procesa antes que el PEDIDO */

		$rank = function($x){ return $x["type"] === "CITA" ? 0 : ($x["type"] === "PEDIDO" ? 1 : 2); };
		$sorted = array();

		foreach ($actions as $i => $x) {

			$sorted[] = array($rank($x), $i, $x);
		}

		usort($sorted, function($p, $q){ return $p[0] === $q[0] ? $p[1] - $q[1] : $p[0] - $q[0]; });
		$actions = array_map(function($p){ return $p[2]; }, $sorted);

		foreach ($actions as $action) {

			$a = $action["args"];

			switch ($action["type"]) {

				/* El cliente cancela su orden (solo hasta N horas antes de la instalación) */

				case "CANCELAR_ORDEN":

					$id = (int)preg_replace('/\D/', '', (string)($a[0] ?? ""));
					$reason = trim((string)($a[1] ?? ""));
					$mine = null;

					foreach (BizOrders::forPhone($phone, 10) as $o) {

						if($o["status_order"] === "Pendiente" && (!$id || (int)$o["id_order"] === $id)){

							$mine = $o;
							break;
						}
					}

					if(!$mine){

						break;
					}

					$limit = BizOrders::cancelDeadline($mine);

					$word = $mine["delivery_order"] === "instalacion" ? "Orden" : "Pedido";

					if(!$limit || $limit["allowed"]){

						BizOrders::cancel($mine["id_order"], "Cancelad".($word === "Orden" ? "a" : "o")." por el cliente por WhatsApp".($reason !== "" ? ": ".$reason : ""), false, "cliente", $reason);
						$clean .= "\n\n❌ *".$word." #".$mine["id_order"]." cancelad".($word === "Orden" ? "a" : "o").".*";

						/* Sin motivo: pedir su comentario (si la IA no lo hizo ya) */

						if($reason === "" && !preg_match('/motivo|qu[eé] pas[oó]|cu[eé]ntanos|nos cuentas|comentario|mejorar/iu', $clean)){

							$clean .= "\n\n🙏 ¿Nos cuentas el motivo o qué podemos mejorar? Tu comentario nos ayuda mucho.";
						}

					}else{

						BizIncidents::record(array("phone" => $phone, "name" => $profileName, "order" => $mine["id_order"], "kind" => "cancelacion", "detail" => "El cliente pidió cancelar la ".mb_strtolower($word)." #".$mine["id_order"]." fuera de plazo (sigue activa).".($reason !== "" ? " Motivo: ".$reason : ""), "feedback" => $reason, "source" => "cliente"));

						/* Fuera de plazo: no se cancela, pero el equipo se entera */

						require_once __DIR__."/business.ops.php";

						Notify::send("⚠️ *El cliente pidió cancelar la orden #".$mine["id_order"]." fuera de plazo*\n\n👤 ".($mine["name_order"] ?: "Sin nombre")."\n📱 +".$mine["contact_order"]."\n🗓️ Instalación: ".date("d/m/Y h:i a", $limit["install"])."\n📝 Motivo: ".($reason !== "" ? $reason : "no indicó")."\n\nLa orden sigue activa: comunícate con el cliente.", (string)$mine["branch_order"], "pedido", "cancel");

						if(!preg_match('/no (se )?(puede|es posible)|ya no|venci|plazo/iu', $clean)){

							$clean .= "\n\n⚠️ La orden #".$mine["id_order"]." ya no se puede cancelar por este medio (faltan menos de ".max(0, (int)(BizStore::get("service")["cancel_hours"] ?? 4))." h para la instalación). Avisamos al equipo para que se comunique contigo.";
						}

						$notes[] = "cancel_late:".$mine["id_order"];
					}

					break;

				case "CANCELAR":

					$date = BizStore::normalizeDate($a[0] ?? "");
					$time = BizStore::normalizeTime($a[1] ?? "");

					foreach (BizStore::upcomingFor($phone) as $appt) {

						if($appt["date_appointment"] === $date && substr($appt["time_appointment"], 0, 5) === $time){

							/* Instalación: el mismo plazo que para cancelar la orden */

							if(BizGeo::installation()){

								$hours = max(0, (int)(BizStore::get("service")["cancel_hours"] ?? 4));
								$start = strtotime($appt["date_appointment"]." ".($time === "00:00" ? "08:00" : $time).":00");

								if(time() > $start - $hours * 3600){

									$clean .= "\n\n⚠️ La instalación ya no se puede cancelar ni cambiar por este medio (faltan menos de ".$hours." h). Avisamos al equipo para que se comunique contigo.";

									require_once __DIR__."/business.ops.php";
									Notify::send("⚠️ *El cliente pidió cancelar o cambiar su instalación fuera de plazo*\n\n👤 ".($appt["name_appointment"] ?: "Sin nombre")."\n📱 +".$appt["phone_appointment"]."\n🗓️ ".date("d/m/Y h:i a", $start)."\n\nComunícate con el cliente.", (string)($appt["branch_appointment"] ?? ""), "agenda", "cancel");
									continue;
								}
							}

							BizStore::setStatus($appt["id_appointment"], "cancelada");
							self::notifyOwner($appt, "cancelada");

							BizIncidents::record(array("phone" => $phone, "name" => $appt["name_appointment"] ?: $profileName, "kind" => "cancelacion", "detail" => "El cliente canceló su cita del ".$appt["date_appointment"]." ".$time.($appt["service_appointment"] ? " (".$appt["service_appointment"].")" : "")." por WhatsApp.", "source" => "cliente"));
						}
					}

					break;

				case "CITA":

					$extra = array_slice($a, 4);
					$people = null;

					if(count($extra) > 1 && preg_match('/^\d{1,3}$/', (string)end($extra))){

						$people = (int)array_pop($extra);
					}

					$data = array(
						"date" => $a[0] ?? "",
						"time" => $a[1] ?? "",
						"name" => ($a[2] ?? "") !== "" ? $a[2] : $profileName,
						"service" => $a[3] ?? "",
						"summary" => implode(" | ", array_filter($extra, "strlen")),
						"people" => $people,
						"phone" => $phone,
						"source" => "ia"
					);

					$result = BizStore::book($data);

					if($result["ok"]){

						if(empty($result["duplicate"])){

							$booked[] = $result["id"];
						}

						self::setBranchFromCoverage($result["id"], $phone);

					}else if($result["error"] === "Fecha u hora inválida."){

						/* No se entendió la fecha: queda como solicitud por confirmar */

						$id = BizStore::request($data + array("source" => "ia"));
						$booked[] = $id;
						$clean .= "\n\n📝 Registramos tu solicitud de reserva. En breve te confirmamos el día y la hora exactos.";

					}else{

						/* Turno no disponible: reemplazar la confirmación de la IA */

						$clean = "😕 Lo siento, ese horario no está disponible (".$result["error"].")";

						if(!empty($result["suggest"])){

							$clean .= "\n\nEstos turnos están libres:";

							foreach (array_slice($result["suggest"], 0, 6) as $s) {

								$clean .= "\n• ".ucfirst(BizStore::dayName($s["date"]))." a las ".BizStore::time12($s["time"]);
							}

							$clean .= "\n\n¿Cuál prefieres?";
						}

						$notes[] = "booking_failed";
					}

					break;

				case "PEDIDO":

					$f = BizOrders::orderFields($a);
					$address = $f["address"];
					$pickup = !BizGeo::installation() && preg_match('/^(recojo|recoger|retiro|tienda|local)/iu', $address);
					$items = BizOrders::parseItems($f["items"]);

					if(empty($items)){

						break;
					}

					/* Plan fuera del catálogo: solo con el precio que dio el equipo;
					   si nadie lo confirmó, queda "por confirmar" (no se inventa) */

					$custom = array();

					foreach ($items as &$item) {

						if(empty($item["matched"])){

							if($item["price"] !== null && !BizInquiries::priceConfirmed($item["price"], $phone)){

								$item["price"] = null;
								$item["subtotal"] = null;
							}

							$custom[] = $item["name"].($item["price"] !== null ? " (precio confirmado por el equipo)" : " (precio por confirmar)");
						}
					}

					unset($item);

					if(!empty($custom)){

						$f["notes"] = trim($f["notes"]." · Plan/producto personalizado: ".implode("; ", $custom), " ·");
					}

					/* Internet: sin los datos obligatorios no se registra la orden */

					if(BizGeo::installation() && !empty($missing = BizOrders::missingFields($f))){

						$notes[] = "order_missing:".implode(", ", $missing);
						break;
					}

					/* Internet con Agenda: la orden se genera SOLO con la fecha de instalación ya
					   agendada (en esta misma respuesta o en un mensaje anterior) */

					if(BizGeo::installation() && !empty(BizStore::get("agenda")["enabled"]) && empty($booked) && !BizOrders::pendingAppointmentFor($phone) && !BizOrders::recentScheduledOrder($phone)){

						$notes[] = "order_needs_date";
						break;
					}

					$order = BizOrders::create(array(
						"name" => $f["name"] !== "" ? $f["name"] : $profileName,
						"address" => $pickup ? "" : $address,
						"reference" => $f["reference"],
						"email" => $f["email"],
						"contact_phone" => $f["phones"],
						"location" => in_array($f["location"], BizGeo::mapsLinks($f["location"]), true) ? $f["location"] : "",
						"delivery" => $pickup ? "recojo" : "delivery",
						"items" => $items,
						"payment" => $f["payment"],
						"notes" => $f["notes"],
						"document" => $f["document"],
						"phone" => $phone,
						"conversation" => $conversation,
						"source" => "ia"
					));

					if($order["ok"]){

						$orders[] = $order["id"];

						/* La misma orden repetida (la IA volvió a poner la etiqueta): igual se le
						   muestra la ficha al cliente y se avisa al equipo si aún no se avisó */

						if(!empty($order["duplicate"])){

							$newOrders[] = $order["id"];
						}

						if(empty($order["duplicate"])){

							$newOrders[] = $order["id"];

							$line = "\n\n🧾 *".(BizGeo::installation() ? "Orden" : "Pedido")." #".$order["id"]." registrad".(BizGeo::installation() ? "a" : "o")."*";
							$line .= (float)$order["fee"] > 0 ? "\n".BizOrders::feeLabel().": ".cms_money($order["fee"]) : "";
							$split = BizOrders::breakdown(BizOrders::find($order["id"]));

							$line .= $split
								? "\nMensualidad: *".cms_money($split["monthly"])."*\nPago inicial: *".cms_money($split["upfront"])."*"
								: "\nTotal: *".cms_money($order["total"])."*";
							$line .= $order["unknown"] ? " (algunos productos se confirman aparte)" : "";
							$clean .= $line;
						}
					}

					break;

				case "CONSULTA":

					$id = BizInquiries::create($phone, $profileName, implode(" | ", $a));

					if($id){

						$notes[] = "inquiry:".$id;
					}

					/* La IA solo dejó la etiqueta: el cliente nunca se queda sin respuesta */

					if(trim($clean) === ""){

						$clean = "Déjame consultarlo con el equipo y te escribo apenas tenga la respuesta 🙌 Mientras tanto, ¿te ayudo con algo más?";
					}

					break;

				/* Comentario o problema que contó el cliente */

				case "INCONVENIENTE":

					$kind = $a[0] ?? "otro";
					$summary = trim(implode(" | ", array_slice($a, 1)));
					$lastOrder = BizOrders::forPhone($phone, 1);

					$id = BizIncidents::feedback($phone, $profileName, $kind, $summary !== "" ? $summary : $kind, $lastOrder ? $lastOrder[0]["id_order"] : null);

					if($id){

						$notes[] = "incident:".$id;
					}

					break;

				case "IMAGEN":

					foreach (BizStore::get("images") as $img) {

						if(!empty($img["url"]) && mb_strtolower(trim($img["keyword"] ?? "")) === mb_strtolower($a[0] ?? "") && count($media) < 3){

							$media[] = array("type" => "image", "link" => self::absolute($img["url"]), "caption" => (string)($img["description"] ?? ""));
						}
					}

					break;

				case "PAGO":

					$payment = self::paymentMedia((int)($a[0] ?? 0) - 1, self::amount($a[1] ?? ""), $a[0] ?? "");

					if($payment){

						$media[] = $payment;
					}

					break;
			}
		}

		/* La IA no puso [[PAGO]] pero el cliente pidió el QR o los datos de pago */

		if(!self::has($actions, "PAGO")){

			$auto = self::paymentRequested($clientText);

			if($auto !== null){

				$payment = self::paymentMedia($auto, null);

				if($payment){

					$media[] = $payment;
				}

			}else if(empty($media) && ($img = self::qrImageRequested($clientText))){

				/* El QR se subió en "Imágenes" en lugar de "Pagos y QR" */

				$media[] = array("type" => "image", "link" => self::absolute($img["url"]), "caption" => (string)($img["description"] ?? ""));
			}
		}

		/* Orden + cita de instalación: quedan vinculadas y se avisa una sola vez */

		$linked = array();

		foreach ($orders as $orderId) {

			$apptId = !empty($booked) ? $booked[0] : BizOrders::pendingAppointmentFor($phone);

			if($apptId && BizOrders::linkAppointment($orderId, $apptId)){

				$linked[] = $apptId;
			}
		}

		/* La cita llegó en un mensaje posterior a la orden: vincularla con la
		   orden activa del cliente y avisar la fecha de instalación */

		$scheduled = array();

		if(empty($orders)){

			foreach ($booked as $apptId) {

				$orderId = BizOrders::openOrderWithoutAppointment($phone);

				if($orderId && BizOrders::linkAppointment($orderId, $apptId)){

					$linked[] = $apptId;
					$scheduled[] = $orderId;
				}
			}
		}

		return array("text" => $clean, "media" => $media, "booked" => $booked, "orders" => $orders, "new_orders" => $newOrders, "linked" => $linked, "scheduled" => $scheduled, "notes" => $notes, "actions" => $actions);

	}

	/* La cita toma la sucursal de la última verificación de cobertura */

	static public function setBranchFromCoverage($appointmentId, $phone){

		$last = BizGeo::lastFor($phone);

		if($last && $last["covered_check"] === "si" && !empty($last["branch_check"])){

			BizStore::pdo()->prepare("UPDATE appointments SET branch_appointment = ? WHERE id_appointment = ?")->execute(array($last["branch_check"], (int)$appointmentId));
		}

	}

	/*=============================================
	Pagos: imagen del QR con los datos
	$index: posición del método (0, 1, ...) o su nombre
	=============================================*/

	static public function paymentMedia($index, $amount = null, $name = ""){

		$payments = array_values(BizStore::get("payments"));

		/* La IA pudo escribir el nombre en lugar del número: [[PAGO|Yape]] */

		if(!isset($payments[$index]) && trim((string)$name) !== "" && !is_numeric($name)){

			foreach ($payments as $i => $p) {

				if(mb_stripos((string)$p["name"], trim($name)) !== false){

					$index = $i;
				}
			}
		}

		if(!isset($payments[$index])){

			return null;
		}

		$p = $payments[$index];
		$caption = "💳 *".trim($p["name"] ?? "Pago")."*";
		$caption .= $amount !== null ? "\nMonto a pagar: *".cms_money($amount)."*" : "";
		$caption .= !empty($p["account"]) ? "\nNúmero / cuenta: ".$p["account"] : "";
		$caption .= !empty($p["holder"]) ? "\nTitular: ".$p["holder"] : "";
		$caption .= !empty($p["notes"]) ? "\n".$p["notes"] : "";
		$caption .= "\n\nCuando realices el pago, envíanos la foto del comprobante 📸";

		if(!empty($p["qr"])){

			return array("type" => "image", "link" => self::absolute($p["qr"]), "caption" => $caption);
		}

		return array("type" => "text", "text" => $caption);

	}

	/* ¿El cliente pidió el QR / los datos de un método de pago? Devuelve su índice */

	static public function paymentRequested($clientText){

		$text = BizGeo::normalize($clientText);

		if($text === ""){

			return null;
		}

		$payments = array_values(BizStore::get("payments"));
		$asksQr = (bool)preg_match('/\b(qr|codigo|código)\b/u', $text);
		$asksPay = (bool)preg_match('/\b(qr|pagar|pago|pagos|yapear|plinear|transferir|deposit|numero|cuenta|datos)\b/u', $text);

		if(!$asksPay){

			return null;
		}

		foreach ($payments as $i => $p) {

			$name = BizGeo::normalize($p["name"] ?? "");

			if($name !== "" && preg_match('/\b'.preg_quote($name, "/").'\b/u', $text)){

				return $i;
			}
		}

		/* Pidió "el QR" y solo hay un método con QR */

		if($asksQr){

			$withQr = array_keys(array_filter($payments, function($p){ return !empty($p["qr"]); }));

			if(count($withQr) === 1){

				return $withQr[0];
			}
		}

		return null;

	}

	/* El cliente pide un QR que está en la sección Imágenes (clave "yape", "qr-plin"…) */

	static public function qrImageRequested($clientText){

		$text = BizGeo::normalize($clientText);

		if(!preg_match('/\b(qr|yape|yapear|plin|plinear)\b/u', $text)){

			return null;
		}

		$candidates = array();

		foreach (BizStore::get("images") as $img) {

			$hay = BizGeo::normalize(($img["keyword"] ?? "")." ".($img["description"] ?? ""));

			if(empty($img["url"]) || !preg_match('/\b(qr|yape|plin|pago|pagos)\b/u', $hay)){

				continue;
			}

			/* Coincide el nombre del método que pidió (yape, plin…) */

			foreach (preg_split('/[^a-z0-9]+/', $text) as $word) {

				if(strlen($word) >= 4 && $word !== "pasame" && strpos($hay, $word) !== false){

					return $img;
				}
			}

			$candidates[] = $img;
		}

		return count($candidates) === 1 && preg_match('/\bqr\b/', $text) ? $candidates[0] : null;

	}

	/*=============================================
	Respaldo: la IA dijo "reservado" sin la etiqueta.
	Se le pide a la IA que extraiga los datos de la
	conversación y se guarda la reserva.
	=============================================*/

	static public function looksLikeBooking($text){

		return (bool)preg_match('/(reserva(do|da)?\b.*(confirmad|registrad|list[oa]|hech[oa])|(confirmad|registrad|agendad)[oa]s?\b.*(reserva|cita|mesa|turno)|tu (reserva|cita|mesa) (está|esta|queda|quedó|quedo)|(mesa|cita|turno) (reservad|agendad|confirmad))/iu', (string)$text);

	}

	/*=============================================
	Respaldo de órdenes: la IA dijo "tu orden quedó
	registrada" o "agendé tu instalación" sin la
	etiqueta [[PEDIDO]]. Se extraen los datos de la
	conversación y se registra de verdad.
	=============================================*/

	static public function looksLikeOrder($text){

		return (bool)preg_match('/(orden|pedido|contrataci[oó]n|instalaci[oó]n)\b[^.\n]{0,40}\b(ha sido |fue |qued[oó] |est[aá] )?(registrad|confirmad|agendad|programad|creada|generad)|(registr[eé]|agend[eé]|program[eé]|he (registrado|agendado|programado))\b|voy a (proceder a )?(registrar|agendar)|estoy (registrando|agendando|procesando|generando)|proceder[eé] a (registrar|agendar)|un momento,? por favor/iu', (string)$text);

	}

	/* Afirma que la orden YA está registrada (o promete mandar el número por correo) */

	static public function claimsRegistered($text){

		return (bool)preg_match('/(orden|pedido|contrataci[oó]n)\b[^.\n]{0,40}\b(ha sido|fue|qued[oó]|est[aá]|se ha)\s*(registrad|confirmad|generad|cread|procesad)|registrad[oa]s? (exitosamente|correctamente|con [eé]xito)|n[uú]mero de (orden|pedido)[^.\n]{0,40}(correo|email|enviar|enviad)|(enviaremos|enviar[eé]|llegar[aá]) [^.\n]{0,40}(correo|email)/iu', (string)$text);

	}

	static public function extractOrder(array $history, $assistantText){

		$history[] = array("role" => "assistant", "content" => $assistantText);
		$history[] = array("role" => "user", "content" => "[SISTEMA] Extrae los datos de la orden que el cliente confirmó en esta conversación.");

		$system = "Hoy es ".date("Y-m-d")." (".BizStore::dayName(date("Y-m-d"))."). Devuelve SOLO un JSON válido, sin texto adicional, con este formato: "
			."{\"found\":true,\"nombre\":\"\",\"dni\":\"\",\"correo\":\"\",\"direccion\":\"\",\"referencia\":\"\",\"telefonos\":\"\",\"plan\":\"1 x Nombre del plan @ precio\",\"pago\":\"\",\"notas\":\"\",\"fecha\":\"AAAA-MM-DD\",\"hora\":\"HH:MM\"}. "
			."Usa SOLO datos que escribió el cliente o que se confirmaron en la conversación; si un dato no está, déjalo vacío (no lo inventes). "
			."Si en la dirección viene una referencia (ej. \"a una cuadra de…\"), sepárala en referencia. "
			."En plan usa el nombre exacto del plan elegido; si es un plan personalizado que el equipo confirmó, agrega \" @ precio\" con el precio que dio el equipo. "
			."fecha y hora son las de la instalación elegida (convierte \"lunes a las 8\" a la fecha exacta y hora 24 h); vacías si no eligió. "
			."Si el cliente no confirmó ninguna orden devuelve {\"found\":false}.";

		$answer = AIService::reply($history, $system);

		if(!$answer["ok"] || !preg_match('/\{.*\}/s', $answer["text"], $m)){

			return null;
		}

		$json = json_decode($m[0], true);

		return (is_array($json) && !empty($json["found"])) ? $json : null;

	}

	/* JSON extraído → etiquetas [[PEDIDO]] y [[CITA]] */

	static public function orderTags(array $o){

		$v = function($k) use ($o){ return trim(str_replace(array("|", "[", "]"), " ", (string)($o[$k] ?? ""))); };

		$tags = "[[PEDIDO|nombre=".$v("nombre")."|dni=".$v("dni")."|correo=".$v("correo")."|direccion=".$v("direccion")."|referencia=".$v("referencia")."|telefonos=".$v("telefonos")."|plan=".$v("plan")."|pago=".$v("pago")."|notas=".$v("notas")."]]";

		if($v("fecha") !== "" && $v("hora") !== ""){

			$tags .= "\n[[CITA|".$v("fecha")."|".$v("hora")."|".$v("nombre")."|Instalación|".$v("plan")."]]";
		}

		return $tags;

	}

	static public function extractBooking(array $history, $assistantText){

		$history[] = array("role" => "assistant", "content" => $assistantText);
		$history[] = array("role" => "user", "content" => "[SISTEMA] Extrae los datos de la reserva o cita que se acaba de confirmar en esta conversación.");

		$system = "Hoy es ".date("Y-m-d")." (".BizStore::dayName(date("Y-m-d"))."). Devuelve SOLO un JSON válido, sin texto adicional, con este formato: "
			."{\"found\":true,\"date\":\"AAAA-MM-DD\",\"time\":\"HH:MM\",\"name\":\"\",\"service\":\"\",\"people\":0,\"summary\":\"\"}. "
			."Convierte expresiones como \"mañana\" o \"el sábado\" a la fecha exacta y la hora a formato 24 h. "
			."Si no hay una reserva confirmada devuelve {\"found\":false}.";

		$answer = AIService::reply($history, $system);

		if(!$answer["ok"] || !preg_match('/\{.*\}/s', $answer["text"], $m)){

			return null;
		}

		$json = json_decode($m[0], true);

		return (is_array($json) && !empty($json["found"])) ? $json : null;

	}

	/*=============================================
	Reserva escrita por el cliente al bot (sin IA):
	"Sábado 20/11 a las 8pm, 4 personas, Ana 987654321"
	Queda como solicitud pendiente en la agenda.
	=============================================*/

	static public function reservationFromText($text, $phone, $name = ""){

		$raw = trim((string)$text);
		$t = BizGeo::normalize($raw);

		$date = "";

		if(preg_match('/(\d{4}-\d{2}-\d{2}|\d{1,2}[\/.-]\d{1,2}(?:[\/.-]\d{2,4})?)/', $t, $m)){

			$date = BizStore::normalizeDate($m[1]);
		}

		if($date === "" && preg_match('/\b(pasado manana|manana|hoy)\b/u', $t, $m)){

			$date = BizStore::normalizeDate($m[1]);
		}

		$months = array("enero" => 1, "febrero" => 2, "marzo" => 3, "abril" => 4, "mayo" => 5, "junio" => 6, "julio" => 7, "agosto" => 8, "septiembre" => 9, "setiembre" => 9, "octubre" => 10, "noviembre" => 11, "diciembre" => 12);

		if($date === "" && preg_match('/\b(\d{1,2})\s+de\s+('.implode("|", array_keys($months)).')/u', $t, $m)){

			$date = BizStore::normalizeDate($m[1]."/".$months[$m[2]]);
		}

		$weekdays = array("lunes" => "monday", "martes" => "tuesday", "miercoles" => "wednesday", "jueves" => "thursday", "viernes" => "friday", "sabado" => "saturday", "domingo" => "sunday");

		if($date === "" && preg_match('/\b('.implode("|", array_keys($weekdays)).')\b/u', $t, $m)){

			$date = date("Y-m-d", strtotime(date("N") == date("N", strtotime($weekdays[$m[1]])) ? "today" : "next ".$weekdays[$m[1]]));
		}

		$time = "";

		if(preg_match('/(\d{1,2}(?:[:.]\d{2})?\s*(?:am|pm|a\.\s?m\.|p\.\s?m\.|hrs|horas|h\b|de la (?:manana|tarde|noche))|\d{1,2}:\d{2})/u', $t, $m)){

			$time = BizStore::normalizeTime(str_replace("manana", "mañana", $m[1]));

		}else if(preg_match('/a las (\d{1,2})/u', $t, $m)){

			$h = (int)$m[1];
			$time = sprintf("%02d:00", $h < 12 && $h >= 1 && $h <= 11 ? $h + 12 : $h);
		}

		$people = null;

		if(preg_match('/(\d{1,3})\s*(?:personas|pers|comensales|adultos|invitados)/u', $t, $m) || preg_match('/para\s+(\d{1,3})\b/u', $t, $m)){

			$people = (int)$m[1];
		}

		return BizStore::request(array(
			"date" => $date,
			"time" => $time,
			"name" => $name,
			"service" => "Reserva",
			"summary" => "Mensaje del cliente: ".mb_substr($raw, 0, 500),
			"people" => $people,
			"phone" => $phone,
			"source" => "bot"
		));

	}

	/*=============================================
	Avisar al número del negocio por WhatsApp
	=============================================*/

	static public function notifyOwner($appt, $event = "nueva"){

		require_once __DIR__."/business.ops.php";

		if(empty($appt) || empty(Notify::recipients($appt["branch_appointment"] ?? "", "appts"))){

			return false;
		}

		$pending = ($appt["status_appointment"] ?? "") === "pendiente";
		$icon = $event === "cancelada" ? "❌ *Cita cancelada*" : ($pending ? "⏳ *Nueva solicitud de reserva (por confirmar)*" : "📅 *Nueva cita agendada*");

		$text = $icon."\n\n";
		$text .= "👤 ".($appt["name_appointment"] ?: "Sin nombre")."\n";
		$text .= "📱 +".$appt["phone_appointment"]."\n";
		$text .= "🗓️ ".ucfirst(BizStore::dayName($appt["date_appointment"])).(substr($appt["time_appointment"], 0, 5) !== "00:00" ? " · ".BizStore::time12(substr($appt["time_appointment"], 0, 5)) : " · hora por confirmar")."\n";
		$text .= !empty($appt["people_appointment"]) ? "👥 ".(int)$appt["people_appointment"]." personas\n" : "";
		$text .= !empty($appt["service_appointment"]) ? "🛎️ ".$appt["service_appointment"]."\n" : "";
		$text .= !empty($appt["summary_appointment"]) ? "\n📝 *Resumen:* ".$appt["summary_appointment"]."\n" : "";

		/* Ubicación que mandó el cliente (en tiempo real / actual o enlace de Maps) */

		if($event !== "cancelada"){

			try{

				$map = BizOrders::clientLocation($appt["phone_appointment"], BizGeo::lastFor($appt["phone_appointment"]));

				if($map !== ""){

					$text .= "\n🗺️ Ubicación: ".$map;
				}

			}catch(Throwable $e){}
		}

		if(!empty($appt["branch_appointment"])){

			$text = str_replace("\n📱", "\n🏢 Sucursal: ".$appt["branch_appointment"]."\n📱", $text);
		}

		$sent = Notify::send($text, (string)($appt["branch_appointment"] ?? ""), "agenda", "appts");

		return in_array(true, array_column($sent, "ok"), true);

	}

	/*=============================================
	Utilidades
	=============================================*/

	static public function absolute($url){

		$url = trim((string)$url);

		if(preg_match('#^https?://#i', $url)){

			return $url;
		}

		return rtrim(cms_base_url(), "/")."/".ltrim($url, "/");

	}

	static public function amount($value){

		$value = preg_replace('/[^\d.,]/', '', (string)$value);

		if($value === ""){

			return null;
		}

		/* "1.250,50" / "1,250.50" / "150000" */

		if(preg_match('/[.,]\d{1,2}$/', $value)){

			$decimal = substr($value, -3, 1) === "," || substr($value, -2, 1) === "," ? "," : ".";
			$value = $decimal === "," ? str_replace(array(".", ","), array("", "."), $value) : str_replace(",", "", $value);

		}else{

			$value = str_replace(array(".", ","), "", $value);
		}

		return (float)$value;

	}

}
