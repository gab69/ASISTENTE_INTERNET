/*=============================================
Órdenes: pedidos de los clientes
=============================================*/

(function(){

	var cfg = window.ordersConfig || {};
	var status = "";
	var period = "7";
	var search = cfg.search || "";
	var items = [];
	var knownIds = null;
	var timer = null;

	var recipients = [];

	/* Solo tres situaciones: activa, completada (sola) o cancelada (a mano) */

	var labels = {"Pendiente": "Activa", "Completado": "Completada", "Cancelado": "Cancelada"};

	function label(st){ return labels[st] || "Activa"; }

	function esc(v){
		return String(v == null ? "" : v).replace(/[&<>"']/g, function(c){
			return {"&":"&amp;","<":"&lt;",">":"&gt;",'"':"&quot;","'":"&#39;"}[c];
		});
	}

	function money(n){
		var v = Number(n || 0);
		return cfg.currency + " " + v.toLocaleString("en-US", {minimumFractionDigits: 2, maximumFractionDigits: 2});
	}

	function slug(s){
		return String(s || "Pendiente").toLowerCase().normalize("NFD").replace(/[̀-ͯ]/g, "").replace(/[^a-z]/g, "");
	}

	function ago(dt){

		var d = new Date(String(dt).replace(" ", "T"));
		var diff = (Date.now() - d.getTime()) / 60000;

		if(isNaN(diff)){ return ""; }
		if(diff < 1){ return "ahora"; }
		if(diff < 60){ return "hace " + Math.round(diff) + " min"; }
		if(diff < 1440 && d.getDate() === new Date().getDate()){ return "hoy " + d.toLocaleTimeString("es-PE", {hour: "numeric", minute: "2-digit"}); }

		return d.toLocaleDateString("es-PE", {day: "2-digit", month: "short"}) + " · " + d.toLocaleTimeString("es-PE", {hour: "numeric", minute: "2-digit"});
	}

	function fromDate(){

		if(period === ""){ return ""; }

		var d = new Date(cfg.today + "T00:00:00");

		if(period !== "today"){ d.setDate(d.getDate() - (Number(period) - 1)); }

		return d.getFullYear() + "-" + String(d.getMonth() + 1).padStart(2, "0") + "-" + String(d.getDate()).padStart(2, "0");
	}

	function request(fields){

		var body = new FormData();
		Object.keys(fields).forEach(function(k){ body.append(k, fields[k]); });

		return fetch("/ajax/business.ajax.php", {method: "POST", headers: {"X-CSRF-Token": window.bizCsrf}, body: body, credentials: "same-origin"})
			.then(function(r){ return r.json().catch(function(){ return {ok: false, error: "Respuesta inválida"}; }); })
			.catch(function(){ return {ok: false, error: "Sin conexión"}; });
	}

	/*=============================================
	Tarjeta de pedido
	=============================================*/

	function coverage(o){

		if(o.delivery_order === "recojo"){
			return '<span class="cov cov-pick"><i class="bi bi-shop"></i> Recojo en tienda</span>';
		}

		var km = o.distance_order != null && o.distance_order !== "" ? " · " + Number(o.distance_order).toFixed(1) + " km" : "";

		if(o.coverage_order === "si"){ return '<span class="cov cov-ok"><i class="bi bi-check-circle-fill"></i> Con cobertura' + km + '</span>'; }
		if(o.coverage_order === "no"){ return '<span class="cov cov-no"><i class="bi bi-x-circle-fill"></i> Fuera de cobertura' + km + '</span>'; }
		if(o.coverage_order){ return '<span class="cov"><i class="bi bi-question-circle"></i> Cobertura sin verificar</span>'; }

		return "";
	}

	function mapLink(o){

		if(o.location_order){ return o.location_order; }

		if(o.lat_order && o.lng_order){
			return "https://www.google.com/maps/search/?api=1&query=" + o.lat_order + "," + o.lng_order;
		}

		return "https://www.google.com/maps/search/?api=1&query=" + encodeURIComponent(o.address_order || "");
	}

	/* Avisos al equipo: a quién, por qué vía y si WhatsApp lo entregó */

	function notices(o){

		var log = (o.notify || []).slice(-4);

		if(!log.length){
			return '<div class="order-notify warn"><i class="bi bi-bell-slash"></i><span>' + (recipients.length ? "Aún no se avisó al equipo." : "No hay número para avisos: configúralo en Mi negocio → Cobertura.") + '</span></div>';
		}

		return '<div class="order-notify">' + log.map(function(n){

			var st = n.status === "read" ? "Leído" : n.status === "delivered" ? "Entregado" : n.ok ? "Enviado" : "No llegó";
			var cls = !n.ok ? "bad" : (n.error ? "warn" : "ok");
			var icon = !n.ok ? "bi-x-circle-fill" : (n.status === "read" || n.status === "delivered" ? "bi-check2-all" : "bi-check2");

			return '<div class="' + cls + '" title="' + esc(n.error || "") + '"><i class="bi ' + icon + '"></i><span>' +
				'+' + esc(n.to) + ' · ' + st + (n.via === "plantilla" ? " (plantilla)" : "") + ' · ' + esc(ago(n.at)) +
				(n.error ? '<small>' + esc(n.error) + '</small>' : "") + '</span></div>';
		}).join("") + '</div>';
	}

	/* Teléfonos para llamar el día de la instalación (pueden ser varios) */

	function phones(o){

		var list = String(o.phone_order || "").split(/\s*,\s*/).filter(Boolean);

		if(!list.length){ return ""; }

		return '<div><i class="bi bi-telephone"></i><span>' + list.map(function(p){
			return '<a href="tel:+' + esc(p) + '">+' + esc(p) + '</a>';
		}).join(" · ") + '</span></div>';
	}

	/* Recibo de luz o agua (foto o PDF) que envió el cliente para identificar la vivienda */

	function photos(o){

		if(!o.photos || !o.photos.length){ return ""; }

		return '<div class="order-photos"><small><i class="bi bi-receipt"></i> Recibo de luz o agua del cliente</small><div>' + o.photos.map(function(p){
			if(p.kind === "pdf"){
				return '<a class="order-pdf" href="' + esc(p.url) + '" target="_blank" rel="noopener" title="' + esc(p.caption || "Recibo en PDF") + '"><i class="bi bi-file-earmark-pdf"></i><span>PDF</span></a>';
			}
			return '<a href="' + esc(p.url) + '" target="_blank" rel="noopener" title="' + esc(p.caption || "Foto del cliente") + '"><img src="' + esc(p.url) + '" alt="Foto enviada por el cliente" loading="lazy"></a>';
		}).join("") + '</div></div>';
	}

	function closeNote(o){

		if(o.status_order === "Cancelado"){
			return '<div class="order-closed bad"><i class="bi bi-x-octagon"></i> Cancelada' + (o.cancel_reason_order ? ": " + esc(o.cancel_reason_order) : "") + (o.closed_at_order ? " · " + esc(ago(o.closed_at_order)) : "") + '</div>';
		}

		if(o.status_order === "Completado"){
			return '<div class="order-closed ok"><i class="bi bi-check2-circle"></i> Completada automáticamente' + (o.closed_at_order ? " · " + esc(ago(o.closed_at_order)) : "") + '</div>';
		}

		if(o.appointment && o.appointment.status !== "cancelada"){
			return '<div class="order-closed"><i class="bi bi-clock-history"></i> Se cerrará sola después de la ' + (o.delivery_order === "instalacion" ? "instalación" : "cita") + ', salvo que la reprogrames.</div>';
		}

		return "";
	}

	function card(o){

		var st = o.status_order || "Pendiente";
		var active = st === "Pendiente";
		var isNew = knownIds && knownIds.indexOf(String(o.id_order)) === -1;
		var phone = o.contact_order || o.phone_order || "";
		var subtotal = o.subtotal_order != null && o.subtotal_order !== "" ? Number(o.subtotal_order) : null;
		var feeLine = Number(o.fee_order) > 0;

		var rows = (o.items || []).map(function(i){
			return '<tr><td class="q">' + esc(i.qty) + '×</td><td>' + esc(i.name) + (i.matched === false && i.price == null ? ' <span class="mini-tag" title="No está en el catálogo">confirmar precio</span>' : "") + '</td><td class="r">' + (i.subtotal != null ? money(i.subtotal) : "—") + '</td></tr>';
		}).join("");

		return '<article class="order st-border-' + slug(st) + (isNew ? " is-new" : "") + '" data-id="' + o.id_order + '">' +

			'<header class="order-head">' +
				'<div><strong>#' + esc(o.id_order) + '</strong><small>' + esc(ago(o.created)) + (o.source_order ? ' · ' + (o.source_order === "ia" ? "IA" : o.source_order === "bot" ? "Bot" : esc(o.source_order)) : "") + '</small></div>' +
				'<span class="st st-' + slug(st) + '">' + esc(label(st)) + '</span>' +
			'</header>' +

			'<div class="order-client">' +
				'<div><strong>' + esc(o.name_order || "Cliente sin nombre") + '</strong>' +
				(phone ? '<small>WhatsApp +' + esc(phone) + (o.document_order ? " · DNI " + esc(o.document_order) : "") + '</small>' : "") + '</div>' +
				(phone ? '<a class="btn-icon" href="/chat?phone=' + esc(phone) + '" title="Abrir chat"><i class="bi bi-chat-dots"></i></a>' : "") +
			'</div>' +

			(o.appointment && o.appointment.status !== "cancelada"
				? '<div class="order-install' + (o.appointment.status === "pendiente" ? " pend" : "") + '"><i class="bi bi-calendar2-check"></i> ' + (o.delivery_order === "instalacion" ? "Instalación" : "Cita") + ': ' + esc(o.appointment.label) + (o.appointment.status === "pendiente" ? " (por confirmar)" : "") + '</div>'
				: (o.delivery_order === "instalacion" && active ? '<div class="order-install pend"><i class="bi bi-calendar-x"></i> ' + (o.appointment ? "Instalación cancelada: reprográmala o cancela la orden" : "Instalación sin agendar") + '</div>' : "")) +

			'<table class="order-items"><tbody>' + (rows || '<tr><td colspan="3" class="text-muted">' + esc(o.products_order || "Sin detalle") + '</td></tr>') + '</tbody></table>' +

			'<div class="order-totals">' +
				(subtotal !== null && feeLine && !o.breakdown ? '<div><span>Subtotal</span><span>' + money(subtotal) + '</span></div><div><span>' + esc(cfg.feeLabel) + '</span><span>' + money(o.fee_order) + '</span></div>' : "") +
				(o.breakdown
					? '<div class="total"><span>Mensualidad</span><span>' + money(o.breakdown.monthly) + '</span></div><div class="total"><span>Pago inicial</span><span>' + money(o.breakdown.upfront) + '</span></div>' + (feeLine ? '<div><span>Incluye instalación</span><span>' + money(o.fee_order) + '</span></div>' : "")
					: '<div class="total"><span>Total</span><span>' + money(o.total_order) + '</span></div>') +
			'</div>' +

			'<div class="order-info">' +
				(o.delivery_order !== "recojo" && o.address_order ? '<div><i class="bi bi-geo-alt"></i><span>' + esc(o.address_order) + (o.reference_order ? '<small>Referencia: ' + esc(o.reference_order) + '</small>' : "") + '</span></div>' : "") +
				(o.delivery_order !== "recojo" && (o.location_order || o.lat_order || o.address_order)
					? '<div><i class="bi bi-geo-alt"></i><span><a href="' + esc(mapLink(o)) + '" target="_blank" rel="noopener" class="fw-semibold">' + (o.location_order ? "Ubicación enviada por el cliente" : "Ver en el mapa") + ' <i class="bi bi-box-arrow-up-right"></i></a></span></div>' : "") +
				phones(o) +
				'<div class="order-cov">' + coverage(o) + '</div>' +
				(o.branch_order ? '<div><i class="bi bi-building"></i><span>Sucursal <strong>' + esc(o.branch_order) + '</strong></span></div>' : "") +

				(o.payment_order ? '<div><i class="bi bi-credit-card"></i><span>' + esc(o.payment_order) + '</span></div>' : "") +
				(o.email_order ? '<div><i class="bi bi-envelope"></i><span>' + esc(o.email_order) + '</span></div>' : "") +
				(o.notes_order ? '<div><i class="bi bi-sticky"></i><span>' + esc(o.notes_order) + '</span></div>' : "") +
			'</div>' +

			photos(o) +

			closeNote(o) +

			(active || (o.notify || []).length ? notices(o) : "") +

			(active ? '<footer class="order-actions">' +
				(true ? '<button class="btn btn-ghost btn-sm" data-act="reschedule"><i class="bi bi-calendar-event"></i> ' + (o.appointment && o.appointment.status !== "cancelada" ? "Reprogramar" : "Agendar") + '</button>' : "") +
				(active ? '<button class="btn btn-ghost btn-sm text-danger" data-act="cancel"><i class="bi bi-x-circle"></i> Cancelar</button>' : "") +
				'<button class="btn-icon ms-auto" data-act="resend" title="Reenviar el aviso de esta orden al equipo"><i class="bi bi-bell"></i></button>' +
			'</footer>' : "") +

		'</article>';
	}

	/*=============================================
	Cargar y dibujar
	=============================================*/

	function render(){

		var box = document.getElementById("ordersList");

		if(!items.length){
			box.innerHTML = '<div class="empty"><i class="bi bi-clipboard"></i><p>' + (search ? "No hay órdenes que coincidan con la búsqueda." : "No hay órdenes en este periodo.") + '</p></div>';
			return;
		}

		box.innerHTML = items.map(card).join("");
	}

	function stats(s){

		var counts = s.counts || {};
		var total = 0;

		Object.keys(counts).forEach(function(k){ total += counts[k]; });

		document.querySelector('[data-kpi="today_orders"]').textContent = s.today_orders;
		document.querySelector('[data-kpi="today_total"]').textContent = money(s.today_total);
		document.querySelector('[data-kpi="pending"]').textContent = counts["Pendiente"] || 0;
		document.querySelector('[data-kpi="onway"]').textContent = s.upcoming || 0;

		document.querySelectorAll("#ordersStatus button").forEach(function(b){
			var key = b.getAttribute("data-status");
			var n = key ? (counts[key] || 0) : total;
			b.querySelector("span").textContent = n ? n : "";
		});
	}

	function load(silent){

		return request({action: "orders", status: status, from: fromDate(), search: search}).then(function(res){

			if(!res.ok){ if(!silent){ fncToastr("error", res.error || "No se pudieron cargar los pedidos"); } return; }

			var ids = res.items.map(function(o){ return String(o.id_order); });
			var arrived = knownIds && ids.some(function(id){ return knownIds.indexOf(id) === -1; });

			items = res.items;
			recipients = res.recipients || [];
			document.getElementById("notifyWarn").classList.toggle("d-none", recipients.length > 0);
			render();
			stats(res.stats);

			if(arrived){
				fncToastr("success", "¡Llegó una orden nueva!");
				try{ new Audio("/views/assets/files/68143a7deacde37.mp3").play().catch(function(){}); }catch(e){}
			}

			knownIds = ids;
		});
	}

	function schedule(){

		clearTimeout(timer);
		timer = setTimeout(function(){ load(true).finally(schedule); }, document.hidden ? 60000 : 20000);
	}

	/*=============================================
	Eventos
	=============================================*/

	document.getElementById("ordersStatus").addEventListener("click", function(e){

		var b = e.target.closest("button");
		if(!b){ return; }

		status = b.getAttribute("data-status");
		this.querySelectorAll("button").forEach(function(x){ x.classList.toggle("active", x === b); });
		load();
	});

	document.getElementById("ordersPeriod").addEventListener("change", function(){

		period = this.value;
		try{ localStorage.setItem("ordersPeriod", period); }catch(e){}
		load();
	});

	var searchTimer = null;
	var searchInput = document.getElementById("ordersSearch");

	searchInput.value = search;

	searchInput.addEventListener("input", function(){

		clearTimeout(searchTimer);
		searchTimer = setTimeout(function(){ search = searchInput.value.trim(); load(); }, 350);
	});

	document.getElementById("ordersReload").addEventListener("click", function(){ load(); });

	/*=============================================
	Reprogramar / Cancelar / Reenviar aviso
	=============================================*/

	var modalEl = document.getElementById("orderModal");
	var modal = modalEl ? new bootstrap.Modal(modalEl) : null;
	var current = null;

	function openModal(kind, o){

		current = {kind: kind, id: o.id_order};

		var f = document.getElementById("orderModalForm");
		var resched = kind === "reschedule";

		document.getElementById("orderModalTitle").textContent = (resched ? (o.appointment && o.appointment.status !== "cancelada" ? "Reprogramar" : "Agendar") + (o.delivery_order === "instalacion" ? " instalación" : " cita") : "Cancelar orden") + " · #" + o.id_order;
		f.querySelector("[data-for=reschedule]").classList.toggle("d-none", !resched);
		f.querySelector("[data-for=cancel]").classList.toggle("d-none", resched);
		f.date.value = o.appointment ? o.appointment.date : cfg.today;
		f.time.value = o.appointment && o.appointment.time !== "00:00" ? o.appointment.time : "09:00";
		f.reason.value = "";
		f.notify.checked = true;

		var go = document.getElementById("orderModalGo");
		go.className = "btn " + (resched ? "btn-brand" : "btn-danger");
		go.innerHTML = resched ? '<i class="bi bi-check2"></i> Guardar fecha' : '<i class="bi bi-x-circle"></i> Cancelar orden';

		modal.show();
	}

	if(modalEl){

		document.getElementById("orderModalForm").addEventListener("submit", function(e){

			e.preventDefault();

			var f = this;
			var go = document.getElementById("orderModalGo");
			var fields = current.kind === "reschedule"
				? {action: "order_reschedule", id: current.id, date: f.date.value, time: f.time.value, notify: f.notify.checked ? 1 : 0}
				: {action: "order_cancel", id: current.id, reason: f.reason.value, notify: f.notify.checked ? 1 : 0};

			if(current.kind === "reschedule" && !f.date.value){ fncToastr("error", "Elige la fecha."); return; }

			go.disabled = true;

			request(fields).then(function(r){

				go.disabled = false;

				if(!r.ok){ fncToastr("error", r.error || "No se pudo guardar"); return; }

				modal.hide();
				fncToastr(r.error ? "warning" : "success", r.error || (current.kind === "reschedule" ? "Fecha guardada y equipo avisado" : "Orden #" + current.id + " cancelada"));
				load(true);
			});
		});
	}

	document.getElementById("ordersList").addEventListener("click", function(e){

		var btn = e.target.closest("[data-act]");
		if(!btn){ return; }

		var id = btn.closest(".order").getAttribute("data-id");
		var act = btn.getAttribute("data-act");
		var o = items.filter(function(x){ return String(x.id_order) === id; })[0];

		if(!o){ return; }

		if(act === "reschedule" || act === "cancel"){ openModal(act, o); return; }

		if(act === "resend"){

			btn.disabled = true;

			request({action: "order_resend", id: id}).then(function(r){

				btn.disabled = false;
				fncToastr(r.ok ? "success" : "error", r.ok ? "Aviso reenviado al equipo" : (r.error || "No se pudo enviar"));
				load(true);
			});
		}
	});

	/* Periodo guardado (si se buscó desde el chat, mostrar todo) */

	try{
		var saved = localStorage.getItem("ordersPeriod");
		if(saved !== null){ period = saved; }
	}catch(e){}

	if(search){ period = ""; }

	document.getElementById("ordersPeriod").value = period;

	load().finally(schedule);

})();
