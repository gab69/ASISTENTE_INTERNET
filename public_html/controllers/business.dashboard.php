<?php

require_once __DIR__."/business.ops.php";

/*=============================================
Inicio: consumo (IA y WhatsApp) y actividad
(mensajes, órdenes y resultado de las conversaciones)
=============================================*/

class BizDashboard{

	static public function build($days = 14){

		$days = in_array((int)$days, array(7, 14, 30, 90), true) ? (int)$days : 14;
		$pdo = BizStore::pdo();
		/* Se calcula el doble del periodo: la primera mitad es el periodo anterior (para comparar) */

		$fromDay = date("Y-m-d", strtotime("-".($days * 2 - 1)." days"));
		$from = $fromDay." 00:00:00";

		$all = array();

		for($i = $days * 2 - 1; $i >= 0; $i--){

			$all[] = date("Y-m-d", strtotime("-".$i." days"));
		}

		$labels = array_slice($all, $days);
		$zero = array_fill_keys($all, 0);
		$staff = array_flip(Notify::staffPhones());

		/*---------------------------------------------
		Mensajes: recibidos, respondidos y enviados
		---------------------------------------------*/

		$received = $answered = $sent = $failed = $zero;
		$talks = array();

		$stmt = $pdo->prepare("SELECT phone_message p, type_message t, status_message s, DATE(date_updated_message) d FROM messages WHERE date_updated_message >= ? ORDER BY phone_message, id_message");
		$stmt->execute(array($from));

		$pending = array();
		$lastPhone = null;

		while($m = $stmt->fetch()){

			$phone = (string)$m["p"];
			$day = $m["d"];

			if(isset($staff[$phone]) || !isset($zero[$day])){

				continue;
			}

			if($phone !== $lastPhone){

				$pending = array();
				$lastPhone = $phone;
			}

			if($m["t"] === "client"){

				$received[$day]++;
				$pending[] = $day;
				$talks[$phone."|".$day] = true;

			}else{

				if($m["s"] === "failed"){

					$failed[$day]++;
					continue;
				}

				$sent[$day]++;

				/* Todos los mensajes del cliente de ese turno quedan respondidos */

				foreach ($pending as $d) {

					$answered[$d]++;
				}

				$pending = array();
			}
		}

		/*---------------------------------------------
		Órdenes generadas y rechazadas (canceladas)
		---------------------------------------------*/

		$created = $cancelled = $zero;
		$orderTalks = array();

		$stmt = $pdo->prepare("SELECT contact_order p, DATE(COALESCE(created_at_order, date_created_order)) d FROM orders WHERE COALESCE(created_at_order, date_created_order) >= ?");
		$stmt->execute(array($from));

		foreach ($stmt->fetchAll() as $o) {

			if(isset($zero[$o["d"]])){

				$created[$o["d"]]++;
				$orderTalks[$o["p"]."|".$o["d"]] = true;
			}
		}

		$stmt = $pdo->prepare("SELECT DATE(COALESCE(closed_at_order, created_at_order)) d, COUNT(*) n FROM orders WHERE status_order = 'Cancelado' AND COALESCE(closed_at_order, created_at_order) >= ? GROUP BY d");
		$stmt->execute(array($from));

		foreach ($stmt->fetchAll() as $r) {

			if(isset($zero[$r["d"]])){

				$cancelled[$r["d"]] = (int)$r["n"];
			}
		}

		/*---------------------------------------------
		Resultado de cada conversación del día:
		con orden, sin cobertura o solo consulta
		---------------------------------------------*/

		$noCoverage = array();

		try{

			$stmt = $pdo->prepare("SELECT DISTINCT phone_check p, DATE(date_check) d FROM coverage_checks WHERE covered_check = 'no' AND date_check >= ?");
			$stmt->execute(array($from));

			foreach ($stmt->fetchAll() as $r) {

				$noCoverage[$r["p"]."|".$r["d"]] = true;
			}

		}catch(Throwable $e){}

		$withOrder = $outside = $onlyAsk = $zero;

		foreach (array_keys($talks) as $key) {

			$day = substr($key, strrpos($key, "|") + 1);

			if(isset($orderTalks[$key])){

				$withOrder[$day]++;

			}else if(isset($noCoverage[$key])){

				$outside[$day]++;

			}else{

				$onlyAsk[$day]++;
			}
		}

		/*---------------------------------------------
		Consumo: tokens de IA y mensajes de la API
		---------------------------------------------*/

		$tokIn = $tokOut = $waText = $waTemplate = $waFailed = $zero;

		$stmt = $pdo->prepare("SELECT kind_usage k, detail_usage dt, ok_usage ok, DATE(created_usage) d, SUM(input_usage) i, SUM(output_usage) o, COUNT(*) n FROM usage_log WHERE created_usage >= ? GROUP BY k, dt, ok, d");
		$stmt->execute(array($from));

		foreach ($stmt->fetchAll() as $r) {

			$d = $r["d"];

			if(!isset($zero[$d])){

				continue;
			}

			if($r["k"] === "ai"){

				$tokIn[$d] += (int)$r["i"];
				$tokOut[$d] += (int)$r["o"];

			}else if(!(int)$r["ok"]){

				$waFailed[$d] += (int)$r["n"];

			}else if($r["dt"] === "template"){

				$waTemplate[$d] += (int)$r["n"];

			}else{

				$waText[$d] += (int)$r["n"];
			}
		}

		/*---------------------------------------------
		Indicadores de hoy y del mes, y límites
		---------------------------------------------*/

		$priceIn = (float)cms_config("ai_price_in", 0.15);
		$priceOut = (float)cms_config("ai_price_out", 0.60);
		$priceTpl = (float)cms_config("wa_price_template", 0.02);

		$period = function($since) use ($pdo, $priceIn, $priceOut, $priceTpl){

			$stmt = $pdo->prepare("SELECT kind_usage k, detail_usage dt, ok_usage ok, COALESCE(SUM(input_usage), 0) i, COALESCE(SUM(output_usage), 0) o, COUNT(*) n FROM usage_log WHERE created_usage >= ? GROUP BY k, dt, ok");
			$stmt->execute(array($since));

			$out = array("ai_in" => 0, "ai_out" => 0, "ai_calls" => 0, "wa_sent" => 0, "wa_templates" => 0, "wa_failed" => 0);

			foreach ($stmt->fetchAll() as $r) {

				if($r["k"] === "ai"){

					$out["ai_in"] += (int)$r["i"];
					$out["ai_out"] += (int)$r["o"];
					$out["ai_calls"] += (int)$r["n"];

				}else if(!(int)$r["ok"]){

					$out["wa_failed"] += (int)$r["n"];

				}else{

					$out["wa_sent"] += (int)$r["n"];

					if($r["dt"] === "template"){

						$out["wa_templates"] += (int)$r["n"];
					}
				}
			}

			$out["ai_tokens"] = $out["ai_in"] + $out["ai_out"];
			$out["ai_cost"] = round($out["ai_in"] / 1e6 * $priceIn + $out["ai_out"] / 1e6 * $priceOut, 4);
			$out["wa_cost"] = round($out["wa_templates"] * $priceTpl, 4);

			return $out;
		};

		$today = $period(date("Y-m-d 00:00:00"));
		$month = $period(date("Y-m-01 00:00:00"));

		$minute = $pdo->prepare("SELECT COUNT(*) FROM usage_log WHERE kind_usage = 'wa' AND created_usage >= ?");
		$minute->execute(array(date("Y-m-d H:i:s", time() - 60)));

		$cur = function($a) use ($days){ return array_values(array_slice($a, $days)); };

		$totals = function($half) use ($days, $received, $answered, $sent, $failed, $created, $cancelled, $withOrder, $onlyAsk, $outside, $tokIn, $tokOut){

			$pick = function($a) use ($half, $days){ return array_sum(array_slice($a, $half ? $days : 0, $days)); };
			$talks = $pick($withOrder) + $pick($onlyAsk) + $pick($outside);

			return array(
				"received" => $pick($received),
				"answered" => $pick($answered),
				"sent" => $pick($sent),
				"failed" => $pick($failed),
				"orders" => $pick($created),
				"cancelled" => $pick($cancelled),
				"conversations" => $talks,
				"with_order" => $pick($withOrder),
				"only_ask" => $pick($onlyAsk),
				"outside" => $pick($outside),
				"tokens" => $pick($tokIn) + $pick($tokOut),
				"response_rate" => $pick($received) ? round($pick($answered) / $pick($received) * 100) : null,
				"conversion" => $talks ? round($pick($withOrder) / $talks * 100) : null
			);
		};

		return array(
			"days" => $days,
			"labels" => $labels,
			"series" => array(
				"received" => $cur($received),
				"answered" => $cur($answered),
				"sent" => $cur($sent),
				"orders" => $cur($created),
				"cancelled" => $cur($cancelled),
				"with_order" => $cur($withOrder),
				"only_ask" => $cur($onlyAsk),
				"outside" => $cur($outside),
				"tok_in" => $cur($tokIn),
				"tok_out" => $cur($tokOut),
				"wa_text" => $cur($waText),
				"wa_template" => $cur($waTemplate),
				"wa_failed" => $cur($waFailed)
			),
			"totals" => $totals(true),
			"previous" => $totals(false),
			"upcoming" => self::upcoming(),
			"today" => $today,
			"month" => $month,
			"limits" => array(
				"ai_day" => (int)cms_config("limit_ai_tokens_day", 0),
				"ai_month" => (int)cms_config("limit_ai_tokens_month", 0),
				"send_min" => (int)cms_config("limit_send_per_min", 60),
				"client_min" => (int)cms_config("limit_client_per_min", 8),
				"sent_last_min" => (int)$minute->fetchColumn(),
				"blocked" => Limits::aiBlocked()
			),
			"prices" => array("in" => $priceIn, "out" => $priceOut, "template" => $priceTpl),
			"notify" => array(
				"recipients" => Notify::recipients(),
				"template" => Notify::template() !== ""
			),
			"followup" => (bool)cms_config("followup_enabled", true)
		);

	}

	/* Próximas instalaciones / citas de órdenes activas */

	static public function upcoming($limit = 6){

		try{

			$stmt = BizStore::pdo()->prepare("SELECT o.id_order id, o.name_order name, o.contact_order phone, o.branch_order branch, o.products_order products, a.date_appointment d, a.time_appointment t
				FROM orders o JOIN appointments a ON a.id_appointment = o.appointment_order
				WHERE o.status_order = 'Pendiente' AND a.status_appointment <> 'cancelada' AND a.date_appointment >= ?
				ORDER BY a.date_appointment, a.time_appointment LIMIT ".(int)$limit);
			$stmt->execute(array(date("Y-m-d")));

			return array_map(function($r){
				$first = trim(explode("\n", str_replace(";", "\n", (string)$r["products"]))[0]);
				return array(
					"id" => (int)$r["id"],
					"name" => (string)($r["name"] ?: "Sin nombre"),
					"phone" => (string)$r["phone"],
					"branch" => (string)$r["branch"],
					"plan" => preg_replace('/\s*-\s*S\/.*$/u', "", $first),
					"date" => $r["d"],
					"time" => substr((string)$r["t"], 0, 5)
				);
			}, $stmt->fetchAll());

		}catch(Throwable $e){

			return array();
		}

	}

}
