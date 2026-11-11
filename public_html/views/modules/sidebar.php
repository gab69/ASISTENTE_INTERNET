<?php

$pages = CurlController::request("pages?orderBy=order_page&orderMode=ASC", "GET", array());
$pages = ($pages->status == 200) ? $pages->results : array();

$rol = $_SESSION["admin"]->rol_admin;
$perms = json_decode(urldecode((string)$_SESSION["admin"]->permissions_admin), true) ?: array();
$current = $routesArray[0] ?? "";

/* Página de inicio (orden 1) para marcar "activo" en "/" */

if($current === "" && !empty($pages)){

	$current = $pages[0]->url_page;
}

?>

<aside class="side" id="sidebar-wrapper">

	<div class="side-brand sidebar-heading">
		<span class="side-logo"><?php echo $admin->symbol_admin ?: '<i class="bi bi-chat-dots-fill"></i>' ?></span>
		<span class="menu-text side-title"><?php echo htmlspecialchars((string)$admin->title_admin) ?></span>
	</div>

	<div class="side-label menu-text">Menú</div>

	<ul class="side-nav list-group list-group-flush" id="sortable">

		<?php foreach ($pages as $value): ?>

			<?php if (isset($value->visible_page) && (int)$value->visible_page === 0) continue; /* Oculta desde Configuración → Menú */ ?>

			<?php if ($rol == "superadmin" || $rol == "admin" || ($rol == "editor" && isset($perms[$value->url_page]) && strtolower($perms[$value->url_page]) == "on")): ?>

			<li class="list-group-item side-item <?php echo $value->url_page === $current ? "active" : "" ?>" idPage="<?php echo base64_encode($value->id_page) ?>">

				<?php if ($value->type_page == "external_link" || $value->type_page == "internal_link"): ?>
					<a href="<?php echo htmlspecialchars(urldecode($value->url_page)) ?>" <?php if ($value->type_page == "external_link"): ?>target="_blank" rel="noopener"<?php endif ?> title="<?php echo htmlspecialchars($value->title_page) ?>">
				<?php else: ?>
					<a href="/<?php echo htmlspecialchars($value->url_page) ?>" title="<?php echo htmlspecialchars($value->title_page) ?>">
				<?php endif ?>

					<i class="<?php echo htmlspecialchars($value->icon_page) ?>"></i>
					<span class="menu-text"><?php echo htmlspecialchars($value->title_page) ?></span>

				</a>



			</li>

			<?php endif ?>

		<?php endforeach ?>

	</ul>

	<?php if ($rol == "superadmin"): ?>

		<div class="side-footer menu-text">
			<a href="/setup/#menu" class="side-btn"><i class="bi bi-gear"></i> Configuración</a>
			<!-- "Agregar página" se abre desde Configuración → Menú -->
			<button type="button" class="myPage d-none" id="addPageHidden" aria-hidden="true" tabindex="-1"></button>
		</div>

		<script>
			if(/[?&]nueva-pagina=1/.test(location.search)){
				window.addEventListener("load", function(){
					var b = document.getElementById("addPageHidden");
					if(b){ b.click(); }
					history.replaceState(null, "", location.pathname);
				});
			}
		</script>

	<?php endif ?>

</aside>

<div class="side-backdrop" onclick="document.getElementById('sidebar-wrapper').classList.remove('show')"></div>
