/*=============================================
Chat en vivo
- Una sola petición periódica (mensajes nuevos, estado
  de entrega y lista de contactos)
- Cada 3 s con la pestaña visible, cada 20 s en segundo plano
- Historial permanente: "Cargar mensajes anteriores"
  (también al llegar arriba con el scroll)
=============================================*/

(function(){

	var state = window.chatState || {phone: "", firstId: 0, lastId: 0, lastDay: "", globalId: 0, hasMore: false, moreContacts: false};
	var app = document.getElementById("chatApp");
	var body = document.getElementById("chatBody");
	var list = document.getElementById("contactList");
	var polling = false;
	var loadingOlder = false;
	var timer = null;
	var stopped = false;
	var filter = "";
	var search = "";
	var contactsOffset = 0;
	var mobile = window.matchMedia("(max-width: 991.98px)");

	try{ filter = localStorage.getItem("chatFilter") || ""; }catch(e){}

	function post(data){

		var form = new FormData();

		Object.keys(data).forEach(function(key){ form.append(key, data[key]); });

		return fetch("/ajax/chat.ajax.php", {method: "POST", body: form, credentials: "same-origin"})
			.then(function(r){
				return r.json().then(function(json){
					if(r.status === 401 && json.expired){
						stopped = true;
						fncSweetAlert("error", json.error, setTimeout(function(){ window.location = "/logout"; }, 2500));
					}
					return json;
				});
			});
	}

	function scrollToEnd(smooth){

		if(body){
			body.scrollTo({top: body.scrollHeight, behavior: smooth ? "smooth" : "auto"});
		}
	}

	function nearBottom(){

		return !body || body.scrollHeight - body.scrollTop - body.clientHeight < 160;
	}

	function play(id){

		var audio = document.getElementById(id);

		if(audio){
			audio.currentTime = 0;
			audio.play().catch(function(){});
		}
	}

	/*=============================================
	Consulta periódica
	=============================================*/

	function schedule(){

		clearTimeout(timer);

		if(!stopped){
			timer = setTimeout(poll, document.hidden ? 20000 : 3000);
		}
	}

	function poll(){

		if(polling || stopped){ return schedule(); }

		polling = true;

		post({
			action: "poll",
			phone: state.phone,
			lastId: state.lastId,
			lastDay: state.lastDay,
			globalId: state.globalId,
			filter: filter,
			search: search
		})
		.then(function(res){

			if(!res || !res.ok){ return; }

			/* Mensajes nuevos del chat abierto */

			if(res.html && res.lastId > state.lastId){

				var stick = nearBottom();

				$("#chatMessages").append(res.html);
				state.lastId = res.lastId;
				state.lastDay = res.lastDay || state.lastDay;

				if(stick){ scrollToEnd(true); }

				if(res.newClient){ play("messageSound"); }
			}

			/* Estado de entrega (✓ enviado, ✓✓ entregado, azul leído, ! fallido) */

			Object.keys(res.statuses || {}).forEach(function(id){

				var item = res.statuses[id];
				var msg = $('.msg[data-id="' + id + '"]');

				msg.find(".msg-status").html(item.icon);

				if(item.failed && msg.find(".msg-error").length === 0){
					msg.addClass("failed");
					$('<div class="msg-error"></div>')
						.append('<i class="bi bi-exclamation-triangle"></i> ')
						.append(document.createTextNode(item.error || "No se pudo enviar a WhatsApp"))
						.insertAfter(msg.find(".msg-body"));
				}
			});

			/* Lista de contactos (solo cuando hubo cambios y se ve la primera página) */

			if(typeof res.contacts === "string" && contactsOffset === 0){

				list.innerHTML = res.contacts || emptyList();
				$("#moreContacts").prop("hidden", !res.more);

				if(res.newChat){ play("chatSound"); }
			}

			state.globalId = res.globalId || state.globalId;

		})
		.catch(function(){})
		.finally(function(){

			polling = false;
			schedule();
		});
	}

	document.addEventListener("visibilitychange", function(){

		if(!document.hidden){ poll(); }
	});

	function emptyList(){

		return '<div class="chat-empty-list"><i class="bi bi-search"></i><p>' + (search ? "Sin resultados para «" + $("<div>").text(search).html() + "»" : "No hay conversaciones en este filtro.") + '</p></div>';
	}

	/*=============================================
	Historial: cargar mensajes anteriores
	=============================================*/

	function loadOlder(){

		if(loadingOlder || !state.hasMore || !state.phone || !state.firstId){ return; }

		loadingOlder = true;

		var btn = $("#loadOlder").prop("disabled", true).html('<span class="spinner-border spinner-border-sm"></span> Cargando…');
		var previousHeight = body.scrollHeight;

		post({action: "history", phone: state.phone, beforeId: state.firstId})
		.then(function(res){

			if(!res.ok){ fncToastr("error", res.error || "No se pudo cargar el historial"); return; }

			var box = document.getElementById("chatMessages");
			var firstDate = box.querySelector(".chat-date");
			var chunk = $("<div>").html(res.html);
			var lastChunkDate = chunk.find(".chat-date").last();

			/* Si el bloque anterior termina el mismo día, quitar la etiqueta repetida */

			if(firstDate && lastChunkDate.length && lastChunkDate.attr("data-day") === firstDate.getAttribute("data-day")){
				firstDate.remove();
			}

			$(box).prepend(chunk.children());

			state.firstId = res.firstId;
			state.hasMore = !!res.more;

			$("#olderBox").prop("hidden", !state.hasMore);
			$("#chatStart").prop("hidden", state.hasMore);

			/* Mantener la posición de lectura */
			body.scrollTop = body.scrollHeight - previousHeight;

		})
		.catch(function(){ fncToastr("error", "Sin conexión con el servidor."); })
		.finally(function(){

			loadingOlder = false;
			btn.prop("disabled", false).html('<i class="bi bi-clock-history"></i> Cargar mensajes anteriores');
		});
	}

	$(document).on("click", "#loadOlder", loadOlder);

	if(body){

		body.addEventListener("scroll", function(){

			if(body.scrollTop < 80){ loadOlder(); }
		});
	}

	/*=============================================
	Enviar mensaje manual
	=============================================*/

	var input = document.getElementById("userInput");

	function autosize(){

		if(!input){ return; }
		input.style.height = "auto";
		input.style.height = Math.min(input.scrollHeight, 160) + "px";
	}

	function sendMessage(text){

		text = $.trim(text);

		if(text === "" || !state.phone){ return; }

		var btn = $(".send").prop("disabled", true);

		post({action: "send", phone: state.phone, conversation: text})
		.then(function(res){

			if(!res.ok){
				fncToastr("error", res.error || "No se pudo enviar el mensaje");
			}

			scrollToEnd(true);
			poll();
		})
		.catch(function(){

			fncToastr("error", "Sin conexión con el servidor. El mensaje no se envió.");
			if(input && !input.value){ input.value = text; autosize(); }
		})
		.finally(function(){

			btn.prop("disabled", false);
			if(input){ input.focus(); }
		});
	}

	$(document).on("click", ".send", function(){

		var text = input.value;
		input.value = "";
		autosize();
		sendMessage(text);
	});

	$(document).on("keydown", "#userInput", function(e){

		if(e.key === "Enter" && !e.shiftKey && !e.isComposing){
			e.preventDefault();
			$(".send").trigger("click");
		}
	});

	$(document).on("input", "#userInput", autosize);

	/* Archivo elegido en el administrador de archivos: se envía al cerrar */

	$("#myFiles").on("hidden.bs.modal", function(){

		var url = $("#attachUrl").val();

		if(url){
			$("#attachUrl").val("");
			sendMessage(url);
		}
	});

	/*=============================================
	Activar / desactivar asistente IA por contacto
	=============================================*/

	$(document).on("change", ".changeAI", function(){

		var check = $(this);
		var idContact = check.attr("idContact");
		var aiContact = check.prop("checked") ? 1 : 0;

		post({action: "ai", idContact: idContact, aiContact: aiContact})
		.then(function(res){

			if(res.ok){

				$('.changeAI[idContact="' + idContact + '"]').prop("checked", !!aiContact);
				$("#aiBadge").prop("hidden", !aiContact);
				fncToastr("success", aiContact ? "Asistente IA activado para este contacto" : "Asistente IA desactivado: solo responde tu equipo");
				state.globalId = 0;

			}else{

				check.prop("checked", !aiContact);
				fncToastr("error", "Error al actualizar el contacto");
			}
		});
	});

	/*=============================================
	Editar contacto
	=============================================*/

	$(document).on("click", ".changeContact", function(){

		var dataContact = JSON.parse($(this).attr("infoContact"));

		$("#id_contact").val(dataContact.id_contact);
		$("#phone_contact").val(dataContact.phone_contact);
		$("#name_contact").val(dataContact.name_contact);
		$("#myContact").modal("show");
	});

	/*=============================================
	Lista: búsqueda, filtros y "ver más"
	=============================================*/

	function reloadContacts(append){

		if(!append){ contactsOffset = 0; }

		return post({action: "contacts", phone: state.phone, search: search, filter: filter, offset: contactsOffset})
		.then(function(res){

			if(!res.ok){ return; }

			if(append){
				list.insertAdjacentHTML("beforeend", res.contacts);
			}else{
				list.innerHTML = res.contacts || emptyList();
			}

			$("#moreContacts").prop("hidden", !res.more);
		});
	}

	var searchTimer = null;

	$(document).on("input", ".searchContact", function(){

		var value = $.trim($(this).val());

		clearTimeout(searchTimer);

		searchTimer = setTimeout(function(){
			search = value;
			reloadContacts(false);
		}, 350);
	});

	$(document).on("click", ".chat-filters button", function(){

		filter = $(this).attr("data-filter");
		$(".chat-filters button").removeClass("active");
		$(this).addClass("active");

		try{ localStorage.setItem("chatFilter", filter); }catch(e){}

		reloadContacts(false);
	});

	$("#moreContacts").on("click", function(){

		contactsOffset += 40;
		reloadContacts(true);
	});

	$(document).on("click", "#contactList .ct", function(){

		$(this).addClass("loading");
	});

	/*=============================================
	Panel de información (pedidos y citas)
	=============================================*/

	var info = document.getElementById("chatInfo");

	function setInfo(open){

		if(!info){ return; }

		app.classList.toggle("info-open", open);

		try{ localStorage.setItem("chatInfo", open ? "1" : "0"); }catch(e){}
	}

	$("#infoToggle").on("click", function(){

		var open = !app.classList.contains("info-open");
		setInfo(open);

		if(open && state.phone){
			post({action: "info", phone: state.phone}).then(function(res){ if(res.ok){ $("#chatInfoBody").html(res.html); } });
		}
	});

	$("#infoClose").on("click", function(){ setInfo(false); });

	try{
		if(!mobile.matches && localStorage.getItem("chatInfo") !== "0" && state.phone && window.innerWidth >= 1280){
			app.classList.add("info-open");
		}
	}catch(e){}

	/*=============================================
	Móvil: lista ↔ conversación
	=============================================*/

	$("#chatBack").on("click", function(){

		if(mobile.matches){
			app.classList.remove("has-chat");
			history.replaceState(null, "", "/chat");
		}else{
			window.location = "/chat";
		}
	});

	/* Escritorio sin chat abierto: abrir el más reciente */

	if(!state.phone && !mobile.matches){

		var first = list.querySelector(".ct");

		if(first){ window.location = first.getAttribute("href"); return; }
	}

	/* Restaurar el filtro guardado */

	if(filter){
		$('.chat-filters button[data-filter="' + filter + '"]').trigger("click");
	}

	/* Las imágenes cambian la altura al cargar: seguir abajo al abrir el chat */

	var openedAt = Date.now();

	if(body){
		body.addEventListener("load", function(e){
			if(e.target.tagName === "IMG" && (Date.now() - openedAt < 4000 || nearBottom())){ scrollToEnd(false); }
		}, true);
	}

	$(function(){

		scrollToEnd(false);
		autosize();
		schedule();

		if(input && !mobile.matches){ input.focus(); }
	});

})();
