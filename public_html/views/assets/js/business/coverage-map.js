/*=============================================
Mapa de cobertura: sucursales, radios, distritos,
prueba por clic y confirmación de zonas
=============================================*/

(function(){

	var box = document.getElementById("coverageMap");
	var list = document.getElementById("branchList");

	if(!box || !list || !window.bizCoverage){ return; }

	var COLORS = ["#2563eb", "#16a34a", "#db2777", "#ea580c", "#7c3aed", "#0891b2", "#ca8a04", "#dc2626"];
	var map = null, layer = null, zoneLayer = null, pointMarker = null;
	var lastSignature = "";
	var loading = null;
	var markers = [];
	var pickCard = null;
	var accuracyLayer = null;

	function esc(v){
		return String(v == null ? "" : v).replace(/[&<>"']/g, function(c){
			return {"&":"&amp;","<":"&lt;",">":"&gt;",'"':"&quot;","'":"&#39;"}[c];
		});
	}

	function num(v){ var n = parseFloat(String(v).replace(",", ".")); return isNaN(n) ? null : n; }

	/*=============================================
	Leaflet + OpenStreetMap (se carga solo al abrir la pestaña)
	=============================================*/

	function loadLeaflet(){

		if(window.L){ return Promise.resolve(); }
		if(loading){ return loading; }

		loading = new Promise(function(resolve, reject){

			var css = document.createElement("link");
			css.rel = "stylesheet";
			css.href = "https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.css";
			document.head.appendChild(css);

			var js = document.createElement("script");
			js.src = "https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.js";
			js.onload = resolve;
			js.onerror = reject;
			document.head.appendChild(js);
		});

		return loading;
	}

	function init(){

		if(map){ map.invalidateSize(); draw(true); return; }

		loadLeaflet().then(function(){

			box.innerHTML = "";

			map = L.map(box, {scrollWheelZoom: false, maxZoom: 20}).setView([-12.06, -77.04], 11);

			var streets = L.tileLayer("https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png", {
				maxZoom: 20, maxNativeZoom: 19,
				attribution: "&copy; OpenStreetMap"
			}).addTo(map);

			/* Vista satélite para ubicar el local con exactitud (techo, puerta) */

			var satellite = L.tileLayer("https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}", {
				maxZoom: 20, maxNativeZoom: 19,
				attribution: "Imágenes &copy; Esri"
			});

			L.control.layers({"Mapa": streets, "Satélite": satellite}, null, {position: "topright"}).addTo(map);

			layer = L.layerGroup().addTo(map);
			zoneLayer = L.layerGroup().addTo(map);

			map.on("click", function(e){

				if(pickCard){
					var card = pickCard;
					stopPick();
					window.bizSetBranchCoords(card, e.latlng.lat, e.latlng.lng, "marcada en el mapa");
					return;
				}

				testPoint(e.latlng.lat, e.latlng.lng);
			});
			map.on("focus", function(){ map.scrollWheelZoom.enable(); });
			map.on("blur", function(){ map.scrollWheelZoom.disable(); });

			draw(true);

		}).catch(function(){

			box.innerHTML = '<div class="map-empty"><i class="bi bi-wifi-off"></i><span>No se pudo cargar el mapa. Revisa tu conexión.</span></div>';
		});
	}

	/*=============================================
	Dibujar sucursales y radios
	=============================================*/

	function cards(){ return Array.prototype.slice.call(list.querySelectorAll("[data-branch]")); }

	function draw(fit){

		if(!map){ return; }

		layer.clearLayers();
		markers = [];
		lastSignature = signature();

		var bounds = [];
		var legend = [];

		cards().forEach(function(card, i){

			var color = COLORS[i % COLORS.length];
			var name = card.querySelector("[data-b=name]").value.trim() || "Sucursal " + (i + 1);
			var lat = num(card.querySelector("[data-b=lat]").value);
			var lng = num(card.querySelector("[data-b=lng]").value);
			var radius = num(card.querySelector("[data-b=radius_km]").value) || 0;

			card.style.setProperty("--branch-color", color);

			legend.push('<button type="button" class="cov-chip" data-legend="' + i + '"><span class="dot" style="background:' + color + '"></span>' + esc(name) + '<small>' + (lat === null ? "sin ubicar" : (radius > 0 ? radius + " km" : "distritos")) + '</small></button>');

			if(lat === null || lng === null){ return; }

			var icon = L.divIcon({className: "cov-pin", html: '<span style="background:' + color + '"><i class="bi bi-building"></i></span>', iconSize: [32, 32], iconAnchor: [16, 32]});
			var marker = L.marker([lat, lng], {icon: icon, draggable: true, title: name}).addTo(layer);

			marker.bindTooltip(name, {direction: "top", offset: [0, -30]});
			marker.on("click", function(){ if(window.bizBranchOpen){ window.bizBranchOpen(card); } });

			/* Arrastrar = corregir la ubicación de la sucursal */

			marker.on("dragend", function(){

				var p = marker.getLatLng();
				window.bizSetBranchCoords(card, p.lat, p.lng, "ajustada en el mapa");
			});

			markers.push({card: card, marker: marker});

			bounds.push([lat, lng]);

			if(radius > 0){

				var circle = L.circle([lat, lng], {radius: radius * 1000, color: color, weight: 2, fillColor: color, fillOpacity: .08}).addTo(layer);
				circle.on("click", function(e){ testPoint(e.latlng.lat, e.latlng.lng); });
				var b = circle.getBounds();
				bounds.push([b.getNorth(), b.getEast()], [b.getSouth(), b.getWest()]);
			}
		});

		document.getElementById("covLegend").innerHTML = legend.join("");

		if(fit && bounds.length){ map.fitBounds(bounds, {padding: [20, 20], maxZoom: 14}); }
	}

	function signature(){

		return cards().map(function(c){
			return ["name", "lat", "lng", "radius_km"].map(function(k){ return c.querySelector("[data-b=" + k + "]").value; }).join("|");
		}).join("#");
	}

	/* Redibujar al instante cuando cambian los datos del formulario */

	function redrawIfChanged(){

		if(!map){ return; }
		if(signature() !== lastSignature){ draw(false); }
	}

	list.addEventListener("input", redrawIfChanged);

	/* Respaldo: sucursales agregadas o quitadas */

	setInterval(function(){ if(box.offsetParent){ redrawIfChanged(); } }, 600);

	/* Centrar el mapa en una sucursal (cuando se cambian sus coordenadas) */

	function focusCard(card, noScroll){

		if(!map){ return; }

		draw(false);

		var lat = num(card.querySelector("[data-b=lat]").value);
		var lng = num(card.querySelector("[data-b=lng]").value);

		if(lat === null || lng === null){ return; }

		var radius = num(card.querySelector("[data-b=radius_km]").value) || 0;

		/* Al abrir una sucursal desde la lista se ve toda su zona; al cambiar coordenadas, el punto exacto */

		if(noScroll && radius > 0){
			map.fitBounds(L.latLng(lat, lng).toBounds(radius * 2000), {padding: [16, 16]});
		}else{
			map.setView([lat, lng], Math.max(map.getZoom(), 17));
		}

		markers.forEach(function(m){
			if(m.card === card){ m.marker.openTooltip(); }
		});

		if(!noScroll){ box.scrollIntoView({behavior: "smooth", block: "nearest"}); }
	}

	/* Modo "Marcar en el mapa" */

	function startPick(card){

		init();
		pickCard = card;

		var name = card.querySelector("[data-b=name]").value.trim() || "la sucursal";
		var banner = document.getElementById("covPickBanner");

		banner.querySelector("span").textContent = "Haz clic en el mapa en el punto exacto de «" + name + "». Usa la vista Satélite y acerca el zoom.";
		banner.classList.add("show");
		box.classList.add("picking");
		box.scrollIntoView({behavior: "smooth", block: "center"});
	}

	function stopPick(){

		pickCard = null;
		document.getElementById("covPickBanner").classList.remove("show");
		box.classList.remove("picking");
	}

	document.getElementById("covPickCancel").addEventListener("click", stopPick);

	/* Leyenda: abre la sucursal y centra el mapa en ella */

	document.getElementById("covLegend").addEventListener("click", function(e){

		var chip = e.target.closest("[data-legend]");
		if(!chip){ return; }

		var card = cards()[Number(chip.getAttribute("data-legend"))];
		if(card && window.bizBranchOpen){ window.bizBranchOpen(card); }
	});

	/* Círculo de precisión del GPS mientras se obtiene la ubicación */

	function showAccuracy(lat, lng, accuracy){

		if(!map){ return; }

		if(accuracyLayer){ map.removeLayer(accuracyLayer); }

		accuracyLayer = L.circle([lat, lng], {radius: accuracy, color: "#0ea5e9", weight: 1, fillColor: "#0ea5e9", fillOpacity: .15, interactive: false}).addTo(map);

		map.setView([lat, lng], accuracy > 300 ? 15 : 18);

		clearTimeout(showAccuracy.t);
		showAccuracy.t = setTimeout(function(){ if(accuracyLayer){ map.removeLayer(accuracyLayer); accuracyLayer = null; } }, 15000);
	}

	window.covMap = {focus: focusCard, pick: startPick, accuracy: showAccuracy};

	/* Probar la cobertura en el lugar donde estoy ahora */

	document.getElementById("covTestHere").addEventListener("click", function(){

		var btn = this;
		var out = document.getElementById("mapPointResult");
		var original = btn.innerHTML;

		btn.disabled = true;
		btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Obteniendo ubicación…';
		out.className = "geo-result show wait";
		out.textContent = "Obteniendo tu ubicación con GPS…";

		init();

		window.bizPreciseLocation(function(p){

			out.textContent = "Mejorando precisión… ±" + p.accuracy + " m";
			showAccuracy(p.lat, p.lng, p.accuracy);

		}).then(function(p){

			testPoint(p.lat, p.lng, p.accuracy);

		}).catch(function(msg){

			out.className = "geo-result show bad";
			out.textContent = msg;

		}).finally(function(){

			btn.disabled = false;
			btn.innerHTML = original;
		});
	});

	/*=============================================
	Clic en el mapa: ¿hay cobertura aquí?
	=============================================*/

	function testPoint(lat, lng, accuracy){

		var out = document.getElementById("mapPointResult");
		var where = accuracy != null ? "📍 Tu ubicación (±" + accuracy + " m): " : "";

		if(pointMarker){ map.removeLayer(pointMarker); }
		pointMarker = L.circleMarker([lat, lng], {radius: 8, color: "#111827", weight: 2, fillColor: "#fbbf24", fillOpacity: 1}).addTo(map);

		out.className = "geo-result show wait";
		out.textContent = "Verificando ese punto…";
		document.getElementById("geoTestResult").className = "geo-result";

		bizRequest({action: "geo_test", lat: lat, lng: lng, branches: JSON.stringify(bizCoverage.branches())}).then(function(res){

			if(!res.ok){ out.className = "geo-result show bad"; out.textContent = res.error; return; }

			var r = res.result;
			var compare = (r.compare || []).map(function(c){
				return '<li>' + (c.covered === "si" ? "✅" : "❌") + " " + esc(c.name) + (c.distance != null ? " · " + Number(c.distance).toFixed(1) + " km" : "") + '</li>';
			}).join("");

			out.className = "geo-result show " + (r.covered === "si" ? "ok" : "bad");
			out.innerHTML = where + (r.covered === "si" ? "<strong>Sí hay cobertura</strong> desde " + esc(r.branch) : "<strong>Sin cobertura</strong>" + (r.reason ? ". " + esc(r.reason) : "")) + (compare ? '<ul class="geo-compare">' + compare + "</ul>" : "");

			pointMarker.setStyle({fillColor: r.covered === "si" ? "#22c55e" : "#ef4444"});
		});
	}

	/*=============================================
	Confirmar zonas: guarda, ubica cada distrito
	y deja constancia de la revisión
	=============================================*/

	function setStatus(state, title, text){

		var s = document.getElementById("covStatus");
		s.className = "cov-status " + state;
		s.querySelector("i").className = "bi " + (state === "ok" ? "bi-patch-check-fill" : "bi-exclamation-diamond");
		s.querySelector("strong").textContent = title;
		s.querySelector("span").innerHTML = text;
	}

	function run(){

		var btn = document.getElementById("zonesConfirm");
		var report = document.getElementById("zoneReport");

		btn.disabled = true;
		btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Guardando…';

		bizCoverage.save().then(function(saved){

			if(!saved.ok){
				fncToastr("error", saved.error || "No se pudo guardar");
				return null;
			}

			init();
			zoneLayer && zoneLayer.clearLayers();

			var tasks = [];
			var problems = 0;
			var total = 0;

			report.innerHTML = "";

			cards().forEach(function(card, i){

				var color = COLORS[i % COLORS.length];
				var name = card.querySelector("[data-b=name]").value.trim();
				var lat = num(card.querySelector("[data-b=lat]").value);
				var lng = num(card.querySelector("[data-b=lng]").value);
				var radius = num(card.querySelector("[data-b=radius_km]").value) || 0;
				var zones = card.querySelector("[data-b=zones]").value.split(/[\n,;]+/).map(function(z){ return z.trim(); }).filter(Boolean);

				var group = document.createElement("div");
				group.className = "zone-group";
				group.innerHTML = '<div class="zone-head"><span class="dot" style="background:' + color + '"></span><strong>' + esc(name) + '</strong></div>';
				report.appendChild(group);

				if(lat === null){
					problems++;
					group.insertAdjacentHTML("beforeend", '<div class="zone-line bad">❌ La sucursal no tiene latitud y longitud: usa <em>Usar mi ubicación actual</em> o <em>Marcar en el mapa</em>.</div>');
				}

				if(!zones.length){
					group.insertAdjacentHTML("beforeend", '<div class="zone-line">' + (radius > 0 ? "Cubre por radio: " + radius + " km." : "⚠ Sin radio ni distritos: no cubre nada.") + '</div>');
					if(radius <= 0){ problems++; }
				}

				zones.forEach(function(zone){

					total++;

					var line = document.createElement("div");
					line.className = "zone-line wait";
					line.textContent = "⏳ " + zone;
					group.appendChild(line);

					tasks.push(function(){

						return bizRequest({action: "geo_zone", zone: zone, lat: lat === null ? "" : lat, lng: lng === null ? "" : lng}).then(function(r){

							if(!r.ok || !r.found){
								problems++;
								line.className = "zone-line bad";
								line.innerHTML = "❌ <strong>" + esc(zone) + "</strong>: no se encontró en el mapa. Escríbelo como aparece en Google Maps (ej.: «San Juan de Lurigancho»).";
								return;
							}

							var far = r.distance != null && r.distance > Math.max(radius, 8) * 1.5;

							if(far){ problems++; }

							line.className = "zone-line " + (far ? "warn" : "ok");
							line.innerHTML = (far ? "⚠ " : "✅ ") + "<strong>" + esc(zone) + "</strong>" + (r.distance != null ? " · " + r.distance + " km de la sucursal" : "") + (far ? " — está lejos, ¿es la sucursal correcta?" : "");

							L.circleMarker([r.lat, r.lng], {radius: 6, color: color, weight: 2, fillColor: "#fff", fillOpacity: 1})
								.bindTooltip(zone + (r.distance != null ? " · " + r.distance + " km" : ""))
								.addTo(zoneLayer);
						});
					});
				});
			});

			btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Revisando zonas…';

			/* Una a la vez: OpenStreetMap pide no hacer búsquedas en paralelo */

			return tasks.reduce(function(p, t){ return p.then(t); }, Promise.resolve()).then(function(){

				if(problems > 0 && !confirm("Hay " + problems + " observación(es) en las zonas (revisa la lista junto al mapa). ¿Confirmar las zonas de todos modos?")){

					setStatus("pending", "Zonas de cobertura sin confirmar", "Corrige las observaciones de la lista y vuelve a pulsar <em>Confirmar zonas</em>.");
					return null;
				}

				return bizRequest({action: "zones_confirm", zones: total, warnings: problems});
			});

		}).then(function(res){

			if(res && res.ok){
				setStatus("ok", "Zonas de cobertura confirmadas", esc(res.label) + ". Si cambias una sucursal, deberás confirmarlas de nuevo.");
				fncToastr("success", "Zonas de cobertura confirmadas");
			}else if(res && !res.ok){
				fncToastr("error", res.error || "No se pudo confirmar");
			}

		}).finally(function(){

			btn.disabled = false;
			btn.innerHTML = '<i class="bi bi-check2-circle"></i> Confirmar zonas';
		});
	}

	document.getElementById("zonesConfirm").addEventListener("click", run);

	/* Si se edita una sucursal después de confirmar, avisar que hay que reconfirmar */

	list.addEventListener("input", function(){

		var s = document.getElementById("covStatus");

		if(s.classList.contains("ok")){
			setStatus("changed", "Cambiaste las zonas: vuelve a confirmarlas", "Guarda y pulsa <em>Confirmar zonas</em> para revisar los cambios en el mapa.");
		}
	});

	/* Iniciar el mapa cuando la pestaña Cobertura se vea */

	var tab = document.querySelector('[data-bs-target="#tab-delivery"]');

	if(tab){ tab.addEventListener("shown.bs.tab", init); }

	if(document.getElementById("tab-delivery").classList.contains("active")){ init(); }

})();
