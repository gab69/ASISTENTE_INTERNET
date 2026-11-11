<?php

require_once "controllers/auth.controller.php";

/*=============================================
Vista activa: login | forgot | change | reset
=============================================*/

$view = "login";
$notice = null;
$resetToken = isset($_GET["reset"]) ? (string)$_GET["reset"] : "";
$loginToken = null;

if($resetToken !== ""){

	$view = AuthController::findReset($resetToken) ? "reset" : "login";

	if($view === "login"){

		$notice = array("type" => "error", "text" => "El enlace para restablecer la contraseña no es válido o ya venció. Solicita uno nuevo.");
	}
}

$action = $_POST["auth_action"] ?? "";

if($action === "login"){

	$notice = AuthController::login();

	if($notice["type"] === "login"){

		$loginToken = $notice["token"];
		$notice = null;
	}

}else if($action === "forgot"){

	$notice = AuthController::requestReset();
	$view = $notice["type"] === "success" ? "login" : "forgot";

}else if($action === "change"){

	$notice = AuthController::changePassword();
	$view = $notice["type"] === "success" ? "login" : "change";

}else if($action === "reset"){

	$notice = AuthController::applyReset($resetToken);
	$view = $notice["type"] === "success" ? "login" : (AuthController::findReset($resetToken) ? "reset" : "forgot");
}

$e = function($v){ return htmlspecialchars((string)$v, ENT_QUOTES, "UTF-8"); };

?>

<?php if ($loginToken): ?>
<script>
	try{ localStorage.setItem("tokenAdmin", <?php echo json_encode($loginToken) ?>); }catch(e){}
	location.replace("/");
</script>
<?php endif ?>

<div class="auth-shell">

	<!-- Panel de marca -->

	<aside class="auth-brand" <?php if (!empty($admin->back_admin)): ?>style="--auth-bg:url('<?php echo $e($admin->back_admin) ?>')"<?php endif ?>>
		<div class="auth-brand-inner">
			<div class="auth-logo"><?php echo $admin->symbol_admin ?: '<i class="bi bi-chat-dots-fill"></i>' ?></div>
			<h1><?php echo $e($admin->title_admin ?: "ChatCenter") ?></h1>
			<p>Atiende, agenda y cobra por WhatsApp con ayuda de inteligencia artificial.</p>
			<ul>
				<li><i class="bi bi-chat-heart"></i> Conversaciones en un solo lugar</li>
				<li><i class="bi bi-calendar2-check"></i> Agenda sin turnos duplicados</li>
				<li><i class="bi bi-qr-code"></i> Pagos con QR y monto exacto</li>
			</ul>
		</div>
	</aside>

	<!-- Formularios -->

	<main class="auth-main">

		<div class="auth-card">

			<div class="auth-mobile-logo"><?php echo $admin->symbol_admin ?: '<i class="bi bi-chat-dots-fill"></i>' ?> <?php echo $e($admin->title_admin ?: "ChatCenter") ?></div>

			<?php if ($notice): ?>
				<div class="auth-alert <?php echo $e($notice["type"]) ?>">
					<i class="bi <?php echo $notice["type"] === "success" ? "bi-check-circle" : "bi-exclamation-circle" ?>"></i>
					<span><?php echo $e($notice["text"]) ?></span>
				</div>
			<?php endif ?>

			<!-- Iniciar sesión -->

			<form method="post" action="/" class="auth-view <?php echo $view === "login" ? "active" : "" ?>" data-view="login" autocomplete="on">

				<input type="hidden" name="auth_action" value="login">

				<h2>Bienvenido 👋</h2>
				<p class="auth-sub">Ingresa con tu cuenta para continuar.</p>

				<label class="auth-label" for="email_admin_login">Correo</label>
				<div class="auth-field">
					<i class="bi bi-envelope"></i>
					<input type="email" id="email_admin_login" name="email_admin" placeholder="tu@correo.com" autocomplete="username" required value="<?php echo $e($_POST["email_admin"] ?? "") ?>">
				</div>

				<div class="d-flex justify-content-between align-items-center">
					<label class="auth-label" for="password_admin">Contraseña</label>
					<a href="#" class="auth-link small" data-go="forgot">¿Olvidaste tu contraseña?</a>
				</div>
				<div class="auth-field">
					<i class="bi bi-lock"></i>
					<input type="password" id="password_admin" name="password_admin" placeholder="••••••••" autocomplete="current-password" required>
					<button type="button" class="auth-eye" aria-label="Mostrar contraseña"><i class="bi bi-eye"></i></button>
				</div>

				<label class="auth-check">
					<input type="checkbox" id="remember" onchange="rememberEmail(event)"> Recordar mi correo
				</label>

				<button type="submit" class="auth-btn">Ingresar <i class="bi bi-arrow-right"></i></button>

				<div class="auth-alt">
					<a href="#" class="auth-link" data-go="change"><i class="bi bi-key"></i> Cambiar mi contraseña</a>
				</div>

			</form>

			<!-- Recuperar contraseña -->

			<form method="post" action="/" class="auth-view <?php echo $view === "forgot" ? "active" : "" ?>" data-view="forgot">

				<input type="hidden" name="auth_action" value="forgot">

				<a href="#" class="auth-back" data-go="login"><i class="bi bi-arrow-left"></i> Volver</a>

				<h2>Recuperar contraseña</h2>
				<p class="auth-sub">Te enviaremos un enlace seguro a tu correo para crear una nueva contraseña.</p>

				<label class="auth-label" for="reset_email">Correo registrado</label>
				<div class="auth-field">
					<i class="bi bi-envelope"></i>
					<input type="email" id="reset_email" name="reset_email" placeholder="tu@correo.com" required>
				</div>

				<button type="submit" class="auth-btn">Enviar enlace <i class="bi bi-send"></i></button>

			</form>

			<!-- Cambiar contraseña -->

			<form method="post" action="/" class="auth-view <?php echo $view === "change" ? "active" : "" ?>" data-view="change">

				<input type="hidden" name="auth_action" value="change">

				<a href="#" class="auth-back" data-go="login"><i class="bi bi-arrow-left"></i> Volver</a>

				<h2>Cambiar contraseña</h2>
				<p class="auth-sub">Usa tu contraseña actual para elegir una nueva.</p>

				<label class="auth-label" for="change_email">Correo</label>
				<div class="auth-field">
					<i class="bi bi-envelope"></i>
					<input type="email" id="change_email" name="change_email" placeholder="tu@correo.com" autocomplete="username" required value="<?php echo $e($_POST["change_email"] ?? "") ?>">
				</div>

				<label class="auth-label" for="current_password">Contraseña actual</label>
				<div class="auth-field">
					<i class="bi bi-lock"></i>
					<input type="password" id="current_password" name="current_password" autocomplete="current-password" required>
					<button type="button" class="auth-eye" aria-label="Mostrar contraseña"><i class="bi bi-eye"></i></button>
				</div>

				<label class="auth-label" for="new_password_c">Nueva contraseña</label>
				<div class="auth-field">
					<i class="bi bi-shield-lock"></i>
					<input type="password" id="new_password_c" name="new_password" minlength="8" autocomplete="new-password" placeholder="Mínimo 8 caracteres" required>
					<button type="button" class="auth-eye" aria-label="Mostrar contraseña"><i class="bi bi-eye"></i></button>
				</div>
				<div class="auth-meter"><span></span></div>

				<label class="auth-label" for="confirm_password_c">Repite la nueva contraseña</label>
				<div class="auth-field">
					<i class="bi bi-shield-check"></i>
					<input type="password" id="confirm_password_c" name="confirm_password" minlength="8" autocomplete="new-password" required>
				</div>

				<button type="submit" class="auth-btn">Guardar nueva contraseña</button>

			</form>

			<!-- Nueva contraseña desde el enlace del correo -->

			<?php if ($view === "reset"): ?>

			<form method="post" class="auth-view active" data-view="reset" action="/?reset=<?php echo $e($resetToken) ?>">

				<input type="hidden" name="auth_action" value="reset">

				<h2>Crea tu nueva contraseña</h2>
				<p class="auth-sub">Elige una contraseña segura que no uses en otros sitios.</p>

				<label class="auth-label" for="new_password_r">Nueva contraseña</label>
				<div class="auth-field">
					<i class="bi bi-shield-lock"></i>
					<input type="password" id="new_password_r" name="new_password" minlength="8" autocomplete="new-password" placeholder="Mínimo 8 caracteres" required autofocus>
					<button type="button" class="auth-eye" aria-label="Mostrar contraseña"><i class="bi bi-eye"></i></button>
				</div>
				<div class="auth-meter"><span></span></div>

				<label class="auth-label" for="confirm_password_r">Repite la contraseña</label>
				<div class="auth-field">
					<i class="bi bi-shield-check"></i>
					<input type="password" id="confirm_password_r" name="confirm_password" minlength="8" autocomplete="new-password" required>
				</div>

				<button type="submit" class="auth-btn">Guardar contraseña</button>

			</form>

			<?php endif ?>

		</div>

		<p class="auth-foot">© <?php echo date("Y") ?> <?php echo $e($admin->title_admin ?: "ChatCenter") ?></p>

	</main>

</div>

<script>
(function(){

	/* Cambiar de vista sin recargar */

	document.querySelectorAll("[data-go]").forEach(function(a){
		a.addEventListener("click", function(ev){
			ev.preventDefault();
			var target = a.getAttribute("data-go");
			document.querySelectorAll(".auth-view").forEach(function(v){ v.classList.toggle("active", v.getAttribute("data-view") === target); });
			var alert = document.querySelector(".auth-alert"); if(alert){ alert.remove(); }
			var first = document.querySelector('.auth-view.active input:not([type=hidden])'); if(first){ first.focus(); }
			var email = document.getElementById("email_admin_login").value;
			if(email){ ["reset_email", "change_email"].forEach(function(id){ var f = document.getElementById(id); if(f && !f.value){ f.value = email; } }); }
		});
	});

	/* Mostrar / ocultar contraseña */

	document.querySelectorAll(".auth-eye").forEach(function(b){
		b.addEventListener("click", function(){
			var input = b.parentNode.querySelector("input");
			var show = input.type === "password";
			input.type = show ? "text" : "password";
			b.innerHTML = '<i class="bi ' + (show ? "bi-eye-slash" : "bi-eye") + '"></i>';
		});
	});

	/* Medidor de seguridad */

	document.querySelectorAll('input[name="new_password"]').forEach(function(input){
		var bar = input.closest("form").querySelector(".auth-meter span");
		input.addEventListener("input", function(){
			var v = input.value, s = 0;
			if(v.length >= 8){ s++; } if(v.length >= 12){ s++; }
			if(/[A-Z]/.test(v) && /[a-z]/.test(v)){ s++; } if(/\d/.test(v)){ s++; } if(/[^A-Za-z0-9]/.test(v)){ s++; }
			bar.style.width = (s * 20) + "%";
			bar.style.background = s <= 2 ? "#ef4444" : (s === 3 ? "#f59e0b" : "#10b981");
		});
	});

	/* Las contraseñas nuevas deben coincidir */

	document.querySelectorAll('form[data-view="change"], form[data-view="reset"]').forEach(function(f){
		f.addEventListener("submit", function(ev){
			if(f.new_password.value !== f.confirm_password.value){
				ev.preventDefault();
				fncToastr("error", "Las contraseñas no coinciden");
			}
		});
	});

	/* Evitar doble envío */

	document.querySelectorAll(".auth-view").forEach(function(f){
		f.addEventListener("submit", function(ev){
			if(ev.defaultPrevented){ return; }
			var b = f.querySelector(".auth-btn");
			b.disabled = true;
			b.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Procesando…';
		});
	});

})();
</script>
