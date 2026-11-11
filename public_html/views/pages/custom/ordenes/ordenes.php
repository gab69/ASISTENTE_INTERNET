<?php

require_once "controllers/business.orders.php";

if(empty($_SESSION["biz_csrf"])){

	$_SESSION["biz_csrf"] = bin2hex(random_bytes(24));
}

$delivery = BizStore::get("delivery");
$install = BizGeo::installation();
$filters = array("Pendiente" => "Activas", "Completado" => "Completadas", "Cancelado" => "Canceladas");

?>

<div class="page-wrap">

	<div class="page-head">
		<div>
			<h1 class="page-title"><i class="bi bi-clipboard-check"></i> Órdenes</h1>
			<p class="page-sub"><?php echo $install ? "Órdenes de internet y TV con su plan, sucursal y fecha de instalación. Se cierran solas cuando pasa la instalación; solo cancelas a mano si no continúa." : "Pedidos que tus clientes hacen por WhatsApp. Se cierran solos; solo cancelas a mano si no continúa." ?></p>
		</div>
		<div class="d-flex gap-2 flex-wrap align-items-center">
			<button class="btn btn-ghost" id="ordersReload"><i class="bi bi-arrow-clockwise"></i> Actualizar</button>
		</div>
	</div>

	<?php if (empty($delivery["enabled"])): ?>
		<div class="callout mb-3">
			<i class="bi bi-geo-alt"></i>
			<div>Activa la <strong>cobertura</strong> en <a href="/negocio#delivery" class="fw-semibold">Mi negocio → Cobertura</a> para que la IA verifique la dirección en el mapa antes de pedir los demás datos.</div>
		</div>
	<?php endif ?>

	<div class="callout warn mb-3 d-none" id="notifyWarn">
		<i class="bi bi-bell-slash"></i>
		<div>No hay ningún número que reciba las órdenes y citas. Escribe el <strong>WhatsApp central de órdenes</strong> en <a href="/negocio#delivery" class="fw-semibold">Mi negocio → Cobertura</a>.</div>
	</div>

	<div class="kpis" id="ordersKpis">
		<div class="kpi"><span class="kpi-ico"><i class="bi bi-router"></i></span><div><small>Órdenes de hoy</small><strong data-kpi="today_orders">–</strong></div></div>
		<div class="kpi"><span class="kpi-ico ok"><i class="bi bi-cash-coin"></i></span><div><small>Ventas de hoy</small><strong data-kpi="today_total">–</strong></div></div>
		<div class="kpi"><span class="kpi-ico warn"><i class="bi bi-hourglass-split"></i></span><div><small>Activas</small><strong data-kpi="pending">–</strong></div></div>
		<div class="kpi"><span class="kpi-ico info"><i class="bi bi-calendar2-week"></i></span><div><small><?php echo $install ? "Instalaciones próximas" : "Citas próximas" ?></small><strong data-kpi="onway">–</strong></div></div>
	</div>

	<div class="orders-toolbar">
		<div class="seg-choice" id="ordersStatus">
			<button type="button" class="active" data-status="">Todas <span></span></button>
			<?php foreach ($filters as $st => $label): ?>
				<button type="button" data-status="<?php echo htmlspecialchars($st) ?>"><?php echo htmlspecialchars($label) ?> <span></span></button>
			<?php endforeach ?>
		</div>
		<div class="d-flex gap-2 flex-wrap">
			<select class="form-select form-select-sm w-auto" id="ordersPeriod" style="min-width:170px">
				<option value="today">Hoy</option>
				<option value="7" selected>Últimos 7 días</option>
				<option value="30">Últimos 30 días</option>
				<option value="">Todo</option>
			</select>
			<input type="search" class="form-control form-control-sm" id="ordersSearch" placeholder="Buscar cliente, número, dirección o #orden" style="min-width:240px">
		</div>
	</div>

	<div class="orders-grid" id="ordersList">
		<div class="empty"><span class="spinner-border spinner-border-sm"></span> Cargando órdenes…</div>
	</div>

</div>

<!-- Reprogramar / cancelar -->

<div class="modal fade" id="orderModal" tabindex="-1" aria-hidden="true">
	<div class="modal-dialog modal-dialog-centered">
		<form class="modal-content" id="orderModalForm">
			<div class="modal-header">
				<h5 class="modal-title" id="orderModalTitle">Orden</h5>
				<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
			</div>
			<div class="modal-body">
				<div data-for="reschedule">
					<div class="row g-3">
						<div class="col-7">
							<label class="form-label">Nueva fecha</label>
							<input type="date" class="form-control" name="date" min="<?php echo date("Y-m-d") ?>">
						</div>
						<div class="col-5">
							<label class="form-label">Hora</label>
							<input type="time" class="form-control" name="time" step="900">
						</div>
					</div>
					<div class="form-text mt-2">Se actualiza la Agenda (y Google Calendar), se avisa al equipo y la orden sigue activa hasta la nueva fecha.</div>
				</div>
				<div data-for="cancel" class="d-none">
					<label class="form-label">Motivo <span class="text-muted fw-normal">(opcional)</span></label>
					<input type="text" class="form-control" name="reason" maxlength="255" placeholder="Ej.: el cliente ya no desea el servicio">
					<div class="form-text mt-2">También se cancela la cita de instalación y se avisa al equipo. No se puede deshacer.</div>
				</div>
				<label class="form-check mt-3">
					<input class="form-check-input" type="checkbox" name="notify" checked>
					<span class="form-check-label">Avisar al cliente por WhatsApp</span>
				</label>
			</div>
			<div class="modal-footer">
				<button type="button" class="btn btn-ghost" data-bs-dismiss="modal">Volver</button>
				<button type="submit" class="btn btn-brand" id="orderModalGo">Guardar</button>
			</div>
		</form>
	</div>
</div>

<script>
	window.bizCsrf = <?php echo json_encode($_SESSION["biz_csrf"]) ?>;
	window.ordersConfig = {
		currency: <?php echo json_encode(cms_currency()) ?>,
		feeLabel: <?php echo json_encode(BizOrders::feeLabel()) ?>,
		today: <?php echo json_encode(date("Y-m-d")) ?>,
		search: <?php echo json_encode((string)($_GET["q"] ?? "")) ?>
	};
</script>

<script src="/views/assets/js/business/orders.js?v=8"></script>
