/*=============================================
Bots: editor guiado de los mensajes automáticos
=============================================*/

(function(){

	var bots = window.botsData || [];
	var categories = window.botsCategories || [];

	/*---------------------------------------------
	Qué hace cada bot (por su clave interna)
	---------------------------------------------*/

	var STAGES = [
		{id: "start", name: "Bienvenida", icon: "bi-emoji-smile", text: "El cliente escribe por primera vez"},
		{id: "order", name: "Elegir plan", icon: "bi-wifi", text: "Categorías y planes de internet / TV"},
		{id: "data", name: "Datos de instalación", icon: "bi-geo-alt", text: "Dirección (cobertura), nombre, celular y correo"},
		{id: "close", name: "Confirmación y pago", icon: "bi-credit-card", text: "Revisa la orden y paga"},
		{id: "other", name: "Otros caminos", icon: "bi-signpost-split", text: "Agendar instalación, asesor y archivos"}
	];

	var INFO = {
		welcome: {
			name: "Bienvenida", icon: "bi-emoji-smile", stage: "start", mode: "buttons-fixed",
			when: "Primer mensaje cuando el cliente escribe (o vuelve después de 24 horas sin hablar).",
			what: "Saluda y ofrece tres caminos con botones.",
			fixedButtons: {"1": "Abre la lista de planes", "2": "Envía el mensaje para agendar la instalación", "3": "Pasa la conversación a un asesor"}
		},
		menu: {
			name: "Categorías de planes", icon: "bi-list-ul", stage: "order", mode: "list-menu",
			when: "Cuando el cliente toca «Contratar internet» en la bienvenida, o escribe «menú».",
			what: "Lista de categorías (Internet, Internet + TV…). Al elegir una, se muestran sus planes."
		},
		listMenu: {
			name: "Planes de la categoría", icon: "bi-router", stage: "order", mode: "products",
			when: "Cuando el cliente elige una categoría.",
			what: "Muestra automáticamente los planes de esa categoría (máximo 10) con su precio mensual. Se editan en la sección Productos."
		},
		reset: {
			name: "¿Algo más?", icon: "bi-arrow-repeat", stage: "order", mode: "list-menu",
			when: "Después de que el cliente elige un plan.",
			what: "Le permite agregar TV / TV box, hablar con un asesor o continuar con la instalación."
		},
		delivery: {
			name: "Inicio de datos de instalación", icon: "bi-tools", stage: "data", mode: "text",
			when: "Cuando el cliente elige «Continuar con la instalación».",
			what: "Avisa que le vas a pedir sus datos. Después se pide automáticamente la dirección para verificar la cobertura."
		},
		address: {name: "Pregunta: dirección", icon: "bi-house-door", stage: "data", mode: "text", when: "Después del mensaje de inicio de datos.", what: "Pide la dirección de instalación. Se verifica la cobertura en todas las sucursales: si ninguna llega, se avisa al cliente y no se le piden más datos."},
		name: {name: "Pregunta: nombre", icon: "bi-person", stage: "data", mode: "text", when: "Después de que la dirección tiene cobertura.", what: "Pide el nombre completo del titular. La respuesta se guarda en la orden."},
		phone: {name: "Pregunta: celular", icon: "bi-phone", stage: "data", mode: "text", when: "Después de que el cliente escribe su nombre.", what: "Pide el número de celular. La respuesta se guarda en la orden."},
		email: {name: "Pregunta: correo", icon: "bi-envelope", stage: "data", mode: "text", when: "Después de que el cliente escribe su celular.", what: "Pide el correo electrónico. La respuesta se guarda en la orden."},
		process: {
			name: "Procesando orden", icon: "bi-hourglass-split", stage: "close", mode: "text",
			when: "Después de que el cliente escribe su correo.",
			what: "Mensaje corto de espera. Justo después se envía el resumen para confirmar."
		},
		confirmation: {
			name: "Confirmar orden", icon: "bi-clipboard-check", stage: "close", mode: "confirm",
			when: "Después del mensaje «Procesando orden».",
			what: "Envía el resumen automático (plan, datos y total), guarda la orden en Órdenes, la envía al WhatsApp central y pregunta si está bien.",
			fixedButtons: {"1": "Sí: envía el mensaje de Pago", "2": "No: pasa la conversación a un asesor"}
		},
		checkout: {
			name: "Pago", icon: "bi-credit-card", stage: "close", mode: "text",
			when: "Cuando el cliente confirma la orden.",
			what: "Explica cómo pagar (Yape, Plin, transferencia). El total se agrega automáticamente al final del texto.",
			tip: "Termina tu mensaje con algo como «Total a pagar: S/» para que el valor quede bien ubicado."
		},
		reservation: {
			name: "Agendar instalación", icon: "bi-calendar-check", stage: "other", mode: "text",
			when: "Cuando el cliente toca el segundo botón de la bienvenida.",
			what: "Pide día, hora y dirección para la instalación. Lo que escriba el cliente se guarda en la Agenda como solicitud pendiente.",
			tip: "Con el asistente de IA la instalación se agenda sola en un turno libre y se vincula a la orden."
		},
		conversation: {
			name: "Atención de un asesor", icon: "bi-headset", stage: "other", mode: "text",
			when: "Cuando el cliente pide hablar con alguien o escribe algo que el bot no entiende.",
			what: "Avisa que un asesor continuará la conversación desde el Chat."
		},
		archive: {
			name: "Archivo recibido", icon: "bi-paperclip", stage: "other", mode: "text",
			when: "Cuando el cliente envía una foto, audio, video o documento.",
			what: "Confirma que recibiste el archivo (por ejemplo, un comprobante de pago)."
		}
	};

	var LIMITS = {header: 60, body: 1024, bodyText: 4000, footer: 60, button: 20, rowTitle: 24, rowDesc: 72, listTitle: 24};

	function info(bot){

		return INFO[bot.key] || {
			name: bot.key, icon: "bi-chat-square-text", stage: "other", mode: "generic",
			when: "Bot personalizado: se envía solo si el flujo lo usa.",
			what: "Mensaje adicional."
		};

	}

	/*---------------------------------------------
	Utilidades
	---------------------------------------------*/

	function esc(v){
		return String(v == null ? "" : v).replace(/[&<>"']/g, function(c){
			return {"&":"&amp;","<":"&lt;",">":"&gt;",'"':"&quot;","'":"&#39;"}[c];
		});
	}

	/* *negrita* _cursiva_ ~tachado~ como en WhatsApp */

	function waFormat(text){

		return esc(text)
			.replace(/\*([^*\n]+)\*/g, "<strong>$1</strong>")
			.replace(/(^|[\s(])_([^_\n]+)_/g, "$1<em>$2</em>")
			.replace(/~([^~\n]+)~/g, "<s>$1</s>")
			.replace(/\n/g, "<br>");

	}

	function request(fields){

		var body = new FormData();
		Object.keys(fields).forEach(function(k){ body.append(k, fields[k]); });

		return fetch("/ajax/business.ajax.php", {method: "POST", headers: {"X-CSRF-Token": window.bizCsrf}, body: body, credentials: "same-origin"})
			.then(function(r){ return r.json().catch(function(){ return {ok: false, error: "Respuesta inválida del servidor"}; }); })
			.catch(function(){ return {ok: false, error: "Sin conexión"}; });

	}

	function rowAction(id){

		if(id === "domicilio"){ return "finish"; }
		if(/^\d+$/.test(id)){ return "cat:" + id; }
		return "human";

	}

	function actionLabel(id){

		var a = rowAction(id);

		if(a === "finish"){ return "Continuar con la instalación"; }
		if(a === "human"){ return "Hablar con una persona"; }

		var cat = categories.filter(function(c){ return c.id === id; })[0];

		return "Ver productos de " + (cat ? "«" + cat.title + "»" : "la categoría #" + id + " (no existe)");

	}

	/*=============================================
	Tablero
	=============================================*/

	function snippet(bot){

		var t = bot.body || (bot.key === "confirmation" ? "Resumen automático de la orden" : "");
		t = t.replace(/[*_~]/g, "").replace(/\s+/g, " ").trim();

		return t.length > 110 ? t.slice(0, 110) + "…" : t;

	}

	function badges(bot){

		var out = [];

		if(bot.type === "interactive" && bot.interactive === "button"){ out.push('<span class="mini-tag"><i class="bi bi-ui-radios"></i> ' + bot.buttons.length + ' botones</span>'); }
		if(bot.type === "interactive" && bot.interactive === "list"){ out.push('<span class="mini-tag"><i class="bi bi-list-ul"></i> ' + bot.rows.length + ' opciones</span>'); }
		if(bot.key === "listMenu"){ out.push('<span class="mini-tag"><i class="bi bi-magic"></i> Automático</span>'); }
		if(bot.header_image){ out.push('<span class="mini-tag"><i class="bi bi-image"></i> Imagen</span>'); }
		if(bot.header_video){ out.push('<span class="mini-tag"><i class="bi bi-camera-video"></i> Video</span>'); }

		return out.join("");

	}

	function renderBoard(){

		document.getElementById("flowStrip").innerHTML = STAGES.map(function(s, i){

			return '<a href="#stage-' + s.id + '" class="flow-step">' +
				'<span class="flow-num">' + (i + 1) + '</span>' +
				'<span class="flow-ico"><i class="bi ' + s.icon + '"></i></span>' +
				'<span class="flow-txt"><strong>' + s.name + '</strong><small>' + s.text + '</small></span>' +
			'</a>' + (i < STAGES.length - 1 ? '<i class="bi bi-chevron-right flow-sep"></i>' : "");

		}).join("");

		document.getElementById("botsBoard").innerHTML = STAGES.map(function(s){

			var list = bots.filter(function(b){ return info(b).stage === s.id; });

			if(!list.length){ return ""; }

			return '<section class="bot-stage" id="stage-' + s.id + '">' +
				'<h2 class="bot-stage-title"><i class="bi ' + s.icon + '"></i> ' + s.name + '</h2>' +
				'<div class="bot-grid">' + list.map(function(b){

					var inf = info(b);

					return '<button type="button" class="bot-card" data-bot="' + b.id + '">' +
						'<div class="bot-card-head"><span class="bot-icon"><i class="bi ' + inf.icon + '"></i></span>' +
						'<div class="min-w-0"><strong>' + esc(inf.name) + '</strong><small>' + esc(inf.when) + '</small></div></div>' +
						'<p class="bot-card-text">' + (esc(snippet(b)) || '<em class="text-muted">Sin texto</em>') + '</p>' +
						'<div class="bot-card-foot">' + badges(b) + '<span class="bot-edit"><i class="bi bi-pencil"></i> Editar</span></div>' +
					'</button>';

				}).join("") + '</div>' +
			'</section>';

		}).join("");

	}

	/*=============================================
	Editor
	=============================================*/

	var current = null;
	var modalEl = document.getElementById("botModal");
	var modal = new bootstrap.Modal(modalEl);
	var form = document.getElementById("botForm");

	function counter(max, value){
		var n = String(value || "").length;
		return '<span class="char-count' + (n > max ? " over" : "") + '" data-max="' + max + '">' + n + '/' + max + '</span>';
	}

	function field(label, name, value, max, opts){

		opts = opts || {};

		var input = opts.textarea
			? '<textarea class="form-control" rows="' + (opts.rows || 4) + '" data-k="' + name + '" maxlength="' + max + '" placeholder="' + esc(opts.placeholder || "") + '">' + esc(value) + '</textarea>'
			: '<input class="form-control" data-k="' + name + '" maxlength="' + max + '" value="' + esc(value) + '" placeholder="' + esc(opts.placeholder || "") + '">';

		return '<div class="mb-3">' +
			'<div class="d-flex justify-content-between align-items-end"><label class="form-label">' + label + '</label>' + counter(max, value) + '</div>' +
			input +
			(opts.help ? '<div class="form-text">' + opts.help + '</div>' : "") +
		'</div>';

	}

	function headerFields(b){

		var kind = b.header_video ? "video" : (b.header_image ? "image" : (b.header_text ? "text" : "none"));

		return '<div class="mb-3">' +
			'<label class="form-label">Encabezado <span class="text-muted fw-normal">(opcional, va arriba del mensaje)</span></label>' +
			'<div class="seg-choice" data-header-kind>' +
				[["none", "Sin encabezado", "bi-dash-circle"], ["text", "Texto", "bi-type"], ["image", "Imagen", "bi-image"], ["video", "Video", "bi-camera-video"]].map(function(o){
					return '<button type="button" class="' + (kind === o[0] ? "active" : "") + '" data-kind="' + o[0] + '"><i class="bi ' + o[2] + '"></i> ' + o[1] + '</button>';
				}).join("") +
			'</div>' +
			'<div class="mt-2" data-kind-box="text" ' + (kind === "text" ? "" : "hidden") + '>' + field("Texto del encabezado", "header_text", b.header_text, LIMITS.header) + '</div>' +
			'<div class="mt-2" data-kind-box="image" ' + (kind === "image" ? "" : "hidden") + '>' +
				'<input class="form-control" data-k="header_image" value="' + esc(b.header_image) + '" placeholder="https://…/imagen.jpg">' +
				'<div class="form-text">Enlace público a una imagen JPG o PNG. Puedes subirla en <a href="/archivos" target="_blank">Archivos</a> y copiar su enlace.</div>' +
			'</div>' +
			'<div class="mt-2" data-kind-box="video" ' + (kind === "video" ? "" : "hidden") + '>' +
				'<input class="form-control" data-k="header_video" value="' + esc(b.header_video) + '" placeholder="https://…/video.mp4">' +
				'<div class="form-text">Enlace público a un video MP4 (máximo 16 MB).</div>' +
			'</div>' +
			(b.interactive === "list" || b.key === "menu" || b.key === "reset" ? '<div class="form-text"><i class="bi bi-info-circle"></i> En los mensajes con lista, WhatsApp solo muestra encabezados de texto.</div>' : "") +
		'</div>';

	}

	function fixedButtons(b, meanings){

		return '<div class="mb-3"><label class="form-label">Botones</label>' +
			'<div class="opt-list">' + Object.keys(meanings).map(function(id){

				var btn = b.buttons.filter(function(x){ return String(x.id) === id; })[0] || {title: ""};

				return '<div class="opt-row" data-button="' + id + '">' +
					'<div class="flex-grow-1">' +
						'<div class="d-flex justify-content-between"><span class="opt-label">Botón ' + id + '</span>' + counter(LIMITS.button, btn.title) + '</div>' +
						'<input class="form-control" data-bt="title" maxlength="' + LIMITS.button + '" value="' + esc(btn.title) + '">' +
						'<div class="opt-does"><i class="bi bi-arrow-return-right"></i> ' + esc(meanings[id]) + '</div>' +
					'</div>' +
				'</div>';

			}).join("") + '</div></div>';

	}

	function freeButtons(b){

		return '<div class="mb-3"><label class="form-label">Botones <span class="text-muted fw-normal">(máximo 3)</span></label>' +
			'<div class="opt-list" data-free-buttons>' + b.buttons.map(function(x){ return freeButtonRow(x); }).join("") + '</div>' +
			'<button type="button" class="btn btn-ghost btn-sm mt-2" data-add-button><i class="bi bi-plus-lg"></i> Agregar botón</button></div>';

	}

	function freeButtonRow(x){

		return '<div class="opt-row" data-button="' + esc(x.id) + '">' +
			'<div class="flex-grow-1"><div class="d-flex justify-content-end">' + counter(LIMITS.button, x.title) + '</div>' +
			'<input class="form-control" data-bt="title" maxlength="' + LIMITS.button + '" value="' + esc(x.title) + '" placeholder="Texto del botón"></div>' +
			'<button type="button" class="btn-icon" data-remove-opt title="Quitar"><i class="bi bi-trash3"></i></button>' +
		'</div>';

	}

	function actionSelect(id){

		var a = rowAction(id);
		var opts = categories.map(function(c){
			return '<option value="cat:' + esc(c.id) + '"' + (a === "cat:" + c.id ? " selected" : "") + '>Ver productos de «' + esc(c.title) + '»</option>';
		}).join("");

		if(a.indexOf("cat:") === 0 && !categories.some(function(c){ return "cat:" + c.id === a; })){
			opts += '<option value="' + esc(a) + '" selected>Categoría #' + esc(id) + ' (ya no existe)</option>';
		}

		return '<select class="form-select form-select-sm" data-rw="action">' +
			'<optgroup label="Mostrar productos">' + (opts || '<option disabled>No hay categorías creadas</option>') + '</optgroup>' +
			'<optgroup label="Otras acciones">' +
				'<option value="finish"' + (a === "finish" ? " selected" : "") + '>Continuar con la instalación (pedir datos)</option>' +
				'<option value="human"' + (a === "human" ? " selected" : "") + '>Hablar con una persona</option>' +
			'</optgroup>' +
		'</select>';

	}

	function listRow(r, withAction){

		return '<div class="opt-row align-items-start" data-row="' + esc(r.id) + '">' +
			'<span class="handle-dots"><i class="bi bi-grip-vertical"></i></span>' +
			'<div class="flex-grow-1 d-grid gap-2">' +
				'<div><div class="d-flex justify-content-between"><span class="opt-label">Título</span>' + counter(LIMITS.rowTitle, r.title) + '</div>' +
				'<input class="form-control" data-rw="title" maxlength="' + LIMITS.rowTitle + '" value="' + esc(r.title) + '" placeholder="Ej.: Entradas"></div>' +
				'<div><div class="d-flex justify-content-between"><span class="opt-label">Descripción (opcional)</span>' + counter(LIMITS.rowDesc, r.description) + '</div>' +
				'<input class="form-control" data-rw="description" maxlength="' + LIMITS.rowDesc + '" value="' + esc(r.description) + '" placeholder="Texto pequeño debajo del título"></div>' +
				(withAction ? '<div><span class="opt-label">Al elegirla</span>' + actionSelect(r.id) + '</div>' : '<input type="hidden" data-rw="id" value="' + esc(r.id) + '">') +
			'</div>' +
			'<button type="button" class="btn-icon" data-remove-opt title="Quitar"><i class="bi bi-trash3"></i></button>' +
		'</div>';

	}

	function listFields(b, withAction){

		return field("Texto del botón que abre la lista", "list_title", b.list_title || "", LIMITS.listTitle, {placeholder: "Ej.: Ver planes", help: "Aparece arriba de las opciones cuando el cliente abre la lista."}) +
			'<div class="mb-3"><label class="form-label">Opciones de la lista <span class="text-muted fw-normal">(máximo 10)</span></label>' +
			'<div class="opt-list" data-rows>' + b.rows.map(function(r){ return listRow(r, withAction); }).join("") + '</div>' +
			'<button type="button" class="btn btn-ghost btn-sm mt-2" data-add-row><i class="bi bi-plus-lg"></i> Agregar opción</button></div>';

	}

	function openEditor(id){

		current = JSON.parse(JSON.stringify(bots.filter(function(b){ return String(b.id) === String(id); })[0]));

		if(!current){ return; }

		var inf = info(current);
		var html = '<div class="callout mb-4"><i class="bi bi-lightbulb"></i><div><strong>Para qué sirve:</strong> ' + esc(inf.what) +
			(inf.tip ? '<div class="mt-1 small"><strong>Consejo:</strong> ' + esc(inf.tip) + '</div>' : "") + '</div></div>';

		switch(inf.mode){

			case "text":
				html += field("Mensaje", "body", current.body, LIMITS.bodyText, {textarea: true, rows: 6, placeholder: "Escribe el mensaje que recibirá el cliente"});
				break;

			case "buttons-fixed":
				html += headerFields(current);
				html += field("Mensaje", "body", current.body, LIMITS.body, {textarea: true, rows: 5});
				html += field("Pie del mensaje <span class=\"text-muted fw-normal\">(opcional, texto pequeño gris)</span>", "footer", current.footer, LIMITS.footer);
				html += fixedButtons(current, inf.fixedButtons);
				break;

			case "confirm":
				html += field("Título del resumen", "header_text", current.header_text, LIMITS.header, {placeholder: "Confirma tu orden"});
				html += '<div class="auto-box mb-3"><i class="bi bi-magic"></i> El cuerpo del mensaje se arma solo con los productos, los datos del cliente y el total.</div>';
				html += field("Pregunta final <span class=\"text-muted fw-normal\">(pie del mensaje)</span>", "footer", current.footer, LIMITS.footer, {placeholder: "¿Está bien tu pedido?"});
				html += fixedButtons(current, inf.fixedButtons);
				break;

			case "products":
				html += '<div class="auto-box mb-3"><i class="bi bi-magic"></i> El encabezado (nombre de la categoría) y la lista de productos con precio se generan solos.</div>';
				html += field("Mensaje", "body", current.body, LIMITS.body, {textarea: true, rows: 3});
				html += field("Pie del mensaje <span class=\"text-muted fw-normal\">(opcional)</span>", "footer", current.footer, LIMITS.footer, {placeholder: "O escribe *menu* para volver"});
				break;

			case "list-menu":
				html += field("Encabezado <span class=\"text-muted fw-normal\">(opcional)</span>", "header_text", current.header_text, LIMITS.header);
				html += field("Mensaje", "body", current.body, LIMITS.body, {textarea: true, rows: 4});
				html += field("Pie del mensaje <span class=\"text-muted fw-normal\">(opcional)</span>", "footer", current.footer, LIMITS.footer);
				html += listFields(current, true);
				break;

			default:
				html += '<div class="mb-3"><label class="form-label">Tipo de mensaje</label><div class="seg-choice" data-generic-type>' +
					[["text", "Solo texto", "bi-chat-left-text"], ["button", "Con botones", "bi-ui-radios"], ["list", "Con lista", "bi-list-ul"]].map(function(o){
						var active = (o[0] === "text" && current.type === "text") || (current.type === "interactive" && current.interactive === o[0]);
						return '<button type="button" class="' + (active ? "active" : "") + '" data-gtype="' + o[0] + '"><i class="bi ' + o[2] + '"></i> ' + o[1] + '</button>';
					}).join("") + '</div></div>';
				html += '<div data-g="interactive"' + (current.type === "interactive" ? "" : " hidden") + '>' + headerFields(current) + '</div>';
				html += field("Mensaje", "body", current.body, LIMITS.body, {textarea: true, rows: 5});
				html += '<div data-g="interactive"' + (current.type === "interactive" ? "" : " hidden") + '>' + field("Pie del mensaje <span class=\"text-muted fw-normal\">(opcional)</span>", "footer", current.footer, LIMITS.footer) + '</div>';
				html += '<div data-g="button"' + (current.type === "interactive" && current.interactive === "button" ? "" : " hidden") + '>' + freeButtons(current) + '</div>';
				html += '<div data-g="list"' + (current.type === "interactive" && current.interactive === "list" ? "" : " hidden") + '>' + listFields(current, false) + '</div>';
		}

		form.innerHTML = html;

		document.getElementById("botModalIcon").innerHTML = '<i class="bi ' + inf.icon + '"></i>';
		document.getElementById("botModalTitle").textContent = inf.name;
		document.getElementById("botModalWhen").innerHTML = '<i class="bi bi-clock-history"></i> ' + esc(inf.when);

		$(form).find("[data-rows]").sortable({handle: ".handle-dots", update: preview});

		preview();
		modal.show();

	}

	/* Leer el formulario */

	function collect(){

		var inf = info(current);
		var d = JSON.parse(JSON.stringify(current));

		form.querySelectorAll("[data-k]").forEach(function(f){ d[f.getAttribute("data-k")] = f.value; });

		/* Encabezado: solo el tipo elegido */

		var kindBtn = form.querySelector("[data-header-kind] .active");

		if(kindBtn){
			var kind = kindBtn.getAttribute("data-kind");
			if(kind !== "text"){ d.header_text = ""; }
			if(kind !== "image"){ d.header_image = ""; }
			if(kind !== "video"){ d.header_video = ""; }
		}

		/* Tipo (solo bots personalizados) */

		var g = form.querySelector("[data-generic-type] .active");

		if(g){
			var gt = g.getAttribute("data-gtype");
			d.type = gt === "text" ? "text" : "interactive";
			d.interactive = gt === "text" ? "none" : gt;
		}

		/* Botones */

		if(form.querySelector("[data-button]")){

			d.buttons = [];

			form.querySelectorAll("[data-button]").forEach(function(row){
				if(row.closest("[hidden]")){ return; }
				d.buttons.push({id: row.getAttribute("data-button"), title: row.querySelector('[data-bt="title"]').value});
			});
		}

		/* Lista */

		if(form.querySelector("[data-rows]")){

			d.rows = [];

			form.querySelectorAll("[data-rows] [data-row]").forEach(function(row){

				var id = row.getAttribute("data-row");
				var action = row.querySelector('[data-rw="action"]');

				if(action){
					var v = action.value;
					id = v === "finish" ? "domicilio" : (v === "human" ? "contactar" : v.replace("cat:", ""));
				}

				d.rows.push({
					id: id,
					title: row.querySelector('[data-rw="title"]').value,
					description: row.querySelector('[data-rw="description"]').value
				});
			});
		}

		if(inf.mode === "products"){ d.header_text = ""; }

		return d;

	}

	/*=============================================
	Vista previa estilo WhatsApp
	=============================================*/

	function preview(){

		if(!current){ return; }

		var d = collect();
		var inf = info(current);
		var html = '<div class="wa-bubble">';
		var isList = d.type === "interactive" && d.interactive === "list";

		if(d.type === "interactive" || inf.mode !== "text"){

			if(d.header_video && !isList){ html += '<div class="wa-media"><i class="bi bi-play-circle"></i></div>'; }
			else if(d.header_image && !isList){ html += '<div class="wa-media"><img src="' + esc(d.header_image) + '" alt="" onerror="this.replaceWith(Object.assign(document.createElement(\'i\'),{className:\'bi bi-image\'}))"></div>'; }
			else if(inf.mode === "products"){ html += '<div class="wa-head">' + esc(categories[0] ? categories[0].title : "Categoría") + '</div>'; }
			else if(d.header_text){ html += '<div class="wa-head">' + waFormat(d.header_text) + '</div>'; }
		}

		var body = d.body;

		if(inf.mode === "confirm"){
			body = "1 x Plan 300 Mbps - S/ 89.00\n1 x TV abierta - S/ 15.00\n\n*Nombre:* Ana Pérez\n*Celular:* 987654321\n*Dirección:* Av. Universitaria 1200, Los Olivos\n\n*Total: S/ 104.00*";
		}

		if(current.key === "checkout"){ body += "33000"; }

		html += '<div class="wa-body">' + (waFormat(body) || '<span class="text-muted">Escribe el mensaje…</span>') + '</div>';

		if(d.footer && (d.type === "interactive" || inf.mode !== "text")){ html += '<div class="wa-foot">' + waFormat(d.footer) + '</div>'; }

		html += '<div class="wa-time">' + new Date().toLocaleTimeString("es", {hour: "numeric", minute: "2-digit"}) + '</div></div>';

		if(d.type === "interactive" && d.interactive === "button"){
			html += '<div class="wa-buttons">' + d.buttons.filter(function(b){ return b.title.trim(); }).map(function(b){
				return '<div class="wa-btn"><i class="bi bi-reply"></i> ' + esc(b.title) + '</div>';
			}).join("") + '</div>';
		}

		if(isList || inf.mode === "products"){
			html += '<div class="wa-buttons"><div class="wa-btn"><i class="bi bi-list-ul"></i> Ver opciones</div></div>';

			var rows = inf.mode === "products"
				? [{title: "Producto 1", description: "S/ 12.00"}, {title: "Producto 2", description: "S/ 15.00"}]
				: d.rows.filter(function(r){ return r.title.trim(); });

			html += '<div class="wa-sheet"><div class="wa-sheet-title">' + esc(inf.mode === "products" ? (categories[0] ? categories[0].title : "Categoría") : (d.list_title || "Opciones")) + '</div>' +
				rows.map(function(r){
					return '<div class="wa-row"><div><strong>' + esc(r.title) + '</strong>' + (r.description ? '<small>' + esc(r.description) + '</small>' : "") + '</div><span class="wa-radio"></span></div>';
				}).join("") + '</div>';
		}

		document.getElementById("botPreview").innerHTML = html;

	}

	/*=============================================
	Eventos del editor
	=============================================*/

	form.addEventListener("input", function(e){

		/* Contador del campo editado: el contenedor más cercano con un solo campo */

		var box = null;
		var node = e.target.parentNode;

		while(node && node !== form){

			var counts = node.querySelectorAll(".char-count");
			var inputs = node.querySelectorAll("input:not([type=hidden]), textarea");

			if(counts.length === 1 && inputs.length === 1){ box = counts[0]; break; }
			if(inputs.length > 1){ break; }

			node = node.parentNode;
		}

		if(box){
			var max = Number(box.getAttribute("data-max"));
			box.textContent = e.target.value.length + "/" + max;
			box.classList.toggle("over", e.target.value.length > max);
		}

		preview();

	});

	form.addEventListener("change", preview);

	form.addEventListener("click", function(e){

		var b;

		if((b = e.target.closest("[data-kind]"))){
			b.parentNode.querySelectorAll("button").forEach(function(x){ x.classList.toggle("active", x === b); });
			form.querySelectorAll("[data-kind-box]").forEach(function(x){ x.hidden = x.getAttribute("data-kind-box") !== b.getAttribute("data-kind"); });
			preview();
			return;
		}

		if((b = e.target.closest("[data-gtype]"))){
			var t = b.getAttribute("data-gtype");
			b.parentNode.querySelectorAll("button").forEach(function(x){ x.classList.toggle("active", x === b); });
			form.querySelectorAll('[data-g="interactive"]').forEach(function(x){ x.hidden = t === "text"; });
			form.querySelector('[data-g="button"]').hidden = t !== "button";
			form.querySelector('[data-g="list"]').hidden = t !== "list";
			preview();
			return;
		}

		if((b = e.target.closest("[data-remove-opt]"))){
			b.closest(".opt-row").remove();
			preview();
			return;
		}

		if((b = e.target.closest("[data-add-button]"))){
			var list = form.querySelector("[data-free-buttons]");
			if(list.children.length >= 3){ fncToastr("error", "WhatsApp permite máximo 3 botones."); return; }
			var used = [].map.call(list.querySelectorAll("[data-button]"), function(r){ return Number(r.getAttribute("data-button")) || 0; });
			list.insertAdjacentHTML("beforeend", freeButtonRow({id: String(Math.max.apply(null, used.concat([0])) + 1), title: ""}));
			list.lastElementChild.querySelector("input").focus();
			return;
		}

		if((b = e.target.closest("[data-add-row]"))){
			var rows = form.querySelector("[data-rows]");
			if(rows.children.length >= 10){ fncToastr("error", "WhatsApp permite máximo 10 opciones en una lista."); return; }
			var withAction = info(current).mode === "list-menu";
			var newId = withAction ? (categories[0] ? categories[0].id : "contactar") : "op" + Date.now().toString(36);
			rows.insertAdjacentHTML("beforeend", listRow({id: newId, title: "", description: ""}, withAction));
			rows.lastElementChild.querySelector("input").focus();
			preview();
		}

	});

	/* Guardar */

	document.getElementById("botSave").addEventListener("click", function(){

		var d = collect();
		var inf = info(current);
		var btn = this;

		if(inf.mode !== "confirm" && !d.body.trim()){ fncToastr("error", "Escribe el mensaje."); return; }

		var over = form.querySelector(".char-count.over");
		if(over){ fncToastr("error", "Hay un texto más largo de lo permitido (marcado en rojo)."); return; }

		if(d.rows && d.rows.length){
			var seen = {};
			for(var i = 0; i < d.rows.length; i++){
				if(!d.rows[i].title.trim()){ fncToastr("error", "Cada opción de la lista necesita un título."); return; }
				if(seen[d.rows[i].id]){ fncToastr("error", "Dos opciones hacen lo mismo («" + d.rows[i].title + "»). Cambia una."); return; }
				seen[d.rows[i].id] = true;
			}
		}

		if(d.buttons && d.buttons.some(function(x){ return !x.title.trim(); }) && inf.fixedButtons){
			fncToastr("error", "Escribe el texto de todos los botones."); return;
		}

		btn.disabled = true;

		request({action: "bot_save", id: current.id, data: JSON.stringify(d)}).then(function(res){

			btn.disabled = false;

			if(!res.ok){ fncToastr("error", res.error || "No se pudo guardar"); return; }

			for(var i = 0; i < bots.length; i++){
				if(bots[i].id === current.id){ bots[i] = d; }
			}

			renderBoard();
			modal.hide();
			fncToastr("success", "Bot actualizado");

		});

	});

	document.getElementById("botsBoard").addEventListener("click", function(e){

		var card = e.target.closest("[data-bot]");

		if(card){ openEditor(card.getAttribute("data-bot")); }

	});

	modalEl.addEventListener("hidden.bs.modal", function(){ current = null; });

	renderBoard();

})();
