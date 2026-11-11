<?php

$navEmail = (string)($_SESSION["admin"]->email_admin ?? "");
$navInitial = strtoupper(mb_substr($navEmail !== "" ? $navEmail : "U", 0, 1));
$navRoles = array("superadmin" => "Superadministrador", "admin" => "Administrador", "editor" => "Editor");

?>

<nav class="topbar">

	<button class="btn-icon" id="menu-toggle" aria-label="Menú"><i class="bi bi-list"></i></button>

	<div class="ms-auto dropdown">

		<button class="topbar-user" data-bs-toggle="dropdown" aria-expanded="false">
			<span class="avatar"><?php echo htmlspecialchars($navInitial) ?></span>
			<span class="d-none d-sm-flex flex-column text-start lh-sm">
				<span class="fw-semibold small"><?php echo htmlspecialchars($navEmail) ?></span>
				<span class="text-muted" style="font-size:12px"><?php echo $navRoles[$_SESSION["admin"]->rol_admin] ?? htmlspecialchars($_SESSION["admin"]->rol_admin) ?></span>
			</span>
			<i class="bi bi-chevron-down small text-muted"></i>
		</button>

		<ul class="dropdown-menu dropdown-menu-end shadow-sm">
			<li><a class="dropdown-item" href="#myProfile" data-bs-toggle="modal"><i class="bi bi-person me-2"></i> Mi perfil y contraseña</a></li>
			<li><hr class="dropdown-divider"></li>
			<li><a class="dropdown-item text-danger" href="/logout"><i class="bi bi-box-arrow-right me-2"></i> Cerrar sesión</a></li>
		</ul>

	</div>

</nav>
