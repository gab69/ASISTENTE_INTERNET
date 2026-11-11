<?php

require_once __DIR__."/business.store.php";

/*=============================================
Inconvenientes de la atención

Todo lo que sale mal durante la atención queda
registrado en la tabla `incidents` y se ve en la
página Inconvenientes:
 - Cancelaciones (por el cliente, el equipo o el panel)
 - Reclamos, demoras, quejas y comentarios del cliente
   (la IA los registra con [[INCONVENIENTE|tipo|resumen]])
 - Fallas del sistema (la IA no pudo responder…)

Cuando hay una cancelación o un problema, la IA le
pide al cliente su comentario; lo que responda se
guarda como "retroalimentación" del mismo registro.
=============================================*/

class BizIncidents{

	const KINDS = array(
		"cancelacion" => "Cancelación",
		"reclamo"     => "Reclamo",
		"demora"      => "Demora",
		"error"       => "Error en la atención",
		"sistema"     => "Falla del sistema",
		"sugerencia"  => "Sugerencia",
		"otro"        => "Otro"
	);

	const STATUSES = array("abierto", "en_proceso", "resuelto");

	/* Horas en las que la IA sigue esperando el comentario del cliente */
	const FEEDBACK_HOURS = 72;

	static private function pdo(){

		return BizStore::pdo();

	}

	static public function kind($value){

		$value = mb_strtolower(trim((string)$value));
		$value = strtr($value, array("á" => "a", "é" => "e", "í" => "i", "ó" => "o", "ú" => "u"));

		if(isset(self::KINDS[$value])){

			return $value;
		}

		$map = array(
			"cancel" => "cancelacion", "anul" => "cancelacion",
			"reclam" => "reclamo", "queja" => "reclamo", "molest" => "reclamo", "mal" => "reclamo",
			"demor" => "demora", "tard" => "demora", "retras" => "demora", "espera" => "demora",
			"error" => "error", "equivoc" => "error", "incorrect" => "error",
			"sistema" => "sistema", "falla" => "sistema",
			"sugeren" => "sugerencia", "recomend" => "sugerencia", "comentario" => "sugerencia", "feedback" => "sugerencia"
		);

		foreach ($map as $needle => $kind) {

			if(strpos($value, $needle) !== false){

				return $kind;
			}
		}

		return "otro";

	}

	/*=============================================
	Registrar
	=============================================*/

	static public function record(array $d){

		try{

			$phone = biz_clean_phone($d["phone"] ?? "");
			$kind = self::kind($d["kind"] ?? "otro");
			$detail = trim(mb_substr(preg_replace('/\s+/u', ' ', (string)($d["detail"] ?? "")), 0, 2000));
			$feedback = trim(mb_substr((string)($d["feedback"] ?? ""), 0, 2000));
			$order = (int)($d["order"] ?? 0) ?: null;

			if($detail === "" && $feedback === ""){

				return null;
			}

			/* Evitar duplicados (la misma falla repetida en poco tiempo) */

			$dup = self::pdo()->prepare("SELECT id_incident FROM incidents WHERE phone_incident = ? AND kind_incident = ? AND detail_incident = ? AND created_incident >= ? LIMIT 1");
			$dup->execute(array($phone, $kind, $detail, date("Y-m-d H:i:s", time() - (int)($d["dedupe"] ?? 600))));

			if($id = (int)$dup->fetchColumn()){

				return $id;
			}

			self::pdo()->prepare("INSERT INTO incidents (phone_incident, name_incident, order_incident, kind_incident, detail_incident, feedback_incident, status_incident, source_incident, created_incident, updated_incident) VALUES (?, ?, ?, ?, ?, ?, 'abierto', ?, ?, ?)")
				->execute(array($phone, mb_substr((string)($d["name"] ?? ""), 0, 191), $order, $kind, $detail, $feedback, mb_substr((string)($d["source"] ?? "sistema"), 0, 20), date("Y-m-d H:i:s"), date("Y-m-d H:i:s")));

			return (int)self::pdo()->lastInsertId();

		}catch(Throwable $e){

			/* Tabla aún no creada: nunca detener la atención */
			error_log("Inconvenientes: ".$e->getMessage());

			return null;
		}

	}

	/* Comentario del cliente: completa el último inconveniente que esperaba
	   su opinión o, si no hay ninguno, crea uno nuevo */

	static public function feedback($phone, $name, $kind, $text, $order = null){

		$phone = biz_clean_phone($phone);
		$text = trim((string)$text);

		if($text === ""){

			return null;
		}

		try{

			$stmt = self::pdo()->prepare("SELECT id_incident FROM incidents WHERE phone_incident = ? AND kind_incident <> 'sistema' AND (feedback_incident IS NULL OR feedback_incident = '') AND created_incident >= ? ORDER BY id_incident DESC LIMIT 1");
			$stmt->execute(array($phone, date("Y-m-d H:i:s", time() - self::FEEDBACK_HOURS * 3600)));

			if($id = (int)$stmt->fetchColumn()){

				self::pdo()->prepare("UPDATE incidents SET feedback_incident = ?, updated_incident = ? WHERE id_incident = ?")
					->execute(array(mb_substr($text, 0, 2000), date("Y-m-d H:i:s"), $id));

				return $id;
			}

		}catch(Throwable $e){

			error_log("Inconvenientes: ".$e->getMessage());
			return null;
		}

		$id = self::record(array("phone" => $phone, "name" => $name, "kind" => $kind, "detail" => $text, "feedback" => $text, "order" => $order, "source" => "cliente"));

		/* Un reclamo nuevo se avisa al equipo */

		if($id && in_array(self::kind($kind), array("reclamo", "demora", "error"), true)){

			try{

				require_once __DIR__."/business.ops.php";

				Notify::send("⚠️ *Nuevo inconveniente #".$id."* (".self::KINDS[self::kind($kind)].")\n\n👤 ".($name ?: "Cliente")." · +".$phone."\n💬 ".mb_substr($text, 0, 500)."\n\nVer: ".cms_base_url()."inconvenientes", "", "aviso", "cancel");

			}catch(Throwable $e){

				error_log("Inconvenientes: no se pudo avisar: ".$e->getMessage());
			}
		}

		return $id;

	}

	/* Inconvenientes del cliente que aún esperan su comentario */

	static public function awaiting($phone){

		try{

			$stmt = self::pdo()->prepare("SELECT id_incident, kind_incident, detail_incident, order_incident FROM incidents WHERE phone_incident = ? AND kind_incident <> 'sistema' AND (feedback_incident IS NULL OR feedback_incident = '') AND created_incident >= ? ORDER BY id_incident DESC LIMIT 3");
			$stmt->execute(array(biz_clean_phone($phone), date("Y-m-d H:i:s", time() - self::FEEDBACK_HOURS * 3600)));

			return $stmt->fetchAll();

		}catch(Throwable $e){

			return array();
		}

	}

	/*=============================================
	Texto para el prompt de la IA (atención al cliente)
	=============================================*/

	static public function context($phone){

		$lines = array();
		$lines[] = "";
		$lines[] = "RETROALIMENTACIÓN E INCONVENIENTES (OBLIGATORIO):";
		$lines[] = "- Si el cliente cancela, se queja, reporta un problema (demora, cobro o dato incorrecto, mala atención, algo que no funcionó) o se muestra molesto: discúlpate con empatía y pídele en una línea que te cuente qué pasó o el motivo, porque nos ayuda a mejorar (no insistas si no quiere).";
		$lines[] = "- Cuando el cliente cuente el problema o dé su comentario u opinión sobre la atención, agrega UNA vez la etiqueta [[INCONVENIENTE|tipo|resumen claro de lo que pasó y lo que dijo el cliente]]. Tipos: cancelacion, reclamo, demora, error, sugerencia, otro. Agradécele el comentario.";

		$waiting = self::awaiting($phone);

		if(!empty($waiting)){

			$items = array();

			foreach ($waiting as $w) {

				$items[] = (self::KINDS[$w["kind_incident"]] ?? "Inconveniente").($w["order_incident"] ? " de la orden #".$w["order_incident"] : "").": ".mb_substr((string)$w["detail_incident"], 0, 120);
			}

			$lines[] = "- Este cliente tiene un inconveniente reciente SIN su comentario (".implode(" / ", $items)."). Si en su mensaje explica el motivo o da su opinión, regístralo con [[INCONVENIENTE|tipo|resumen]]. Si aún no se lo pediste, pídeselo una sola vez con amabilidad.";
		}

		return implode("\n", $lines);

	}

	/*=============================================
	Panel
	=============================================*/

	static public function listAll($status = "", $kind = "", $from = "", $search = "", $limit = 500){

		$sql = "SELECT * FROM incidents WHERE 1";
		$args = array();

		if(in_array($status, self::STATUSES, true)){

			$sql .= " AND status_incident = ?";
			$args[] = $status;
		}

		if(isset(self::KINDS[$kind])){

			$sql .= " AND kind_incident = ?";
			$args[] = $kind;
		}

		if($from !== "" && BizStore::validDate($from)){

			$sql .= " AND created_incident >= ?";
			$args[] = $from." 00:00:00";
		}

		if(trim($search) !== ""){

			$like = "%".trim($search)."%";
			$sql .= " AND (name_incident LIKE ? OR phone_incident LIKE ? OR detail_incident LIKE ? OR feedback_incident LIKE ? OR note_incident LIKE ? OR order_incident = ? OR id_incident = ?)";
			$num = (int)preg_replace('/\D/', '', $search);
			array_push($args, $like, $like, $like, $like, $like, $num, $num);
		}

		$sql .= " ORDER BY (status_incident = 'resuelto') ASC, id_incident DESC LIMIT ".max(1, min(2000, (int)$limit));

		$stmt = self::pdo()->prepare($sql);
		$stmt->execute($args);

		return $stmt->fetchAll();

	}

	static public function stats(){

		$out = array("abierto" => 0, "en_proceso" => 0, "resuelto" => 0, "week" => 0, "kinds" => array());

		foreach (self::pdo()->query("SELECT status_incident s, COUNT(*) n FROM incidents GROUP BY status_incident")->fetchAll() as $r) {

			$out[$r["s"]] = (int)$r["n"];
		}

		$stmt = self::pdo()->prepare("SELECT kind_incident k, COUNT(*) n FROM incidents WHERE created_incident >= ? GROUP BY kind_incident ORDER BY n DESC");
		$stmt->execute(array(date("Y-m-d 00:00:00", strtotime("-29 days"))));

		foreach ($stmt->fetchAll() as $r) {

			$out["kinds"][$r["k"]] = (int)$r["n"];
		}

		$week = self::pdo()->prepare("SELECT COUNT(*) FROM incidents WHERE created_incident >= ?");
		$week->execute(array(date("Y-m-d 00:00:00", strtotime("-6 days"))));
		$out["week"] = (int)$week->fetchColumn();

		return $out;

	}

	static public function update($id, $status, $note){

		if(!in_array($status, self::STATUSES, true)){

			return false;
		}

		return self::pdo()->prepare("UPDATE incidents SET status_incident = ?, note_incident = ?, resolved_incident = IF(? = 'resuelto', COALESCE(resolved_incident, ?), NULL), updated_incident = ? WHERE id_incident = ?")
			->execute(array($status, mb_substr(trim((string)$note), 0, 2000), $status, date("Y-m-d H:i:s"), date("Y-m-d H:i:s"), (int)$id));

	}

	static public function delete($id){

		return self::pdo()->prepare("DELETE FROM incidents WHERE id_incident = ?")->execute(array((int)$id));

	}

	/* Resumen corto para el asistente del número principal */

	static public function openSummary($limit = 10){

		try{

			$stmt = self::pdo()->query("SELECT * FROM incidents WHERE status_incident <> 'resuelto' ORDER BY id_incident DESC LIMIT ".(int)$limit);
			$lines = array();

			foreach ($stmt->fetchAll() as $r) {

				$lines[] = "- Inconveniente #".$r["id_incident"]." · ".(self::KINDS[$r["kind_incident"]] ?? "Otro")." · ".date("d/m H:i", strtotime($r["created_incident"])).($r["order_incident"] ? " · orden #".$r["order_incident"] : "")." · ".($r["name_incident"] ?: "+".$r["phone_incident"]).": ".mb_substr((string)$r["detail_incident"], 0, 160).(trim((string)$r["feedback_incident"]) !== "" ? " | Comentario del cliente: ".mb_substr($r["feedback_incident"], 0, 200) : "");
			}

			return $lines;

		}catch(Throwable $e){

			return array();
		}

	}

}
