<?php

if(empty($_SESSION["biz_csrf"])){

	$_SESSION["biz_csrf"] = bin2hex(random_bytes(24));
}

$isSuper = ($_SESSION["admin"]->rol_admin ?? "") === "superadmin";
$hour = (int)date("G");
$greeting = $hour < 12 ? "Buenos días" : ($hour < 19 ? "Buenas tardes" : "Buenas noches");

?>

<div class="page-wrap dx">

	<!-- ================= CABECERA ================= -->

	<div class="dx-hero">
		<div class="dx-hero-text">
			<span class="dx-eyebrow"><i class="bi bi-speedometer2"></i> Inicio · <span id="dashRange">…</span></span>
			<h1><?php echo htmlspecialchars($greeting) ?> 👋</h1>
			<p>Así van tus conversaciones, órdenes y el consumo de la IA y de WhatsApp.</p>
		</div>
		<div class="dx-hero-tools">
			<div class="dx-period" role="group" aria-label="Periodo" id="dashDays">
				<button type="button" data-days="7">7 días</button>
				<button type="button" data-days="14" class="on">14 días</button>
				<button type="button" data-days="30">30 días</button>
				<button type="button" data-days="90">90 días</button>
			</div>
			<button class="btn btn-ghost btn-sm" id="dashReload" title="Actualizar"><i class="bi bi-arrow-clockwise"></i><span class="d-none d-sm-inline"> Actualizar</span></button>
			<?php if ($isSuper): ?>
				<a class="btn btn-ghost btn-sm" href="/setup/#limits" title="Límites"><i class="bi bi-sliders"></i><span class="d-none d-sm-inline"> Límites</span></a>
			<?php endif ?>
		</div>
	</div>

	<div id="dashAlerts"></div>

	<!-- ================= INDICADORES PRINCIPALES ================= -->

	<div class="dx-kpis">

		<article class="dx-kpi">
			<div class="dx-kpi-top"><span class="dx-ico c1"><i class="bi bi-chat-dots"></i></span><small>Conversaciones</small></div>
			<strong data-k="conversations">–</strong>
			<div class="dx-kpi-foot"><span class="dx-delta" data-d="conversations"></span><span class="sub" data-k="conversations_sub"></span></div>
			<svg class="dx-spark" data-spark="received" viewBox="0 0 120 32" preserveAspectRatio="none" aria-hidden="true"></svg>
		</article>

		<article class="dx-kpi">
			<div class="dx-kpi-top"><span class="dx-ico c2"><i class="bi bi-bag-check"></i></span><small>Órdenes generadas</small></div>
			<strong data-k="orders">–</strong>
			<div class="dx-kpi-foot"><span class="dx-delta" data-d="orders"></span><span class="sub" data-k="orders_sub"></span></div>
			<svg class="dx-spark" data-spark="orders" viewBox="0 0 120 32" preserveAspectRatio="none" aria-hidden="true"></svg>
		</article>

		<article class="dx-kpi">
			<div class="dx-kpi-top"><span class="dx-ico c3"><i class="bi bi-reply-all"></i></span><small>Tasa de respuesta</small></div>
			<strong data-k="response">–</strong>
			<div class="dx-kpi-foot"><span class="dx-delta" data-d="response_rate"></span><span class="sub" data-k="response_sub"></span></div>
			<svg class="dx-spark" data-spark="answered" viewBox="0 0 120 32" preserveAspectRatio="none" aria-hidden="true"></svg>
		</article>

		<article class="dx-kpi">
			<div class="dx-kpi-top"><span class="dx-ico c4"><i class="bi bi-x-octagon"></i></span><small>Órdenes rechazadas</small></div>
			<strong data-k="cancelled">–</strong>
			<div class="dx-kpi-foot"><span class="dx-delta" data-d="cancelled" data-inverse="1"></span><span class="sub" data-k="cancelled_sub"></span></div>
			<svg class="dx-spark" data-spark="cancelled" viewBox="0 0 120 32" preserveAspectRatio="none" aria-hidden="true"></svg>
		</article>

	</div>

	<!-- ================= ACTIVIDAD ================= -->

	<div class="dx-grid dx-8-4">

		<section class="chart-card">
			<header>
				<div><h3>Mensajes por día</h3><p>Recibidos de los clientes, cuántos se respondieron y los enviados por el negocio. El día de hoy va punteado porque aún no termina.</p></div>
			</header>
			<div class="chart-box tall"><canvas id="chMessages" role="img" aria-label="Mensajes por día"></canvas></div>
			<details class="chart-table" data-table="messages"><summary>Ver datos</summary><div></div></details>
		</section>

		<section class="chart-card">
			<header><div><h3>Resultado de las conversaciones</h3><p>Cada cliente por día.</p></div></header>
			<div class="dx-split" id="dashSplit"></div>
			<div class="chart-box short"><canvas id="chOutcome" role="img" aria-label="Resultado de las conversaciones por día"></canvas></div>
			<details class="chart-table" data-table="outcome"><summary>Ver datos</summary><div></div></details>
		</section>

	</div>

	<div class="dx-grid dx-8-4">

		<section class="chart-card">
			<header><div><h3>Órdenes por día</h3><p>Generadas y rechazadas (canceladas).</p></div><a class="dx-link" href="/ordenes">Ver órdenes <i class="bi bi-arrow-right"></i></a></header>
			<div class="chart-box"><canvas id="chOrders" role="img" aria-label="Órdenes por día"></canvas></div>
			<details class="chart-table" data-table="orders"><summary>Ver datos</summary><div></div></details>
		</section>

		<section class="chart-card">
			<header><div><h3>Próximas instalaciones</h3><p>Órdenes activas con fecha agendada.</p></div><a class="dx-link" href="/agenda">Agenda <i class="bi bi-arrow-right"></i></a></header>
			<ul class="dx-agenda" id="dashUpcoming"><li class="dx-empty">Cargando…</li></ul>
		</section>

	</div>

	<!-- ================= CONSUMO ================= -->

	<div class="dx-section">
		<h2><i class="bi bi-cpu"></i> Consumo y límites</h2>
		<span>Este mes · los costos son estimados según los precios de Configuración</span>
	</div>

	<div class="dx-usage">

		<article class="dx-use">
			<span class="dx-ico c1"><i class="bi bi-stars"></i></span>
			<div>
				<small>Tokens de IA · hoy</small>
				<strong data-k="ai_today">–</strong>
				<span class="sub" data-k="ai_today_sub"></span>
				<div class="meter d-none" data-meter="ai_day"><i></i></div>
			</div>
		</article>

		<article class="dx-use">
			<span class="dx-ico c1"><i class="bi bi-calendar3"></i></span>
			<div>
				<small>Tokens de IA · mes</small>
				<strong data-k="ai_month">–</strong>
				<span class="sub" data-k="ai_month_sub"></span>
				<div class="meter d-none" data-meter="ai_month"><i></i></div>
			</div>
		</article>

		<article class="dx-use">
			<span class="dx-ico c3"><i class="bi bi-whatsapp"></i></span>
			<div>
				<small>Mensajes de WhatsApp · mes</small>
				<strong data-k="wa_month">–</strong>
				<span class="sub" data-k="wa_month_sub"></span>
			</div>
		</article>

		<article class="dx-use">
			<span class="dx-ico c2"><i class="bi bi-cash-coin"></i></span>
			<div>
				<small>Gasto estimado · mes</small>
				<strong data-k="cost_month">–</strong>
				<span class="sub" data-k="cost_month_sub"></span>
			</div>
		</article>

		<article class="dx-use">
			<span class="dx-ico c5"><i class="bi bi-speedometer"></i></span>
			<div>
				<small>Envíos en el último minuto</small>
				<strong data-k="rate">–</strong>
				<span class="sub" data-k="rate_sub"></span>
				<div class="meter" data-meter="rate"><i></i></div>
			</div>
		</article>

	</div>

	<div class="dx-grid dx-6-6">

		<section class="chart-card">
			<header><div><h3>Tokens de IA por día</h3><p>Entrada (instrucciones, catálogo e historial) y salida (respuestas).</p></div></header>
			<div class="chart-box"><canvas id="chTokens" role="img" aria-label="Tokens de IA por día"></canvas></div>
			<details class="chart-table" data-table="tokens"><summary>Ver datos</summary><div></div></details>
		</section>

		<section class="chart-card">
			<header><div><h3>API de WhatsApp por día</h3><p>Sesión (gratis dentro de las 24 h), plantillas (con costo) y fallidos.</p></div></header>
			<div class="chart-box"><canvas id="chWhatsapp" role="img" aria-label="Mensajes de la API de WhatsApp por día"></canvas></div>
			<details class="chart-table" data-table="whatsapp"><summary>Ver datos</summary><div></div></details>
		</section>

	</div>

</div>

<script>
	window.bizCsrf = <?php echo json_encode($_SESSION["biz_csrf"]) ?>;
	window.dashConfig = {super: <?php echo $isSuper ? "true" : "false" ?>};
</script>

<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
<script src="/views/assets/js/business/dashboard.js?v=2"></script>
