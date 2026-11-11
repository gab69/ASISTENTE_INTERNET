/*=============================================
Agenda: calendario mensual, detalle del día y citas
=============================================*/

(function(){

	var today = window.agendaToday;
	var selected = window.agendaOpen || today;
	var cursor = new Date(selected + "T00:00:00");
	cursor.setDate(1);
	var items = [];
	var upcoming = [];

	var months = ["enero","febrero","marzo","abril","mayo","junio","julio","agosto","septiembre","octubre","noviembre","diciembre"];
	var days = ["domingo","lunes","martes","miércoles","jueves","viernes","sábado"];
	var statusLabel = {confirmada: "Confirmada", atendida: "Atendida", cancelada: "Cancelada", pendiente: "Pendiente"};

	function esc(v){
		return String(v == null ? "" : v).replace(/[&<>"']/g, function(c){
			return {"&":"&amp;","<":"&lt;",">":"&gt;",'"':"&quot;","'":"&#39;"}[c];
		});
	}

	function pad(n){ return (n < 10 ? "0" : "") + n; }
	function ymd(d){ return d.getFullYear() + "-" + pad(d.getMonth() + 1) + "-" + pad(d.getDate()); }

	function time12(hhmm){
		if(String(hhmm).slice(0, 5) === "00:00"){ return "Por confirmar"; }
		var p = String(hhmm).split(":"), h = +p[0], m = p[1];
		return ((h % 12) || 12) + ":" + m + (h < 12 ? " a. m." : " p. m.");
	}

	function request(fields){

		var body = new FormData();
		Object.keys(fields).forEach(function(k){ body.append(k, fields[k]); });

		return fetch("/ajax/business.ajax.php", {method: "POST", headers: {"X-CSRF-Token": window.bizCsrf}, body: body, credentials: "same-origin"})
			.then(function(r){ return r.json().catch(function(){ return {ok:false, error:"Respuesta inválida"}; }); })
			.catch(function(){ return {ok:false, error:"Sin conexión"}; });

	}

	function gcal(a){

		var start = (a.date_appointment + "T" + a.time_appointment).replace(/[-:]/g, "");
		var d = new Date(a.date_appointment + "T" + a.time_appointment);
		d.setMinutes(d.getMinutes() + (+a.duration_appointment || 30));
		var end = ymd(d).replace(/-/g, "") + "T" + pad(d.getHours()) + pad(d.getMinutes()) + "00";

		return "https://calendar.google.com/calendar/render?action=TEMPLATE" +
			"&text=" + encodeURIComponent((a.service_appointment || "Cita") + " - " + (a.name_appointment || a.phone_appointment || "")) +
			"&dates=" + start.slice(0, 15) + "/" + end +
			"&details=" + encodeURIComponent((a.summary_appointment || "") + (a.phone_appointment ? "\nWhatsApp: +" + a.phone_appointment : ""));

	}

	/*=============================================
	Cargar datos
	=============================================*/

	function load(){

		var first = new Date(cursor.getFullYear(), cursor.getMonth(), 1);
		var last = new Date(cursor.getFullYear(), cursor.getMonth() + 1, 0);
		var from = new Date(first); from.setDate(from.getDate() - 7);
		var to = new Date(last); to.setDate(to.getDate() + 7);

		var future = new Date(today + "T00:00:00"); future.setDate(future.getDate() + 60);

		Promise.all([
			request({action: "appointments", from: ymd(from), to: ymd(to), all: 1}),
			request({action: "appointments", from: today, to: ymd(future)})
		]).then(function(res){

			if(!res[0].ok){ fncToastr("error", res[0].error); return; }

			items = res[0].items || [];
			upcoming = (res[1].items || []).slice(0, 8);
			renderCalendar();
			renderDay();
			renderUpcoming();

		});

	}

	/*=============================================
	Calendario
	=============================================*/

	function renderCalendar(){

		document.getElementById("calTitle").textContent = months[cursor.getMonth()].replace(/^./, function(c){ return c.toUpperCase(); }) + " " + cursor.getFullYear();

		var first = new Date(cursor.getFullYear(), cursor.getMonth(), 1);
		var offset = (first.getDay() + 6) % 7; /* lunes primero */
		var start = new Date(first); start.setDate(1 - offset);

		var html = "";

		for(var i = 0; i < 42; i++){

			var d = new Date(start); d.setDate(start.getDate() + i);
			var key = ymd(d);
			var list = items.filter(function(a){ return a.date_appointment === key && a.status_appointment !== "cancelada"; });

			var cls = ["cal-day"];
			if(d.getMonth() !== cursor.getMonth()){ cls.push("muted"); }
			if(key === today){ cls.push("today"); }
			if(key === selected){ cls.push("selected"); }

			html += '<button type="button" class="' + cls.join(" ") + '" data-date="' + key + '">' +
				'<span class="num">' + d.getDate() + '</span>' +
				list.slice(0, 3).map(function(a){
					return '<span class="cal-evt' + (a.status_appointment === "pendiente" ? " pending" : "") + '">' + (a.time_appointment.slice(0, 5) !== "00:00" ? esc(a.time_appointment.slice(0, 5)) + ' ' : '⏳ ') + esc(a.name_appointment || a.service_appointment || "Cita") + '</span>';
				}).join("") +
				(list.length > 3 ? '<span class="cal-more">+' + (list.length - 3) + ' más</span>' : "") +
				(list.length ? '<span class="cal-dot">' + list.length + '</span>' : "") +
			'</button>';
		}

		document.getElementById("calDays").innerHTML = html;

	}

	function card(a, compact){

		var st = a.status_appointment;

		return '<div class="appt ' + esc(st) + '">' +
			'<div class="appt-time">' + (compact ? '<small>' + esc(a.date_appointment.slice(8, 10) + "/" + a.date_appointment.slice(5, 7)) + '</small>' : "") + time12(a.time_appointment.slice(0, 5)) + '</div>' +
			'<div class="appt-body">' +
				'<div class="appt-name">' + esc(a.name_appointment || "Sin nombre") + ' <span class="badge-st ' + esc(st) + '">' + (statusLabel[st] || st) + '</span></div>' +
				(a.service_appointment || a.people_appointment || a.branch_appointment ? '<div class="appt-svc">' + esc(a.service_appointment || "") + (a.people_appointment ? ' · <i class="bi bi-people"></i> ' + esc(a.people_appointment) + ' pers.' : "") + (a.branch_appointment ? ' · <i class="bi bi-building"></i> ' + esc(a.branch_appointment) : "") + '</div>' : "") +
				(!compact && a.summary_appointment ? '<div class="appt-sum">' + esc(a.summary_appointment) + '</div>' : "") +
				(!compact ? '<div class="appt-actions">' +
					(a.phone_appointment ? '<a class="chip" href="/chat?phone=' + esc(a.phone_appointment) + '"><i class="bi bi-chat-dots"></i> Chat</a>' : "") +
					'<a class="chip" target="_blank" rel="noopener" href="' + esc(gcal(a)) + '"><i class="bi bi-google"></i> Calendar</a>' +
					(st !== "cancelada" ? '<button class="chip" data-act="notify" data-id="' + a.id_appointment + '"><i class="bi bi-whatsapp"></i> Reenviar</button>' : "") +
					(st === "pendiente" ? '<button class="chip" data-act="edit" data-id="' + a.id_appointment + '"><i class="bi bi-check2-circle"></i> Confirmar</button>' : "") +
					(st === "confirmada" ? '<button class="chip" data-act="atendida" data-id="' + a.id_appointment + '"><i class="bi bi-check2"></i> Atendida</button>' : "") +
					(st !== "cancelada" ? '<button class="chip" data-act="edit" data-id="' + a.id_appointment + '"><i class="bi bi-pencil"></i></button>' : "") +
					(st !== "cancelada" ? '<button class="chip danger" data-act="cancelada" data-id="' + a.id_appointment + '"><i class="bi bi-x-lg"></i></button>' : '<button class="chip" data-act="confirmada" data-id="' + a.id_appointment + '"><i class="bi bi-arrow-counterclockwise"></i> Restaurar</button>') +
				'</div>' : "") +
			'</div>' +
		'</div>';

	}

	function renderDay(){

		var d = new Date(selected + "T00:00:00");
		var list = items.filter(function(a){ return a.date_appointment === selected; });

		document.getElementById("dayWeek").textContent = days[d.getDay()];
		document.getElementById("dayTitle").textContent = d.getDate() + " de " + months[d.getMonth()];
		document.getElementById("dayCount").textContent = list.filter(function(a){ return a.status_appointment !== "cancelada"; }).length; document.getElementById("dayCount").textContent += document.getElementById("dayCount").textContent === "1" ? " cita" : " citas";

		document.getElementById("dayList").innerHTML = list.length
			? list.map(function(a){ return card(a, false); }).join("")
			: '<div class="empty"><i class="bi bi-calendar2-check"></i><p>Sin citas este día</p><button class="btn btn-ghost btn-sm" data-act="new-day">Agendar aquí</button></div>';

	}

	function renderUpcoming(){

		document.getElementById("upcomingList").innerHTML = upcoming.length
			? upcoming.map(function(a){ return card(a, true); }).join("")
			: '<p class="text-muted small m-0">No hay citas próximas.</p>';

	}

	/*=============================================
	Eventos
	=============================================*/

	document.getElementById("calPrev").onclick = function(){ cursor.setMonth(cursor.getMonth() - 1); load(); };
	document.getElementById("calNext").onclick = function(){ cursor.setMonth(cursor.getMonth() + 1); load(); };
	document.getElementById("calToday").onclick = function(){ cursor = new Date(today + "T00:00:00"); cursor.setDate(1); selected = today; load(); };

	document.getElementById("calDays").addEventListener("click", function(e){

		var b = e.target.closest(".cal-day");
		if(!b){ return; }

		selected = b.getAttribute("data-date");

		var d = new Date(selected + "T00:00:00");

		if(d.getMonth() !== cursor.getMonth()){
			cursor = new Date(d); cursor.setDate(1); load(); return;
		}

		renderCalendar();
		renderDay();

	});

	document.addEventListener("click", function(e){

		var btn = e.target.closest("[data-act]");
		if(!btn){ return; }

		var act = btn.getAttribute("data-act");
		var id = btn.getAttribute("data-id");

		if(act === "new-day"){ openModal(null, selected); return; }

		if(act === "edit"){
			openModal(items.concat(upcoming).find(function(a){ return String(a.id_appointment) === String(id); }));
			return;
		}

		if(act === "notify"){
			btn.disabled = true;
			request({action: "notify", id: id}).then(function(r){
				btn.disabled = false;
				fncToastr(r.ok ? "success" : "error", r.ok ? "Resumen enviado por WhatsApp" : r.error);
			});
			return;
		}

		if(act === "cancelada" && !confirm("¿Cancelar esta cita? El turno quedará libre.")){ return; }

		request({action: "status", id: id, status: act, notify: act === "cancelada" ? 1 : 0}).then(function(r){
			if(!r.ok){ fncToastr("error", r.error || "No se pudo actualizar"); return; }
			fncToastr("success", "Cita actualizada");
			load();
		});

	});

	/*=============================================
	Modal de cita con selector de turnos libres
	=============================================*/

	var modal = new bootstrap.Modal(document.getElementById("apptModal"));
	var form = document.getElementById("apptForm");

	function loadSlots(current){

		var date = form.date.value;
		var picker = document.getElementById("slotPicker");

		if(!date){ picker.innerHTML = ""; return; }

		picker.innerHTML = '<span class="text-muted small">Cargando turnos…</span>';

		request({action: "slots", date: date, exclude: form.id.value || 0}).then(function(r){

			if(!r.ok){ picker.innerHTML = ""; fncToastr("error", r.error); return; }

			if(!r.all.length){
				picker.innerHTML = '<span class="text-muted small">Ese día no hay atención según tus horarios.</span>';
				document.getElementById("slotHint").textContent = "";
				return;
			}

			document.getElementById("slotHint").textContent = r.free.length + " libres de " + r.all.length;

			picker.innerHTML = r.all.map(function(t){
				var free = r.free.indexOf(t) > -1 || t === current;
				return '<button type="button" class="slot' + (free ? "" : " busy") + (t === form.time.value ? " active" : "") + '" data-time="' + t + '"' + (free ? "" : " disabled title=\"Ocupado\"") + '>' + time12(t) + '</button>';
			}).join("");

		});

	}

	document.getElementById("slotPicker").addEventListener("click", function(e){

		var s = e.target.closest(".slot");
		if(!s || s.disabled){ return; }

		form.time.value = s.getAttribute("data-time");
		this.querySelectorAll(".slot").forEach(function(x){ x.classList.toggle("active", x === s); });

	});

	form.date.addEventListener("change", function(){ form.time.value = ""; loadSlots(); });

	function openModal(a, date){

		form.reset();
		form.id.value = a ? a.id_appointment : "";
		form.name.value = a ? a.name_appointment : "";
		form.phone.value = a ? a.phone_appointment : "";
		form.service.value = a ? a.service_appointment : "";
		form.summary.value = a ? a.summary_appointment : "";
		form.people.value = a && a.people_appointment ? a.people_appointment : "";
		form.date.value = a ? a.date_appointment : (date || selected);
		form.time.value = a && a.time_appointment.slice(0, 5) !== "00:00" ? a.time_appointment.slice(0, 5) : "";
		form.notify.checked = !a;
		form.notify.closest(".col-12").style.display = a ? "none" : "";

		document.getElementById("apptModalTitle").textContent = a ? (a.status_appointment === "pendiente" ? "Confirmar reserva" : "Editar cita") : "Nueva cita";

		loadSlots(a ? a.time_appointment.slice(0, 5) : null);
		modal.show();

	}

	document.getElementById("btnNewAppt").onclick = function(){ openModal(null); };

	form.addEventListener("submit", function(e){

		e.preventDefault();

		if(!form.time.value){ fncToastr("error", "Elige un turno libre."); return; }

		var btn = form.querySelector('[type="submit"]');
		btn.disabled = true;

		request({
			action: "save_appointment",
			id: form.id.value,
			name: form.name.value,
			phone: form.phone.value,
			service: form.service.value,
			summary: form.summary.value,
			people: form.people.value,
			date: form.date.value,
			time: form.time.value,
			notify: form.notify.checked ? 1 : 0
		}).then(function(r){

			btn.disabled = false;

			if(!r.ok){
				fncToastr("error", r.error || "No se pudo guardar");
				loadSlots();
				return;
			}

			modal.hide();
			fncToastr("success", "Cita guardada");
			selected = form.date.value;
			cursor = new Date(selected + "T00:00:00"); cursor.setDate(1);
			load();

		});

	});

	/*=============================================
	Google Calendar
	=============================================*/

	var gModal = new bootstrap.Modal(document.getElementById("gcalModal"));
	var gForm = document.getElementById("gcalForm");

	function gcalState(){

		request({action: "gcal_get"}).then(function(r){

			if(!r.ok){ return; }

			var box = document.getElementById("gcalStatus");

			box.className = "gcal-status " + (r.enabled ? "on" : "off");
			box.innerHTML = r.enabled
				? '<i class="bi bi-check-circle-fill"></i><span>Conectado al calendario <strong>' + esc(r.calendar_id) + '</strong>. Las citas se sincronizan solas.</span>'
				: '<i class="bi bi-plug"></i><span>Aún no está conectado.</span>';

			gForm.calendar_id.value = r.calendar_id || "";
			document.getElementById("gcalCredHint").textContent = r.has_credentials ? "Guardado: " + r.client_email + " (déjalo vacío para conservarlo)" : "";

			var url = r.ics_url;
			document.getElementById("icsUrl").value = url;
			document.getElementById("icsGoogle").href = "https://calendar.google.com/calendar/render?cid=" + encodeURIComponent(url.replace(/^https?:/, "webcal:"));
		});
	}

	document.getElementById("btnGcal").onclick = function(){ gcalState(); gModal.show(); };

	document.getElementById("gcalFile").addEventListener("change", function(){

		var file = this.files[0];
		if(!file){ return; }

		var reader = new FileReader();
		reader.onload = function(){ gForm.credentials.value = reader.result; };
		reader.readAsText(file);
	});

	gForm.addEventListener("submit", function(e){

		e.preventDefault();

		var btn = gForm.querySelector('[type="submit"]');
		btn.disabled = true;
		btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Probando…';

		request({action: "gcal_save", calendar_id: gForm.calendar_id.value.trim(), credentials: gForm.credentials.value.trim()}).then(function(r){

			btn.disabled = false;
			btn.innerHTML = '<i class="bi bi-plug"></i> Conectar y probar';

			if(!r.ok){ fncToastr("error", r.error || "No se pudo conectar"); return; }

			gForm.credentials.value = "";
			fncSweetAlert("success", r.message, "");
			gcalState();
		});
	});

	document.getElementById("gcalSync").onclick = function(){

		var btn = this;
		btn.disabled = true;

		request({action: "gcal_sync"}).then(function(r){
			btn.disabled = false;
			fncToastr(r.ok ? "success" : "error", r.ok ? r.message : r.error);
		});
	};

	document.getElementById("gcalOff").onclick = function(){

		if(!confirm("¿Desconectar Google Calendar? Los eventos ya creados se quedan en tu calendario.")){ return; }

		request({action: "gcal_disconnect"}).then(function(r){
			if(r.ok){ fncToastr("success", "Google Calendar desconectado"); gcalState(); }
		});
	};

	document.getElementById("icsCopy").onclick = function(){

		var input = document.getElementById("icsUrl");
		navigator.clipboard.writeText(input.value).then(function(){ fncToastr("success", "Enlace copiado"); });
	};

	document.getElementById("icsReset").onclick = function(){

		if(!confirm("El enlace anterior dejará de funcionar. ¿Generar uno nuevo?")){ return; }

		request({action: "ics_reset"}).then(function(r){ if(r.ok){ gcalState(); fncToastr("success", "Enlace nuevo generado"); } });
	};

	load();

})();
