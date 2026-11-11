<?php

if(empty($_SESSION["biz_csrf"])){

	$_SESSION["biz_csrf"] = bin2hex(random_bytes(24));
}

$webhookUrl = cms_base_url()."webhook/";
$verifyToken = (string)cms_config("webhook_verify_token", "");
$isSuper = ($_SESSION["admin"]->rol_admin ?? "") === "superadmin";

?>

<div class="page-wrap">

	<div class="page-head">
		<div>
			<h1 class="page-title"><i class="bi bi-whatsapp"></i> WhatsApp</h1>
			<p class="page-sub">Conecta tu número de WhatsApp Business. Al guardar, el sistema verifica los datos con Meta al instante.</p>
		</div>
		<button class="btn btn-brand" id="waAdd"><i class="bi bi-plus-lg"></i> Conectar número</button>
	</div>

	<div id="waList" class="wa-accounts">
		<div class="empty"><span class="spinner-border spinner-border-sm"></span> Cargando…</div>
	</div>

	<div class="row g-4 mt-1">

		<div class="col-lg-7">
			<div class="card-soft h-100">
				<div class="card-soft-head">
					<h2><i class="bi bi-1-circle text-muted"></i> ¿Dónde encuentro los datos?</h2>
					<p>En <a href="https://developers.facebook.com/apps" target="_blank" rel="noopener">Meta for Developers</a> → tu app → <strong>WhatsApp → Configuración de la API</strong>.</p>
				</div>
				<ol class="gcal-steps mb-0">
					<li><span><strong>ID del número de teléfono</strong>: aparece debajo del número (solo dígitos, ej. <code>1234567890123456</code>). No es el número de celular.</span></li>
					<li><span><strong>ID de la cuenta de WhatsApp Business</strong>: está en la misma pantalla (opcional, solo para tu referencia).</span></li>
					<li><span><strong>Token</strong>: el temporal vence en 24 h. Para producción crea un <strong>token permanente</strong> en Meta Business → Configuración → Usuarios del sistema → Generar token, con los permisos <code>whatsapp_business_messaging</code> y <code>whatsapp_business_management</code>.</span></li>
				</ol>
			</div>
		</div>

		<div class="col-lg-5">
			<div class="card-soft h-100">
				<div class="card-soft-head">
					<h2><i class="bi bi-2-circle text-muted"></i> Webhook (para recibir mensajes)</h2>
					<p>En Meta → WhatsApp → Configuración → Webhook, pega estos datos y suscríbete al campo <strong>messages</strong>.</p>
				</div>
				<label class="form-label">URL de devolución de llamada</label>
				<div class="input-group mb-3">
					<input class="form-control font-monospace small" value="<?php echo htmlspecialchars($webhookUrl) ?>" readonly>
					<button class="btn btn-ghost" type="button" data-copy="<?php echo htmlspecialchars($webhookUrl) ?>"><i class="bi bi-clipboard"></i></button>
				</div>
				<label class="form-label">Token de verificación</label>
				<div class="input-group">
					<input class="form-control font-monospace small" value="<?php echo htmlspecialchars($verifyToken) ?>" readonly>
					<button class="btn btn-ghost" type="button" data-copy="<?php echo htmlspecialchars($verifyToken) ?>"><i class="bi bi-clipboard"></i></button>
				</div>
				<?php if ($isSuper): ?>
					<div class="form-text">Se cambia en <a href="/setup/#whatsapp">Configuración → WhatsApp</a>.</div>
				<?php endif ?>
			</div>
		</div>

	</div>

</div>

<!--=============================================
Modal: conectar / editar número
==============================================-->

<div class="modal fade" id="waModal" tabindex="-1">
	<div class="modal-dialog modal-dialog-centered">
		<form class="modal-content" id="waForm" autocomplete="off">

			<div class="modal-header">
				<h5 class="modal-title" id="waTitle">Conectar número</h5>
				<button type="button" class="btn-close" data-bs-dismiss="modal"></button>
			</div>

			<div class="modal-body">

				<input type="hidden" name="id">

				<div class="mb-3">
					<label class="form-label">ID del número de teléfono</label>
					<input class="form-control font-monospace" name="id_number" inputmode="numeric" placeholder="1234567890123456" required>
				</div>

				<div class="mb-3">
					<label class="form-label">Token de acceso</label>
					<textarea class="form-control font-monospace small" name="token" rows="3" placeholder="EAA…"></textarea>
					<div class="form-text" id="waTokenHint"></div>
				</div>

				<div class="mb-3">
					<label class="form-label">ID de la cuenta de WhatsApp Business <span class="text-muted fw-normal">(opcional)</span></label>
					<input class="form-control font-monospace" name="id_app" inputmode="numeric">
				</div>

				<div class="d-grid gap-2">
					<div class="form-check form-switch">
						<input class="form-check-input" type="checkbox" name="status" id="waStatus" checked>
						<label class="form-check-label" for="waStatus">Activo (este número atiende a los clientes)</label>
					</div>
					<div class="form-check form-switch">
						<input class="form-check-input" type="checkbox" name="ai" id="waAi">
						<label class="form-check-label" for="waAi">Asistente IA activado para los contactos nuevos</label>
					</div>
				</div>

				<div class="geo-result" id="waResult"></div>

			</div>

			<div class="modal-footer">
				<button type="button" class="btn btn-ghost me-auto" id="waForce" hidden>Guardar sin verificar</button>
				<button type="button" class="btn btn-ghost" data-bs-dismiss="modal">Cancelar</button>
				<button type="submit" class="btn btn-brand"><i class="bi bi-shield-check"></i> Verificar y guardar</button>
			</div>

		</form>
	</div>
</div>

<script>
	window.bizCsrf = <?php echo json_encode($_SESSION["biz_csrf"]) ?>;
</script>

<script src="/views/assets/js/business/whatsapp.js?v=1"></script>
