<?php

require_once "controllers/business.orders.php";

if(empty($_SESSION["biz_csrf"])){

	$_SESSION["biz_csrf"] = bin2hex(random_bytes(24));
}

$biz = BizStore::all();

?>

<div class="page-wrap">

	<div class="page-head">
		<div>
			<h1 class="page-title"><i class="bi bi-shop"></i> Mi negocio</h1>
			<p class="page-sub">Todo lo que escribas aquí lo usa el asistente de IA para responder a tus clientes por WhatsApp.</p>
		</div>
	</div>

	<ul class="nav seg-tabs mb-4" role="tablist">
		<li><button class="seg-tab active" data-bs-toggle="tab" data-bs-target="#tab-company" type="button"><i class="bi bi-building"></i> <span>Empresa</span></button></li>
		<li><button class="seg-tab" data-bs-toggle="tab" data-bs-target="#tab-contacts" type="button"><i class="bi bi-telephone"></i> <span>Números por área</span></button></li>
		<li><button class="seg-tab" data-bs-toggle="tab" data-bs-target="#tab-payments" type="button"><i class="bi bi-qr-code"></i> <span>Pagos y QR</span></button></li>
		<li><button class="seg-tab" data-bs-toggle="tab" data-bs-target="#tab-images" type="button"><i class="bi bi-images"></i> <span>Imágenes</span></button></li>
		<li><button class="seg-tab" data-bs-toggle="tab" data-bs-target="#tab-service" type="button"><i class="bi bi-router"></i> <span>Servicio</span></button></li>
		<li><button class="seg-tab" data-bs-toggle="tab" data-bs-target="#tab-delivery" type="button"><i class="bi bi-geo-alt"></i> <span>Cobertura</span></button></li>
		<li><button class="seg-tab" data-bs-toggle="tab" data-bs-target="#tab-agenda" type="button"><i class="bi bi-calendar2-week"></i> <span>Agenda</span></button></li>
		<li><button class="seg-tab" data-bs-toggle="tab" data-bs-target="#tab-inquiries" type="button"><i class="bi bi-patch-question"></i> <span>Consultas</span> <span class="tab-badge" id="inqBadge" hidden></span></button></li>
	</ul>

	<div class="tab-content">

		<!--=============================================
		EMPRESA
		==============================================-->

		<div class="tab-pane fade show active" id="tab-company">

			<form class="card-soft" data-section="company">

				<div class="card-soft-head">
					<h2>Información de la empresa</h2>
					<p>Datos generales que la IA comparte cuando el cliente pregunta.</p>
				</div>

				<div class="row g-3">
					<div class="col-md-6">
						<label class="form-label">Nombre del negocio</label>
						<input class="form-control" name="name" placeholder="Ej.: Clínica Sonrisas">
					</div>
					<div class="col-md-6">
						<label class="form-label">Horario de atención</label>
						<input class="form-control" name="hours" placeholder="Lun a vie 8:00 a. m. – 6:00 p. m.">
					</div>
					<div class="col-12">
						<label class="form-label">¿Qué hace tu negocio?</label>
						<textarea class="form-control" name="description" rows="3" placeholder="Describe tus productos o servicios, precios y lo que te diferencia."></textarea>
					</div>
					<div class="col-md-6">
						<label class="form-label">Dirección</label>
						<input class="form-control" name="address" placeholder="Av. Larco 123, Miraflores, Lima">
					</div>
					<div class="col-md-6">
						<label class="form-label">Correo</label>
						<input class="form-control" name="email" type="email" placeholder="contacto@minegocio.com">
					</div>
					<div class="col-md-6">
						<label class="form-label">Sitio web</label>
						<input class="form-control" name="website" placeholder="https://minegocio.com">
					</div>
					<div class="col-md-6">
						<label class="form-label">Redes sociales</label>
						<input class="form-control" name="social" placeholder="Instagram @minegocio · Facebook /minegocio">
					</div>
					<div class="col-12">
						<label class="form-label">Información adicional <span class="text-muted fw-normal">(preguntas frecuentes, políticas, promociones)</span></label>
						<textarea class="form-control" name="extra" rows="4" placeholder="Ej.: Hacemos envíos a todo el país. Las citas se pueden cambiar con 24 h de anticipación."></textarea>
					</div>
				</div>

				<div class="card-soft-foot">
					<button class="btn btn-brand" type="submit"><i class="bi bi-check2"></i> Guardar</button>
				</div>

			</form>

		</div>

		<!--=============================================
		CONTACTOS
		==============================================-->

		<div class="tab-pane fade" id="tab-contacts">

			<form class="card-soft mb-4" data-section="main">

				<div class="card-soft-head">
					<h2><i class="bi bi-star"></i> Número principal</h2>
					<p>El WhatsApp del dueño o encargado. Recibe <strong>todas</strong> las órdenes, instalaciones, cancelaciones y consultas, y puede escribirle al bot para consultar el sistema (por ejemplo «órdenes de hoy»).</p>
				</div>

				<div class="row g-3 align-items-end">
					<div class="col-md-6">
						<label class="form-label">WhatsApp principal</label>
						<input class="form-control" name="phone" inputmode="tel" placeholder="51987654321 (con código de país)">
						<div class="form-text">Distinto al WhatsApp del negocio. Si lo dejas vacío se usa el «WhatsApp central de órdenes» de Cobertura.</div>
					</div>
					<div class="col-md-6">
						<div class="callout mb-0">
							<i class="bi bi-info-circle"></i>
							<div>Para que los avisos lleguen siempre, escríbele al bot desde este número una vez al día o configura la plantilla de abajo.</div>
						</div>
					</div>
				</div>

				<div class="card-soft-foot">
					<button class="btn btn-brand" type="submit"><i class="bi bi-check2"></i> Guardar</button>
				</div>

			</form>

			<form class="card-soft" data-section="contacts">

				<div class="card-soft-head">
					<h2>Números por área</h2>
					<p>Un celular por cada área (Ventas, Soporte técnico, Pagos…) y para qué sirve. La asistente deriva al cliente al número correcto según su necesidad, y puedes elegir qué avisos recibe cada uno.</p>
				</div>

				<div class="area-help">
					<div><i class="bi bi-chat-dots"></i> <b>Lo comparte con clientes:</b> la asistente da este número solo cuando la consulta corresponde a esa área.</div>
					<div><i class="bi bi-bell"></i> <b>Avisos:</b> órdenes nuevas, instalaciones y cancelaciones llegan a este WhatsApp (además del número central de Cobertura).</div>
				</div>

				<div class="repeat-list" data-list="contacts"></div>

				<button type="button" class="btn btn-ghost mt-2" data-add="contacts"><i class="bi bi-plus-lg"></i> Agregar número</button>

				<div class="card-soft-foot">
					<button class="btn btn-brand" type="submit"><i class="bi bi-check2"></i> Guardar</button>
				</div>

			</form>

			<form class="card-soft mt-4" data-section="notices">

				<div class="card-soft-head">
					<h2>Plantilla para que los avisos lleguen siempre</h2>
					<p>WhatsApp solo entrega un mensaje libre a un número que le escribió al bot en las <strong>últimas 24 horas</strong>. Si no, el aviso de la orden falla con el error <strong>131047</strong> («No llegó»). Con una plantilla aprobada, las órdenes, citas y consultas llegan aunque el número no haya escrito.</p>
				</div>

				<ol class="steps-mini">
					<li><i class="bi bi-1-circle"></i><span>Entra a <a href="https://business.facebook.com/wa/manage/message-templates/" target="_blank" rel="noopener">WhatsApp Manager → Plantillas</a> y crea una de categoría <strong>Utilidad</strong>.</span></li>
					<li><i class="bi bi-2-circle"></i><span>Usa este cuerpo (una sola variable): <code>📢 Nuevo aviso de ventas: {{1}}. Revisa el panel para ver el detalle.</code></span></li>
					<li><i class="bi bi-3-circle"></i><span>Cuando Meta la apruebe, escribe aquí su nombre exacto y guarda.</span></li>
				</ol>

				<div class="row g-3">
					<div class="col-md-8">
						<label class="form-label">Nombre de la plantilla</label>
						<input class="form-control font-monospace" name="template" placeholder="aviso_orden (vacío = solo texto)" pattern="[a-z0-9_]*">
						<div class="form-text">Solo minúsculas, números y guion bajo, igual que en WhatsApp Manager.</div>
					</div>
					<div class="col-md-4">
						<label class="form-label">Idioma</label>
						<input class="form-control" name="lang" placeholder="es">
						<div class="form-text">Ej.: <code>es</code>, <code>es_PE</code>, <code>es_MX</code>.</div>
					</div>
				</div>

				<div class="callout mt-3">
					<i class="bi bi-info-circle"></i>
					<div>Sin plantilla, cada número que recibe avisos debe escribirle al WhatsApp del negocio al menos una vez al día. Revisa también que sea un celular real con código de país (ej. <code>51987654321</code>).</div>
				</div>

				<div class="card-soft-foot">
					<button class="btn btn-brand" type="submit"><i class="bi bi-check2"></i> Guardar</button>
				</div>

			</form>

		</div>

		<!--=============================================
		PAGOS
		==============================================-->

		<div class="tab-pane fade" id="tab-payments">

			<form class="card-soft" data-section="payments">

				<div class="card-soft-head">
					<h2>Métodos de pago</h2>
					<p>Cuando el cliente pida el QR (ej.: <em>"pásame el QR de Yape"</em>) o vaya a pagar, la IA le envía la <strong>imagen del QR</strong> con el número, el titular y el monto en soles. Sube el QR en JPG o PNG.</p>
				</div>

				<div class="repeat-list" data-list="payments"></div>

				<button type="button" class="btn btn-ghost mt-2" data-add="payments"><i class="bi bi-plus-lg"></i> Agregar método de pago</button>

				<div class="card-soft-foot">
					<button class="btn btn-brand" type="submit"><i class="bi bi-check2"></i> Guardar</button>
				</div>

			</form>

		</div>

		<!--=============================================
		IMÁGENES
		==============================================-->

		<div class="tab-pane fade" id="tab-images">

			<form class="card-soft" data-section="images">

				<div class="card-soft-head">
					<h2>Imágenes para responder</h2>
					<p>Menú, catálogo, ubicación, precios… La IA envía la imagen cuando el cliente la necesita según su descripción.</p>
				</div>

				<div class="repeat-list grid-list" data-list="images"></div>

				<button type="button" class="btn btn-ghost mt-2" data-add="images"><i class="bi bi-plus-lg"></i> Agregar imagen</button>

				<div class="card-soft-foot">
					<button class="btn btn-brand" type="submit"><i class="bi bi-check2"></i> Guardar</button>
				</div>

			</form>

		</div>

		<!--=============================================
		SERVICIO (pedidos o internet / TV con instalación)
		==============================================-->

		<div class="tab-pane fade" id="tab-service">

			<form class="card-soft" data-section="service">

				<div class="card-soft-head">
					<h2>¿Qué vendes?</h2>
					<p>Define cómo atiende la IA: pedidos a domicilio, o venta de planes de internet / TV que requieren <strong>agendar la instalación</strong>.</p>
				</div>

				<div class="row g-3">

					<div class="col-12">
						<div class="type-cards">
							<label class="type-card" data-mode-card>
								<input type="radio" name="mode" value="installation" class="d-none">
								<i class="bi bi-router"></i>
								<strong>Internet y TV con instalación</strong>
								<span>Planes por mes, cobertura por sucursal y cita de instalación.</span>
							</label>
							<label class="type-card" data-mode-card>
								<input type="radio" name="mode" value="delivery" class="d-none">
								<i class="bi bi-bag"></i>
								<strong>Pedidos a domicilio</strong>
								<span>Productos con envío o recojo en tienda.</span>
							</label>
						</div>
					</div>

					<div class="col-12 svc-install">
						<div class="callout">
							<i class="bi bi-list-check"></i>
							<div>Los <strong>planes y sus precios</strong> se cargan en <a href="/productos" class="fw-semibold">Productos</a> (ej.: categoría <em>Planes de internet</em> → "Plan 100 Mbps" a <?php echo htmlspecialchars(cms_currency()) ?> 69.00). La IA solo ofrece esos precios.
							<?php $plans = BizOrders::catalogue(); ?>
							<?php if (!empty($plans)): ?>
								<div class="mt-2 small text-muted">Catálogo actual: <?php echo htmlspecialchars(implode(" · ", array_map(function($p){ return $p["name"]." ".cms_money($p["price"]); }, array_slice($plans, 0, 8)))) ?><?php echo count($plans) > 8 ? " …" : "" ?></div>
							<?php endif ?>
							</div>
						</div>
					</div>

					<div class="col-md-6 svc-install">
						<label class="form-label">Costo de instalación</label>
						<div class="input-group"><span class="input-group-text"><?php echo htmlspecialchars(cms_currency()) ?></span><input class="form-control" type="number" min="0" step="0.5" name="install_fee"></div>
						<div class="form-text">0 = instalación gratis. Se suma una sola vez a la orden.</div>
					</div>

					<div class="col-md-6 svc-install">
						<label class="form-label">Requisitos para instalar</label>
						<input class="form-control" name="requirements" placeholder="DNI del titular, recibo de luz">
						<div class="form-text">La IA los pide antes de agendar.</div>
					</div>

					<div class="col-md-6 svc-install">
						<div class="form-check form-switch mt-md-4">
							<input class="form-check-input" type="checkbox" name="pay_on_install" id="pay_on_install">
							<label class="form-check-label" for="pay_on_install">El pago se realiza <strong>en persona el día de la instalación</strong></label>
						</div>
						<div class="form-text">La IA lo menciona al resumir la orden. Solo si el cliente pide otros medios, le muestra los de <em>Pagos y QR</em>.</div>
					</div>

					<div class="col-md-6 svc-install">
						<label class="form-label">El cliente puede cancelar su orden hasta</label>
						<div class="input-group"><input class="form-control" type="number" min="0" max="168" step="1" name="cancel_hours"><span class="input-group-text">horas antes de la instalación</span></div>
						<div class="form-text">Al cancelar por WhatsApp se avisa al número central. Pasado ese plazo la IA no la cancela y avisa al equipo. 0 = hasta la hora de la instalación.</div>
					</div>

					<div class="col-12 svc-install">
						<div class="card-soft p-3" style="box-shadow:none">
							<div class="form-check form-switch mb-3">
								<input class="form-check-input" type="checkbox" name="tv_enabled" id="svc_tv">
								<label class="form-check-label fw-semibold" for="svc_tv"><i class="bi bi-tv"></i> Ofrecer señal de TV abierta (con TV box)</label>
							</div>
							<div class="row g-3 svc-tv">
								<div class="col-md-6">
									<label class="form-label">Precio mensual de la TV</label>
									<div class="input-group"><span class="input-group-text"><?php echo htmlspecialchars(cms_currency()) ?></span><input class="form-control" type="number" min="0" step="0.5" name="tv_price"></div>
									<div class="form-text">0 = incluida con el plan.</div>
								</div>
								<div class="col-md-6">
									<label class="form-label">Precio del TV box</label>
									<div class="input-group"><span class="input-group-text"><?php echo htmlspecialchars(cms_currency()) ?></span><input class="form-control" type="number" min="0" step="0.5" name="tvbox_price"></div>
									<div class="form-text">Si el cliente ya tiene uno, no se cobra. 0 = no lo vendemos.</div>
								</div>
								<div class="col-12">
									<label class="form-label">Detalles de la TV <span class="text-muted fw-normal">(opcional)</span></label>
									<input class="form-control" name="tv_notes" placeholder="Ej.: más de 40 canales nacionales en HD, compatible con cualquier TV box Android">
								</div>
							</div>
						</div>
					</div>

					<div class="col-12 svc-install">
						<div class="callout">
							<i class="bi bi-calendar2-check"></i>
							<div>Las instalaciones se agendan con los horarios de <a href="#" data-goto="agenda" class="fw-semibold">Agenda</a> (días, horas, duración del turno y <strong>citas por turno = cuadrillas de técnicos</strong>). Activa la agenda para que la IA pueda reservar.</div>
						</div>
					</div>

				</div>

				<div class="card-soft-foot">
					<button class="btn btn-brand" type="submit"><i class="bi bi-check2"></i> Guardar</button>
				</div>

			</form>

		</div>

		<!--=============================================
		COBERTURA Y SUCURSALES
		==============================================-->

		<div class="tab-pane fade" id="tab-delivery">

			<form class="card-soft covx" data-section="delivery">

				<div class="cov-head">
					<div class="cov-head-text">
						<span class="cov-head-ico"><i class="bi bi-broadcast-pin"></i></span>
						<div>
							<h2>Cobertura y sucursales</h2>
							<p>La IA ubica al cliente (por su pin 📍, un enlace de Maps o su dirección), lo <strong>compara con todas las sucursales</strong> y asigna la más cercana con cobertura.</p>
						</div>
					</div>
					<label class="cov-switch" for="delivery_enabled">
						<span>Verificar cobertura</span>
						<span class="form-check form-switch m-0"><input class="form-check-input" type="checkbox" name="enabled" id="delivery_enabled"></span>
					</label>
				</div>

				<div class="cov-kpis" id="covKpis">
					<div class="cov-kpi"><span class="dx-ico c1"><i class="bi bi-buildings"></i></span><div><strong data-ck="branches">–</strong><small>Sucursales</small></div></div>
					<div class="cov-kpi"><span class="dx-ico c3"><i class="bi bi-geo-alt"></i></span><div><strong data-ck="located">–</strong><small>Ubicadas en el mapa</small></div></div>
					<div class="cov-kpi"><span class="dx-ico c2"><i class="bi bi-signpost-split"></i></span><div><strong data-ck="zones">–</strong><small>Distritos con cobertura</small></div></div>
					<div class="cov-kpi"><span class="dx-ico c5"><i class="bi bi-whatsapp"></i></span><div><strong data-ck="phones">–</strong><small>Con WhatsApp propio</small></div></div>
				</div>

				<?php $confirmed = BizGeo::zonesConfirmed(); $confirmedRaw = BizStore::get("delivery")["zones_confirmed"] ?? null; ?>

				<div class="cov-status <?php echo $confirmed ? "ok" : ($confirmedRaw ? "changed" : "pending") ?>" id="covStatus">
					<i class="bi <?php echo $confirmed ? "bi-patch-check-fill" : "bi-exclamation-diamond" ?>"></i>
					<div>
						<strong><?php echo $confirmed ? "Zonas de cobertura confirmadas" : ($confirmedRaw ? "Cambiaste las zonas: vuelve a confirmarlas" : "Zonas de cobertura sin confirmar") ?></strong>
						<span><?php echo $confirmed ? "El ".date("d/m/Y \a \l\a\s h:i a", strtotime($confirmed["at"])).($confirmed["by"] ? " por ".htmlspecialchars($confirmed["by"]) : "").". Si cambias una sucursal, deberás confirmarlas de nuevo." : "Revisa en el mapa que cada sucursal y cada distrito estén donde corresponde y pulsa <em>Confirmar zonas</em>." ?></span>
					</div>
					<button type="button" class="btn btn-brand btn-sm ms-auto" id="zonesConfirm"><i class="bi bi-check2-circle"></i> Confirmar zonas</button>
				</div>

				<div class="cov-layout">

					<!-- Sucursales -->

					<div class="cov-col-list">

						<div class="cov-list-head">
							<div>
								<h3>Sucursales</h3>
								<span id="branchCount"></span>
							</div>
							<button type="button" class="btn btn-brand btn-sm" id="branchAdd"><i class="bi bi-plus-lg"></i> Agregar</button>
						</div>

						<div class="branch-list" id="branchList"></div>

					</div>

					<!-- Mapa y pruebas -->

					<div class="cov-col-map">

						<div class="cov-map-wrap">
							<div class="cov-pick-banner" id="covPickBanner"><i class="bi bi-cursor-fill"></i> <span></span> <button type="button" class="btn btn-sm btn-light" id="covPickCancel">Cancelar</button></div>
							<div class="cov-map" id="coverageMap"><div class="map-empty"><i class="bi bi-map"></i><span>Cargando mapa…</span></div></div>
							<div class="cov-legend" id="covLegend"></div>
						</div>

						<div class="cov-test">
							<div class="cov-test-head"><i class="bi bi-bullseye"></i> Probar cobertura <small>o haz clic en cualquier punto del mapa</small></div>
							<div class="cov-test-row">
								<div class="input-group input-group-sm">
									<input class="form-control" id="geoTestInput" placeholder="Dirección o enlace de Maps (ej.: Jr. Huiracocha 1234, Jesús María)">
									<button type="button" class="btn btn-ghost" id="geoTest"><i class="bi bi-search"></i> Verificar</button>
								</div>
								<button type="button" class="btn btn-ghost btn-sm" id="covTestHere" title="Probar en mi ubicación actual"><i class="bi bi-geo-fill"></i> <span>Mi ubicación</span></button>
							</div>
							<div class="geo-result" id="geoTestResult"></div>
							<div class="geo-result" id="mapPointResult"></div>
						</div>

						<div class="zone-report" id="zoneReport"></div>

					</div>

				</div>

				<!-- Ajustes generales -->

				<div class="cov-settings">

					<div class="cov-settings-head"><i class="bi bi-sliders2"></i> Ajustes generales</div>

					<div class="row g-3">

						<div class="col-lg-6">
							<label class="form-label"><i class="bi bi-whatsapp"></i> WhatsApp central de órdenes</label>
							<input class="form-control" name="notify_phone" inputmode="tel" placeholder="51987654321 (con código de país)">
							<div class="form-text">Recibe <strong>todas</strong> las órdenes. Cada sucursal recibe además las suyas, y puedes sumar áreas en <a href="#" data-goto-tab="contacts">Números por área</a>.</div>
						</div>

						<div class="col-sm-6 col-lg-3">
							<label class="form-label">Ciudad y país para buscar</label>
							<input class="form-control" name="city_hint" placeholder="Lima, Perú">
						</div>

						<div class="col-sm-6 col-lg-3">
							<label class="form-label">Llave de Google Maps <span class="text-muted fw-normal">(opcional)</span></label>
							<input class="form-control font-monospace" name="maps_key" placeholder="AIza…" autocomplete="off">
						</div>

						<div class="col-12 svc-delivery">
							<div class="row g-3">
								<div class="col-6 col-md-3">
									<label class="form-label">Costo de envío</label>
									<div class="input-group"><span class="input-group-text"><?php echo htmlspecialchars(cms_currency()) ?></span><input class="form-control" type="number" min="0" step="0.5" name="fee"></div>
								</div>
								<div class="col-6 col-md-3">
									<label class="form-label">Adicional por km</label>
									<div class="input-group"><span class="input-group-text"><?php echo htmlspecialchars(cms_currency()) ?></span><input class="form-control" type="number" min="0" step="0.1" name="fee_per_km"></div>
								</div>
								<div class="col-6 col-md-3">
									<label class="form-label">Envío gratis desde</label>
									<div class="input-group"><span class="input-group-text"><?php echo htmlspecialchars(cms_currency()) ?></span><input class="form-control" type="number" min="0" step="1" name="free_from"></div>
								</div>
								<div class="col-6 col-md-3">
									<label class="form-label">Pedido mínimo</label>
									<div class="input-group"><span class="input-group-text"><?php echo htmlspecialchars(cms_currency()) ?></span><input class="form-control" type="number" min="0" step="1" name="min_order"></div>
								</div>
								<div class="col-12">
									<div class="form-check form-switch">
										<input class="form-check-input" type="checkbox" name="pickup" id="delivery_pickup">
										<label class="form-check-label" for="delivery_pickup">También se puede recoger en tienda</label>
									</div>
								</div>
							</div>
						</div>

					</div>

				</div>

				<div class="card-soft-foot">
					<a href="/ordenes" class="btn btn-ghost"><i class="bi bi-clipboard-check"></i> Ver órdenes</a>
					<button class="btn btn-brand" type="submit"><i class="bi bi-check2"></i> Guardar todo</button>
				</div>

			</form>

		</div>

		<!--=============================================
		AGENDA
		==============================================-->

		<div class="tab-pane fade" id="tab-agenda">

			<form class="card-soft" data-section="agenda">

				<div class="card-soft-head d-flex justify-content-between align-items-start gap-3">
					<div>
						<h2>Agenda de citas</h2>
						<p>La IA solo ofrece turnos libres. Si un turno ya está tomado, el sistema lo rechaza y sugiere otros.</p>
					</div>
					<div class="form-check form-switch m-0 pt-1">
						<input class="form-check-input" type="checkbox" name="enabled" id="agenda_enabled">
						<label class="form-check-label" for="agenda_enabled">Activa</label>
					</div>
				</div>

				<div class="row g-3">

					<div class="col-12">
						<div class="callout">
							<i class="bi bi-whatsapp"></i>
							<div>
								<label class="form-label mb-1">Número que recibe las citas</label>
								<input class="form-control" name="notify_phone" placeholder="51987654321 (con código de país)">
								<div class="form-text">Le llega por WhatsApp el resumen de cada cita con un botón para agregarla a Google Calendar. Ese número debe haber escrito a tu WhatsApp Business en las últimas 24 h (regla de Meta).</div>
							</div>
						</div>
					</div>

					<div class="col-12">
						<label class="form-label">Días de atención</label>
						<div class="day-picker">
							<?php foreach (array(1 => "Lun", "Mar", "Mié", "Jue", "Vie", "Sáb", "Dom") as $n => $d): ?>
								<input type="checkbox" class="btn-check" name="days" value="<?php echo $n ?>" id="day<?php echo $n ?>">
								<label class="day-chip" for="day<?php echo $n ?>"><?php echo $d ?></label>
							<?php endforeach ?>
						</div>
					</div>

					<div class="col-6 col-md-3">
						<label class="form-label">Abre</label>
						<input class="form-control" type="time" name="open">
					</div>
					<div class="col-6 col-md-3">
						<label class="form-label">Cierra</label>
						<input class="form-control" type="time" name="close">
					</div>
					<div class="col-6 col-md-3">
						<label class="form-label">Descanso desde</label>
						<input class="form-control" type="time" name="break_start">
					</div>
					<div class="col-6 col-md-3">
						<label class="form-label">Descanso hasta</label>
						<input class="form-control" type="time" name="break_end">
					</div>

					<div class="col-12">
						<label class="form-label">Horarios fijos (opcional)</label>
						<input class="form-control" name="times" placeholder="08:00, 10:00, 12:00, 14:00, 16:00, 17:00">
						<div class="form-text">Si los escribes, el cliente solo puede elegir esas horas (se ignoran apertura, cierre y descanso). Para internet, si lo dejas vacío se usan 8:00, 10:00, 12:00, 2:00, 4:00 y 5:00 p. m.</div>
					</div>

					<div class="col-6 col-md-3">
						<label class="form-label">Duración del turno</label>
						<select class="form-select" name="slot_minutes">
							<?php foreach (array(15, 20, 30, 45, 60, 90, 120) as $m): ?>
								<option value="<?php echo $m ?>"><?php echo $m ?> min</option>
							<?php endforeach ?>
						</select>
					</div>
					<div class="col-6 col-md-3">
						<label class="form-label">Citas por turno</label>
						<input class="form-control" type="number" min="1" max="50" name="capacity">
						<div class="form-text">1 = nunca dos citas a la misma hora.</div>
					</div>
					<div class="col-6 col-md-3">
						<label class="form-label">Agendar hasta</label>
						<div class="input-group">
							<input class="form-control" type="number" min="1" max="120" name="max_days">
							<span class="input-group-text">días</span>
						</div>
					</div>
					<div class="col-6 col-md-3">
						<label class="form-label">Anticipación mínima</label>
						<div class="input-group">
							<input class="form-control" type="number" min="0" max="72" step="0.5" name="min_hours">
							<span class="input-group-text">horas</span>
						</div>
					</div>

					<div class="col-12">
						<label class="form-label">Servicios que se pueden agendar</label>
						<textarea class="form-control" name="services" rows="2" placeholder="Ej.: Limpieza dental, Valoración, Blanqueamiento"></textarea>
					</div>

				</div>

				<div class="card-soft-foot">
					<a href="/agenda" class="btn btn-ghost"><i class="bi bi-calendar2-week"></i> Ver calendario</a>
					<button class="btn btn-brand" type="submit"><i class="bi bi-check2"></i> Guardar</button>
				</div>

			</form>

		</div>

		<!--=============================================
		CONSULTAS AL EQUIPO
		==============================================-->

		<div class="tab-pane fade" id="tab-inquiries">

			<form class="card-soft" data-section="inquiries">

				<div class="card-soft-head">
					<h2>Cuando la IA no sabe la respuesta</h2>
					<p>En lugar de inventar, la IA le dice al cliente que va a consultar y le escribe a este número. Cuando respondas, la respuesta le llega al cliente y queda guardada para la próxima vez.</p>
				</div>

				<ol class="steps-mini">
					<li><i class="bi bi-chat-left-dots"></i><span>El cliente pregunta algo que no está en <em>Mi negocio</em>.</span></li>
					<li><i class="bi bi-send"></i><span>La IA te envía la pregunta por WhatsApp con un número, por ejemplo <strong>#12</strong>.</span></li>
					<li><i class="bi bi-reply"></i><span>Respondes <strong>citando ese mensaje</strong> o escribiendo <strong>#12 tu respuesta</strong>.</span></li>
					<li><i class="bi bi-lightbulb"></i><span>El cliente recibe la respuesta y la IA la recuerda para futuras preguntas.</span></li>
				</ol>

				<div class="row g-3">
					<div class="col-12">
						<div class="callout">
							<i class="bi bi-check-circle"></i>
							<div>Las consultas están <strong>siempre activas</strong>: si el cliente pide una promoción, plan o producto que no está aquí, la IA le dice que lo va a consultar en lugar de cerrar la conversación.</div>
						</div>
					</div>
					<div class="col-md-6">
						<label class="form-label">Número que responde las consultas <span class="text-muted fw-normal">(recomendado)</span></label>
						<input class="form-control" name="phone" inputmode="tel" placeholder="51987654321 (con código de país)">
						<div class="form-text">Distinto al WhatsApp del negocio. Si lo dejas vacío, las consultas llegan a los números que reciben las órdenes; ahí responde citando el mensaje o con <strong>#número</strong>.</div>
					</div>
					<div class="col-md-6">
						<label class="form-label">Nombre de quien responde <span class="text-muted fw-normal">(opcional)</span></label>
						<input class="form-control" name="name" placeholder="Ej.: Laura, gerencia">
					</div>
				</div>

				<div class="callout mt-3">
					<i class="bi bi-info-circle"></i>
					<div>WhatsApp solo deja que el negocio le escriba a un número que le haya enviado un mensaje en las <strong>últimas 24 horas</strong>. Escríbele al WhatsApp del negocio desde este número una vez al día, o responde las consultas desde esta pantalla.</div>
				</div>

				<div class="card-soft-foot">
					<button class="btn btn-brand" type="submit"><i class="bi bi-check2"></i> Guardar</button>
				</div>

			</form>

			<div class="card-soft mt-4">
				<div class="card-soft-head d-flex flex-wrap justify-content-between align-items-start gap-2">
					<div>
						<h2>Consultas pendientes</h2>
						<p>Preguntas de clientes que esperan respuesta. También puedes responderlas desde aquí.</p>
					</div>
					<button type="button" class="btn btn-ghost btn-sm" id="inqReload"><i class="bi bi-arrow-clockwise"></i> Actualizar</button>
				</div>
				<div id="inqPending" class="inq-list"></div>
			</div>

			<div class="card-soft mt-4">
				<div class="card-soft-head d-flex flex-wrap justify-content-between align-items-start gap-2">
					<div>
						<h2>Respuestas aprendidas</h2>
						<p>La IA usa estas respuestas cuando le preguntan algo parecido. Puedes corregirlas, pausarlas o agregar nuevas.</p>
					</div>
					<button type="button" class="btn btn-brand btn-sm" id="inqAdd"><i class="bi bi-plus-lg"></i> Agregar respuesta</button>
				</div>
				<input type="search" class="form-control mb-3" id="inqSearch" placeholder="Buscar en las respuestas…">
				<div id="inqLearned" class="inq-list"></div>
			</div>

		</div>

	</div>

	<details class="card-soft mt-4 help-box">
		<summary><i class="bi bi-lightbulb"></i> ¿Cómo lo usa la IA?</summary>
		<ul class="mb-0 mt-3">
			<li>Lee esta información en cada respuesta: no necesitas repetirla en el prompt.</li>
			<li><strong>Agenda:</strong> pide nombre, servicio, día y hora; al confirmar guarda la cita con un resumen de lo que quiere el cliente y te avisa por WhatsApp.</li>
			<li><strong>Pagos:</strong> cuando el cliente pide el QR (Yape, Plin…) o va a pagar, envía la imagen del QR con los datos y el monto en soles.</li>
			<li><strong>Delivery:</strong> pide primero la dirección, la verifica en el mapa y solo si hay cobertura continúa con el pedido. Los pedidos confirmados aparecen en <em>Órdenes</em>.</li>
			<li><strong>Imágenes:</strong> usa la descripción para decidir cuándo enviarlas (ej.: "menú del día", "mapa de cómo llegar").</li>
			<li><strong>Consultas:</strong> si no sabe algo, pregunta a tu equipo por WhatsApp y recuerda la respuesta.</li>
			<li>La IA responde solo a los contactos con el robot activado en el chat.</li>
		</ul>
	</details>

</div>

<script>
	window.bizData = <?php echo json_encode($biz, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
	window.bizCsrf = <?php echo json_encode($_SESSION["biz_csrf"]) ?>;
	window.bizCatalogue = <?php echo json_encode(array_map(function($p){ return array("name" => $p["name"], "price" => cms_money($p["price"]), "category" => $p["category"]); }, BizOrders::catalogue()), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
</script>

<script src="/views/assets/js/business/business.js?v=13"></script>
<script src="/views/assets/js/business/coverage-map.js?v=3"></script>
<script src="/views/assets/js/business/inquiries.js?v=1"></script>
