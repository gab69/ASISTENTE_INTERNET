/*=============================================
Mi negocio → Consultas: preguntas que la IA envió
al equipo y respuestas aprendidas
=============================================*/

(function(){

	var pendingBox = document.getElementById("inqPending");
	var learnedBox = document.getElementById("inqLearned");

	if(!pendingBox){ return; }

	var items = [];

	function esc(v){
		return String(v == null ? "" : v).replace(/[&<>"']/g, function(c){
			return {"&":"&amp;","<":"&lt;",">":"&gt;",'"':"&quot;","'":"&#39;"}[c];
		});
	}

	function when(dt){

		if(!dt){ return ""; }

		var d = new Date(String(dt).replace(" ", "T"));

		if(isNaN(d)){ return dt; }

		return d.toLocaleDateString("es", {day: "numeric", month: "short"}) + " · " + d.toLocaleTimeString("es", {hour: "numeric", minute: "2-digit"});

	}

	function find(id){
		return items.filter(function(i){ return String(i.id_inquiry) === String(id); })[0];
	}

	/*=============================================
	Pintar
	=============================================*/

	function render(){

		var pending = items.filter(function(i){ return i.status_inquiry === "pendiente"; });
		var learned = items.filter(function(i){ return i.status_inquiry === "respondida"; });
		var badge = document.getElementById("inqBadge");

		badge.hidden = !pending.length;
		badge.textContent = pending.length;

		pendingBox.innerHTML = pending.length ? pending.map(function(i){

			var client = i.phone_inquiry
				? '<a href="/chat?phone=' + encodeURIComponent(i.phone_inquiry) + '"><i class="bi bi-person"></i> ' + esc(i.name_inquiry || ("+" + i.phone_inquiry)) + '</a>'
				: '<span><i class="bi bi-person"></i> Sin cliente</span>';

			var warn = i.wamid_inquiry ? "" :
				'<div class="inq-warn"><i class="bi bi-exclamation-triangle"></i> El aviso no llegó al número del equipo. ' +
				'<button type="button" class="btn-link-inline" data-inq-notify="' + i.id_inquiry + '">Reenviar aviso</button></div>';

			return '<div class="inq-item pending" data-id="' + i.id_inquiry + '">' +
				'<div class="inq-meta"><span class="chip warn">#' + i.id_inquiry + '</span> ' + client + ' <span class="text-muted">· ' + esc(when(i.date_created_inquiry)) + '</span></div>' +
				'<div class="inq-q"><i class="bi bi-chat-left-quote"></i> ' + esc(i.question_inquiry) + '</div>' +
				warn +
				'<textarea class="form-control mt-2" rows="2" placeholder="Escribe la respuesta para el cliente…" data-answer></textarea>' +
				'<div class="inq-actions">' +
					'<label class="form-check-label small"><input type="checkbox" class="form-check-input me-1" data-send checked> Enviar al cliente</label>' +
					'<label class="form-check-label small"><input type="checkbox" class="form-check-input me-1" data-learn checked> Recordar para próximas consultas</label>' +
					'<span class="ms-auto d-flex gap-2">' +
						'<button type="button" class="btn btn-ghost btn-sm" data-inq-discard="' + i.id_inquiry + '"><i class="bi bi-x-lg"></i> Descartar</button>' +
						'<button type="button" class="btn btn-brand btn-sm" data-inq-answer="' + i.id_inquiry + '"><i class="bi bi-send"></i> Responder</button>' +
					'</span>' +
				'</div>' +
			'</div>';

		}).join("") : '<div class="empty-mini"><i class="bi bi-check2-circle"></i> No hay consultas pendientes.</div>';

		renderLearned(learned);

	}

	function renderLearned(learned){

		var q = (document.getElementById("inqSearch").value || "").toLowerCase().trim();

		if(q){
			learned = learned.filter(function(i){ return (i.question_inquiry + " " + i.answer_inquiry).toLowerCase().indexOf(q) > -1; });
		}

		learnedBox.innerHTML = learned.length ? learned.map(function(i){

			return '<div class="inq-item' + (Number(i.learn_inquiry) ? "" : " paused") + '" data-id="' + i.id_inquiry + '">' +
				'<div class="inq-q"><i class="bi bi-question-circle"></i> ' + esc(i.question_inquiry) + '</div>' +
				'<div class="inq-a">' + esc(i.answer_inquiry).replace(/\n/g, "<br>") + '</div>' +
				'<div class="inq-actions">' +
					'<div class="form-check form-switch m-0" title="Si está apagada, la IA no usa esta respuesta">' +
						'<input class="form-check-input" type="checkbox" role="switch" data-inq-learn="' + i.id_inquiry + '" ' + (Number(i.learn_inquiry) ? "checked" : "") + '>' +
						'<label class="form-check-label small">' + (Number(i.learn_inquiry) ? "La IA la usa" : "Pausada") + '</label>' +
					'</div>' +
					(i.name_inquiry || i.phone_inquiry ? '<span class="small text-muted">Preguntó ' + esc(i.name_inquiry || ("+" + i.phone_inquiry)) + '</span>' : "") +
					'<span class="ms-auto d-flex gap-1">' +
						'<button type="button" class="btn-icon" data-inq-edit="' + i.id_inquiry + '" title="Editar"><i class="bi bi-pencil"></i></button>' +
						'<button type="button" class="btn-icon" data-inq-delete="' + i.id_inquiry + '" title="Eliminar"><i class="bi bi-trash3"></i></button>' +
					'</span>' +
				'</div>' +
			'</div>';

		}).join("") : '<div class="empty-mini"><i class="bi bi-journal-text"></i> ' + (q ? "Sin resultados." : "Aún no hay respuestas guardadas. Se agregan solas cuando respondes una consulta.") + '</div>';

	}

	function load(){

		bizRequest({action: "inquiries"}).then(function(res){

			if(!res.ok){ fncToastr("error", res.error || "No se pudieron cargar las consultas"); return; }

			items = res.items || [];
			render();

		});

	}

	/*=============================================
	Editor de respuestas (agregar / editar)
	=============================================*/

	function editor(item){

		var html =
			'<div class="modal fade" id="inqModal" tabindex="-1"><div class="modal-dialog modal-dialog-centered"><div class="modal-content">' +
				'<form>' +
				'<div class="modal-header"><h5 class="modal-title">' + (item ? "Editar respuesta" : "Nueva respuesta") + '</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button></div>' +
				'<div class="modal-body">' +
					'<label class="form-label">Pregunta del cliente</label>' +
					'<input class="form-control mb-3" name="question" required maxlength="1000" placeholder="Ej.: ¿Atienden los domingos?" value="' + esc(item ? item.question_inquiry : "") + '">' +
					'<label class="form-label">Respuesta</label>' +
					'<textarea class="form-control mb-3" name="answer" rows="4" required placeholder="Ej.: Sí, los domingos atendemos de 9 a 1.">' + esc(item ? item.answer_inquiry : "") + '</textarea>' +
					'<div class="form-check form-switch"><input class="form-check-input" type="checkbox" role="switch" name="learn" id="inqLearnField" ' + (!item || Number(item.learn_inquiry) ? "checked" : "") + '><label class="form-check-label" for="inqLearnField">La IA puede usar esta respuesta</label></div>' +
				'</div>' +
				'<div class="modal-footer"><button type="button" class="btn btn-ghost" data-bs-dismiss="modal">Cancelar</button><button class="btn btn-brand" type="submit"><i class="bi bi-check2"></i> Guardar</button></div>' +
				'</form>' +
			'</div></div></div>';

		var old = document.getElementById("inqModal");
		if(old){ old.remove(); }

		document.body.insertAdjacentHTML("beforeend", html);

		var el = document.getElementById("inqModal");
		var modal = new bootstrap.Modal(el);
		var form = el.querySelector("form");

		form.addEventListener("submit", function(e){

			e.preventDefault();

			bizRequest({
				action: "inquiry_save",
				id: item ? item.id_inquiry : 0,
				question: form.question.value,
				answer: form.answer.value,
				learn: form.learn.checked ? 1 : ""
			}).then(function(res){

				if(!res.ok){ fncToastr("error", res.error || "No se pudo guardar"); return; }

				modal.hide();
				fncToastr("success", "Respuesta guardada");
				load();

			});

		});

		el.addEventListener("hidden.bs.modal", function(){ el.remove(); });
		modal.show();

	}

	/*=============================================
	Acciones
	=============================================*/

	document.addEventListener("click", function(e){

		var b;

		if((b = e.target.closest("[data-inq-answer]"))){

			var box = b.closest(".inq-item");
			var answer = box.querySelector("[data-answer]").value.trim();

			if(!answer){ fncToastr("error", "Escribe la respuesta."); return; }

			b.disabled = true;

			bizRequest({
				action: "inquiry_answer",
				id: b.getAttribute("data-inq-answer"),
				answer: answer,
				send: box.querySelector("[data-send]").checked ? 1 : "",
				learn: box.querySelector("[data-learn]").checked ? 1 : ""
			}).then(function(res){

				b.disabled = false;

				if(!res.ok){ fncToastr("error", res.error || "No se pudo guardar"); return; }

				if(box.querySelector("[data-send]").checked && !res.delivered){
					fncToastr("warning", "Respuesta guardada, pero no se pudo enviar al cliente: " + (res.error || "WhatsApp la rechazó"));
				}else{
					fncToastr("success", box.querySelector("[data-send]").checked ? "Respuesta enviada al cliente" : "Respuesta guardada");
				}

				load();

			});

			return;
		}

		if((b = e.target.closest("[data-inq-discard]"))){

			bizRequest({action: "inquiry_status", id: b.getAttribute("data-inq-discard"), status: "descartada"}).then(function(res){
				if(res.ok){ fncToastr("success", "Consulta descartada"); load(); }
			});

			return;
		}

		if((b = e.target.closest("[data-inq-notify]"))){

			b.disabled = true;

			bizRequest({action: "inquiry_notify", id: b.getAttribute("data-inq-notify")}).then(function(res){
				b.disabled = false;
				fncToastr(res.ok ? "success" : "error", res.ok ? "Aviso enviado al equipo" : res.error);
				if(res.ok){ load(); }
			});

			return;
		}

		if((b = e.target.closest("[data-inq-edit]"))){

			editor(find(b.getAttribute("data-inq-edit")));
			return;
		}

		if((b = e.target.closest("[data-inq-delete]"))){

			var id = b.getAttribute("data-inq-delete");

			fncSweetAlert("confirm", "¿Eliminar esta respuesta?", "La IA dejará de usarla.").then(function(ok){

				if(!ok){ return; }

				bizRequest({action: "inquiry_delete", id: id}).then(function(res){
					if(res.ok){ fncToastr("success", "Respuesta eliminada"); load(); }
				});

			});
		}

	});

	document.addEventListener("change", function(e){

		var t = e.target.closest("[data-inq-learn]");

		if(!t){ return; }

		bizRequest({action: "inquiry_learn", id: t.getAttribute("data-inq-learn"), learn: t.checked ? 1 : ""}).then(function(res){

			if(!res.ok){ t.checked = !t.checked; fncToastr("error", "No se pudo actualizar"); return; }

			var item = find(t.getAttribute("data-inq-learn"));
			if(item){ item.learn_inquiry = t.checked ? 1 : 0; }
			render();

		});

	});

	document.getElementById("inqAdd").addEventListener("click", function(){ editor(null); });
	document.getElementById("inqReload").addEventListener("click", load);
	document.getElementById("inqSearch").addEventListener("input", function(){
		renderLearned(items.filter(function(i){ return i.status_inquiry === "respondida"; }));
	});

	load();

})();
