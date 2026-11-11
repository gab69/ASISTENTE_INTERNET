<?php

require_once __DIR__."/business.store.php";

/*=============================================
Cobertura de delivery

Antes de pedir nombre, teléfono o pago, se verifica
si la dirección (o la ubicación 📍 que envía el
cliente por WhatsApp) está dentro de la zona de
reparto:
 - por distancia en línea recta desde el local, y/o
 - por distritos/zonas escritos en Mi negocio.

Para ubicar direcciones usa Google Maps (si hay
llave de Geocoding) o OpenStreetMap (gratis).
=============================================*/

class BizGeo{

	static public function settings(){

		return BizStore::get("delivery");

	}

	static public function enabled(){

		return !empty(self::settings()["enabled"]);

	}

	/*=============================================
	Utilidades
	=============================================*/

	static public function normalize($text){

		$text = mb_strtolower(trim((string)$text));
		$text = strtr($text, array("á" => "a", "é" => "e", "í" => "i", "ó" => "o", "ú" => "u", "ü" => "u", "ñ" => "n"));

		return preg_replace('/\s+/', " ", $text);

	}

	static public function lines($text){

		return array_values(array_filter(array_map(function($l){ return self::normalize($l); }, preg_split('/[\r\n,;]+/', (string)$text)), "strlen"));

	}

	static public function distance($lat1, $lng1, $lat2, $lng2){

		$r = 6371;
		$dLat = deg2rad($lat2 - $lat1);
		$dLng = deg2rad($lng2 - $lng1);
		$a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

		return $r * 2 * atan2(sqrt($a), sqrt(1 - $a));

	}

	/* Coordenadas dentro de un texto: enlaces de Maps o "-12.04, -77.03" */

	static public function coordsFromText($text){

		$text = (string)$text;

		$text = str_replace(array("%2C", "%2c", "%20", "+"), array(",", ",", " ", " "), $text);

		if(preg_match('/!3d(-?\d{1,2}\.\d+)!4d(-?\d{1,3}\.\d+)/', $text, $m)
		|| preg_match('/[?&](?:q|query|ll|destination|daddr)=(?:loc:)?(-?\d{1,2}\.\d+)\s*,\s*(-?\d{1,3}\.\d+)/', $text, $m)
		|| preg_match('#/(?:search|place|dir)/(?:[^/]*/)?(-?\d{1,2}\.\d+)\s*,\s*(-?\d{1,3}\.\d+)#', $text, $m)
		|| preg_match('/@(-?\d{1,2}\.\d+),(-?\d{1,3}\.\d+)/', $text, $m)
		|| preg_match('/(?<![\d.])(-?\d{1,2}\.\d{3,})\s*,\s*(-?\d{1,3}\.\d{3,})(?![\d.])/', $text, $m)){

			$lat = (float)$m[1];
			$lng = (float)$m[2];

			if(abs($lat) <= 90 && abs($lng) <= 180){

				return array($lat, $lng);
			}
		}

		return null;

	}

	/* Enlaces de mapas que manda el cliente (Google Maps, enlaces cortos, Waze) */

	static public function mapsLinks($text){

		preg_match_all('#https?://(?:www\.)?(?:maps\.app\.goo\.gl|goo\.gl/maps|maps\.google\.[a-z.]+|google\.[a-z.]+/maps|waze\.com|ul\.waze\.com)[^\s<>"\']*#iu', (string)$text, $m);

		return array_values(array_unique($m[0] ?? array()));

	}

	/* Los enlaces cortos (maps.app.goo.gl) no traen coordenadas: se sigue la redirección */

	static public function expandLinks($text){

		$text = (string)$text;

		foreach (self::mapsLinks($text) as $link) {

			if(!preg_match('#maps\.app\.goo\.gl|goo\.gl/maps#i', $link) || self::coordsFromText($link)){

				continue;
			}

			$curl = curl_init($link);

			curl_setopt_array($curl, array(
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_FOLLOWLOCATION => true,
				CURLOPT_MAXREDIRS => 6,
				CURLOPT_CONNECTTIMEOUT => 5,
				CURLOPT_TIMEOUT => 8,
				CURLOPT_NOBODY => true,
				CURLOPT_USERAGENT => "Mozilla/5.0"
			));

			curl_exec($curl);
			$final = (string)curl_getinfo($curl, CURLINFO_EFFECTIVE_URL);
			curl_close($curl);

			if($final !== "" && $final !== $link){

				$text .= " ".urldecode($final);
			}
		}

		return $text;

	}

	/* Planes y servicios que ofrece una sucursal (vacío = todo el catálogo) */

	static public function branchPlans($branchName){

		require_once __DIR__."/business.orders.php";

		$catalogue = BizOrders::catalogue();
		$b = trim((string)$branchName) !== "" ? self::findBranch($branchName) : null;
		$only = array_map(array(__CLASS__, "normalize"), array_filter((array)($b["plans"] ?? array()), "strlen"));

		if(empty($only)){

			return $catalogue;
		}

		return array_values(array_filter($catalogue, function($p) use ($only){ return in_array(self::normalize($p["name"]), $only, true); }));

	}

	static private function get($url, array $headers = array()){

		$curl = curl_init($url);

		curl_setopt_array($curl, array(
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_CONNECTTIMEOUT => 6,
			CURLOPT_TIMEOUT => 12,
			CURLOPT_HTTPHEADER => array_merge(array("Accept: application/json", "Accept-Language: es"), $headers),
			CURLOPT_USERAGENT => "ChatCenter/1.0 (+".cms_base_url().")"
		));

		$raw = curl_exec($curl);
		curl_close($curl);

		return $raw === false ? null : json_decode((string)$raw, true);

	}

	static private function countryCode(){

		$code = strtoupper((string)cms_config("currency_code", "PEN"));
		$map = array("PEN" => "pe", "COP" => "co", "MXN" => "mx", "CLP" => "cl", "ARS" => "ar", "BOB" => "bo", "USD" => "", "EUR" => "es");

		return $map[$code] ?? "";

	}

	/*=============================================
	Dirección → coordenadas
	Devuelve [lat, lng, label, district] o null
	=============================================*/

	static public function geocode($query){

		$query = trim((string)$query);

		if($query === ""){

			return null;
		}

		$s = self::settings();
		$hint = trim((string)($s["city_hint"] ?? ""));

		/* Primero con la ciudad por defecto; si no aparece, tal como la escribió el cliente */

		if($hint !== "" && mb_stripos($query, $hint) === false){

			$found = self::geocodeQuery($query.", ".$hint);

			if($found){

				return $found;
			}
		}

		return self::geocodeQuery($query);

	}

	/*=============================================
	Caché de direcciones (30 días): evita repetir
	búsquedas en el mapa y responde al instante
	=============================================*/

	static private function cacheFile(){

		return CMS_DIR."/config/geo_cache.json";

	}

	static private function cacheGet($key){

		static $cache = null;

		if($cache === null){

			$cache = is_file(self::cacheFile()) ? (json_decode((string)@file_get_contents(self::cacheFile()), true) ?: array()) : array();
		}

		$item = $cache[$key] ?? null;

		return ($item && ($item["t"] ?? 0) > time() - 2592000) ? $item : null;

	}

	static private function cachePut($key, $value){

		$cache = is_file(self::cacheFile()) ? (json_decode((string)@file_get_contents(self::cacheFile()), true) ?: array()) : array();

		if(count($cache) > 3000){

			$cache = array_slice($cache, -2000, null, true);
		}

		$cache[$key] = array("t" => time(), "v" => $value);

		@file_put_contents(self::cacheFile(), json_encode($cache, JSON_UNESCAPED_UNICODE), LOCK_EX);

	}

	static private function geocodeQuery($query){

		$key = md5(self::normalize($query));
		$hit = self::cacheGet($key);

		if($hit){

			return $hit["v"];
		}

		$found = self::geocodeRemote($query);

		/* Solo se guarda lo encontrado (lo no encontrado se reintenta) */

		if($found){

			self::cachePut($key, $found);
		}

		return $found;

	}

	static private function geocodeRemote($query){

		$s = self::settings();

		$country = self::countryCode();

		/* Google Maps (más preciso con direcciones locales) */

		if(trim((string)$s["maps_key"]) !== ""){

			$json = self::get("https://maps.googleapis.com/maps/api/geocode/json?".http_build_query(array(
				"address" => $query,
				"key" => trim($s["maps_key"]),
				"language" => "es",
				"region" => $country
			)));

			if(!empty($json["results"][0]["geometry"]["location"])){

				$r = $json["results"][0];
				$district = "";

				foreach ((array)$r["address_components"] as $c) {

					if(array_intersect($c["types"], array("sublocality", "sublocality_level_1", "locality", "administrative_area_level_3"))){

						$district .= " ".$c["long_name"];
					}
				}

				return array((float)$r["geometry"]["location"]["lat"], (float)$r["geometry"]["location"]["lng"], (string)$r["formatted_address"], trim($district));
			}

			if(!empty($json["error_message"])){

				error_log("Google Geocoding: ".$json["error_message"]);
			}
		}

		/* OpenStreetMap (gratis) */

		$params = array("format" => "jsonv2", "addressdetails" => 1, "limit" => 1, "q" => $query);

		if($country !== ""){

			$params["countrycodes"] = $country;
		}

		$json = self::get("https://nominatim.openstreetmap.org/search?".http_build_query($params));

		if(!empty($json[0]["lat"])){

			$a = (array)($json[0]["address"] ?? array());
			$district = implode(" ", array_filter(array($a["suburb"] ?? "", $a["city_district"] ?? "", $a["town"] ?? "", $a["city"] ?? "", $a["village"] ?? "")));

			return array((float)$json[0]["lat"], (float)$json[0]["lon"], (string)$json[0]["display_name"], $district);
		}

		return null;

	}

	/*=============================================
	Sucursales
	Cada una: {id, name, address, lat, lng, radius_km,
	zones, excluded, phone}. Si no hay sucursales se usa
	la configuración anterior (un solo local).
	=============================================*/

	/* Sucursales escritas en el formulario (sin guardar) para probarlas antes */

	static public $override = null;

	static public function branches(){

		$s = self::settings();
		$list = array();

		foreach ((array)(self::$override ?? ($s["branches"] ?? array())) as $b) {

			if(trim((string)($b["name"] ?? "")) !== "" || trim((string)($b["address"] ?? "")) !== ""){

				$list[] = $b;
			}
		}

		if(empty($list)){

			$list[] = array(
				"id" => "principal",
				"name" => "Principal",
				"address" => trim((string)($s["origin_address"] ?: (BizStore::get("company")["address"] ?? ""))),
				"lat" => $s["lat"],
				"lng" => $s["lng"],
				"radius_km" => $s["radius_km"],
				"zones" => $s["zones"],
				"excluded" => $s["excluded"],
				"phone" => ""
			);
		}

		return $list;

	}

	/* Coordenadas de una sucursal: solo latitud y longitud exactas.
	   (Antes se buscaba la dirección en el mapa y el punto podía quedar
	   a cientos de metros, o desactualizado si se cambiaba la dirección.) */

	static public function branchCoords(array $b){

		$lat = str_replace(",", ".", trim((string)($b["lat"] ?? "")));
		$lng = str_replace(",", ".", trim((string)($b["lng"] ?? "")));

		if(is_numeric($lat) && is_numeric($lng) && abs((float)$lat) <= 90 && abs((float)$lng) <= 180 && !((float)$lat == 0 && (float)$lng == 0)){

			return array((float)$lat, (float)$lng);
		}

		return null;

	}

	/* Huella de la configuración de cobertura: si cambia, hay que volver a confirmarla */

	static public function zonesHash($branches = null){

		$parts = array();

		foreach (($branches ?? self::branches()) as $b) {

			$parts[] = array(
				self::normalize($b["name"] ?? ""),
				round((float)($b["lat"] ?? 0), 6), round((float)($b["lng"] ?? 0), 6),
				(float)($b["radius_km"] ?? 0),
				self::lines($b["zones"] ?? ""), self::lines($b["excluded"] ?? "")
			);
		}

		return md5(json_encode($parts));

	}

	static public function zonesConfirmed(){

		$c = self::settings()["zones_confirmed"] ?? null;

		return is_array($c) && ($c["hash"] ?? "") === self::zonesHash() ? $c : null;

	}

	/* Compatibilidad: coordenadas de la primera sucursal */

	static public function origin(){

		$branches = self::branches();

		return self::branchCoords($branches[0]);

	}

	static public function findBranch($name){

		foreach (self::branches() as $b) {

			if(self::normalize($b["name"] ?? "") === self::normalize($name)){

				return $b;
			}
		}

		return null;

	}

	/*=============================================
	Costo: envío (delivery) o instalación (internet/TV)
	=============================================*/

	static public function installation(){

		return (BizStore::get("service")["mode"] ?? "delivery") === "installation";

	}

	static public function fee($distance){

		if(self::installation()){

			return round((float)BizStore::get("service")["install_fee"], 2);
		}

		$s = self::settings();
		$fee = (float)$s["fee"] + ($distance !== null ? (float)$s["fee_per_km"] * $distance : 0);

		/* Redondeo a 50 céntimos */
		return round(ceil($fee * 2) / 2, 2);

	}

	/*=============================================
	Verificar cobertura en TODAS las sucursales
	y quedarse con la más cercana que sí cubre.
	$coords: [lat, lng] si el cliente envió su ubicación.
	Devuelve "covered": si | no | desconocido, la
	sucursal elegida y la comparación de todas.
	=============================================*/

	static public function check($query, $phone = "", $coords = null){

		$query = trim((string)$query);
		$normalized = self::normalize($query);
		$label = $query;
		$district = "";

		$result = array("covered" => "desconocido", "distance" => null, "zone" => "", "fee" => null, "label" => $query, "lat" => null, "lng" => null, "reason" => "", "branch" => "", "branch_phone" => "", "compare" => array());

		/* 1) Ubicar la dirección una sola vez */

		if(!$coords){

			$coords = self::coordsFromText($query);
		}

		if($coords){

			$result["lat"] = $coords[0];
			$result["lng"] = $coords[1];
			$label = "la ubicación enviada (".round($coords[0], 5).", ".round($coords[1], 5).")";

		}else{

			$geo = self::geocode($query);

			if($geo){

				$result["lat"] = $geo[0];
				$result["lng"] = $geo[1];
				$label = $geo[2];
				$district = self::normalize($geo[3]);
			}
		}

		$result["label"] = $label;
		$haystack = $normalized." ".self::normalize($label)." ".$district;

		/* 2) Evaluar cada sucursal */

		$evaluated = array();

		foreach (self::branches() as $b) {

			$e = array("name" => (string)($b["name"] ?: "Principal"), "phone" => biz_clean_phone($b["phone"] ?? ""), "covered" => "desconocido", "distance" => null, "zone" => "", "reason" => "", "radius" => (float)($b["radius_km"] ?? 0));

			$excluded = false;

			foreach (self::lines($b["excluded"] ?? "") as $zone) {

				if($zone !== "" && strpos($haystack, $zone) !== false){

					$e["covered"] = "no";
					$e["zone"] = $zone;
					$e["reason"] = "la zona «".$zone."» no tiene cobertura";
					$excluded = true;
				}
			}

			$origin = self::branchCoords($b);

			if($origin && $result["lat"] !== null){

				$e["distance"] = round(self::distance($origin[0], $origin[1], $result["lat"], $result["lng"]), 2);
			}

			if(!$excluded){

				foreach (self::lines($b["zones"] ?? "") as $zone) {

					if($zone !== "" && strpos($haystack, $zone) !== false){

						$e["covered"] = "si";
						$e["zone"] = $zone;
					}
				}

				if($e["covered"] !== "si" && $e["radius"] > 0 && $e["distance"] !== null){

					$e["covered"] = $e["distance"] <= $e["radius"] ? "si" : "no";

					if($e["covered"] === "no"){

						$e["reason"] = "está a ".number_format($e["distance"], 1)." km y cubre hasta ".rtrim(rtrim(number_format($e["radius"], 1), "0"), ".")." km";
					}
				}

				if($e["covered"] === "desconocido" && $result["lat"] !== null && ($e["radius"] <= 0 || $e["distance"] === null) && !empty(self::lines($b["zones"] ?? ""))){

					$e["covered"] = "no";
					$e["reason"] = "la dirección no está en sus distritos";
				}
			}

			$evaluated[] = $e;
		}

		/* 3) La más cercana con cobertura; si ninguna cubre, la más cercana */

		$byDistance = function($a, $b){

			$da = $a["distance"] === null ? INF : $a["distance"];
			$db = $b["distance"] === null ? INF : $b["distance"];

			return $da <=> $db;
		};

		usort($evaluated, $byDistance);

		$covering = array_values(array_filter($evaluated, function($e){ return $e["covered"] === "si"; }));
		$result["compare"] = $evaluated;

		if(!empty($covering)){

			$best = $covering[0];
			$result["covered"] = "si";

		}else{

			$best = $evaluated[0] ?? null;
			$known = array_filter($evaluated, function($e){ return $e["covered"] === "no"; });
			$result["covered"] = ($result["lat"] === null && empty($known)) ? "desconocido" : "no";
		}

		if($best){

			$result["branch"] = $best["name"];
			$result["branch_phone"] = $best["phone"];
			$result["distance"] = $best["distance"];
			$result["zone"] = $best["zone"];
		}

		if($result["covered"] === "no"){

			$result["reason"] = $best && $best["reason"] !== ""
				? (count($evaluated) > 1 ? "La sucursal más cercana (".$best["name"].") ".$best["reason"]."." : ucfirst($best["reason"]).".")
				: "Ninguna sucursal cubre esa dirección.";
		}

		if($result["covered"] === "desconocido"){

			$result["reason"] = "No se encontró la dirección en el mapa.";
		}

		if($result["covered"] === "si"){

			$result["fee"] = self::fee($result["distance"]);
		}

		return self::save($phone, $query, $result);

	}

	static private function save($phone, $query, array $result){

		try{

			$stmt = BizStore::pdo()->prepare("INSERT INTO coverage_checks (phone_check, query_check, label_check, lat_check, lng_check, distance_check, covered_check, zone_check, fee_check, branch_check, date_check) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
			$stmt->execute(array(biz_clean_phone($phone), mb_substr($query, 0, 1000), mb_substr((string)$result["label"], 0, 1000), $result["lat"], $result["lng"], $result["distance"], $result["covered"], mb_substr((string)$result["zone"], 0, 191), $result["fee"], mb_substr((string)$result["branch"], 0, 120), date("Y-m-d H:i:s")));

		}catch(Throwable $e){

			error_log("Cobertura: ".$e->getMessage());
		}

		return $result;

	}

	/* Última verificación de un cliente (últimas 12 h) */

	static public function lastFor($phone){

		try{

			$stmt = BizStore::pdo()->prepare("SELECT * FROM coverage_checks WHERE phone_check = ? AND date_check >= ? ORDER BY id_check DESC LIMIT 1");
			$stmt->execute(array(biz_clean_phone($phone), date("Y-m-d H:i:s", time() - 43200)));

			return $stmt->fetch() ?: null;

		}catch(Throwable $e){

			return null;
		}

	}

	/*=============================================
	Texto del resultado para la IA y para el cliente
	=============================================*/

	static public function describe(array $r){

		$where = trim((string)$r["label"]) !== "" ? "«".$r["label"]."»" : "la dirección";
		$multi = count(self::branches()) > 1;
		$cost = self::installation() ? "Costo de instalación" : "Costo de envío";

		if($r["covered"] === "si"){

			$details = array();

			if($multi && ($r["branch"] ?? "") !== ""){ $details[] = "la sucursal más cercana con cobertura es ".$r["branch"]; }
			if($r["distance"] !== null){ $details[] = "a ".number_format((float)$r["distance"], 1)." km"; }
			if(($r["zone"] ?? "") !== ""){ $details[] = "zona ".$r["zone"]; }

			$text = "SÍ HAY COBERTURA para ".$where.(!empty($details) ? " (".implode(", ", $details).")" : "");
			$text .= ". ".$cost.": ".((float)$r["fee"] > 0 ? cms_money($r["fee"]) : "gratis").".";

			/* Lo que se vende en esa zona (según la sucursal que atiende) */

			$plans = self::branchPlans($r["branch"] ?? "");

			if(!empty($plans)){

				$text .= " PLANES Y SERVICIOS DISPONIBLES EN ESTA ZONA (muestra solo estos, con su precio mensual): ".implode("; ", array_map(function($p){ return $p["name"]." ".cms_money($p["price"]); }, $plans)).".";
			}

			return $text;
		}

		if($r["covered"] === "no"){

			return "NO HAY COBERTURA para ".$where.". ".$r["reason"];
		}

		return "NO SE PUDO VERIFICAR ".$where.". ".$r["reason"]." Pide una referencia más precisa (calle y número, distrito) o que comparta su ubicación de WhatsApp (📎 → Ubicación).";

	}

	static public function describeRow(array $row){

		return self::describe(array(
			"covered" => $row["covered_check"],
			"distance" => $row["distance_check"],
			"zone" => (string)$row["zone_check"],
			"fee" => $row["fee_check"],
			"label" => (string)($row["label_check"] ?: $row["query_check"]),
			"branch" => (string)($row["branch_check"] ?? ""),
			"reason" => ""
		));

	}

}
