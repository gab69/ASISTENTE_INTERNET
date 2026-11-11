/*=============================================
Inicio: indicadores, actividad y consumo
Colores: paleta categórica validada (orden fijo:
azul, naranja, aqua). Un solo eje por gráfico.
El color sigue a la serie en todos los gráficos:
Recibidos/Generadas/Con orden = azul,
Respondidos/Rechazadas/Solo consulta = naranja.
=============================================*/

(function(){

	var SERIES = ["#2a78d6", "#eb6834", "#1baf7a"];
	var INK = "#52514e";
	var GRID = "rgba(15, 23, 42, .07)";

	var charts = {};
	var tables = {};
	var cfg = window.dashConfig || {};
	var days = 14;

	function esc(v){
		return String(v == null ? "" : v).replace(/[&<>"']/g, function(c){
			return {"&":"&amp;","<":"&lt;",">":"&gt;",'"':"&quot;","'":"&#39;"}[c];
		});
	}

	function n(v){ return Number(v || 0).toLocaleString("es-PE"); }

	function compact(v){

		v = Number(v || 0);

		if(v >= 1e6){ return (v / 1e6).toLocaleString("es-PE", {maximumFractionDigits: 2}) + " M"; }
		if(v >= 1e4){ return (v / 1e3).toLocaleString("es-PE", {maximumFractionDigits: 1}) + " mil"; }

		return n(v);
	}

	function usd(v){ return "US$ " + Number(v || 0).toLocaleString("en-US", {minimumFractionDigits: 2, maximumFractionDigits: 2}); }

	function day(d){

		var dt = new Date(d + "T00:00:00");
		return dt.toLocaleDateString("es-PE", {day: "2-digit", month: "short"}).replace(".", "");
	}

	function longDay(d){

		var dt = new Date(d + "T00:00:00");
		var today = new Date(); today.setHours(0, 0, 0, 0);
		var diff = Math.round((dt - today) / 86400000);

		if(diff === 0){ return "Hoy"; }
		if(diff === 1){ return "Mañana"; }

		var s = dt.toLocaleDateString("es-PE", {weekday: "short", day: "2-digit", month: "short"}).replace(/\./g, "");
		return s.charAt(0).toUpperCase() + s.slice(1);
	}

	function time12(t){

		if(!t || t === "00:00"){ return "Hora por confirmar"; }

		var p = t.split(":"), h = Number(p[0]);
		return (h % 12 || 12) + ":" + p[1] + (h < 12 ? " a. m." : " p. m.");
	}

	function set(key, html){

		var el = document.querySelector('[data-k="' + key + '"]');
		if(el){ el.innerHTML = html; }
	}

	function meter(key, value, limit){

		var el = document.querySelector('[data-meter="' + key + '"]');
		if(!el){ return; }

		if(!limit){ el.classList.add("d-none"); return; }

		var pct = Math.min(100, Math.round(value / limit * 100));

		el.classList.remove("d-none");
		el.classList.toggle("warn", pct >= 80 && pct < 100);
		el.classList.toggle("full", pct >= 100);
		el.querySelector("i").style.width = pct + "%";
		el.setAttribute("title", pct + "% del límite");
	}

	function request(fields){

		var body = new FormData();
		Object.keys(fields).forEach(function(k){ body.append(k, fields[k]); });

		return fetch("/ajax/business.ajax.php", {method: "POST", headers: {"X-CSRF-Token": window.bizCsrf}, body: body, credentials: "same-origin"})
			.then(function(r){ return r.json().catch(function(){ return {ok: false, error: "Respuesta inválida"}; }); })
			.catch(function(){ return {ok: false, error: "Sin conexión"}; });
	}

	/*=============================================
	Comparación con el periodo anterior
	(flecha + texto: nunca solo color)
	=============================================*/

	function delta(key, cur, prev, points){

		var el = document.querySelector('[data-d="' + key + '"]');
		if(!el){ return; }

		var inverse = el.getAttribute("data-inverse") === "1";

		if(cur == null || prev == null || (!points && !prev)){
			el.className = "dx-delta flat";
			el.innerHTML = prev === 0 && cur > 0 ? '<i class="bi bi-stars"></i> Nuevo' : "";
			el.title = "";
			return;
		}

		var diff = points ? cur - prev : Math.round((cur - prev) / prev * 100);
		var dir = diff > 0 ? "up" : (diff < 0 ? "down" : "flat");
		var good = dir === "flat" ? "" : ((dir === "up") !== inverse ? " good" : " bad");
		var icon = dir === "up" ? "bi-arrow-up-right" : (dir === "down" ? "bi-arrow-down-right" : "bi-dash");

		el.className = "dx-delta " + dir + good;
		el.innerHTML = '<i class="bi ' + icon + '"></i> ' + (diff > 0 ? "+" : "") + diff + (points ? " pts" : "%");
		el.title = "Periodo anterior: " + n(prev) + (points ? "%" : "");
	}

	/* Mini gráfico de tendencia (SVG) */

	function spark(key, values, color){

		var svg = document.querySelector('[data-spark="' + key + '"]');
		if(!svg || !values.length){ return; }

		var max = Math.max.apply(null, values.concat([1]));
		var step = values.length > 1 ? 120 / (values.length - 1) : 120;
		var pts = values.map(function(v, i){ return [i * step, 30 - (v / max) * 26]; });
		var line = pts.map(function(p, i){ return (i ? "L" : "M") + p[0].toFixed(1) + " " + p[1].toFixed(1); }).join(" ");

		svg.innerHTML = '<path d="' + line + ' L120 32 L0 32 Z" fill="' + color + '" opacity=".10"></path>' +
			'<path d="' + line + '" fill="none" stroke="' + color + '" stroke-width="2" stroke-linejoin="round" stroke-linecap="round" vector-effect="non-scaling-stroke"></path>';
	}

	/*=============================================
	Gráficos (Chart.js)
	=============================================*/

	function base(stacked, opts){

		opts = opts || {};

		return {
			responsive: true,
			maintainAspectRatio: false,
			animation: {duration: 250},
			interaction: {mode: "index", intersect: false},
			plugins: {
				legend: {display: opts.legend !== false, position: "top", align: "start", labels: {usePointStyle: true, pointStyle: "rectRounded", boxWidth: 10, boxHeight: 10, color: INK, font: {size: 12}, padding: 14}},
				tooltip: {
					backgroundColor: "#ffffff", titleColor: "#0b0b0b", bodyColor: INK, borderColor: "rgba(15,23,42,.12)", borderWidth: 1,
					padding: 10, boxPadding: 4, usePointStyle: true,
					callbacks: {label: function(c){ return " " + c.dataset.label + ": " + n(c.parsed.y); }}
				}
			},
			scales: {
				x: {stacked: stacked, grid: {display: false}, ticks: {color: INK, maxRotation: 0, autoSkipPadding: 14, font: {size: 11}}, border: {color: GRID}},
				y: {stacked: stacked, beginAtZero: true, grid: {color: GRID}, border: {display: false}, ticks: {color: INK, precision: 0, font: {size: 11}, maxTicksLimit: 6, callback: function(v){ return compact(v); }}}
			}
		};
	}

	function barSet(label, data, i){
		return {label: label, data: data, backgroundColor: SERIES[i], borderColor: "#ffffff", borderWidth: 0, borderRadius: 4, borderSkipped: "start", maxBarThickness: 22, categoryPercentage: .7, barPercentage: .9};
	}

	function stackSet(label, data, i, last){
		/* 2 px de separación entre segmentos apilados */
		var s = barSet(label, data, i);
		s.borderWidth = {top: 2, right: 0, bottom: 0, left: 0};
		s.borderRadius = last ? {topLeft: 4, topRight: 4} : 0;
		return s;
	}

	function lineSet(label, data, i){

		var last = data.length - 1;

		return {
			label: label, data: data, borderColor: SERIES[i], backgroundColor: SERIES[i], borderWidth: 2,
			pointRadius: 0, pointHoverRadius: 5, pointHoverBorderWidth: 2, pointHoverBorderColor: "#ffffff", tension: .3, fill: false,
			/* Hoy aún no termina: el último tramo va punteado */
			segment: {borderDash: function(ctx){ return ctx.p1DataIndex === last ? [5, 4] : undefined; }}
		};
	}

	function draw(id, type, labels, datasets, stacked, opts){

		if(!window.Chart){ return; }

		if(charts[id]){ charts[id].destroy(); }

		charts[id] = new Chart(document.getElementById(id), {type: type, data: {labels: labels, datasets: datasets}, options: base(stacked, opts)});
	}

	/* Tabla con los mismos datos (accesible y para copiar) */

	function table(key, labels, cols){

		tables[key] = '<div class="table-responsive"><table class="table table-sm"><thead><tr><th>Día</th>' + cols.map(function(c){ return "<th class=\"text-end\">" + esc(c[0]) + "</th>"; }).join("") + '</tr></thead><tbody>' +
			labels.map(function(l, i){ return "<tr><td>" + esc(l) + "</td>" + cols.map(function(c){ return '<td class="text-end">' + n(c[1][i]) + "</td>"; }).join("") + "</tr>"; }).reverse().join("") +
			"</tbody></table></div>";

		var box = document.querySelector('[data-table="' + key + '"]');

		if(box && box.open){ box.querySelector("div").innerHTML = tables[key]; }
	}

	document.querySelectorAll(".chart-table").forEach(function(d){
		d.addEventListener("toggle", function(){
			if(d.open){ d.querySelector("div").innerHTML = tables[d.getAttribute("data-table")] || ""; }
		});
	});

	/*=============================================
	Pintar
	=============================================*/

	function alerts(d){

		var out = [];

		if(d.limits.blocked){
			out.push('<div class="callout warn mb-3"><i class="bi bi-pause-circle"></i><div><strong>La IA está pausada.</strong> ' + esc(d.limits.blocked) + ' Los clientes reciben el mensaje de "un asesor continuará". ' + (cfg.super ? '<a href="/setup/#limits">Ajustar límites</a>' : "") + '</div></div>');
		}

		if(!d.notify.recipients.length){
			out.push('<div class="callout warn mb-3"><i class="bi bi-bell-slash"></i><div><strong>Nadie recibe las órdenes ni las citas.</strong> Escribe el WhatsApp central en <a href="/negocio#delivery">Mi negocio → Cobertura</a> o marca avisos en <a href="/negocio#contacts">Números por área</a>.</div></div>');
		}else if(!d.notify.template){
			out.push('<div class="callout mb-3"><i class="bi bi-info-circle"></i><div>Los avisos van a <strong>' + d.notify.recipients.map(function(p){ return "+" + esc(p); }).join(", ") + '</strong>. WhatsApp solo los entrega si ese número escribió al bot en las últimas 24 h. ' + (cfg.super ? 'Para que lleguen siempre, configura una <a href="/setup/#whatsapp">plantilla de avisos</a>.' : "Pide al administrador configurar una plantilla de avisos.") + '</div></div>');
		}

		document.getElementById("dashAlerts").innerHTML = out.join("");
	}

	/* Reparto de las conversaciones: barra segmentada + leyenda con valores */

	function split(t){

		var parts = [["Con orden", t.with_order, 0], ["Solo consulta", t.only_ask, 1], ["Sin cobertura", t.outside, 2]];
		var total = t.conversations || 0;
		var box = document.getElementById("dashSplit");

		if(!total){
			box.innerHTML = '<div class="dx-empty">Aún no hay conversaciones en este periodo.</div>';
			return;
		}

		box.innerHTML = '<div class="dx-bar" role="img" aria-label="' + parts.map(function(p){ return p[0] + " " + Math.round(p[1] / total * 100) + "%"; }).join(", ") + '">' +
				parts.filter(function(p){ return p[1] > 0; }).map(function(p){ return '<i style="flex:' + p[1] + ';background:' + SERIES[p[2]] + '" title="' + p[0] + ': ' + n(p[1]) + '"></i>'; }).join("") +
			'</div>' +
			'<ul class="dx-legend">' + parts.map(function(p){
				return '<li><span class="dot" style="background:' + SERIES[p[2]] + '"></span><span class="lbl">' + p[0] + '</span><b>' + n(p[1]) + '</b><em>' + Math.round(p[1] / total * 100) + '%</em></li>';
			}).join("") + '</ul>';
	}

	function upcoming(list){

		var box = document.getElementById("dashUpcoming");

		if(!list || !list.length){
			box.innerHTML = '<li class="dx-empty"><i class="bi bi-calendar-x"></i> No hay instalaciones agendadas.</li>';
			return;
		}

		box.innerHTML = list.map(function(u){

			var dt = new Date(u.date + "T00:00:00");

			return '<li><a href="/ordenes?q=' + u.id + '">' +
				'<span class="dx-date"><b>' + dt.getDate() + '</b><small>' + dt.toLocaleDateString("es-PE", {month: "short"}).replace(".", "") + '</small></span>' +
				'<span class="dx-who"><strong>' + esc(u.name) + '</strong><small>' + esc(longDay(u.date)) + " · " + esc(time12(u.time)) + (u.branch ? " · " + esc(u.branch) : "") + '</small><small class="plan">' + esc(u.plan) + '</small></span>' +
				'<span class="dx-id">#' + u.id + '</span>' +
			'</a></li>';
		}).join("");
	}

	function render(d){

		var t = d.totals, p = d.previous || {}, s = d.series, labels = d.labels.map(day);

		alerts(d);

		document.getElementById("dashRange").textContent = day(d.labels[0]) + " al " + day(d.labels[d.labels.length - 1]) + " · vs. " + d.days + " días anteriores";

		/* Indicadores principales */

		set("conversations", n(t.conversations));
		set("conversations_sub", n(t.received) + " mensajes recibidos");
		delta("conversations", t.conversations, p.conversations);
		spark("received", s.received, SERIES[0]);

		set("orders", n(t.orders));
		set("orders_sub", t.conversion != null ? t.conversion + "% de conversión" : "");
		delta("orders", t.orders, p.orders);
		spark("orders", s.orders, SERIES[0]);

		set("response", t.response_rate != null ? t.response_rate + "%" : "–");
		set("response_sub", n(t.answered) + " respondidos" + (t.failed ? " · " + n(t.failed) + " fallidos" : ""));
		delta("response_rate", t.response_rate, p.response_rate, true);
		spark("answered", s.answered, SERIES[1]);

		set("cancelled", n(t.cancelled));
		set("cancelled_sub", n(t.outside) + " sin cobertura");
		delta("cancelled", t.cancelled, p.cancelled);
		spark("cancelled", s.cancelled, SERIES[1]);

		/* Consumo */

		set("ai_today", compact(d.today.ai_tokens));
		set("ai_today_sub", n(d.today.ai_calls) + " respuestas · " + usd(d.today.ai_cost) + (d.limits.ai_day ? " · límite " + compact(d.limits.ai_day) : ""));
		meter("ai_day", d.today.ai_tokens, d.limits.ai_day);

		set("ai_month", compact(d.month.ai_tokens));
		set("ai_month_sub", "Entrada " + compact(d.month.ai_in) + " · salida " + compact(d.month.ai_out) + (d.limits.ai_month ? " · límite " + compact(d.limits.ai_month) : ""));
		meter("ai_month", d.month.ai_tokens, d.limits.ai_month);

		set("wa_month", n(d.month.wa_sent));
		set("wa_month_sub", n(d.month.wa_templates) + " plantillas · " + n(d.month.wa_failed) + " fallidos");

		set("cost_month", usd(d.month.ai_cost + d.month.wa_cost));
		set("cost_month_sub", "IA " + usd(d.month.ai_cost) + " · WhatsApp " + usd(d.month.wa_cost));

		set("rate", n(d.limits.sent_last_min));
		set("rate_sub", d.limits.send_min ? "Límite " + n(d.limits.send_min) + "/min" + (d.limits.client_min ? " · " + n(d.limits.client_min) + " por cliente" : "") : "Sin límite por minuto");
		meter("rate", d.limits.sent_last_min, d.limits.send_min);

		split(t);
		upcoming(d.upcoming);

		/* Gráficos */

		draw("chMessages", "line", labels, [lineSet("Recibidos", s.received, 0), lineSet("Respondidos", s.answered, 1), lineSet("Enviados", s.sent, 2)], false);
		table("messages", labels, [["Recibidos", s.received], ["Respondidos", s.answered], ["Enviados", s.sent]]);

		draw("chOutcome", "bar", labels, [stackSet("Con orden", s.with_order, 0, false), stackSet("Solo consulta", s.only_ask, 1, false), stackSet("Sin cobertura", s.outside, 2, true)], true, {legend: false});
		table("outcome", labels, [["Con orden", s.with_order], ["Solo consulta", s.only_ask], ["Sin cobertura", s.outside]]);

		draw("chOrders", "bar", labels, [barSet("Generadas", s.orders, 0), barSet("Rechazadas", s.cancelled, 1)], false);
		table("orders", labels, [["Generadas", s.orders], ["Rechazadas", s.cancelled]]);

		draw("chTokens", "bar", labels, [stackSet("Entrada", s.tok_in, 0, false), stackSet("Salida", s.tok_out, 1, true)], true);
		table("tokens", labels, [["Entrada", s.tok_in], ["Salida", s.tok_out]]);

		draw("chWhatsapp", "bar", labels, [stackSet("Sesión", s.wa_text, 0, false), stackSet("Plantillas", s.wa_template, 1, false), stackSet("Fallidos", s.wa_failed, 2, true)], true);
		table("whatsapp", labels, [["Sesión", s.wa_text], ["Plantillas", s.wa_template], ["Fallidos", s.wa_failed]]);
	}

	/* Periodo (botones segmentados) */

	var period = document.getElementById("dashDays");

	function mark(){
		period.querySelectorAll("[data-days]").forEach(function(b){
			var on = Number(b.getAttribute("data-days")) === days;
			b.classList.toggle("on", on);
			b.setAttribute("aria-pressed", on ? "true" : "false");
		});
	}

	try{ var saved = Number(localStorage.getItem("dashDays")); if([7, 14, 30, 90].indexOf(saved) > -1){ days = saved; } }catch(e){}

	mark();

	function load(){

		document.querySelector(".dx").classList.add("loading");

		return request({action: "dashboard", days: days}).then(function(res){

			document.querySelector(".dx").classList.remove("loading");

			if(!res.ok){ fncToastr("error", res.error || "No se pudo cargar el inicio"); return; }

			render(res.data);
		});
	}

	period.addEventListener("click", function(e){

		var b = e.target.closest("[data-days]");
		if(!b){ return; }

		days = Number(b.getAttribute("data-days"));
		try{ localStorage.setItem("dashDays", days); }catch(err){}
		mark();
		load();
	});

	document.getElementById("dashReload").addEventListener("click", load);

	load();

	/* Se actualiza solo cada minuto mientras está abierto */

	setInterval(function(){ if(!document.hidden){ load(); } }, 60000);

})();
