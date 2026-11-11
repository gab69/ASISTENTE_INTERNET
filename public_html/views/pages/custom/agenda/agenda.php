<?php

require_once "controllers/business.calendar.php";

if(empty($_SESSION["biz_csrf"])){

	$_SESSION["biz_csrf"] = bin2hex(random_bytes(24));
}

$agenda = BizStore::get("agenda");
$gcalOn = BizCalendar::enabled();
$openDate = BizStore::validDate((string)($_GET["date"] ?? "")) ? $_GET["date"] : date("Y-m-d");

?>

<div class="page-wrap">

	<div class="page-head">
		<div>
			<h1 class="page-title"><i class="bi bi-calendar2-week"></i> Agenda</h1>
			<p class="page-sub">Citas y reservas agendadas por la IA, los bots y tu equipo. Las <strong>pendientes</strong> son solicitudes que debes confirmar.</p>
		</div>
		<div class="d-flex gap-2 flex-wrap">
			<button class="btn btn-ghost" id="btnGcal"><i class="bi bi-google"></i> Google Calendar <?php echo $gcalOn ? '<span class="badge bg-success ms-1">Conectado</span>' : '' ?></button>
			<a href="/negocio#agenda" class="btn btn-ghost"><i class="bi bi-sliders"></i> Horarios</a>
			<button class="btn btn-brand" id="btnNewAppt"><i class="bi bi-plus-lg"></i> Nueva cita</button>
		</div>
	</div>

	<?php if (empty($agenda["notify_phone"])): ?>
		<div class="callout warn mb-3">
			<i class="bi bi-bell"></i>
			<div>Aún no configuraste el número que recibe las citas por WhatsApp. <a href="/negocio#agenda" class="fw-semibold">Configúralo en Mi negocio → Agenda</a>.</div>
		</div>
	<?php endif ?>

	<?php if (empty($agenda["enabled"])): ?>
		<div class="callout warn mb-3">
			<i class="bi bi-calendar-x"></i>
			<div>La agenda está <strong>desactivada</strong>: la IA no puede registrar reservas. <a href="/negocio#agenda" class="fw-semibold">Actívala en Mi negocio → Agenda</a>.</div>
		</div>
	<?php endif ?>

	<div class="row g-4">

		<div class="col-xl-8">

			<div class="card-soft p-0 overflow-hidden">

				<div class="cal-head">
					<button class="btn-icon" id="calPrev" title="Mes anterior"><i class="bi bi-chevron-left"></i></button>
					<h2 id="calTitle" class="m-0"></h2>
					<button class="btn-icon" id="calNext" title="Mes siguiente"><i class="bi bi-chevron-right"></i></button>
					<button class="btn btn-ghost btn-sm ms-auto" id="calToday">Hoy</button>
				</div>

				<div class="cal-grid cal-week">
					<div>Lun</div><div>Mar</div><div>Mié</div><div>Jue</div><div>Vie</div><div>Sáb</div><div>Dom</div>
				</div>

				<div class="cal-grid" id="calDays"></div>

			</div>

		</div>

		<div class="col-xl-4">

			<div class="card-soft day-panel">

				<div class="d-flex justify-content-between align-items-center mb-3">
					<div>
						<div class="text-muted small" id="dayWeek"></div>
						<h3 class="m-0" id="dayTitle"></h3>
					</div>
					<span class="pill" id="dayCount"></span>
				</div>

				<div id="dayList" class="appt-list"></div>

			</div>

			<div class="card-soft mt-4">
				<h3 class="h6 mb-3">Próximas citas</h3>
				<div id="upcomingList" class="appt-list compact"></div>
			</div>

		</div>

	</div>

</div>

<!--=============================================
Modal: nueva / editar cita
==============================================-->

<div class="modal fade" id="apptModal" tabindex="-1">
	<div class="modal-dialog modal-dialog-centered">
		<form class="modal-content" id="apptForm">

			<div class="modal-header border-0 pb-0">
				<h5 class="modal-title" id="apptModalTitle">Nueva cita</h5>
				<button type="button" class="btn-close" data-bs-dismiss="modal"></button>
			</div>

			<div class="modal-body">

				<input type="hidden" name="id">

				<div class="row g-3">
					<div class="col-6">
						<label class="form-label">Nombre</label>
						<input class="form-control" name="name" required>
					</div>
					<div class="col-6">
						<label class="form-label">WhatsApp</label>
						<input class="form-control" name="phone" placeholder="51987654321" inputmode="tel">
					</div>
					<div class="col-8">
						<label class="form-label">Servicio</label>
						<input class="form-control" name="service" placeholder="Ej.: Reserva de mesa">
					</div>
					<div class="col-4">
						<label class="form-label">Personas</label>
						<input class="form-control" type="number" min="1" max="500" name="people" placeholder="—">
					</div>
					<div class="col-12">
						<label class="form-label">Fecha</label>
						<input class="form-control" type="date" name="date" required>
					</div>
					<div class="col-12">
						<label class="form-label d-flex justify-content-between">Hora <span class="text-muted small fw-normal" id="slotHint"></span></label>
						<div id="slotPicker" class="slot-picker"></div>
						<input type="hidden" name="time" required>
					</div>
					<div class="col-12">
						<label class="form-label">Resumen / qué necesita</label>
						<textarea class="form-control" name="summary" rows="2"></textarea>
					</div>
					<div class="col-12">
						<div class="form-check">
							<input class="form-check-input" type="checkbox" name="notify" id="apptNotify" checked>
							<label class="form-check-label ms-1" for="apptNotify">Avisar por WhatsApp al número del negocio</label>
						</div>
					</div>
				</div>

			</div>

			<div class="modal-footer border-0">
				<button type="button" class="btn btn-ghost" data-bs-dismiss="modal">Cancelar</button>
				<button type="submit" class="btn btn-brand">Guardar cita</button>
			</div>

		</form>
	</div>
</div>

<!--=============================================
Modal: Google Calendar
==============================================-->

<div class="modal fade" id="gcalModal" tabindex="-1">
	<div class="modal-dialog modal-dialog-centered modal-lg modal-dialog-scrollable">
		<div class="modal-content">

			<div class="modal-header">
				<h5 class="modal-title"><i class="bi bi-google me-1"></i> Ver la agenda en Google Calendar</h5>
				<button type="button" class="btn-close" data-bs-dismiss="modal"></button>
			</div>

			<div class="modal-body">

				<ul class="nav seg-tabs mb-3" role="tablist">
					<li><button class="seg-tab active" data-bs-toggle="tab" data-bs-target="#gcal-auto" type="button"><i class="bi bi-lightning-charge"></i> <span>Automática (recomendada)</span></button></li>
					<li><button class="seg-tab" data-bs-toggle="tab" data-bs-target="#gcal-link" type="button"><i class="bi bi-link-45deg"></i> <span>Por enlace</span></button></li>
				</ul>

				<div class="tab-content">

					<div class="tab-pane fade show active" id="gcal-auto">

						<div class="gcal-status off" id="gcalStatus"><i class="bi bi-plug"></i><span>Cargando…</span></div>

						<p class="text-muted small">Cada cita nueva, editada o cancelada aparece <strong>al instante</strong> en tu Google Calendar y en tu celular, con recordatorio 30 minutos antes. Se configura una sola vez:</p>

						<ol class="gcal-steps">
							<li><span>Entra a <a href="https://console.cloud.google.com/projectcreate" target="_blank" rel="noopener">Google Cloud</a>, crea un proyecto y activa la <a href="https://console.cloud.google.com/apis/library/calendar-json.googleapis.com" target="_blank" rel="noopener">Google Calendar API</a>.</span></li>
							<li><span>Ve a <a href="https://console.cloud.google.com/iam-admin/serviceaccounts" target="_blank" rel="noopener">Cuentas de servicio</a> → <em>Crear cuenta de servicio</em> → abre la cuenta → <em>Claves</em> → <em>Agregar clave</em> → <strong>JSON</strong>. Se descarga un archivo.</span></li>
							<li><span>En <a href="https://calendar.google.com/calendar/r/settings" target="_blank" rel="noopener">Google Calendar</a> abre la configuración de tu calendario → <em>Compartir con personas específicas</em> → agrega el correo de la cuenta de servicio (termina en <code>iam.gserviceaccount.com</code>) con permiso <strong>Hacer cambios en eventos</strong>.</span></li>
							<li><span>En esa misma página copia el <strong>ID del calendario</strong> (si es tu calendario principal es tu correo de Gmail) y pégalo abajo junto con el archivo JSON.</span></li>
						</ol>

						<form id="gcalForm" autocomplete="off">
							<div class="row g-3">
								<div class="col-12">
									<label class="form-label">ID del calendario</label>
									<input class="form-control" name="calendar_id" placeholder="tucorreo@gmail.com">
								</div>
								<div class="col-12">
									<label class="form-label d-flex justify-content-between">Archivo JSON de la cuenta de servicio <span class="text-muted fw-normal small" id="gcalCredHint"></span></label>
									<input class="form-control mb-2" type="file" accept=".json,application/json" id="gcalFile">
									<textarea class="form-control font-monospace small" name="credentials" rows="3" placeholder='O pega aquí el contenido: {"type": "service_account", ...}'></textarea>
								</div>
							</div>
							<div class="d-flex flex-wrap gap-2 mt-3">
								<button type="submit" class="btn btn-brand"><i class="bi bi-plug"></i> Conectar y probar</button>
								<button type="button" class="btn btn-ghost" id="gcalSync"><i class="bi bi-arrow-repeat"></i> Sincronizar ahora</button>
								<button type="button" class="btn btn-ghost text-danger ms-auto" id="gcalOff"><i class="bi bi-x-circle"></i> Desconectar</button>
							</div>
						</form>

					</div>

					<div class="tab-pane fade" id="gcal-link">

						<p class="text-muted small">Sin configurar nada en Google Cloud: suscríbete a tu agenda desde Google Calendar, el calendario del iPhone u Outlook. <strong>Google la actualiza sola cada pocas horas</strong> (no es al instante).</p>

						<label class="form-label">Enlace privado de tu agenda</label>
						<div class="input-group mb-2">
							<input class="form-control font-monospace small" id="icsUrl" readonly>
							<button class="btn btn-ghost" type="button" id="icsCopy" title="Copiar"><i class="bi bi-clipboard"></i></button>
						</div>
						<div class="form-text mb-3">No lo compartas: quien tenga el enlace puede ver tus citas. <button type="button" class="btn-link-inline" id="icsReset">Generar un enlace nuevo</button></div>

						<ol class="gcal-steps">
							<li><span>Pulsa <a href="#" target="_blank" rel="noopener" id="icsGoogle" class="fw-semibold">Agregar a Google Calendar</a> (desde una computadora) y confirma.</span></li>
							<li><span>O en Google Calendar web: <em>Otros calendarios</em> → <strong>+</strong> → <em>Desde URL</em> → pega el enlace.</span></li>
							<li><span>En el celular abre la app de Google Calendar → Configuración → activa el calendario nuevo para verlo.</span></li>
						</ol>

					</div>

				</div>

			</div>

		</div>
	</div>
</div>

<script>
	window.bizCsrf = <?php echo json_encode($_SESSION["biz_csrf"]) ?>;
	window.agendaToday = <?php echo json_encode(date("Y-m-d")) ?>;
	window.agendaOpen = <?php echo json_encode($openDate) ?>;
</script>

<script src="/views/assets/js/business/agenda.js?v=3"></script>
