/*=============================================
WhatsApp: conectar el número con verificación en Meta
=============================================*/

(function(){

	var items = [];
	var modal = new bootstrap.Modal(document.getElementById("waModal"));
	var form = document.getElementById("waForm");
	var result = document.getElementById("waResult");
	var forceBtn = document.getElementById("waForce");

	function esc(v){
		return String(v == null ? "" : v).replace(/[&<>"']/g, function(c){
			return {"&":"&amp;","<":"&lt;",">":"&gt;",'"':"&quot;","'":"&#39;"}[c];
		});
	}

	function request(fields){

		var body = new FormData();
		Object.keys(fields).forEach(function(k){ body.append(k, fields[k]); });

		/* Nunca esperar sin respuesta: máximo 20 s */

		var controller = window.AbortController ? new AbortController() : null;
		var timer = setTimeout(function(){ if(controller){ controller.abort(); } }, 20000);

		return fetch("/ajax/whatsapp.ajax.php", {method: "POST", headers: {"X-CSRF-Token": window.bizCsrf}, body: body, credentials: "same-origin", signal: controller ? controller.signal : undefined})
			.then(function(r){ return r.json().catch(function(){ return {ok: false, error: "Respuesta inválida del servidor (revisa logs/php_error.log)."}; }); })
			.catch(function(e){ return {ok: false, error: e && e.name === "AbortError" ? "El servidor tardó demasiado en responder. Inténtalo de nuevo." : "Sin conexión con el servidor."}; })
			.finally(function(){ clearTimeout(timer); });
	}

	function show(state, text){
		result.className = "geo-result show " + state;
		result.textContent = text;
	}

	/*=============================================
	Lista de números
	=============================================*/

	function render(){

		var box = document.getElementById("waList");

		if(!items.length){
			box.innerHTML = '<div class="card-soft text-center py-5"><div class="empty"><i class="bi bi-whatsapp"></i><p>Aún no conectas tu número de WhatsApp Business.</p><button class="btn btn-brand" data-act="add"><i class="bi bi-plus-lg"></i> Conectar número</button></div></div>';
			return;
		}

		box.innerHTML = items.map(function(a){

			var number = a.number_whatsapp ? "+" + String(a.number_whatsapp).replace(/\D/g, "") : "Número sin verificar";

			return '<div class="wa-account' + (Number(a.status_whatsapp) ? " active" : "") + '" data-id="' + a.id_whatsapp + '">' +
				'<span class="wa-account-ico"><i class="bi bi-whatsapp"></i></span>' +
				'<div class="wa-account-main">' +
					'<strong>' + esc(number) + '</strong>' +
					'<small>ID ' + esc(a.id_number_whatsapp || "—") + ' · token ' + esc(a.token_hint || "sin token") + '</small>' +
					'<div class="wa-account-tags">' +
						(Number(a.status_whatsapp) ? '<span class="st st-entregado">Activo</span>' : '<span class="st">Inactivo</span>') +
						(Number(a.ai_whatsapp) ? '<span class="st st-confirmado"><i class="bi bi-stars"></i> IA para nuevos</span>' : "") +
						'<span class="wa-state" data-state></span>' +
					'</div>' +
				'</div>' +
				'<div class="wa-account-actions">' +
					'<button class="btn btn-ghost btn-sm" data-act="verify"><i class="bi bi-plug"></i> Probar conexión</button>' +
					'<button class="btn btn-ghost btn-sm" data-act="test"><i class="bi bi-send"></i> Mensaje de prueba</button>' +
					'<button class="btn-icon" data-act="edit" title="Editar"><i class="bi bi-pencil"></i></button>' +
					'<button class="btn-icon" data-act="delete" title="Eliminar"><i class="bi bi-trash3"></i></button>' +
				'</div>' +
			'</div>';

		}).join("");

		/* Verificar al abrir la página el número activo */

		var active = items.find(function(a){ return Number(a.status_whatsapp); });

		if(active){ verify(active.id_whatsapp, true); }
	}

	function load(){

		return request({action: "list"}).then(function(r){

			if(!r.ok){ document.getElementById("waList").innerHTML = '<div class="callout warn"><i class="bi bi-exclamation-triangle"></i><div>' + esc(r.error) + '</div></div>'; return; }

			items = r.items || [];
			render();
		});
	}

	function verify(id, silent){

		var card = document.querySelector('.wa-account[data-id="' + id + '"]');
		var state = card ? card.querySelector("[data-state]") : null;

		if(state){ state.className = "wa-state wait"; state.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Verificando con Meta…'; }

		return request({action: "verify", id: id}).then(function(r){

			if(state){
				state.className = "wa-state " + (r.ok ? "ok" : "bad");
				state.innerHTML = (r.ok ? '<i class="bi bi-check-circle-fill"></i> ' : '<i class="bi bi-x-circle-fill"></i> ') + esc(r.ok ? r.message : r.error);
			}

			if(!silent){ fncToastr(r.ok ? "success" : "error", r.ok ? r.message : r.error); }
		});
	}

	/*=============================================
	Formulario
	=============================================*/

	function openForm(a){

		form.reset();
		result.className = "geo-result";
		forceBtn.hidden = true;

		form.id.value = a ? a.id_whatsapp : "";
		form.id_number.value = a ? a.id_number_whatsapp : "";
		form.id_app.value = a ? (a.id_app_whatsapp || "") : "";
		form.status.checked = a ? !!Number(a.status_whatsapp) : true;
		form.ai.checked = a ? !!Number(a.ai_whatsapp) : false;
		form.token.required = !a || !a.has_token;

		document.getElementById("waTokenHint").textContent = a && a.has_token ? "Guardado (" + a.token_hint + "). Déjalo vacío para conservarlo." : "Pégalo completo, sin espacios.";
		document.getElementById("waTitle").textContent = a ? "Editar número" : "Conectar número";

		modal.show();
	}

	function save(force){

		var btn = form.querySelector('[type="submit"]');
		btn.disabled = true;
		forceBtn.disabled = true;

		show("wait", "Verificando los datos con Meta… (unos segundos)");

		request({
			action: "save",
			id: form.id.value,
			id_number: form.id_number.value.trim(),
			id_app: form.id_app.value.trim(),
			token: form.token.value.replace(/\s+/g, ""),
			status: form.status.checked ? 1 : "",
			ai: form.ai.checked ? 1 : "",
			force: force ? 1 : ""
		}).then(function(r){

			btn.disabled = false;
			forceBtn.disabled = false;

			if(!r.ok){
				show("bad", r.error || "No se pudo guardar.");
				forceBtn.hidden = !r.can_force;
				return;
			}

			show(r.verified ? "ok" : "bad", r.message);
			fncToastr(r.verified ? "success" : "warning", r.message);

			setTimeout(function(){ modal.hide(); load(); }, r.verified ? 1200 : 2500);
		});
	}

	form.addEventListener("submit", function(e){ e.preventDefault(); save(false); });

	forceBtn.addEventListener("click", function(){

		if(confirm("Los datos no se pudieron verificar con Meta. ¿Guardar de todos modos?")){ save(true); }
	});

	/*=============================================
	Acciones
	=============================================*/

	document.getElementById("waAdd").addEventListener("click", function(){ openForm(null); });

	document.addEventListener("click", function(e){

		var btn = e.target.closest("[data-act]");
		if(!btn){ return; }

		var card = btn.closest(".wa-account");
		var id = card ? card.getAttribute("data-id") : null;
		var a = items.find(function(x){ return String(x.id_whatsapp) === String(id); });
		var act = btn.getAttribute("data-act");

		if(act === "add"){ openForm(null); }
		if(act === "edit"){ openForm(a); }
		if(act === "verify"){ verify(id, false); }

		if(act === "test"){

			var to = prompt("¿A qué número envío la prueba? (con código de país, ej.: 51987654321)");
			if(!to){ return; }

			btn.disabled = true;

			request({action: "test", id: id, to: to}).then(function(r){
				btn.disabled = false;
				fncToastr(r.ok ? "success" : "error", r.ok ? r.message : r.error);
			});
		}

		if(act === "delete"){

			if(!confirm("¿Eliminar este número? Dejará de responder a los clientes.")){ return; }

			request({action: "delete", id: id}).then(function(r){
				if(r.ok){ fncToastr("success", "Número eliminado"); load(); }
			});
		}
	});

	document.querySelectorAll("[data-copy]").forEach(function(b){
		b.addEventListener("click", function(){
			navigator.clipboard.writeText(b.getAttribute("data-copy")).then(function(){ fncToastr("success", "Copiado"); });
		});
	});

	load();

})();
