<?php

require_once __DIR__."/business.store.php";
require_once __DIR__."/business.geo.php";
require_once __DIR__."/chat.service.php";
require_once __DIR__."/business.ops.php";

/*=============================================
Pedidos de los clientes (tabla orders)

Los crea el asistente de IA con la etiqueta
  [[PEDIDO|Nombre|Dirección o RECOJO|2 x Producto; 1 x Producto|Pago|Notas]]
o el bot de pedidos. Los precios salen del catálogo
(Productos), nunca de lo que escriba la IA.
=============================================*/

class BizOrders{

	/* Activa (Pendiente) → Completado sola al pasar la instalación, o Cancelado a mano */

	const STATUSES = array("Pendiente", "Completado", "Cancelado");

	static private function pdo(){

		return BizStore::pdo();

	}

	/*=============================================
	Catálogo (categorías y productos con precio)
	=============================================*/

	static public function catalogue(){

		static $cache = null;

		if($cache !== null){

			return $cache;
		}

		$cache = array();

		try{

			$rows = self::pdo()->query("SELECT p.title_product, p.price_product, p.code_product, c.title_category FROM products p LEFT JOIN categories c ON c.id_category = p.id_category_product ORDER BY c.id_category, p.id_product")->fetchAll();

			foreach ($rows as $r) {

				$cache[] = array(
					"name" => trim(urldecode((string)$r["title_product"])),
					"price" => (float)$r["price_product"],
					"code" => (string)$r["code_product"],
					"category" => trim(urldecode((string)$r["title_category"]))
				);
			}

		}catch(Throwable $e){

			/* Sin tablas de productos: el negocio no vende por catálogo */
		}

		return $cache;

	}

	/* "Envío" (pedidos a domicilio) o "Instalación" (internet / TV) */

	static public function feeLabel(){

		return BizGeo::installation() ? "Instalación" : "Envío";

	}

	/* TV abierta y TV box: precio desde Mi negocio → Servicio (si no están en el catálogo) */

	static public function servicePrice($name){

		$service = BizStore::get("service");

		if(empty($service["tv_enabled"])){

			return null;
		}

		$n = BizGeo::normalize($name);

		if(preg_match('/tv\s*-?\s*box|tvbox|decodificador/u', $n)){

			return array("name" => "TV box", "price" => (float)$service["tvbox_price"]);
		}

		if(preg_match('/\btv\b|television|senal abierta|tv abierta/u', $n)){

			return array("name" => "TV abierta", "price" => (float)$service["tv_price"]);
		}

		return null;

	}

	static public function findProduct($name){

		$needle = BizGeo::normalize($name);

		if($needle === ""){

			return null;
		}

		$best = null;

		foreach (self::catalogue() as $p) {

			$hay = BizGeo::normalize($p["name"]);

			if($hay === $needle || BizGeo::normalize($p["code"]) === $needle){

				return $p;
			}

			if($best === null && (strpos($hay, $needle) !== false || strpos($needle, $hay) !== false)){

				$best = $p;
			}
		}

		return $best;

	}

	/*=============================================
	"2 x Pizza familiar; 1 x Gaseosa @3.50"
	=============================================*/

	static public function parseItems($text){

		$items = array();

		foreach (preg_split('/\s*[;\n]+\s*/u', (string)$text) as $chunk) {

			$chunk = trim($chunk, " -•*\t");

			if($chunk === ""){

				continue;
			}

			$qty = 1;
			$price = null;

			if(preg_match('/@\s*(?:S\/\.?\s*)?([\d.,]+)\s*$/iu', $chunk, $m)){

				$price = (float)str_replace(",", ".", $m[1]);
				$chunk = trim(substr($chunk, 0, -strlen($m[0])));
			}

			if(preg_match('/^(\d+)\s*(?:x|×|und\.?|unid\.?|u\.)?\s+(.+)$/iu', $chunk, $m)){

				$qty = max(1, (int)$m[1]);
				$chunk = $m[2];

			}else if(preg_match('/^(.+?)\s*(?:x|×)\s*(\d+)$/iu', $chunk, $m)){

				$qty = max(1, (int)$m[2]);
				$chunk = $m[1];
			}

			/* Plan personalizado con precio del equipo ("Plan 1200 Mbps + TV @ 160"):
			   no es la TV suelta ni un plan parecido del catálogo */

			$isPlan = (bool)preg_match('/\d+\s*(mbps|mb|megas?|gbps|gigas?)\b|\b(plan|fibra|internet)\b/u', BizGeo::normalize($chunk));
			$product = self::findProduct($chunk);

			if($product && $price !== null && $isPlan && BizGeo::normalize($product["name"]) !== BizGeo::normalize($chunk)){

				$product = null;
			}

			$service = $product || ($isPlan && $price !== null) ? null : self::servicePrice($chunk);

			if($service){

				/* "TV box (cliente tiene)" no se cobra */

				$own = preg_match('/propio|tiene|cuenta con|ya tengo|suyo/u', BizGeo::normalize($chunk));
				$product = array("name" => $service["name"].($own ? " (propio del cliente)" : ""), "price" => $own ? 0 : $service["price"]);
			}

			/* "TV box propio" no se cobra aunque el TV box esté en el catálogo */

			if($product && !$service && preg_match('/tv\s*-?\s*box|decodificador/u', BizGeo::normalize($product["name"])) && preg_match('/propio|tiene|cuenta con|ya tengo|suyo/u', BizGeo::normalize($chunk)) && strpos($product["name"], "propio") === false){

				$product = array("name" => $product["name"]." (propio del cliente)", "price" => 0);
			}

			$unit = $product ? $product["price"] : $price;

			$items[] = array(
				"qty" => $qty,
				"name" => $product ? $product["name"] : mb_substr(trim($chunk), 0, 120),
				"price" => $unit !== null ? round((float)$unit, 2) : null,
				"subtotal" => $unit !== null ? round($qty * (float)$unit, 2) : null,
				"matched" => (bool)$product
			);
		}

		return $items;

	}

	/* Pedidos antiguos: "Producto - $5 USD\n..." */

	static public function legacyItems($text){

		$items = array();

		foreach (preg_split('/\r?\n/', (string)$text) as $line) {

			$line = trim($line);

			if($line === ""){

				continue;
			}

			$price = null;

			if(preg_match('/\s+-\s+(?:\$|S\/\.?)\s*([\d.,]+)/u', $line, $m)){

				$price = (float)str_replace(",", ".", $m[1]);
				$line = trim(substr($line, 0, strpos($line, $m[0])));
			}

			$items[] = array("qty" => 1, "name" => $line, "price" => $price, "subtotal" => $price, "matched" => true);
		}

		return $items;

	}

	static public function items(array $order){

		$items = json_decode((string)($order["items_order"] ?? ""), true);

		return is_array($items) ? $items : self::legacyItems($order["products_order"] ?? "");

	}

	static public function itemsText(array $items){

		$lines = array();

		foreach ($items as $i) {

			$lines[] = $i["qty"]." x ".$i["name"].($i["subtotal"] !== null ? " - ".cms_money($i["subtotal"]) : "");
		}

		return implode("\n", $lines);

	}

	/*=============================================
	Crear pedido
	=============================================*/

	static public function create(array $data){

		$phone = biz_clean_phone((string)($data["phone"] ?? ""));
		$items = (array)($data["items"] ?? array());

		if(empty($items)){

			return array("ok" => false, "error" => "El pedido no tiene productos.");
		}

		$subtotal = 0;
		$unknown = false;

		foreach ($items as $i) {

			if($i["subtotal"] === null){

				$unknown = true;

			}else{

				$subtotal += (float)$i["subtotal"];
			}
		}

		$installation = BizGeo::installation();
		$delivery = ($data["delivery"] ?? "delivery") === "recojo" && !$installation ? "recojo" : ($installation ? "instalacion" : "delivery");
		$settings = BizGeo::settings();
		$coverage = null;
		$fee = 0;

		if($delivery !== "recojo"){

			$coverage = BizGeo::lastFor($phone);

			if($installation){

				$fee = round((float)BizStore::get("service")["install_fee"], 2);

			}else if($coverage && $coverage["covered_check"] === "si"){

				$fee = (float)$coverage["fee_check"];
			}

			if(!$installation && (float)$settings["free_from"] > 0 && $subtotal >= (float)$settings["free_from"]){

				$fee = 0;
			}
		}

		$total = round($subtotal + $fee, 2);
		$address = $delivery === "recojo" ? "Recojo en tienda" : trim((string)($data["address"] ?? ($coverage["query_check"] ?? "")));

		/* Evitar el mismo pedido dos veces (la IA puede repetir la etiqueta) */

		$itemsJson = json_encode($items, JSON_UNESCAPED_UNICODE);

		$dup = self::pdo()->prepare("SELECT id_order FROM orders WHERE contact_order = ? AND items_order = ? AND created_at_order >= ? AND status_order <> 'Cancelado' ORDER BY id_order DESC LIMIT 1");
		$dup->execute(array($phone, $itemsJson, date("Y-m-d H:i:s", time() - 3600)));
		$dupId = (int)$dup->fetchColumn();

		if($dupId){

			return array("ok" => true, "id" => $dupId, "total" => $total, "fee" => $fee, "subtotal" => $subtotal, "unknown" => $unknown, "duplicate" => true);
		}

		/* Ubicación exacta (enlace de Maps o pin de WhatsApp) y fotos que envió el cliente */

		$location = trim((string)($data["location"] ?? "")) ?: ($delivery !== "recojo" ? self::clientLocation($phone, $coverage) : "");
		$photos = $data["photos"] ?? self::clientPhotos($phone);

		$stmt = self::pdo()->prepare("INSERT INTO orders (conversation_order, products_order, items_order, phone_order, name_order, email_order, address_order, reference_order, contact_order, subtotal_order, fee_order, total_order, status_order, delivery_order, payment_order, notes_order, lat_order, lng_order, distance_order, coverage_order, source_order, date_created_order, created_at_order, branch_order, document_order, location_order, photos_order) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Pendiente', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

		$stmt->execute(array(
			(string)($data["conversation"] ?? ""),
			self::itemsText($items),
			$itemsJson,
			self::phonesText($data["contact_phone"] ?? "") ?: $phone,
			mb_substr(trim((string)($data["name"] ?? "")), 0, 191),
			mb_substr(trim((string)($data["email"] ?? "")), 0, 191),
			$address,
			mb_substr(trim((string)($data["reference"] ?? "")), 0, 500),
			$phone,
			round($subtotal, 2),
			$fee,
			$total,
			$delivery,
			mb_substr(trim((string)($data["payment"] ?? "")), 0, 80),
			trim((string)($data["notes"] ?? "")),
			$delivery !== "recojo" && $coverage ? $coverage["lat_check"] : null,
			$delivery !== "recojo" && $coverage ? $coverage["lng_check"] : null,
			$delivery !== "recojo" && $coverage ? $coverage["distance_check"] : null,
			$delivery !== "recojo" ? ($coverage["covered_check"] ?? "sin verificar") : null,
			(string)($data["source"] ?? "ia"),
			date("Y-m-d"),
			date("Y-m-d H:i:s"),
			$delivery !== "recojo" && $coverage ? (string)($coverage["branch_check"] ?? "") : "",
			mb_substr(preg_replace('/[^\w-]/u', '', (string)($data["document"] ?? "")), 0, 30),
			mb_substr($location, 0, 500) ?: null,
			!empty($photos) ? json_encode($photos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null
		));

		$id = (int)self::pdo()->lastInsertId();

		return array("ok" => true, "id" => $id, "total" => $total, "fee" => $fee, "subtotal" => round($subtotal, 2), "unknown" => $unknown);

	}

	/*=============================================
	Datos de la etiqueta [[PEDIDO]]
	Con nombre (recomendado):
	  [[PEDIDO|nombre=..|dni=..|correo=..|direccion=..|referencia=..|telefonos=..|plan=1 x ..|pago=..|notas=..]]
	Por posición (versiones anteriores):
	  [[PEDIDO|Nombre|Dirección|items|Pago|Notas|DNI]]
	=============================================*/

	static public function orderFields(array $args){

		$aliases = array(
			"nombre" => "name", "nombres" => "name", "titular" => "name", "name" => "name",
			"dni" => "document", "documento" => "document", "ce" => "document",
			"correo" => "email", "email" => "email", "mail" => "email",
			"direccion" => "address", "dirección" => "address", "domicilio" => "address",
			"referencia" => "reference", "referencias" => "reference",
			"telefonos" => "phones", "teléfonos" => "phones", "telefono" => "phones", "teléfono" => "phones", "celular" => "phones", "celulares" => "phones",
			"plan" => "items", "planes" => "items", "items" => "items", "productos" => "items", "servicios" => "items",
			"pago" => "payment", "forma de pago" => "payment",
			"notas" => "notes", "nota" => "notes",
			"ubicacion" => "location", "ubicación" => "location", "mapa" => "location"
		);

		$out = array("name" => "", "document" => "", "email" => "", "address" => "", "reference" => "", "phones" => "", "items" => "", "payment" => "", "notes" => "", "location" => "");
		$named = false;

		foreach ($args as $arg) {

			if(preg_match('/^\s*([a-záéíóúñ ]{2,20})\s*[=:]\s*(.*)$/iu', (string)$arg, $m) && isset($aliases[mb_strtolower(trim($m[1]))])){

				$out[$aliases[mb_strtolower(trim($m[1]))]] = trim($m[2]);
				$named = true;
			}
		}

		if(!$named){

			$out["name"] = trim((string)($args[0] ?? ""));
			$out["address"] = trim((string)($args[1] ?? ""));
			$out["items"] = trim((string)($args[2] ?? ""));
			$out["payment"] = trim((string)($args[3] ?? ""));
			$out["notes"] = trim((string)($args[4] ?? ""));
			$out["document"] = trim((string)($args[5] ?? ""));
		}

		return $out;

	}

	/* Datos obligatorios para registrar una orden de instalación */

	static public function missingFields(array $f){

		$missing = array();
		$name = trim(preg_replace('/\s+/u', " ", (string)$f["name"]));
		$doc = preg_replace('/\D/', '', (string)$f["document"]);

		if(count(explode(" ", $name)) < 2){ $missing[] = "nombres y apellidos del titular"; }
		if(!preg_match('/^\d{8}$/', $doc) && !preg_match('/^[A-Z0-9]{9,12}$/i', preg_replace('/[^A-Z0-9]/i', '', (string)$f["document"]))){ $missing[] = "DNI (8 dígitos)"; }
		if(!filter_var(trim((string)$f["email"]), FILTER_VALIDATE_EMAIL)){ $missing[] = "correo electrónico válido"; }
		if(mb_strlen(trim((string)$f["address"])) < 6){ $missing[] = "dirección de instalación"; }
		if(mb_strlen(trim((string)$f["reference"])) < 3){ $missing[] = "referencia de la dirección"; }
		if(self::phonesText($f["phones"]) === ""){ $missing[] = "al menos un teléfono para llamar el día de la instalación"; }
		if(empty(self::parseItems($f["items"]))){ $missing[] = "plan elegido"; }

		return $missing;

	}

	/* "987654321 / 912 345 678" → "51987654321, 51912345678" */

	static public function phonesText($text){

		preg_match_all('/\+?\d[\d\s-]{6,16}\d/', (string)$text, $m);

		$list = array();

		foreach ($m[0] as $raw) {

			$digits = preg_replace('/\D/', '', $raw);

			if(strlen($digits) === 9 && $digits[0] === "9"){ $digits = "51".$digits; }

			if(strlen($digits) >= 7 && strlen($digits) <= 15){ $list[] = $digits; }
		}

		return implode(", ", array_values(array_unique($list)));

	}

	/* Último enlace de ubicación que mandó el cliente (pin de WhatsApp o Google Maps) */

	static public function clientLocation($phone, $coverage = null){

		$stmt = self::pdo()->prepare("SELECT client_message FROM messages WHERE phone_message = ? AND type_message = 'client' AND date_updated_message >= ? ORDER BY id_message DESC LIMIT 60");
		$stmt->execute(array(biz_clean_phone($phone), date("Y-m-d H:i:s", time() - 3 * 86400)));

		foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $msg) {

			$links = BizGeo::mapsLinks((string)$msg);

			if(!empty($links)){

				return $links[0];
			}
		}

		if($coverage && $coverage["lat_check"] !== null && $coverage["lng_check"] !== null){

			return "https://www.google.com/maps?q=".$coverage["lat_check"].",".$coverage["lng_check"];
		}

		return "";

	}

	/* Enlace de ubicación para el equipo, en este orden:
	   1) la ubicación guardada en la orden
	   2) la última ubicación de WhatsApp (en tiempo real o actual) o enlace de Maps que mandó el cliente
	   3) el punto donde se verificó la cobertura
	   4) la búsqueda de la dirección escrita en Google Maps */

	static public function mapLink(array $o){

		if(trim((string)($o["location_order"] ?? "")) !== ""){

			return trim($o["location_order"]);
		}

		$phone = (string)($o["contact_order"] ?? "");

		if($phone !== ""){

			try{

				$link = self::clientLocation($phone, BizGeo::lastFor($phone));

				if($link !== ""){

					/* Se guarda para que el panel muestre la misma ubicación */

					self::pdo()->prepare("UPDATE orders SET location_order = ? WHERE id_order = ? AND (location_order IS NULL OR location_order = '')")->execute(array(mb_substr($link, 0, 500), (int)$o["id_order"]));

					return $link;
				}

			}catch(Throwable $e){}
		}

		if(!empty($o["lat_order"]) && !empty($o["lng_order"])){

			return "https://www.google.com/maps?q=".$o["lat_order"].",".$o["lng_order"];
		}

		if(trim((string)($o["address_order"] ?? "")) !== ""){

			return "https://www.google.com/maps/search/?api=1&query=".rawurlencode(trim($o["address_order"]));
		}

		return "";

	}

	/* Fotos (o PDF) que envió el cliente: recibo de luz o agua para identificar la vivienda.
	   Se guardan en el servidor porque WhatsApp borra los archivos a los 30 días.
	   Con $download = false solo dice si ya envió alguna (sin descargar nada). */

	static public function clientPhotos($phone, $download = true){

		$stmt = self::pdo()->prepare("SELECT id_message, client_message FROM messages WHERE phone_message = ? AND type_message = 'client' AND (client_message LIKE '%\"type\":\"image\"%' OR (client_message LIKE '%\"type\":\"document\"%' AND client_message LIKE '%pdf%')) AND date_updated_message >= ? ORDER BY id_message DESC LIMIT 3");
		$stmt->execute(array(biz_clean_phone($phone), date("Y-m-d H:i:s", time() - 2 * 86400)));

		$rows = $stmt->fetchAll();

		if(!$download){

			return count($rows) > 0;
		}

		$photos = array();
		$api = null;

		foreach ($rows as $r) {

			$json = json_decode((string)$r["client_message"], true);

			if(empty($json["id"])){

				continue;
			}

			$path = null;

			try{

				$api = $api ?: ChatService::api();
				$path = $api ? CurlController::apiWS($api, (string)$json["id"]) : null;

			}catch(Throwable $e){

				error_log("Órdenes: no se pudo descargar la foto del cliente: ".$e->getMessage());
			}

			$photos[] = array("message" => (int)$r["id_message"], "media" => (string)$json["id"], "url" => $path ?: null, "caption" => trim(urldecode((string)($json["caption"] ?? ""))), "kind" => ($json["type"] ?? "") === "document" ? "pdf" : "image");
		}

		return array_reverse($photos);

	}

	/*=============================================
	Internet / TV: separar la mensualidad (plan + TV)
	del pago inicial (instalación + TV box)
	=============================================*/

	static public function breakdown(array $o){

		if(($o["delivery_order"] ?? "") !== "instalacion"){

			return null;
		}

		$monthly = 0;
		$upfront = (float)$o["fee_order"];

		foreach (self::items($o) as $i) {

			$amount = (float)($i["subtotal"] ?? 0);

			if(preg_match('/tv\s*box/i', (string)$i["name"])){

				$upfront += $amount;

			}else{

				$monthly += $amount;
			}
		}

		return array("monthly" => round($monthly, 2), "upfront" => round($upfront, 2));

	}

	/*=============================================
	Vincular la cita de instalación con la orden
	=============================================*/

	static public function linkAppointment($orderId, $appointmentId){

		$o = self::find($orderId);
		$a = BizStore::appointment($appointmentId);

		if(!$o || !$a){

			return false;
		}

		self::pdo()->prepare("UPDATE orders SET appointment_order = ? WHERE id_order = ?")->execute(array((int)$appointmentId, (int)$orderId));

		$note = "Orden #".$o["id_order"].": ".str_replace("\n", "; ", self::itemsText(self::items($o)));
		$summary = trim((string)$a["summary_appointment"]);

		self::pdo()->prepare("UPDATE appointments SET summary_appointment = ?, branch_appointment = COALESCE(NULLIF(?, ''), branch_appointment) WHERE id_appointment = ?")
			->execute(array(strpos($summary, "Orden #".$o["id_order"]) === false ? trim($summary." | ".$note, " |") : $summary, (string)$o["branch_order"], (int)$appointmentId));

		BizStore::syncCalendar($appointmentId);

		return true;

	}

	/* Cita de instalación reciente del cliente aún sin orden (últimas 3 h) */

	static public function pendingAppointmentFor($phone){

		$stmt = self::pdo()->prepare("SELECT a.id_appointment FROM appointments a WHERE a.phone_appointment = ? AND a.status_appointment <> 'cancelada' AND a.date_created_appointment >= ? AND NOT EXISTS (SELECT 1 FROM orders o WHERE o.appointment_order = a.id_appointment) ORDER BY a.id_appointment DESC LIMIT 1");
		$stmt->execute(array(biz_clean_phone($phone), date("Y-m-d H:i:s", time() - 10800)));

		return (int)$stmt->fetchColumn() ?: null;

	}

	/* Orden activa de la última hora que YA tiene fecha (la IA repitió la etiqueta:
	   create() la reconoce como la misma y se le vuelve a mostrar al cliente) */

	static public function recentScheduledOrder($phone){

		$stmt = self::pdo()->prepare("SELECT o.id_order FROM orders o JOIN appointments a ON a.id_appointment = o.appointment_order WHERE o.contact_order = ? AND o.status_order = 'Pendiente' AND o.created_at_order >= ? AND a.status_appointment <> 'cancelada' ORDER BY o.id_order DESC LIMIT 1");
		$stmt->execute(array(biz_clean_phone($phone), date("Y-m-d H:i:s", time() - 3600)));

		return (int)$stmt->fetchColumn() ?: null;

	}

	/* Orden activa reciente del cliente que aún no tiene fecha de instalación */

	static public function openOrderWithoutAppointment($phone){

		$stmt = self::pdo()->prepare("SELECT o.id_order FROM orders o LEFT JOIN appointments a ON a.id_appointment = o.appointment_order WHERE o.contact_order = ? AND o.status_order = 'Pendiente' AND o.created_at_order >= ? AND (o.appointment_order IS NULL OR a.status_appointment = 'cancelada') ORDER BY o.id_order DESC LIMIT 1");
		$stmt->execute(array(biz_clean_phone($phone), date("Y-m-d H:i:s", time() - 7 * 86400)));

		return (int)$stmt->fetchColumn() ?: null;

	}

	/*=============================================
	Lecturas
	=============================================*/

	static public function find($id){

		$stmt = self::pdo()->prepare("SELECT * FROM orders WHERE id_order = ?");
		$stmt->execute(array((int)$id));

		return $stmt->fetch() ?: null;

	}

	static public function listAll($status = "", $from = "", $search = "", $limit = 300){

		$sql = "SELECT * FROM orders WHERE 1";
		$args = array();

		if($status !== "" && in_array($status, self::STATUSES, true)){

			$sql .= " AND status_order = ?";
			$args[] = $status;
		}

		if($from !== "" && BizStore::validDate($from)){

			$sql .= " AND COALESCE(created_at_order, date_created_order) >= ?";
			$args[] = $from." 00:00:00";
		}

		if(trim($search) !== ""){

			$like = "%".trim($search)."%";
			$sql .= " AND (name_order LIKE ? OR contact_order LIKE ? OR phone_order LIKE ? OR address_order LIKE ? OR products_order LIKE ? OR branch_order LIKE ? OR document_order LIKE ? OR id_order = ?)";
			array_push($args, $like, $like, $like, $like, $like, $like, $like, (int)preg_replace('/\D/', '', $search));
		}

		$sql .= " ORDER BY id_order DESC LIMIT ".max(1, min(1000, (int)$limit));

		$stmt = self::pdo()->prepare($sql);
		$stmt->execute($args);

		$out = array();

		foreach ($stmt->fetchAll() as $o) {

			$o["items"] = self::items($o);
			$o["created"] = $o["created_at_order"] ?: ($o["date_created_order"]." 00:00:00");
			$o["appointment"] = null;
			$o["breakdown"] = self::breakdown($o);
			$o["notify"] = Notify::withStatus(json_decode((string)($o["notify_order"] ?? ""), true) ?: array());
			$o["photos"] = array_map(function($p){ return array("url" => $p["url"] ?: "/ajax/media.php?id=".rawurlencode((string)$p["media"]), "caption" => (string)($p["caption"] ?? ""), "kind" => (string)($p["kind"] ?? "image")); }, json_decode((string)($o["photos_order"] ?? ""), true) ?: array());

			if(!empty($o["appointment_order"])){

				$a = BizStore::appointment($o["appointment_order"]);

				if($a){

					$o["appointment"] = array("date" => $a["date_appointment"], "time" => substr($a["time_appointment"], 0, 5), "status" => $a["status_appointment"], "label" => ucfirst(BizStore::dayName($a["date_appointment"])).(substr($a["time_appointment"], 0, 5) !== "00:00" ? " · ".BizStore::time12(substr($a["time_appointment"], 0, 5)) : ""));
				}
			}

			$out[] = $o;
		}

		return $out;

	}

	static public function stats(){

		$today = date("Y-m-d");

		$counts = array();

		foreach (self::pdo()->query("SELECT status_order, COUNT(*) n FROM orders GROUP BY status_order")->fetchAll() as $r) {

			$counts[$r["status_order"] ?: "Pendiente"] = (int)$r["n"];
		}

		$stmt = self::pdo()->prepare("SELECT COUNT(*) n, COALESCE(SUM(total_order), 0) total FROM orders WHERE COALESCE(created_at_order, date_created_order) >= ? AND status_order <> 'Cancelado'");
		$stmt->execute(array($today." 00:00:00"));
		$day = $stmt->fetch();

		$up = self::pdo()->prepare("SELECT COUNT(*) FROM orders o JOIN appointments a ON a.id_appointment = o.appointment_order WHERE o.status_order = 'Pendiente' AND a.status_appointment <> 'cancelada' AND a.date_appointment >= ?");
		$up->execute(array($today));

		return array("counts" => $counts, "today_orders" => (int)$day["n"], "today_total" => (float)$day["total"], "upcoming" => (int)$up->fetchColumn());

	}

	static public function forPhone($phone, $limit = 5){

		$stmt = self::pdo()->prepare("SELECT * FROM orders WHERE contact_order = ? ORDER BY id_order DESC LIMIT ".(int)$limit);
		$stmt->execute(array(biz_clean_phone($phone)));

		return $stmt->fetchAll();

	}

	static public function setStatus($id, $status){

		if(!in_array($status, self::STATUSES, true)){

			return false;
		}

		$stmt = self::pdo()->prepare("UPDATE orders SET status_order = ? WHERE id_order = ?");

		return $stmt->execute(array($status, (int)$id));

	}

	/*=============================================
	Confirmación completa para el cliente, en el
	mismo chat, apenas se registra la orden (sin
	correos ni "te enviaremos el número")
	=============================================*/

	static public function confirmation($id){

		$o = self::find($id);

		if(!$o){

			return "";
		}

		$install = $o["delivery_order"] === "instalacion";
		$first = trim(explode(" ", trim((string)$o["name_order"]))[0] ?? "");
		$items = self::items($o);
		$split = self::breakdown($o);

		$t = "✅ *¡Listo".($first !== "" ? ", ".$first : "")."! Tu ".($install ? "orden quedó registrada" : "pedido quedó registrado").".*\n\n";
		$t .= "🧾 *".($install ? "Orden" : "Pedido")." N.° ".$o["id_order"]."*\n\n";
		$t .= "📦 *".($install ? "Plan" : "Productos").":*\n".implode("\n", array_map(function($i){ return "• ".$i["qty"]." x ".$i["name"].($i["subtotal"] !== null ? " — ".cms_money($i["subtotal"]) : " — precio por confirmar"); }, $items))."\n";

		if($split){

			$t .= "\n💰 *Mensualidad:* ".cms_money($split["monthly"])."\n";
			$t .= "💵 *Pago inicial".((float)$o["fee_order"] > 0 ? " (instalación)" : "").":* ".cms_money($split["upfront"])."\n";

		}else{

			$t .= (float)$o["fee_order"] > 0 ? "\n🚚 ".self::feeLabel().": ".cms_money($o["fee_order"])."\n" : "\n";
			$t .= "💰 *Total:* ".cms_money($o["total_order"])."\n";
		}

		$t .= "\n👤 *Titular:* ".($o["name_order"] ?: "—").(!empty($o["document_order"]) ? " · DNI ".$o["document_order"] : "")."\n";
		$t .= $o["delivery_order"] === "recojo" ? "🏪 *Entrega:* recojo en tienda\n" : "📍 *Dirección:* ".$o["address_order"].(!empty($o["reference_order"]) ? " (".$o["reference_order"].")" : "")."\n";

		if(!empty($o["phone_order"])){

			$t .= "📞 *Contacto:* ".implode(", ", array_map(function($p){ return "+".$p; }, explode(", ", $o["phone_order"])))."\n";
		}

		if(!empty($o["appointment_order"]) && ($a = self::appointmentRow($o["appointment_order"]))){

			$time = substr($a["time_appointment"], 0, 5);
			$t .= "🗓️ *".($install ? "Instalación" : "Cita").":* ".ucfirst(BizStore::dayName($a["date_appointment"])).($time !== "00:00" ? " a las ".BizStore::time12($time) : " (hora por confirmar)")."\n";

		}else if($install){

			$t .= "🗓️ *Instalación:* ".(!empty(BizStore::get("agenda")["enabled"]) ? "falta elegir la fecha" : "te confirmamos la fecha en breve")."\n";
		}

		if(!empty($o["payment_order"])){

			$t .= "💳 *Pago:* ".$o["payment_order"]."\n";
		}

		/* Instalación sin fecha: se le pide al cliente que elija el día y la hora */

		if($install && !empty(BizStore::get("agenda")["enabled"]) && !(!empty($o["appointment_order"]) && self::appointmentRow($o["appointment_order"]))){

			$t .= "\nGuarda este número de orden para cualquier consulta.\n\n".self::askInstallDate();

			return $t;
		}

		$t .= "\n".($install ? "El técnico te llamará antes de llegar. " : "")."Guarda este número de ".($install ? "orden" : "pedido")." para cualquier consulta. ¿Te ayudo en algo más? 😊";

		return $t;

	}

	/* Pregunta por la fecha de instalación con los próximos turnos libres de la Agenda */

	static public function askInstallDate($lead = null){

		$options = array();

		try{

			$agenda = BizStore::get("agenda");

			if(!empty($agenda["enabled"])){

				foreach (BizStore::nextFree(60) as $s) {

					if(count($options) >= 4 && !isset($options[$s["date"]])){

						break;
					}

					if(!isset($options[$s["date"]])){

						$options[$s["date"]] = array();
					}

					if(count($options[$s["date"]]) < 6){

						$options[$s["date"]][] = $s["time"];
					}
				}
			}

		}catch(Throwable $e){

			error_log("Pedidos: no se pudieron leer los turnos libres: ".$e->getMessage());
		}

		if(empty($options)){

			return ($lead ? "📅 *Antes de generar tu orden, elige la fecha de tu instalación.*\n\n" : "")."📅 *¿Qué día y en qué horario te acomoda la instalación?* Escríbeme el día y la hora que prefieras y lo agendo. 😊";
		}

		$t = ($lead ?: "📅 *Ahora elige la fecha de tu instalación.* Estos son los próximos horarios disponibles:")."\n";

		foreach ($options as $date => $times) {

			$t .= "• ".BizStore::slotLine($date, $times)."\n";
		}

		return $t."\n¿Cuál te acomoda? Si prefieres otro día u hora, dímelo. 😊";

	}

	/*=============================================
	Datos de la orden: se piden UNO POR UNO.
	Si la IA pidió varios datos juntos (en lista o en
	una frase), se reemplaza por la pregunta del
	primero. Si ofreció horarios con otro formato, se
	muestran los turnos libres reales con el formato
	"Sábado 7 de noviembre: 8:00, 10:00, 12:00, 2:00…"
	=============================================*/

	const DATA_FIELDS = array("name", "dni", "email", "address", "reference", "phones", "bill");

	static public function fieldQuestion($field){

		$q = array(
			"name" => "👤 ¿Cuáles son los *nombres y apellidos del titular* del servicio?",
			"dni" => "🪪 ¿Cuál es el número de *DNI* del titular?",
			"email" => "📧 ¿Cuál es tu *correo electrónico*?",
			"address" => "🏠 ¿Cuál es la *dirección exacta de la instalación*? (calle, número y distrito)",
			"reference" => "📍 ¿Qué *referencia* tiene la dirección? (por ejemplo: frente al parque, casa de 2 pisos color blanco)",
			"phones" => "📱 ¿A qué *número de celular* te llamamos el día de la instalación? Si quieres, puedes darnos más de uno.",
			"bill" => "🧾 Por último, ¿puedes enviarnos una *foto de tu recibo de luz o de agua*? Es *opcional*, pero ayuda al técnico a ubicar tu vivienda. Si no la tienes, escribe *continuar*."
		);

		return $q[$field] ?? "";

	}

	/* Campos a los que se refiere un texto ("DNI (8 dígitos)", "- Correo electrónico"…) */

	static public function fieldsIn($line){

		$t = mb_strtolower((string)$line);
		$t = strtr($t, array("á" => "a", "é" => "e", "í" => "i", "ó" => "o", "ú" => "u"));
		$found = array();

		if(preg_match('/referencia/u', $t)){

			$found[] = "reference";
			$t = preg_replace('/referencia\s+(de\s+(la|tu|su)\s+)?direccion/u', "referencia", $t);
		}

		if(preg_match('/\bnombres?\b|apellidos?/u', $t)){ $found[] = "name"; }
		if(preg_match('/\bdni\b|documento de identidad/u', $t)){ $found[] = "dni"; }
		if(preg_match('/correo|e-?mail/u', $t)){ $found[] = "email"; }
		if(preg_match('/direccion/u', $t)){ $found[] = "address"; }
		if(preg_match('/telefono|celular|numero de contacto|numeros? para llamar/u', $t)){ $found[] = "phones"; }
		if(preg_match('/recibo/u', $t)){ $found[] = "bill"; }

		return array_values(array_intersect(self::DATA_FIELDS, $found));

	}

	static public function tidyReply($text){

		$text = (string)$text;

		if(trim($text) === "" || strpos($text, "[[") !== false){

			return $text;
		}

		/* 1) Varios datos pedidos a la vez → solo el primero */

		$asked = array();
		$firstLine = null;
		$lines = preg_split('/\R/u', $text);

		foreach ($lines as $i => $line) {

			$plain = trim(str_replace(array("*", "_"), "", $line));

			/* Líneas con valores (un resumen de datos ya dados) no cuentan */

			if($plain === "" || preg_match('/:\s*\S{2,}/u', $plain) || strpos($plain, "@") !== false || preg_match('/\d{6,}/', $plain)){

				continue;
			}

			$fields = self::fieldsIn($plain);
			$bullet = (bool)preg_match('/^([-•·▪◦*]|\d+[.)°]|[a-z][.)])\s*/u', trim($line));

			if(($bullet && count($fields) >= 1) || count($fields) >= 2){

				$asked = array_merge($asked, $fields);
				$firstLine = $firstLine === null ? $i : $firstLine;
			}
		}

		$asked = array_values(array_intersect(self::DATA_FIELDS, array_unique($asked)));

		if(count(array_diff($asked, array("bill"))) >= 2){

			$keep = array();

			foreach (preg_split('/\n\s*\n/u', implode("\n", array_slice($lines, 0, $firstLine))) as $para) {

				/* Se quita la frase que introducía la lista ("necesito estos datos:") */

				if(trim($para) === "" || preg_match('/datos|siguiente|:\s*$/u', mb_strtolower($para))){

					continue;
				}

				$keep[] = trim($para);
			}

			$first = $asked[0];
			$keep[] = ($first === "name" ? "Para registrar tu orden te pediré unos datos, *uno por uno* 📝\n\n" : "").self::fieldQuestion($first);

			return implode("\n\n", $keep);
		}

		/* 2) Ofreció horarios con otro formato → turnos libres reales con el formato fijo */

		try{

			if(empty(BizStore::get("agenda")["enabled"]) || !BizGeo::installation()){

				return $text;
			}

		}catch(Throwable $e){

			return $text;
		}

		$timeRx = '/\b\d{1,2}:\d{2}\b|\b\d{1,2}\s*(a\.?\s?m\.?|p\.?\s?m\.?|hrs?\b)/iu';
		$dayRx = '/lunes|martes|mi[eé]rcoles|jueves|viernes|s[aá]bado|domingo|ma[nñ]ana|hoy/iu';

		if(preg_match_all($timeRx, $text) < 3 || !preg_match($dayRx, $text)){

			return $text;
		}

		$keep = array();

		foreach (preg_split('/\n\s*\n/u', trim($text)) as $para) {

			if(preg_match($timeRx, $para)){

				break;
			}

			$keep[] = trim($para);
		}

		/* La frase que presentaba los horarios ("estos son los horarios disponibles:") */

		if(!empty($keep) && preg_match('/:\s*$|horario|turno|disponib/iu', end($keep))){

			array_pop($keep);
		}

		$keep[] = self::askInstallDate("📅 *Elige la fecha de tu instalación.* Estos son los horarios disponibles:");

		return implode("\n\n", array_filter($keep));

	}

	static private function appointmentRow($id){

		$a = BizStore::appointment($id);

		return $a && $a["status_appointment"] !== "cancelada" ? $a : null;

	}

	/*=============================================
	Mensajes de WhatsApp
	=============================================*/

	static public function summary(array $o, $forOwner = false){

		$items = self::items($o);

		$install = $o["delivery_order"] === "instalacion";

		$text = ($forOwner ? ($install ? "📡 *Nueva orden de instalación #".$o["id_order"]."*" : "🛍️ *Nuevo pedido #".$o["id_order"]."*") : "🧾 *".($install ? "Orden" : "Pedido")." #".$o["id_order"]."*")."\n\n";
		$text .= self::itemsText($items)."\n";

		/* Plan o producto fuera del catálogo (precio confirmado por el equipo en una consulta) */

		if($forOwner && array_filter($items, function($i){ return isset($i["matched"]) && !$i["matched"]; })){

			$text .= "⚠️ Incluye un plan o producto fuera del catálogo, con el precio que confirmó el equipo. Revísalo.\n";
		}

		if((float)$o["fee_order"] > 0){

			$text .= ($install ? "Instalación" : "Envío").": ".cms_money($o["fee_order"])."\n";
		}

		$split = self::breakdown($o);

		if($split){

			$text .= "*Mensualidad: ".cms_money($split["monthly"])."*\n";
			$text .= "*Pago inicial (instalación".($split["upfront"] > (float)$o["fee_order"] ? " + TV box" : "")."): ".cms_money($split["upfront"])."*\n";

		}else{

			$text .= "*Total: ".cms_money($o["total_order"])."*\n";
		}

		if($forOwner){

			$text .= "\n👤 ".($o["name_order"] ?: "Sin nombre").(!empty($o["document_order"]) ? " · DNI ".$o["document_order"] : "")."\n💬 WhatsApp +".$o["contact_order"]."\n";

			if(!empty($o["phone_order"]) && $o["phone_order"] !== $o["contact_order"]){

				$text .= "📞 Llamar a: ".implode(", ", array_map(function($p){ return "+".$p; }, explode(", ", $o["phone_order"])))."\n";
			}

			if(!empty($o["email_order"])){

				$text .= "✉️ ".$o["email_order"]."\n";
			}
		}

		$text .= $o["delivery_order"] === "recojo" ? "🏪 Recojo en tienda\n" : "📍 ".$o["address_order"]."\n";

		if($forOwner && !empty($o["reference_order"])){

			$text .= "🧭 Referencia: ".$o["reference_order"]."\n";
		}

		if($forOwner && $o["delivery_order"] !== "recojo" && ($map = self::mapLink($o)) !== ""){

			$text .= "🗺️ Ubicación: ".$map."\n";
		}

		if($forOwner && !empty($o["photos_order"])){

			$text .= "🧾 El cliente envió su recibo de luz o agua para identificar la vivienda (ver en el panel)\n";
		}

		if(!empty($o["branch_order"])){

			$text .= "🏢 Sucursal: ".$o["branch_order"]."\n";
		}

		if($forOwner && $o["distance_order"] !== null){

			$text .= "📏 ".number_format((float)$o["distance_order"], 1)." km · cobertura: ".$o["coverage_order"]."\n";
		}

		if(!empty($o["appointment_order"]) && ($a = BizStore::appointment($o["appointment_order"]))){

			$time = substr($a["time_appointment"], 0, 5);
			$text .= "🗓️ ".($install ? "Instalación" : "Cita").": ".ucfirst(BizStore::dayName($a["date_appointment"])).($time !== "00:00" ? " · ".BizStore::time12($time) : " · hora por confirmar")."\n";
		}

		if(!empty($o["payment_order"])){

			$text .= "💳 ".$o["payment_order"]."\n";
		}

		if(!empty($o["notes_order"])){

			$text .= "📝 ".$o["notes_order"]."\n";
		}

		if($forOwner){

			$text .= "\nVer: ".cms_base_url()."ordenes";
		}

		return trim($text);

	}

	/* Se envía al número central de órdenes, al de Agenda y al WhatsApp de la sucursal */

	static public function notifyOwner($id, $event = "nueva"){

		$o = self::find($id);

		if(!$o){

			return false;
		}

		/* Orden nueva ya avisada con éxito: no se repite el aviso */

		if($event === "nueva"){

			foreach ((json_decode((string)($o["notify_order"] ?? ""), true) ?: array()) as $entry) {

				if(($entry["event"] ?? "") === "nueva" && !empty($entry["ok"])){

					return true;
				}
			}
		}

		$text = self::summary($o, true);
		$install = $o["delivery_order"] === "instalacion";
		$titles = array(
			"agendada" => "🗓️ *".($install ? "Instalación agendada" : "Cita agendada")." · Orden #".$o["id_order"]."*",
			"reprogramada" => "🔁 *".($install ? "Instalación reprogramada" : "Cita reprogramada")." · Orden #".$o["id_order"]."*"
		);

		if(isset($titles[$event])){

			$lines = explode("\n", $text);
			$lines[0] = $titles[$event];
			$text = implode("\n", $lines);

		}else if($event === "cancelada"){

			$text = "❌ *Orden #".$o["id_order"]." cancelada*\n\n👤 ".($o["name_order"] ?: "Sin nombre")."\n📱 +".$o["contact_order"].(!empty($o["branch_order"]) ? "\n🏢 Sucursal: ".$o["branch_order"] : "").(!empty($o["cancel_reason_order"]) ? "\n📝 Motivo: ".$o["cancel_reason_order"] : "");
		}

		$topic = $event === "cancelada" ? "cancel" : ($event === "nueva" ? "orders" : "appts");
		$sent = Notify::send($text, (string)$o["branch_order"], "pedido", $topic);

		foreach ($sent as &$entry) {

			$entry["event"] = $event;
		}

		unset($entry);

		/* Registro de avisos visible en Órdenes (a quién, cuándo, si llegó) */

		$log = json_decode((string)($o["notify_order"] ?? ""), true) ?: array();
		$log = array_slice(array_merge($log, $sent), -20);

		self::pdo()->prepare("UPDATE orders SET notify_order = ? WHERE id_order = ?")->execute(array(json_encode($log, JSON_UNESCAPED_UNICODE), (int)$id));

		$ok = in_array(true, array_column($sent, "ok"), true);

		/* El aviso no salió: queda en Inconvenientes para que alguien lo vea */

		if(!$ok){

			require_once __DIR__."/business.incidents.php";

			BizIncidents::record(array(
				"phone" => $o["contact_order"],
				"name" => $o["name_order"],
				"order" => $o["id_order"],
				"kind" => "sistema",
				"detail" => "No se pudo avisar al equipo la orden #".$o["id_order"]." (".$event."): ".(empty($sent) ? "no hay ningún número configurado. Escribe el número principal en Mi negocio › Números por área." : implode(" · ", array_map(function($e){ return "+".$e["to"].": ".$e["error"]; }, $sent))),
				"source" => "sistema"
			));
		}

		return $ok;

	}

	/* Plazo para que el cliente cancele: hasta N horas antes de la instalación.
	   Sin fecha de instalación: null (se puede cancelar siempre) */

	static public function cancelDeadline(array $o){

		$a = !empty($o["appointment_order"]) ? BizStore::appointment($o["appointment_order"]) : null;

		if(!$a || $a["status_appointment"] === "cancelada"){

			return null;
		}

		$time = substr((string)$a["time_appointment"], 0, 5);
		$install = strtotime($a["date_appointment"]." ".($time === "00:00" ? "08:00" : $time).":00");
		$until = $install - max(0, (int)(BizStore::get("service")["cancel_hours"] ?? 4)) * 3600;

		return array("install" => $install, "until" => $until, "allowed" => time() <= $until);

	}

	/*=============================================
	Cancelar (a mano desde Órdenes, o el cliente
	por WhatsApp dentro del plazo)
	=============================================*/

	/* $source: panel | cliente | equipo (asistente del número principal)
	   $feedback: lo que dijo el cliente (si ya lo dio) */

	static public function cancel($id, $reason = "", $tellClient = false, $source = "panel", $feedback = ""){

		$o = self::find($id);

		if(!$o){

			return array("ok" => false, "error" => "La orden no existe.");
		}

		if($o["status_order"] === "Cancelado"){

			return array("ok" => true, "error" => "", "already" => true);
		}

		self::pdo()->prepare("UPDATE orders SET status_order = 'Cancelado', cancel_reason_order = ?, closed_at_order = ? WHERE id_order = ?")
			->execute(array(mb_substr(trim((string)$reason), 0, 255), date("Y-m-d H:i:s"), (int)$id));

		if(!empty($o["appointment_order"])){

			BizStore::setStatus($o["appointment_order"], "cancelada");
		}

		/* Toda cancelación queda en Inconvenientes (con el comentario del cliente si lo hay) */

		require_once __DIR__."/business.incidents.php";

		$who = array("panel" => "desde el panel", "cliente" => "por el cliente (WhatsApp)", "equipo" => "por el equipo (WhatsApp del número principal)");

		BizIncidents::record(array(
			"phone" => $o["contact_order"],
			"name" => $o["name_order"],
			"order" => $o["id_order"],
			"kind" => "cancelacion",
			"detail" => ($o["delivery_order"] === "instalacion" ? "Orden" : "Pedido")." #".$o["id_order"]." cancelad".($o["delivery_order"] === "instalacion" ? "a" : "o")." ".($who[$source] ?? $source).(trim((string)$reason) !== "" ? ". Motivo: ".trim($reason) : "").". ".str_replace("\n", "; ", self::itemsText(self::items($o))),
			"feedback" => $feedback,
			"source" => $source
		));

		self::notifyOwner($id, "cancelada");

		$client = null;

		if($tellClient){

			$client = self::notifyClient($id);
		}

		return array("ok" => true, "error" => "", "client" => $client);

	}

	/*=============================================
	Reprogramar la instalación (o agendarla si no tenía)
	=============================================*/

	static public function reschedule($id, $date, $time, $tellClient = false){

		$o = self::find($id);

		if(!$o || $o["status_order"] === "Cancelado"){

			return array("ok" => false, "error" => "La orden no existe o está cancelada.");
		}

		$a = !empty($o["appointment_order"]) ? BizStore::appointment($o["appointment_order"]) : null;

		$data = array(
			"date" => $date,
			"time" => $time,
			"phone" => $o["contact_order"],
			"name" => $a["name_appointment"] ?? $o["name_order"],
			"service" => $a["service_appointment"] ?? ($o["delivery_order"] === "instalacion" ? "Instalación" : "Entrega"),
			"summary" => $a["summary_appointment"] ?? ("Orden #".$o["id_order"]),
			"source" => "panel",
			"force" => true
		);

		if($a && $a["status_appointment"] !== "cancelada"){

			$result = BizStore::book($data, (int)$a["id_appointment"]);

		}else{

			$result = BizStore::book($data);

			if($result["ok"]){

				self::linkAppointment($id, $result["id"]);
			}
		}

		if(!$result["ok"]){

			return array("ok" => false, "error" => $result["error"]);
		}

		BizStore::markNotified($result["id"]);

		/* Si ya se había cerrado sola, vuelve a quedar activa */

		self::pdo()->prepare("UPDATE orders SET status_order = 'Pendiente', closed_at_order = NULL WHERE id_order = ?")->execute(array((int)$id));
		self::pdo()->prepare("UPDATE appointments SET status_appointment = 'confirmada' WHERE id_appointment = ? AND status_appointment = 'atendida'")->execute(array((int)$result["id"]));

		self::notifyOwner($id, $a && $a["status_appointment"] !== "cancelada" ? "reprogramada" : "agendada");

		$client = null;

		if($tellClient){

			$client = self::notifyClient($id, "reprogramada");
		}

		return array("ok" => true, "error" => "", "client" => $client);

	}

	/*=============================================
	Cierre automático: pasada la fecha de instalación
	(o de la cita) la orden se completa sola. Las que
	no tienen fecha siguen activas hasta agendarlas.
	Pedidos a domicilio sin cita: al día siguiente.
	=============================================*/

	static public function autoClose(){

		$closed = 0;
		$now = time();

		$rows = self::pdo()->query("SELECT o.id_order, o.delivery_order, o.created_at_order, a.id_appointment, a.date_appointment, a.time_appointment, a.duration_appointment, a.status_appointment FROM orders o LEFT JOIN appointments a ON a.id_appointment = o.appointment_order WHERE o.status_order = 'Pendiente'")->fetchAll();

		foreach ($rows as $r) {

			$end = null;

			if(!empty($r["id_appointment"])){

				if($r["status_appointment"] === "cancelada"){

					continue;
				}

				$end = self::installEnd($r["date_appointment"], $r["time_appointment"], $r["duration_appointment"]);

			}else if($r["delivery_order"] !== "instalacion" && !empty($r["created_at_order"])){

				$end = strtotime($r["created_at_order"]) + 86400;
			}

			if($end && $end < $now){

				self::pdo()->prepare("UPDATE orders SET status_order = 'Completado', closed_at_order = ? WHERE id_order = ? AND status_order = 'Pendiente'")->execute(array(date("Y-m-d H:i:s"), (int)$r["id_order"]));

				if(!empty($r["id_appointment"])){

					self::pdo()->prepare("UPDATE appointments SET status_appointment = 'atendida' WHERE id_appointment = ? AND status_appointment <> 'atendida'")->execute(array((int)$r["id_appointment"]));
				}

				$closed++;
			}
		}

		return $closed;

	}

	/* Sin hora (todo el día): al terminar el día. Con hora: 2 h después como mínimo */

	static public function installEnd($date, $time, $duration = 0){

		$time = substr((string)$time, 0, 5);

		return $time === "00:00" || $time === ""
			? strtotime($date." 23:59:59")
			: strtotime($date." ".$time.":00") + max(7200, (int)$duration * 60);

	}

	static public function notifyClient($id, $event = ""){

		$o = self::find($id);

		if(!$o || empty($o["contact_order"])){

			return array("ok" => false, "error" => "La orden no tiene número de cliente.");
		}

		$label = $o["delivery_order"] === "instalacion" ? "orden" : "pedido";

		if($event === "reprogramada" && !empty($o["appointment_order"]) && ($a = BizStore::appointment($o["appointment_order"]))){

			$time = substr($a["time_appointment"], 0, 5);
			$text = "🗓️ Tu ".($o["delivery_order"] === "instalacion" ? "instalación" : "cita")." (".$label." #".$o["id_order"].") quedó para el *".BizStore::dayName($a["date_appointment"])."*".($time !== "00:00" ? " a las *".BizStore::time12($time)."*" : "").". ¡Te esperamos! 🙌";

		}else if($o["status_order"] === "Cancelado"){

			$text = "❌ Tu ".$label." #".$o["id_order"]." fue cancelad".($label === "orden" ? "a" : "o").(!empty($o["cancel_reason_order"]) ? ": ".$o["cancel_reason_order"] : "").".\n\n🙏 Lamentamos el inconveniente. ¿Nos cuentas qué pasó o cómo podemos mejorar? Tu comentario nos ayuda mucho.";

		}else{

			$text = "🧾 Tu ".$label." #".$o["id_order"]." está registrada. Te avisaremos cualquier novedad. 🙌";
		}

		$api = ChatService::api();
		$result = ChatService::deliver($api, $o["contact_order"], ChatService::textPayload($o["contact_order"], $text), $text, '{"type":"pedido","title":""}');

		return array("ok" => $result["ok"], "error" => $result["error"]);

	}

}
