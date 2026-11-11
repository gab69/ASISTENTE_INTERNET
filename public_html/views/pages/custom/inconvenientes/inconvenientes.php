<?php

require_once "controllers/business.incidents.php";

if(empty($_SESSION["biz_csrf"])){

	$_SESSION["biz_csrf"] = bin2hex(random_bytes(24));
}

?>

<div class="page-wrap">

	<div class="page-head">
		<div>
			<h1 class="page-title"><i class="bi bi-exclamation-octagon"></i> Inconvenientes</h1>
			<p class="page-sub">Todo lo que salió mal en la atención: cancelaciones, reclamos, demoras y fallas, con el comentario que dejó el cliente. La IA le pide su opinión al cliente cuando cancela o reporta un problema.</p>
		</div>
		<div class="d-flex gap-2 flex-wrap align-items-center">
			<button class="btn btn-ghost" id="incReload"><i class="bi bi-arrow-clockwise"></i> Actualizar</button>
		</div>
	</div>

	<div class="kpis" id="incKpis">
		<div class="kpi"><span class="kpi-ico warn"><i class="bi bi-exclamation-circle"></i></span><div><small>Abiertos</small><strong data-kpi="abierto">–</strong></div></div>
		<div class="kpi"><span class="kpi-ico info"><i class="bi bi-hourglass-split"></i></span><div><small>En proceso</small><strong data-kpi="en_proceso">–</strong></div></div>
		<div class="kpi"><span class="kpi-ico ok"><i class="bi bi-check2-circle"></i></span><div><small>Resueltos</small><strong data-kpi="resuelto">–</strong></div></div>
		<div class="kpi"><span class="kpi-ico"><i class="bi bi-calendar-week"></i></span><div><small>Últimos 7 días</small><strong data-kpi="week">–</strong></div></div>
	</div>

	<div class="callout mb-3 d-none" id="incTop"><i class="bi bi-bar-chart"></i><div></div></div>

	<div class="orders-toolbar">
		<div class="seg-choice" id="incStatus">
			<button type="button" class="active" data-status="">Todos</button>
			<button type="button" data-status="abierto">Abiertos</button>
			<button type="button" data-status="en_proceso">En proceso</button>
			<button type="button" data-status="resuelto">Resueltos</button>
		</div>
		<div class="d-flex gap-2 flex-wrap">
			<select class="form-select form-select-sm w-auto" id="incKind" style="min-width:170px">
				<option value="">Todos los tipos</option>
				<?php foreach (BizIncidents::KINDS as $k => $label): ?>
					<option value="<?php echo htmlspecialchars($k) ?>"><?php echo htmlspecialchars($label) ?></option>
				<?php endforeach ?>
			</select>
			<select class="form-select form-select-sm w-auto" id="incPeriod" style="min-width:150px">
				<option value="today">Hoy</option>
				<option value="7">Últimos 7 días</option>
				<option value="30" selected>Últimos 30 días</option>
				<option value="">Todo</option>
			</select>
			<input type="search" class="form-control form-control-sm" id="incSearch" placeholder="Buscar cliente, número, #orden o texto" style="min-width:230px">
		</div>
	</div>

	<div class="inq-list" id="incList">
		<div class="empty"><span class="spinner-border spinner-border-sm"></span> Cargando…</div>
	</div>

</div>

<div class="modal fade" id="incModal" tabindex="-1" aria-hidden="true">
	<div class="modal-dialog modal-dialog-centered">
		<form class="modal-content" id="incForm">
			<div class="modal-header">
				<h5 class="modal-title" id="incModalTitle">Inconveniente</h5>
				<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
			</div>
			<div class="modal-body">
				<input type="hidden" name="id">
				<label class="form-label">Estado</label>
				<select class="form-select mb-3" name="status">
					<option value="abierto">Abierto</option>
					<option value="en_proceso">En proceso</option>
					<option value="resuelto">Resuelto</option>
				</select>
				<label class="form-label">Nota interna <span class="text-muted fw-normal">(qué se hizo)</span></label>
				<textarea class="form-control" name="note" rows="4" maxlength="2000" placeholder="Ej.: Se llamó al cliente y se reprogramó la instalación."></textarea>
			</div>
			<div class="modal-footer">
				<button type="button" class="btn btn-ghost" data-bs-dismiss="modal">Volver</button>
				<button type="submit" class="btn btn-brand">Guardar</button>
			</div>
		</form>
	</div>
</div>

<script>
	window.bizCsrf = <?php echo json_encode($_SESSION["biz_csrf"]) ?>;
	window.incConfig = {
		today: <?php echo json_encode(date("Y-m-d")) ?>,
		kinds: <?php echo json_encode(BizIncidents::KINDS, JSON_UNESCAPED_UNICODE) ?>,
		canDelete: <?php echo json_encode(in_array($_SESSION["admin"]->rol_admin ?? "", array("superadmin", "admin"), true)) ?>
	};
</script>

<script src="/views/assets/js/business/incidents.js?v=1"></script>
