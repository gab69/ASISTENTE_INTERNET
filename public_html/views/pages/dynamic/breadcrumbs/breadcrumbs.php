<!--==============================
  Encabezado de la sección
 ================================-->

<?php $widths = array("100" => "col-lg-12", "75" => "col-lg-9", "50" => "col-lg-6", "33" => "col-lg-4", "25" => "col-lg-3"); ?>

<div class="<?php echo $widths[(string)$module->width_module] ?? "col-lg-12" ?> col-12 mb-2 position-relative section-head">

	<div class="page-head mb-2">

		<div>
			<div class="crumbs"><a href="/">Inicio</a> <i class="bi bi-chevron-right"></i> <span><?php echo htmlspecialchars(ucfirst($module->title_module)) ?></span></div>
			<h1 class="page-title text-capitalize">
				<?php if (!empty($page->results[0]->icon_page)): ?><i class="<?php echo htmlspecialchars($page->results[0]->icon_page) ?>"></i><?php endif ?>
				<?php echo htmlspecialchars($module->title_module) ?>
			</h1>
		</div>

		<?php if ($_SESSION["admin"]->rol_admin == "superadmin"): ?>

			<div class="module-tools">
				<button type="button" class="btn-icon myModule" item='<?php echo htmlspecialchars(json_encode($module), ENT_QUOTES) ?>' idPage="<?php echo $page->results[0]->id_page ?>" title="Editar encabezado"><i class="bi bi-pencil"></i></button>
				<button type="button" class="btn-icon deleteModule" idModule="<?php echo base64_encode($module->id_module) ?>" title="Eliminar"><i class="bi bi-trash3"></i></button>
			</div>

		<?php endif ?>

	</div>

</div>
