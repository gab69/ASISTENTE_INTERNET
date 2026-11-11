/*=============================================
Páginas del menú: crear, editar, ordenar y borrar
=============================================*/

/*---------------------------------------------
Catálogo de íconos (Bootstrap Icons) con
palabras clave en español para buscarlos
---------------------------------------------*/

var PAGE_ICONS = {
	"Comunicación": [
		["chat-dots", "chat mensajes conversación"], ["chat-left-text", "chat mensaje texto"], ["whatsapp", "whatsapp"],
		["envelope", "correo email"], ["telephone", "teléfono llamada contacto"], ["megaphone", "campaña anuncio marketing"],
		["bell", "notificación aviso alerta"], ["send", "enviar envío"], ["inbox", "bandeja entrada"], ["robot", "bot ia asistente robot"],
		["diagram-3", "bots flujo diagrama"], ["patch-question", "consulta pregunta ayuda"]
	],
	"Personas": [
		["people", "clientes personas usuarios equipo"], ["person", "persona usuario perfil"], ["person-lines-fill", "contactos directorio"],
		["person-badge", "empleado carnet"], ["person-gear", "administrador admin"], ["person-plus", "registro nuevo cliente"],
		["person-heart", "fidelización favorito"], ["person-vcard", "tarjeta datos"], ["building", "empresa negocio"], ["shop", "tienda negocio local"]
	],
	"Ventas": [
		["cart3", "carrito compras pedido"], ["bag", "bolsa compra tienda"], ["receipt", "factura recibo orden pedido"],
		["cash-coin", "dinero pago caja"], ["credit-card", "tarjeta pago"], ["wallet2", "billetera cartera pagos"],
		["qr-code", "qr pago código"], ["tag", "precio etiqueta producto"], ["tags", "categorías etiquetas"],
		["percent", "descuento promoción oferta"], ["gift", "regalo promoción"], ["truck", "envío domicilio entrega despacho"],
		["box-seam", "producto inventario paquete"], ["boxes", "inventario bodega stock"], ["basket", "canasta mercado"]
	],
	"Agenda": [
		["calendar2-week", "agenda calendario citas"], ["calendar-check", "cita confirmada reserva"], ["calendar-event", "evento fecha"],
		["clock", "hora horario tiempo"], ["alarm", "alarma recordatorio"], ["hourglass-split", "espera pendiente"],
		["list-check", "tareas pendientes checklist"], ["kanban", "tablero proceso"]
	],
	"Datos": [
		["speedometer2", "inicio tablero dashboard"], ["graph-up-arrow", "estadísticas crecimiento ventas"], ["bar-chart", "reporte gráfico barras"],
		["pie-chart", "gráfico torta"], ["table", "tabla datos"], ["clipboard-data", "informe reporte"], ["database", "base datos"],
		["file-earmark-text", "documento archivo página"], ["folder", "carpeta archivos"], ["images", "imágenes galería fotos"],
		["file-earmark-image", "archivos multimedia"], ["journal-text", "bitácora notas registro"], ["card-checklist", "lista categorías"]
	],
	"Configuración": [
		["gear", "configuración ajustes"], ["sliders", "ajustes opciones"], ["shield-lock", "seguridad permisos"], ["key", "llave acceso contraseña"],
		["plug", "integración conexión api"], ["link-45deg", "enlace link"], ["globe", "web sitio internet"], ["palette", "diseño colores"],
		["tools", "herramientas soporte"], ["question-circle", "ayuda preguntas"], ["info-circle", "información"], ["house", "inicio casa"]
	],
	"Rubros": [
		["cup-hot", "café restaurante bebida"], ["egg-fried", "comida restaurante cocina"], ["heart-pulse", "salud clínica médico"],
		["capsule", "farmacia medicina"], ["scissors", "peluquería barbería estética"], ["mortarboard", "educación curso estudiantes"],
		["car-front", "auto vehículo taller"], ["house-door", "inmobiliaria propiedad"], ["airplane", "viajes turismo"],
		["bicycle", "deporte bicicleta"], ["camera", "fotografía"], ["music-note-beamed", "música eventos"], ["briefcase", "servicios oficina trabajo"],
		["star", "favoritos destacado"], ["trophy", "premios logros"], ["geo-alt", "ubicación sucursal mapa"]
	]
};

/* Sugerir un ícono según el nombre de la página */

function pageSuggestIcon(title){

	var t = String(title || "").toLowerCase().normalize("NFD").replace(/[̀-ͯ]/g, "");

	if(!t.trim()){ return null; }

	var best = null;
	var words = t.split(/[^a-z0-9]+/).filter(function(w){ return w.length > 2; });

	Object.keys(PAGE_ICONS).forEach(function(group){

		PAGE_ICONS[group].forEach(function(item){

			var keys = item[1].normalize("NFD").replace(/[̀-ͯ]/g, "");

			words.forEach(function(w){

				if(!best && keys.split(" ").some(function(k){ return k.indexOf(w) === 0 || w.indexOf(k) === 0; })){
					best = "bi bi-" + item[0];
				}
			});
		});
	});

	return best;

}

function pageSlug(text){

	return String(text || "").toLowerCase().normalize("NFD").replace(/[̀-ͯ]/g, "")
		.replace(/[^a-z0-9]+/g, "-").replace(/^-+|-+$/g, "").slice(0, 40);

}

(function(){

	var modal = document.getElementById("myPage");

	if(!modal){ return; }

	var title = document.getElementById("title_page");
	var url = document.getElementById("url_page");
	var icon = document.getElementById("icon_page");
	var type = document.getElementById("type_page");
	var grid = document.getElementById("iconGrid");
	var search = document.getElementById("iconSearch");
	var group = document.getElementById("iconGroup");
	var state = {editing: false, urlTouched: false, iconTouched: false};

	group.innerHTML = '<option value="">Todas las categorías</option>' + Object.keys(PAGE_ICONS).map(function(g){
		return '<option value="' + g + '">' + g + '</option>';
	}).join("");

	/* Pintar la grilla de íconos */

	function renderIcons(){

		var q = search.value.toLowerCase().normalize("NFD").replace(/[̀-ͯ]/g, "").trim();
		var g = group.value;
		var html = "";

		Object.keys(PAGE_ICONS).forEach(function(name){

			if(g && g !== name){ return; }

			var list = PAGE_ICONS[name].filter(function(item){
				return !q || (item[0] + " " + item[1]).normalize("NFD").replace(/[̀-ͯ]/g, "").indexOf(q) > -1;
			});

			if(!list.length){ return; }

			if(!q && !g){ html += '<div class="icon-group-title">' + name + '</div>'; }

			html += list.map(function(item){
				var cls = "bi bi-" + item[0];
				var label = item[1].split(" ")[0];
				return '<button type="button" class="icon-opt' + (icon.value.trim() === cls ? " active" : "") + '" data-icon="' + cls + '" title="' + label + '" role="option">' +
					'<i class="' + cls + '"></i><span>' + label + '</span></button>';
			}).join("");

		});

		grid.innerHTML = html || '<div class="icon-empty">No hay íconos con ese nombre. Prueba con otra palabra.</div>';

	}

	function preview(){

		var cls = icon.value.trim() || "bi bi-file-earmark-text";
		var external = type.value === "external_link";

		document.getElementById("pagePreviewIcon").className = cls;
		document.getElementById("pagePreviewTitle").textContent = title.value.trim() || "Nombre de la página";
		document.getElementById("pagePreviewUrl").textContent = external ? (url.value || "https://…") : "/" + (url.value || "");

		grid.querySelectorAll(".icon-opt").forEach(function(b){ b.classList.toggle("active", b.getAttribute("data-icon") === cls); });

	}

	function setType(value){

		type.value = value || "modules";

		modal.querySelectorAll(".type-card").forEach(function(c){ c.classList.toggle("active", c.getAttribute("data-type") === type.value); });

		var external = type.value === "external_link";
		var internal = type.value === "internal_link";

		url.previousElementSibling.textContent = external ? "🌐" : "/";
		url.placeholder = external ? "https://misitio.com" : (internal ? "chat" : "proveedores");
		document.getElementById("urlHelp").textContent = external ? "Escribe la dirección completa del sitio."
			: (internal ? "Escribe la ruta de la sección del panel, por ejemplo: chat" : "Se completa sola a partir del nombre.");

		preview();

	}

	modal.querySelectorAll(".type-card").forEach(function(c){
		c.addEventListener("click", function(){ setType(c.getAttribute("data-type")); });
	});

	grid.addEventListener("click", function(e){

		var b = e.target.closest(".icon-opt");

		if(!b){ return; }

		icon.value = b.getAttribute("data-icon");
		state.iconTouched = true;
		preview();

	});

	title.addEventListener("input", function(){

		if(!state.editing && !state.urlTouched && type.value !== "external_link"){
			url.value = pageSlug(title.value);
		}

		if(!state.editing && !state.iconTouched){
			var s = pageSuggestIcon(title.value);
			if(s){ icon.value = s; }
		}

		preview();

	});

	url.addEventListener("input", function(){ state.urlTouched = true; preview(); });
	icon.addEventListener("input", function(){ state.iconTouched = true; preview(); });
	icon.addEventListener("change", function(){ setTimeout(preview, 0); });
	search.addEventListener("input", renderIcons);
	group.addEventListener("change", renderIcons);

	/*=============================================
	Abrir: nueva o editar
	=============================================*/

	$(document).on("click", ".myPage", function(){

		var page = $(this).attr("page");
		var data = page ? JSON.parse(page) : null;

		$("input[name='id_page']").remove();

		state.editing = !!data;
		state.urlTouched = !!data;
		state.iconTouched = !!data;
		search.value = "";
		group.value = "";

		if(data){

			$("#title_page").before('<input type="hidden" id="id_page" name="id_page" value="' + btoa(data.id_page) + '">');

			title.value = data.title_page;
			try{ url.value = decodeURIComponent(String(data.url_page)); }catch(err){ url.value = data.url_page; }
			icon.value = data.icon_page || "bi bi-file-earmark-text";
			setType(data.type_page);

			document.getElementById("pageModalTitle").textContent = "Editar página";

		}else{

			title.value = "";
			url.value = "";
			icon.value = "bi bi-file-earmark-text";
			setType("modules");

			document.getElementById("pageModalTitle").textContent = "Nueva página";
		}

		modal.querySelector("form").classList.remove("was-validated");
		renderIcons();
		preview();

		$("#myPage").modal("show");

	});

	modal.addEventListener("shown.bs.modal", function(){ title.focus(); });

	/* Si el ícono quedó vacío, usar uno por defecto */

	modal.querySelector("form").addEventListener("submit", function(){

		if(!icon.value.trim()){ icon.value = pageSuggestIcon(title.value) || "bi bi-file-earmark-text"; }

	}, true);

})();

/*=============================================
Cambiar orden de páginas
=============================================*/

$("#sortable").sortable({
	placeholder: 'sort-highlight',
	handle: '.handle',
	forcePlaceholderSize: true,
	zIndex:999999,
	out: function(event,ui){

		var listPage = $("#sortable li");
		var countList = 0;

		listPage.each((i)=>{

			var idPage = $(listPage[i]).attr("idPage");
			var index = i+1;

			var data = new FormData();
			data.append("idPage",idPage);
			data.append("index", index);
			data.append("token", localStorage.getItem("tokenAdmin"));

			$.ajax({

				url:"/ajax/pages.ajax.php",
				method:"POST",
				data:data,
				contentType:false,
				cache:false,
				processData:false,
				success: function(response){

					if(response == 200){

						countList++;

						if(countList == listPage.length){

							fncToastr("success", "El orden del menú se actualizó");
						}
					}

				}

			})

		})

	}

})

/*=============================================
Eliminar una página
=============================================*/

$(document).on("click",".deletePage",function(){

	var idPage = $(this).attr("idPage");

	if(atob(idPage) == 1 || atob(idPage) == 2){

		fncToastr("error", "Esta página no se puede borrar");
		return;
	}

	fncSweetAlert("confirm", "¿Seguro que quieres borrar esta página?", "").then(resp=>{

		if(resp){

			var data = new FormData();
			data.append("idPageDelete",idPage);
			data.append("token", localStorage.getItem("tokenAdmin"));

			$.ajax({

				url:"/ajax/pages.ajax.php",
				method:"POST",
				data:data,
				contentType:false,
				cache:false,
				processData:false,
				success: function(response){

					if(response == 200){

						fncSweetAlert("success","La página se eliminó",setTimeout(()=>location.reload(),1250));

					}else{

						fncToastr("error", "No se puede borrar: la página tiene módulos. Elimínalos primero.");
					}
				}

			})

		}
	})

})
