/*=============================================
Inconvenientes de la atención
=============================================*/

(function(){

	var cfg = window.incConfig || {};
	var status = "";
	var items = [];
	var timer = null;

	var statusLabels = {"abierto": "Abierto", "en_proceso": "En proceso", "resuelto": "Resuelto"};
	var sourceLabels = {"cliente": "Cliente", "equipo": "Equipo (WhatsApp)", "panel": "Panel", "sistema": "Sistema", "ia": "IA"};
	var kindIcons = {"cancelacion": "bi-x-octagon", "reclamo": "bi-emoji-frown", "demora": "bi-clock-history", "error": "bi-bug", "sistema": "bi-cpu", "sugerencia": "bi-lightbulb", "otro": "bi-three-dots"};

	function esc(v){
		return String(v == null ? "" : v).replace(/[&<>"']/g, function(c){
			return {"&":"&amp;","<":"&lt;",">":"&gt;",'"':"&quot;","'":"&#39;"}[c];
		});
	}

	function request(fields){

		var body = new FormData();
		Object.keys(fields).forEach(function(k){ body.append(k, fields[k]); });

		return fetch("/ajax/business.ajax.php", {
			method: "POST",
			headers: {"X-CSRF-Token": window.bizCsrf},
			body: body,
			credentials: "same-origin"
		}).then(function(r){
			return r.json().catch(function(){ return {ok: false, error: "Respuesta inválida del servidor"}; });
		}).catch(function(){
			return {ok: false, error: "Sin conexión"};
		});

	}

	function toast(text, type){
		if(window.fncToastr){ fncToastr(type || "success", text); } else { alert(text); }
	}

	function when(dt){

		var d = new Date(String(dt || "").replace(" ", "T"));

		if(isNaN(d.getTime())){ return ""; }

		return d.toLocaleDateString("es-PE", {day: "2-digit", month: "short"}) + " · " + d.toLocaleTimeString("es-PE", {hour: "numeric", minute: "2-digit"});
	}

	function fromDate(){

		var period = document.getElementById("incPeriod").value;

		if(period === ""){ return ""; }

		var d = new Date(cfg.today + "T00:00:00");

		if(period !== "today"){ d.setDate(d.getDate() - (Number(period) - 1)); }

		return d.getFullYear() + "-" + String(d.getMonth() + 1).padStart(2, "0") + "-" + String(d.getDate()).padStart(2, "0");
	}

	function render(){

		var box = document.getElementById("incList");

		if(!items.length){
			box.innerHTML = '<div class="empty"><i class="bi bi-emoji-smile"></i><p>No hay inconvenientes con estos filtros.</p></div>';
			return;
		}

		box.innerHTML = items.map(function(i){

			var client = i.phone_incident
				? '<a href="/chat?phone=' + encodeURIComponent(i.phone_incident) + '"><i class="bi bi-person"></i> ' + esc(i.name_incident || ("+" + i.phone_incident)) + '</a>'
				: '<span class="text-muted">Sin cliente</span>';

			var order = i.order_incident ? ' · <a href="/ordenes?q=' + encodeURIComponent("#" + i.order_incident) + '">Orden #' + esc(i.order_incident) + '</a>' : "";
			var st = i.status_incident || "abierto";
			var chip = st === "resuelto" ? "chip" : (st === "en_proceso" ? "chip" : "chip warn");

			var feedback = String(i.feedback_incident || "").trim() !== ""
				? '<div class="inq-a"><i class="bi bi-chat-left-quote"></i> <strong>Comentario del cliente:</strong> ' + esc(i.feedback_incident).replace(/\n/g, "<br>") + '</div>'
				: (i.kind_incident !== "sistema" ? '<div class="small text-muted mt-1"><i class="bi bi-hourglass"></i> Esperando el comentario del cliente</div>' : "");

			var note = String(i.note_incident || "").trim() !== ""
				? '<div class="small mt-1"><i class="bi bi-journal-text"></i> <strong>Nota:</strong> ' + esc(i.note_incident) + '</div>'
				: "";

			return '<div class="inq-item' + (st === "resuelto" ? " paused" : " pending") + '" data-id="' + i.id_incident + '">' +
				'<div class="inq-meta"><span class="' + chip + '">#' + esc(i.id_incident) + ' · ' + esc(statusLabels[st] || st) + '</span> ' +
					'<span class="chip"><i class="bi ' + (kindIcons[i.kind_incident] || "bi-three-dots") + '"></i> ' + esc(cfg.kinds[i.kind_incident] || i.kind_incident) + '</span> ' +
					client + order +
					' <span class="text-muted">· ' + esc(when(i.created_incident)) + ' · ' + esc(sourceLabels[i.source_incident] || i.source_incident || "") + '</span></div>' +
				'<div class="inq-q"><i class="bi bi-exclamation-circle"></i> ' + esc(i.detail_incident) + '</div>' +
				feedback + note +
				'<div class="inq-actions">' +
					(st !== "resuelto" ? '<button type="button" class="btn btn-brand btn-sm" data-inc-resolve="' + i.id_incident + '"><i class="bi bi-check2"></i> Resuelto</button>' : "") +
					'<button type="button" class="btn btn-ghost btn-sm" data-inc-edit="' + i.id_incident + '"><i class="bi bi-pencil"></i> Estado y nota</button>' +
					(cfg.canDelete ? '<button type="button" class="btn-icon" data-inc-delete="' + i.id_incident + '" title="Eliminar"><i class="bi bi-trash3"></i></button>' : "") +
				'</div>' +
			'</div>';

		}).join("");

	}

	function renderStats(s){

		document.querySelectorAll("#incKpis [data-kpi]").forEach(function(el){
			el.textContent = Number(s[el.getAttribute("data-kpi")] || 0);
		});

		var top = document.getElementById("incTop");
		var kinds = Object.keys(s.kinds || {});

		if(!kinds.length){ top.classList.add("d-none"); return; }

		top.classList.remove("d-none");
		top.querySelector("div").innerHTML = "<strong>Últimos 30 días:</strong> " + kinds.map(function(k){
			return esc(cfg.kinds[k] || k) + " <strong>" + s.kinds[k] + "</strong>";
		}).join(" · ");

	}

	function load(){

		request({
			action: "incidents",
			status: status,
			kind: document.getElementById("incKind").value,
			from: fromDate(),
			search: document.getElementById("incSearch").value.trim()
		}).then(function(res){

			if(!res.ok){
				document.getElementById("incList").innerHTML = '<div class="empty"><i class="bi bi-exclamation-triangle"></i><p>' + esc(res.error || "No se pudo cargar") + '</p></div>';
				return;
			}

			items = res.items || [];
			render();
			renderStats(res.stats || {});

		});

	}

	function find(id){
		return items.filter(function(i){ return String(i.id_incident) === String(id); })[0];
	}

	function save(id, st, note){

		return request({action: "incident_update", id: id, status: st, note: note}).then(function(res){
			if(res.ok){ toast("Guardado"); load(); } else { toast(res.error || "No se pudo guardar", "error"); }
			return res;
		});

	}

	/* Filtros */

	document.querySelectorAll("#incStatus button").forEach(function(b){
		b.addEventListener("click", function(){
			document.querySelectorAll("#incStatus button").forEach(function(x){ x.classList.remove("active"); });
			b.classList.add("active");
			status = b.getAttribute("data-status");
			load();
		});
	});

	document.getElementById("incKind").addEventListener("change", load);
	document.getElementById("incPeriod").addEventListener("change", load);
	document.getElementById("incReload").addEventListener("click", load);
	document.getElementById("incSearch").addEventListener("input", function(){
		clearTimeout(timer);
		timer = setTimeout(load, 350);
	});

	/* Acciones */

	var modalEl = document.getElementById("incModal");
	var modal = window.bootstrap ? new bootstrap.Modal(modalEl) : null;
	var form = document.getElementById("incForm");

	document.getElementById("incList").addEventListener("click", function(e){

		var b = e.target.closest("button");

		if(!b){ return; }

		if(b.hasAttribute("data-inc-resolve")){

			var item = find(b.getAttribute("data-inc-resolve"));
			b.disabled = true;
			save(item.id_incident, "resuelto", item.note_incident || "");
			return;
		}

		if(b.hasAttribute("data-inc-edit")){

			var it = find(b.getAttribute("data-inc-edit"));
			form.id.value = it.id_incident;
			form.status.value = it.status_incident || "abierto";
			form.note.value = it.note_incident || "";
			document.getElementById("incModalTitle").textContent = "Inconveniente #" + it.id_incident;
			if(modal){ modal.show(); }
			return;
		}

		if(b.hasAttribute("data-inc-delete")){

			if(!confirm("¿Eliminar este registro? No se puede deshacer.")){ return; }

			request({action: "incident_delete", id: b.getAttribute("data-inc-delete")}).then(function(res){
				if(res.ok){ toast("Eliminado"); load(); } else { toast(res.error || "No se pudo eliminar", "error"); }
			});
		}

	});

	form.addEventListener("submit", function(e){

		e.preventDefault();

		save(form.id.value, form.status.value, form.note.value).then(function(res){
			if(res.ok && modal){ modal.hide(); }
		});

	});

	load();
	setInterval(function(){ if(!document.hidden){ load(); } }, 60000);

})();
