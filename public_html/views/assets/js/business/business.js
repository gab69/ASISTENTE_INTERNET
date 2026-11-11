/*=============================================
Mi negocio: empresa, contactos, pagos, imágenes y agenda
=============================================*/

(function(){

	var data = window.bizData || {};

	function esc(v){
		return String(v == null ? "" : v).replace(/[&<>"']/g, function(c){
			return {"&":"&amp;","<":"&lt;",">":"&gt;",'"':"&quot;","'":"&#39;"}[c];
		});
	}

	/* Petición al servidor */

	window.bizRequest = function(fields, file){

		var body = new FormData();

		Object.keys(fields).forEach(function(k){ body.append(k, fields[k]); });

		if(file){ body.append("file", file); }

		return fetch("/ajax/business.ajax.php", {
			method: "POST",
			headers: {"X-CSRF-Token": window.bizCsrf},
			body: body,
			credentials: "same-origin"
		}).then(function(r){
			return r.json().catch(function(){ return {ok:false, error:"Respuesta inválida del servidor"}; });
		}).catch(function(){
			return {ok:false, error:"Sin conexión"};
		});

	};

	/*=============================================
	Plantillas de filas repetibles
	=============================================*/

	var rows = {

		contacts: function(c){
			var share = c.share === undefined ? true : !!c.share;
			function chip(field, on, icon, text){
				return '<label class="area-chip"><input type="checkbox" data-f="' + field + '"' + (on ? " checked" : "") + '><span><i class="bi ' + icon + '"></i> ' + text + '</span></label>';
			}
			return '<div class="repeat-row area-row">' +
				'<div class="flex-grow-1">' +
					'<div class="row g-2">' +
						'<div class="col-sm-6 col-lg-4"><label class="area-lbl">Área</label><input class="form-control" data-f="label" placeholder="Ventas, Soporte técnico, Pagos…" value="' + esc(c.label) + '"></div>' +
						'<div class="col-sm-6 col-lg-4"><label class="area-lbl">Celular (WhatsApp)</label><input class="form-control" data-f="phone" placeholder="51987654321" inputmode="tel" value="' + esc(c.phone) + '"></div>' +
						'<div class="col-lg-4"><label class="area-lbl">Responsable <span>(opcional)</span></label><input class="form-control" data-f="name" placeholder="Nombre" value="' + esc(c.name) + '"></div>' +
						'<div class="col-12"><label class="area-lbl">¿Para qué sirve este número?</label><textarea class="form-control" rows="2" data-f="purpose" placeholder="Ej.: clientes con internet lento o sin conexión, problemas con el router. Atiende 24/7.">' + esc(c.purpose) + '</textarea></div>' +
					'</div>' +
					'<div class="area-chips">' +
						chip("share", share, "bi-chat-dots", "La asistente lo comparte con clientes") +
						chip("orders", !!c.orders, "bi-bag-check", "Recibe órdenes nuevas") +
						chip("appts", !!c.appts, "bi-calendar-check", "Recibe instalaciones / citas") +
						chip("cancel", !!c.cancel, "bi-x-octagon", "Recibe cancelaciones") +
					'</div>' +
				'</div>' +
				'<button type="button" class="btn-icon" data-remove title="Quitar"><i class="bi bi-trash3"></i></button>' +
			'</div>';
		},

		payments: function(p){
			return '<div class="repeat-row align-items-start">' +
				'<div class="qr-drop" data-upload title="Subir QR (JPG o PNG)">' +
					(p.qr ? '<img src="' + esc(p.qr) + '" alt="QR">' : '<i class="bi bi-qr-code"></i><span>Subir QR</span>') +
					'<input type="hidden" data-f="qr" value="' + esc(p.qr) + '">' +
				'</div>' +
				'<div class="row g-2 flex-grow-1">' +
					'<input type="hidden" data-f="id" value="' + esc(p.id) + '">' +
					'<div class="col-md-6"><input class="form-control" data-f="name" placeholder="Método (Yape, Plin, BCP…)" value="' + esc(p.name) + '"></div>' +
					'<div class="col-md-6"><input class="form-control" data-f="holder" placeholder="Titular" value="' + esc(p.holder) + '"></div>' +
					'<div class="col-md-6"><input class="form-control" data-f="account" placeholder="N.º de cuenta o celular" value="' + esc(p.account) + '"></div>' +
					'<div class="col-md-6"><input class="form-control" data-f="notes" placeholder="Nota (ej.: 5% de descuento)" value="' + esc(p.notes) + '"></div>' +
				'</div>' +
				'<button type="button" class="btn-icon" data-remove title="Quitar"><i class="bi bi-trash3"></i></button>' +
			'</div>';
		},

		images: function(i){
			return '<div class="image-card">' +
				'<div class="image-drop" data-upload title="Subir imagen (JPG o PNG)">' +
					(i.url ? '<img src="' + esc(i.url) + '" alt="">' : '<i class="bi bi-cloud-arrow-up"></i><span>Subir imagen</span>') +
					'<input type="hidden" data-f="url" value="' + esc(i.url) + '">' +
				'</div>' +
				'<div class="p-3">' +
					'<input class="form-control form-control-sm mb-2" data-f="keyword" placeholder="Clave corta: menu, ubicacion…" value="' + esc(i.keyword) + '">' +
					'<textarea class="form-control form-control-sm" rows="2" data-f="description" placeholder="¿Cuándo se envía? Ej.: el menú con precios">' + esc(i.description) + '</textarea>' +
				'</div>' +
				'<button type="button" class="btn-icon floating" data-remove title="Quitar"><i class="bi bi-x-lg"></i></button>' +
			'</div>';
		}
	};

	function renderList(name){

		var box = document.querySelector('[data-list="' + name + '"]');
		var items = data[name] || [];

		box.innerHTML = items.length ? items.map(rows[name]).join("") : "";

		if(!items.length){ box.insertAdjacentHTML("beforeend", rows[name]({})); }

	}

	["contacts", "payments", "images"].forEach(renderList);

	document.querySelectorAll("[data-add]").forEach(function(btn){

		btn.addEventListener("click", function(){

			var name = btn.getAttribute("data-add");
			document.querySelector('[data-list="' + name + '"]').insertAdjacentHTML("beforeend", rows[name]({}));

		});

	});

	document.addEventListener("click", function(e){

		var remove = e.target.closest("[data-remove]");

		if(remove){

			(remove.closest(".repeat-row") || remove.closest(".image-card")).remove();
			return;
		}

		var upload = e.target.closest("[data-upload]");

		if(upload){

			var input = document.createElement("input");
			input.type = "file";
			input.accept = "image/png,image/jpeg";

			input.onchange = function(){

				if(!input.files[0]){ return; }

				upload.classList.add("loading");

				bizRequest({action: "upload"}, input.files[0]).then(function(res){

					upload.classList.remove("loading");

					if(!res.ok){ fncToastr("error", res.error); return; }

					var hidden = upload.querySelector("input[type=hidden]");
					hidden.value = res.url;
					upload.querySelectorAll("img, i, span").forEach(function(n){ n.remove(); });
					upload.insertAdjacentHTML("afterbegin", '<img src="' + esc(res.url) + '" alt="">');
					fncToastr("success", "Imagen subida. Recuerda guardar.");

				});

			};

			input.click();
		}

	});

	/*=============================================
	Rellenar formularios simples
	=============================================*/

	function fillSimple(form, values){

		Object.keys(values).forEach(function(k){

			var v = values[k];

			if(k === "days"){

				form.querySelectorAll('[name="days"]').forEach(function(cb){ cb.checked = (v || []).map(Number).indexOf(Number(cb.value)) > -1; });
				return;
			}

			var field = form.querySelector('[name="' + k + '"]');

			if(!field){ return; }

			if(field.type === "checkbox"){ field.checked = !!v; } else { field.value = v == null ? "" : v; }

		});

	}

	fillSimple(document.querySelector('[data-section="company"]'), data.company || {});
	fillSimple(document.querySelector('[data-section="agenda"]'), data.agenda || {});
	fillSimple(document.querySelector('[data-section="inquiries"]'), data.inquiries || {});
	fillSimple(document.querySelector('[data-section="notices"]'), data.notices || {});
	fillSimple(document.querySelector('[data-section="main"]'), data.main && data.main.phone ? data.main : {phone: (data.delivery && data.delivery.notify_phone) || ""});
	fillSimple(document.querySelector('[data-section="delivery"]'), data.delivery || {});

	/*=============================================
	Leer cada sección
	=============================================*/

	function collect(form){

		var section = form.getAttribute("data-section");

		if(section === "company" || section === "agenda" || section === "inquiries" || section === "notices" || section === "main" || section === "delivery" || section === "service"){

			var out = {};

			if(section === "delivery"){ out.branches = collectBranches(); }

			form.querySelectorAll("[name]").forEach(function(f){

				if(f.name === "days"){
					out.days = out.days || [];
					if(f.checked){ out.days.push(Number(f.value)); }
				}else if(f.type === "radio"){
					if(f.checked){ out[f.name] = f.value; }
				}else if(f.type === "checkbox"){
					out[f.name] = f.checked;
				}else{
					out[f.name] = f.value;
				}

			});

			return out;
		}

		var list = [];

		form.querySelectorAll(".repeat-row, .image-card").forEach(function(row){

			var item = {};
			var empty = true;

			row.querySelectorAll("[data-f]").forEach(function(f){
				if(f.type === "checkbox"){ item[f.getAttribute("data-f")] = f.checked; return; }
				item[f.getAttribute("data-f")] = f.value.trim();
				if(f.value.trim() !== "" && f.getAttribute("data-f") !== "id"){ empty = false; }
			});

			if(!empty){ list.push(item); }

		});

		return list;

	}

	function validate(section, value){

		if(section === "payments"){
			for(var i = 0; i < value.length; i++){
				if(!value[i].name){ return "Escribe el nombre de cada método de pago."; }
			}
		}

		if(section === "images"){
			for(var j = 0; j < value.length; j++){
				if(!value[j].url){ return "Falta subir una imagen."; }
				if(!value[j].keyword){ return "Cada imagen necesita una clave corta."; }
			}
		}

		if(section === "contacts"){
			for(var k = 0; k < value.length; k++){
				var area = "«" + (value[k].label || "Número " + (k + 1)) + "»";
				if(!value[k].label){ return "Escribe el área de cada número (Ventas, Soporte técnico, Pagos…)."; }
				if(!/\d{9,}/.test(value[k].phone.replace(/\D/g, ""))){ return "El celular de " + area + " debe incluir el código de país (ej.: 51987654321)."; }
				if(!value[k].purpose){ return "Indica para qué sirve el número de " + area + "."; }
			}
		}

		if(section === "inquiries" && value.enabled && !/\d{7,}/.test(String(value.phone).replace(/\D/g, ""))){
			return "Escribe el número que responde las consultas, con código de país.";
		}

		if(section === "delivery" && value.enabled){

			if(!value.branches.length){ return "Agrega al menos una sucursal."; }

			for(var b = 0; b < value.branches.length; b++){

				var br = value.branches[b];
				var label = "«" + (br.name || "Sucursal " + (b + 1)) + "»";

				if(!br.name){ return "Escribe el nombre de cada sucursal."; }
				if(br.plans === false){ return label + ": marca al menos un plan o servicio disponible en la zona."; }
				if(br.lat === "" || br.lng === ""){ return "Falta la ubicación (latitud y longitud) de " + label + "."; }
				if(!coordOk(br.lat, 90) || !coordOk(br.lng, 180)){ return "Las coordenadas de " + label + " no son válidas. Ejemplo: latitud -12.0464210, longitud -77.0428150."; }
				if(Number(br.radius_km) <= 0 && !String(br.zones).trim()){ return label + ": indica un radio o al menos un distrito con cobertura."; }
				if(br.phone && !/\d{9,}/.test(br.phone.replace(/\D/g, ""))){ return "El WhatsApp de " + label + " debe incluir el código de país."; }
			}

			if(value.notify_phone && !/\d{9,}/.test(value.notify_phone.replace(/\D/g, ""))){ return "El WhatsApp central debe incluir el código de país."; }
		}

		if(section === "agenda" && value.enabled){
			if(!value.days.length){ return "Elige al menos un día de atención."; }
			if(value.open >= value.close){ return "La hora de cierre debe ser después de la apertura."; }
			if((value.break_start && !value.break_end) || (!value.break_start && value.break_end)){ return "Completa ambas horas del descanso o déjalas vacías."; }
		}

		return "";

	}

	document.querySelectorAll("form[data-section]").forEach(function(form){

		form.addEventListener("submit", function(e){

			e.preventDefault();

			var section = form.getAttribute("data-section");
			var value = collect(form);
			var error = validate(section, value);

			if(error){

				fncToastr("error", error);

				/* Abrir la sucursal que tiene el problema */

				var bad = section === "delivery" && branchList ? branchList.querySelector("[data-branch].incomplete") : null;
				if(bad){ window.bizBranchOpen(bad); }

				return;
			}

			var btn = form.querySelector('button[type="submit"]');
			btn.disabled = true;

			bizRequest({action: "save_section", key: section, value: JSON.stringify(value)}).then(function(res){

				btn.disabled = false;

				if(!res.ok){ fncToastr("error", res.error || "No se pudo guardar"); return; }

				data[section] = res.value;

				if(rows[section]){ renderList(section); }
				if(section === "delivery" && branchList){ renderBranches(); }

				fncToastr("success", "Cambios guardados");

			});

		});

	});

	/*=============================================
	Cobertura: sucursales (agregar, ubicar, mapa)
	=============================================*/

	var dForm = document.querySelector('[data-section="delivery"]');
	var branchList = document.getElementById("branchList");

	function coordOk(v, limit){
		var s = String(v).trim().replace(",", ".");
		return /^-?\d{1,3}(\.\d+)?$/.test(s) && Math.abs(parseFloat(s)) <= limit;
	}

	/* Acepta "-12.04, -77.03", "-12.04 -77.03" o un enlace de Google Maps (@lat,lng · q=lat,lng · !3dlat!4dlng) */

	function parseCoords(text){

		text = String(text || "");

		var m = text.match(/!3d(-?\d+\.\d+)!4d(-?\d+\.\d+)/) ||
			text.match(/[?&](?:q|query|ll|destination)=(-?\d+\.\d+)(?:,|%2C)\s*(-?\d+\.\d+)/i) ||
			text.match(/@(-?\d+\.\d+),(-?\d+\.\d+)/) ||
			text.match(/^\s*\(?\s*(-?\d{1,3}\.\d+)\s*[,;\s]\s*(-?\d{1,3}\.\d+)\s*\)?\s*$/);

		if(!m){ return null; }

		var lat = parseFloat(m[1]), lng = parseFloat(m[2]);

		return Math.abs(lat) <= 90 && Math.abs(lng) <= 180 ? {lat: lat, lng: lng} : null;
	}

	/* Guardado inmediato cuando cambia la ubicación de una sucursal */

	var autoTimer = null;

	function setLocState(card, state, html){
		var el = card && card.querySelector("[data-loc-state]");
		if(el){ el.className = "loc-state " + state; el.innerHTML = html; }
	}

	function refreshCard(card){

		var lat = card.querySelector("[data-b=lat]");
		var lng = card.querySelector("[data-b=lng]");
		var okLat = coordOk(lat.value, 90), okLng = coordOk(lng.value, 180);

		lat.classList.toggle("is-invalid", lat.value.trim() !== "" && !okLat);
		lng.classList.toggle("is-invalid", lng.value.trim() !== "" && !okLng);

		var link = card.querySelector("[data-branch-map]");

		if(okLat && okLng){
			link.href = "https://www.google.com/maps/search/?api=1&query=" + lat.value.trim() + "," + lng.value.trim();
			link.classList.remove("disabled");
		}else{
			link.href = "#";
			link.classList.add("disabled");
		}

		return okLat && okLng;
	}

	function autoSave(card, extra){

		if(!refreshCard(card)){
			setLocState(card, "bad", '<i class="bi bi-exclamation-circle"></i> Coordenadas incompletas o no válidas. Ejemplo: -12.0464210 y -77.0428150.');
			return;
		}

		setLocState(card, "wait", '<span class="spinner-border spinner-border-sm"></span> Guardando ubicación…');

		clearTimeout(autoTimer);

		autoTimer = setTimeout(function(){

			window.bizCoverage.save().then(function(res){

				if(res.ok){
					setLocState(card, "ok", '<i class="bi bi-check-circle-fill"></i> Ubicación guardada' + (extra ? " · " + extra : "") + '. Ya se usa para verificar la cobertura.');
				}else{
					setLocState(card, "bad", '<i class="bi bi-exclamation-circle"></i> No se guardó: ' + esc(res.error || "revisa los datos"));
				}
			});

		}, 500);
	}

	/* Fijar coordenadas desde la ubicación actual, el mapa o un enlace pegado */

	function setBranchCoords(card, lat, lng, extra){

		card.querySelector("[data-b=lat]").value = Number(lat).toFixed(7);
		card.querySelector("[data-b=lng]").value = Number(lng).toFixed(7);

		card.dispatchEvent(new Event("input", {bubbles: true}));

		if(window.covMap){ window.covMap.focus(card); }

		autoSave(card, extra);
	}

	/* Ubicación del dispositivo con la mayor precisión posible (GPS): se escuchan
	   varias lecturas durante unos segundos y se queda la más precisa */

	function preciseLocation(onProgress){

		return new Promise(function(resolve, reject){

			if(!window.isSecureContext){ reject("Tu navegador solo permite usar la ubicación en páginas con https://. Entra al panel con https."); return; }
			if(!navigator.geolocation){ reject("Este navegador no permite obtener la ubicación."); return; }

			var best = null;
			var done = false;
			var watch = null;

			function finish(){

				if(done){ return; }
				done = true;
				if(watch !== null){ navigator.geolocation.clearWatch(watch); }

				best ? resolve(best) : reject("No se pudo obtener tu ubicación. Activa el GPS y vuelve a intentarlo.");
			}

			watch = navigator.geolocation.watchPosition(function(p){

				var c = p.coords;

				if(!best || c.accuracy < best.accuracy){
					best = {lat: c.latitude, lng: c.longitude, accuracy: Math.round(c.accuracy)};
					onProgress && onProgress(best);
				}

				if(best.accuracy <= 15){ finish(); }

			}, function(err){

				if(err.code === 1){ done = true; navigator.geolocation.clearWatch(watch); reject("Bloqueaste el permiso de ubicación. Actívalo en el candado de la barra de direcciones y vuelve a intentarlo."); return; }
				if(err.code === 3 && best){ finish(); }

			}, {enableHighAccuracy: true, maximumAge: 0, timeout: 20000});

			setTimeout(finish, 12000);
		});
	}

	window.bizPreciseLocation = preciseLocation;

	function branchRow(b){

		b = b || {};

		var hasCoords = b.lat !== "" && b.lat != null && b.lng !== "" && b.lng != null;

		var radius = b.radius_km != null && b.radius_km !== "" ? b.radius_km : 5;

		return '<div class="branch-card" data-branch>' +
			'<input type="hidden" data-b="id" value="' + esc(b.id) + '">' +
			'<input type="hidden" data-b="address" value="' + esc(b.address) + '">' +

			/* Resumen (siempre visible) */

			'<div class="branch-sum" data-branch-toggle role="button" tabindex="0" aria-expanded="false">' +
				'<span class="branch-ico"><i class="bi bi-shop"></i></span>' +
				'<div class="branch-sum-main">' +
					'<strong data-sum-name></strong>' +
					'<div class="branch-badges" data-sum-badges></div>' +
				'</div>' +
				'<span class="branch-sum-act"><span class="d-none d-sm-inline" data-sum-act>Editar</span> <i class="bi bi-chevron-down"></i></span>' +
			'</div>' +

			/* Editor */

			'<div class="branch-body">' +
				'<label class="form-label">Nombre de la sucursal</label>' +
				'<input class="form-control branch-name mb-3" data-b="name" placeholder="Ej.: Sede Norte" value="' + esc(b.name) + '">' +
				'<div class="branch-tabs" role="tablist">' +
					'<button type="button" class="on" data-pane-btn="loc" role="tab" aria-selected="true"><i class="bi bi-geo-alt"></i> Ubicación</button>' +
					'<button type="button" data-pane-btn="area" role="tab" aria-selected="false"><i class="bi bi-bullseye"></i> Cobertura</button>' +
					((window.bizCatalogue || []).length ? '<button type="button" data-pane-btn="plans" role="tab" aria-selected="false"><i class="bi bi-wifi"></i> Planes</button>' : "") +
					'<button type="button" data-pane-btn="contact" role="tab" aria-selected="false"><i class="bi bi-whatsapp"></i> Contacto</button>' +
				'</div>' +

				'<div class="branch-pane on" data-pane="loc">' +
			'<div class="branch-loc">' +
				'<div class="loc-title"><i class="bi bi-geo-alt-fill"></i> Ubicación exacta de la sucursal</div>' +
				'<div class="loc-fields">' +
					'<label class="loc-field"><span>Latitud</span><input class="form-control" data-b="lat" inputmode="decimal" autocomplete="off" placeholder="-12.0464210" value="' + esc(b.lat) + '"></label>' +
					'<label class="loc-field"><span>Longitud</span><input class="form-control" data-b="lng" inputmode="decimal" autocomplete="off" placeholder="-77.0428150" value="' + esc(b.lng) + '"></label>' +
				'</div>' +
				'<div class="loc-actions">' +
					'<button type="button" class="btn btn-brand btn-sm" data-branch-here><i class="bi bi-geo-fill"></i> Usar mi ubicación actual</button>' +
					'<button type="button" class="btn btn-ghost btn-sm" data-branch-pick><i class="bi bi-geo-alt"></i> Marcar en el mapa</button>' +
					'<a class="btn btn-ghost btn-sm' + (hasCoords ? "" : " disabled") + '" data-branch-map target="_blank" rel="noopener" href="' + (hasCoords ? "https://www.google.com/maps/search/?api=1&query=" + b.lat + "," + b.lng : "#") + '"><i class="bi bi-box-arrow-up-right"></i> Ver en Google Maps</a>' +
				'</div>' +
				'<div class="loc-state ' + (hasCoords ? "ok" : "bad") + '" data-loc-state>' + (hasCoords ? '<i class="bi bi-check-circle-fill"></i> Ubicada' : '<i class="bi bi-exclamation-circle"></i> Falta la ubicación: escribe las coordenadas, usa tu ubicación actual o márcala en el mapa.') + '</div>' +
				'<div class="loc-tip"><i class="bi bi-lightbulb"></i> Puedes pegar un enlace de Google Maps en cualquiera de los dos campos.</div>' +
			'</div>' +
				'</div>' +

				'<div class="branch-pane" data-pane="area">' +
					'<label class="form-label">Radio de cobertura</label>' +
					'<div class="radius-row">' +
						'<input type="range" class="form-range" min="0" max="30" step="0.5" data-radius-range value="' + esc(Math.min(30, Number(radius) || 0)) + '" aria-label="Radio de cobertura en km">' +
						'<div class="input-group input-group-sm radius-num"><input class="form-control" type="number" min="0" max="200" step="0.5" data-b="radius_km" value="' + esc(radius) + '"><span class="input-group-text">km</span></div>' +
					'</div>' +
					'<div class="form-text mb-3">0 km = solo cubre los distritos de la lista. El círculo se ve al instante en el mapa.</div>' +
					'<div class="row g-3">' +
						'<div class="col-md-6">' +
							'<label class="form-label"><i class="bi bi-check-circle text-success"></i> Distritos con cobertura <span class="text-muted fw-normal">(uno por línea)</span></label>' +
							'<textarea class="form-control" rows="4" data-b="zones" placeholder="Comas&#10;Los Olivos">' + esc(b.zones) + '</textarea>' +
						'</div>' +
						'<div class="col-md-6">' +
							'<label class="form-label"><i class="bi bi-slash-circle text-danger"></i> Zonas SIN cobertura <span class="text-muted fw-normal">(opcional)</span></label>' +
							'<textarea class="form-control" rows="4" data-b="excluded" placeholder="Ancón">' + esc(b.excluded) + '</textarea>' +
						'</div>' +
					'</div>' +
				'</div>' +

				'<div class="branch-pane" data-pane="plans">' + plansBlock(b) + '</div>' +

				'<div class="branch-pane" data-pane="contact">' +
					'<label class="form-label"><i class="bi bi-whatsapp"></i> WhatsApp de la sucursal</label>' +
					'<input class="form-control" data-b="phone" inputmode="tel" placeholder="51987654321 (con código de país)" value="' + esc(b.phone) + '">' +
					'<div class="form-text">Recibe las órdenes e instalaciones de esta sucursal, además del WhatsApp central.</div>' +
				'</div>' +

				'<div class="branch-foot">' +
					'<button type="button" class="btn btn-link text-danger px-0" data-branch-remove><i class="bi bi-trash3"></i> Eliminar sucursal</button>' +
					'<div class="d-flex gap-2">' +
						'<button type="button" class="btn btn-ghost btn-sm" data-branch-close>Cerrar</button>' +
						'<button type="button" class="btn btn-brand btn-sm" data-branch-save><i class="bi bi-check2"></i> Guardar sucursal</button>' +
					'</div>' +
				'</div>' +
			'</div>' +
		'</div>';
	}

	/* Resumen de la sucursal (nombre + chips) y totales de arriba */

	function lines(v){ return String(v || "").split(/[\n,;]+/).map(function(z){ return z.trim(); }).filter(Boolean); }

	function summarize(card){

		var get = function(k){ var f = card.querySelector("[data-b=" + k + "]"); return f ? f.value.trim() : ""; };
		var located = coordOk(get("lat"), 90) && coordOk(get("lng"), 180);
		var radius = Number(get("radius_km")) || 0;
		var zones = lines(get("zones")).length;
		var boxes = card.querySelectorAll("[data-plan]");
		var plans = Array.prototype.filter.call(boxes, function(x){ return x.checked; }).length;
		var chips = [];

		chips.push(located ? '<span class="bb ok"><i class="bi bi-geo-alt-fill"></i> Ubicada</span>' : '<span class="bb bad"><i class="bi bi-exclamation-circle"></i> Sin ubicar</span>');
		chips.push(radius > 0 ? '<span class="bb"><i class="bi bi-bullseye"></i> ' + radius + ' km</span>' : '<span class="bb"><i class="bi bi-bullseye"></i> Solo distritos</span>');
		if(zones){ chips.push('<span class="bb"><i class="bi bi-signpost-split"></i> ' + zones + (zones === 1 ? " distrito" : " distritos") + '</span>'); }
		if(boxes.length){ chips.push('<span class="bb' + (plans ? "" : " bad") + '"><i class="bi bi-wifi"></i> ' + (plans === boxes.length ? "Todos los planes" : plans + (plans === 1 ? " plan" : " planes")) + '</span>'); }
		if(get("phone")){ chips.push('<span class="bb"><i class="bi bi-whatsapp"></i> +' + esc(get("phone").replace(/\D/g, "")) + '</span>'); }
		if(radius <= 0 && !zones){ chips.push('<span class="bb bad"><i class="bi bi-exclamation-triangle"></i> No cubre nada</span>'); }

		card.querySelector("[data-sum-name]").textContent = get("name") || "Nueva sucursal";
		card.querySelector("[data-sum-badges]").innerHTML = chips.join("");
		card.classList.toggle("incomplete", !located || !get("name"));
	}

	function kpis(){

		var cards = branchList.querySelectorAll("[data-branch]");
		var located = 0, zones = 0, phones = 0;

		cards.forEach(function(c){
			if(coordOk(c.querySelector("[data-b=lat]").value, 90) && coordOk(c.querySelector("[data-b=lng]").value, 180)){ located++; }
			zones += lines(c.querySelector("[data-b=zones]").value).length;
			if(c.querySelector("[data-b=phone]").value.trim()){ phones++; }
		});

		var set = function(k, v){ var el = document.querySelector('[data-ck="' + k + '"]'); if(el){ el.textContent = v; } };

		set("branches", cards.length);
		set("located", located + " / " + cards.length);
		set("zones", zones);
		set("phones", phones + " / " + cards.length);
	}

	/* Abrir / cerrar el editor de una sucursal (una a la vez) */

	function openBranch(card, open){

		if(open === undefined){ open = !card.classList.contains("open"); }

		if(open){
			branchList.querySelectorAll("[data-branch].open").forEach(function(c){ if(c !== card){ openBranch(c, false); } });
		}

		card.classList.toggle("open", open);
		card.querySelector("[data-branch-toggle]").setAttribute("aria-expanded", open ? "true" : "false");
		card.querySelector("[data-sum-act]").textContent = open ? "Cerrar" : "Editar";

		if(open && window.covMap){ window.covMap.focus(card, true); }
	}

	function showPane(card, name){

		card.querySelectorAll("[data-pane-btn]").forEach(function(b){
			var on = b.getAttribute("data-pane-btn") === name;
			b.classList.toggle("on", on);
			b.setAttribute("aria-selected", on ? "true" : "false");
		});
		card.querySelectorAll("[data-pane]").forEach(function(p){ p.classList.toggle("on", p.getAttribute("data-pane") === name); });
	}

	window.bizBranchOpen = function(card){

		openBranch(card, true);
		card.scrollIntoView({behavior: "smooth", block: "nearest"});
	};

	/* Planes y servicios que se venden en la zona de esta sucursal */

	function plansBlock(b){

		var catalogue = window.bizCatalogue || [];

		if(!catalogue.length){ return ""; }

		var chosen = (b.plans || []).map(function(n){ return String(n).toLowerCase(); });
		var all = !chosen.length;

		return '<div class="branch-plans">' +
			'<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">' +
				'<label class="form-label m-0"><i class="bi bi-wifi"></i> Planes y servicios disponibles en esta zona</label>' +
				'<span class="small"><a href="#" data-plans="all">Todos</a> · <a href="#" data-plans="none">Ninguno</a></span>' +
			'</div>' +
			'<div class="plan-chips">' + catalogue.map(function(p){
				var on = all || chosen.indexOf(String(p.name).toLowerCase()) !== -1;
				return '<label class="plan-chip"><input type="checkbox" data-plan value="' + esc(p.name) + '"' + (on ? " checked" : "") + '><span>' + esc(p.name) + ' <small>' + esc(p.price) + '</small></span></label>';
			}).join("") + '</div>' +
			'<div class="form-text">La IA solo ofrece en esta zona los planes marcados (después de verificar la cobertura).</div>' +
		'</div>';
	}

	function countBranches(){

		var n = branchList.querySelectorAll("[data-branch]").length;
		document.getElementById("branchCount").textContent = n === 1 ? "1 sucursal" : n + " sucursales · se asigna la más cercana con cobertura";
		branchList.querySelectorAll("[data-branch]").forEach(summarize);
		kpis();
	}

	function renderBranches(){

		var d = data.delivery || {};
		var list = d.branches && d.branches.length ? d.branches : [];

		/* Configuración anterior (un solo local): convertirla en la primera sucursal */

		if(!list.length && (d.origin_address || d.lat)){
			list = [{id: "", name: "Principal", address: d.origin_address, lat: d.lat, lng: d.lng, radius_km: d.radius_km, zones: d.zones, excluded: d.excluded, phone: ""}];
		}

		var openId = (branchList.querySelector("[data-branch].open [data-b=id]") || {}).value;

		branchList.innerHTML = (list.length ? list : [{}]).map(branchRow).join("");
		countBranches();

		/* Reabrir la que estaba abierta (tras guardar) o la primera incompleta */

		var cards = Array.prototype.slice.call(branchList.querySelectorAll("[data-branch]"));
		var target = cards.filter(function(c){ return openId && c.querySelector("[data-b=id]").value === openId; })[0] || cards.filter(function(c){ return c.classList.contains("incomplete"); })[0];

		if(target){ openBranch(target, true); }
	}

	function collectBranches(){

		var out = [];

		branchList.querySelectorAll("[data-branch]").forEach(function(card){

			var b = {};
			card.querySelectorAll("[data-b]").forEach(function(f){ b[f.getAttribute("data-b")] = f.value.trim(); });

			/* Todos marcados = todo el catálogo (incluye los planes que se agreguen después) */

			var boxes = card.querySelectorAll("[data-plan]");
			var checked = Array.prototype.filter.call(boxes, function(x){ return x.checked; });

			b.plans = !boxes.length || checked.length === boxes.length ? [] : (checked.length ? checked.map(function(x){ return x.value; }) : false);

			if(b.name || b.address){ out.push(b); }
		});

		return out;
	}

	/* Para el mapa de cobertura (coverage-map.js) */

	window.bizCoverage = {
		branches: function(){ return collectBranches(); },
		save: function(){

			var value = collect(dForm);
			var error = validate("delivery", value);

			if(error){ return Promise.resolve({ok: false, error: error}); }

			return bizRequest({action: "save_section", key: "delivery", value: JSON.stringify(value)}).then(function(res){
				if(res.ok){ data.delivery = res.value; }
				return res;
			});
		}
	};

	if(dForm && branchList){

		renderBranches();

		document.getElementById("branchAdd").addEventListener("click", function(){
			branchList.insertAdjacentHTML("beforeend", branchRow({}));
			countBranches();
			var card = branchList.lastElementChild;
			openBranch(card, true);
			card.scrollIntoView({behavior: "smooth", block: "nearest"});
			card.querySelector("[data-b=name]").focus();
		});

		/* Ir a otra pestaña desde un enlace */

		document.querySelectorAll("[data-goto-tab]").forEach(function(a){
			a.addEventListener("click", function(e){
				e.preventDefault();
				bootstrap.Tab.getOrCreateInstance(document.querySelector('[data-bs-target="#tab-' + a.getAttribute("data-goto-tab") + '"]')).show();
			});
		});

		branchList.addEventListener("keydown", function(e){

			var toggle = e.target.closest("[data-branch-toggle]");

			if(toggle && (e.key === "Enter" || e.key === " ")){
				e.preventDefault();
				openBranch(toggle.closest("[data-branch]"));
			}
		});

		branchList.addEventListener("click", function(e){

			var card = e.target.closest("[data-branch]");
			if(!card){ return; }

			if(e.target.closest("[data-branch-toggle]")){ openBranch(card); return; }

			if(e.target.closest("[data-branch-close]")){ openBranch(card, false); return; }

			var paneBtn = e.target.closest("[data-pane-btn]");

			if(paneBtn){ showPane(card, paneBtn.getAttribute("data-pane-btn")); return; }

			var saveBtn = e.target.closest("[data-branch-save]");

			if(saveBtn){

				saveBtn.disabled = true;

				window.bizCoverage.save().then(function(res){
					saveBtn.disabled = false;
					if(!res.ok){ fncToastr("error", res.error || "No se pudo guardar"); return; }
					fncToastr("success", "Sucursal guardada");
				});
				return;
			}

			if(e.target.closest("[data-branch-remove]")){

				if(branchList.querySelectorAll("[data-branch]").length === 1){ fncToastr("error", "Debe quedar al menos una sucursal."); return; }

				var name = card.querySelector("[data-b=name]").value.trim() || "esta sucursal";

				if(!confirm("¿Eliminar «" + name + "»? Se quita al pulsar Guardar.")){ return; }

				card.remove();
				countBranches();
				return;
			}

			/* Usar la ubicación actual del dispositivo (estando en la sucursal) */

			var here = e.target.closest("[data-branch-here]");

			if(here){

				var original = here.innerHTML;
				here.disabled = true;
				here.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Buscando GPS…';
				setLocState(card, "wait", '<span class="spinner-border spinner-border-sm"></span> Obteniendo tu ubicación con GPS… quédate quieto unos segundos.');

				preciseLocation(function(p){

					setLocState(card, "wait", '<span class="spinner-border spinner-border-sm"></span> Mejorando precisión… ±' + p.accuracy + " m");
					if(window.covMap){ window.covMap.accuracy(p.lat, p.lng, p.accuracy); }

				}).then(function(p){

					setBranchCoords(card, p.lat, p.lng, "precisión ±" + p.accuracy + " m");

					if(p.accuracy > 100){
						fncToastr("warning", "La precisión es baja (±" + p.accuracy + " m). Si estás en una PC, usa el celular con GPS o arrastra el marcador al punto exacto.");
					}

				}).catch(function(msg){

					setLocState(card, "bad", '<i class="bi bi-exclamation-circle"></i> ' + esc(msg));
					fncToastr("error", msg);

				}).finally(function(){

					here.disabled = false;
					here.innerHTML = original;
				});

				return;
			}

			/* Marcar en el mapa: el siguiente clic en el mapa fija la sucursal */

			var plansAct = e.target.closest("[data-plans]");

			if(plansAct){

				e.preventDefault();
				card.querySelectorAll("[data-plan]").forEach(function(x){ x.checked = plansAct.getAttribute("data-plans") === "all"; });
				summarize(card);
				return;
			}

			if(e.target.closest("[data-branch-pick]")){

				if(window.covMap){ window.covMap.pick(card); }
			}
		});

		/* Escribir coordenadas: se validan y el mapa se mueve al instante; al salir del campo se guardan */

		/* Resumen y totales al instante; el deslizador y el número del radio van juntos */

		branchList.addEventListener("input", function(e){

			var card = e.target.closest("[data-branch]");
			if(!card){ return; }

			if(e.target.matches("[data-radius-range]")){
				var num = card.querySelector("[data-b=radius_km]");
				num.value = e.target.value;
				num.dispatchEvent(new Event("input", {bubbles: true}));
				return;
			}

			if(e.target.matches("[data-b=radius_km]")){
				card.querySelector("[data-radius-range]").value = Math.min(30, Number(e.target.value) || 0);
			}

			summarize(card);
			kpis();
		});

		branchList.addEventListener("change", function(e){
			if(e.target.matches("[data-plan]")){ summarize(e.target.closest("[data-branch]")); }
		});

		branchList.addEventListener("input", function(e){

			if(!e.target.matches("[data-b=lat], [data-b=lng]")){ return; }

			var card = e.target.closest("[data-branch]");

			if(refreshCard(card)){
				setLocState(card, "wait", '<i class="bi bi-pencil"></i> Coordenadas nuevas: se guardan al salir del campo.');
				if(window.covMap){ window.covMap.focus(card); }
			}else{
				setLocState(card, "bad", '<i class="bi bi-exclamation-circle"></i> Coordenadas incompletas o no válidas. Ejemplo: -12.0464210 y -77.0428150.');
			}
		});

		branchList.addEventListener("change", function(e){

			if(!e.target.matches("[data-b=lat], [data-b=lng]")){ return; }

			autoSave(e.target.closest("[data-branch]"));
		});

		/* Pegar "-12.12, -77.03" o un enlace de Google Maps completa ambos campos */

		branchList.addEventListener("paste", function(e){

			if(!e.target.matches("[data-b=lat], [data-b=lng]")){ return; }

			var p = parseCoords((e.clipboardData || window.clipboardData).getData("text"));

			if(p){
				e.preventDefault();
				setBranchCoords(e.target.closest("[data-branch]"), p.lat, p.lng);
			}
		});

		window.bizSetBranchCoords = setBranchCoords;

		document.getElementById("geoTestInput").addEventListener("keydown", function(e){
			if(e.key === "Enter"){ e.preventDefault(); document.getElementById("geoTest").click(); }
		});

		document.getElementById("geoTest").addEventListener("click", function(){

			var btn = this;
			var out = document.getElementById("geoTestResult");
			var address = document.getElementById("geoTestInput").value.trim();

			if(!address){ return; }

			btn.disabled = true;
			out.className = "geo-result show wait";
			out.textContent = "Buscando en el mapa y comparando sucursales…";
			document.getElementById("mapPointResult").className = "geo-result";

			bizRequest({action: "geo_test", address: address, branches: JSON.stringify(collectBranches())}).then(function(res){

				btn.disabled = false;

				if(!res.ok){ out.className = "geo-result show bad"; out.textContent = res.error; return; }

				var compare = (res.result.compare || []).map(function(c){
					var icon = c.covered === "si" ? "✅" : (c.covered === "no" ? "❌" : "❔");
					return '<li>' + icon + ' <strong>' + esc(c.name) + '</strong>' + (c.distance != null ? " · " + Number(c.distance).toFixed(1) + " km" : "") + (c.reason ? " · " + esc(c.reason) : (c.zone ? " · zona " + esc(c.zone) : "")) + '</li>';
				}).join("");

				out.className = "geo-result show " + (res.result.covered === "si" ? "ok" : "bad");
				out.innerHTML = esc(res.text) + (compare && (res.result.compare || []).length > 1 ? '<ul class="geo-compare">' + compare + '</ul>' : "");
			});
		});
	}

	/*=============================================
	Servicio: internet / TV con instalación o pedidos
	=============================================*/

	var sForm = document.querySelector('[data-section="service"]');

	function serviceMode(){

		var mode = (sForm.querySelector("[name=mode]:checked") || {}).value || "delivery";

		sForm.querySelectorAll("[data-mode-card]").forEach(function(c){ c.classList.toggle("active", c.querySelector("input").checked); });
		sForm.querySelectorAll(".svc-install").forEach(function(el){ el.style.display = mode === "installation" ? "" : "none"; });
		document.querySelectorAll(".svc-delivery").forEach(function(el){ el.style.display = mode === "installation" ? "none" : ""; });
		sForm.querySelectorAll(".svc-tv").forEach(function(el){ el.style.display = sForm.tv_enabled.checked ? "" : "none"; });
	}

	if(sForm){

		var svc = data.service || {};
		var svcFields = Object.assign({}, svc);

		delete svcFields.mode;
		fillSimple(sForm, svcFields);
		(sForm.querySelector('[name=mode][value="' + (svc.mode || "delivery") + '"]') || {}).checked = true;

		sForm.addEventListener("change", serviceMode);
		serviceMode();

		sForm.querySelectorAll("[data-goto]").forEach(function(a){
			a.addEventListener("click", function(e){
				e.preventDefault();
				bootstrap.Tab.getOrCreateInstance(document.querySelector('[data-bs-target="#tab-' + a.getAttribute("data-goto") + '"]')).show();
			});
		});
	}


	/* Recordar la pestaña abierta (o abrir la del enlace: /negocio#delivery) */

	try{

		var saved = location.hash ? "#tab-" + location.hash.slice(1) : localStorage.getItem("bizTab");

		if(saved && document.querySelector('[data-bs-target="' + saved + '"]')){
			bootstrap.Tab.getOrCreateInstance(document.querySelector('[data-bs-target="' + saved + '"]')).show();
		}

		document.querySelectorAll(".seg-tab").forEach(function(t){
			t.addEventListener("shown.bs.tab", function(){ try{ localStorage.setItem("bizTab", t.getAttribute("data-bs-target")); }catch(err){} });
		});

	}catch(err){}

})();
