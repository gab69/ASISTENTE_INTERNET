<?php

require_once dirname(__DIR__)."/bootstrap.php";
require_once __DIR__."/curl.controller.php";
require_once __DIR__."/schema.php";

/*=============================================
Acceso al panel: iniciar sesión, recuperar la
contraseña por enlace temporal y cambiarla.
Cada método devuelve ["type" => success|error|info, "text" => ...]
=============================================*/

class AuthController{

	const RESET_MINUTES = 60;
	const MIN_LENGTH = 8;

	static private function pdo(){

		static $pdo = null;

		if($pdo === null){

			$pdo = cms_schema_pdo();
			$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
		}

		return $pdo;

	}

	static private function hash($password){

		return crypt((string)$password, (string)cms_config("password_salt"));

	}

	/* Máximo de intentos por sesión para frenar ataques de fuerza bruta */

	static private function throttle($bucket, $max = 8, $window = 900){

		$now = time();
		$hits = array_filter((array)($_SESSION["auth_hits"][$bucket] ?? array()), function($t) use ($now, $window){ return $t > $now - $window; });

		if(count($hits) >= $max){

			return false;
		}

		$hits[] = $now;
		$_SESSION["auth_hits"][$bucket] = array_values($hits);

		return true;

	}

	static private function msg($type, $text){

		return array("type" => $type, "text" => $text);

	}

	/*=============================================
	Iniciar sesión
	=============================================*/

	static public function login(){

		$email = trim((string)($_POST["email_admin"] ?? ""));
		$password = (string)($_POST["password_admin"] ?? "");

		if($email === "" || $password === ""){

			return self::msg("error", "Escribe tu correo y tu contraseña.");
		}

		if(!self::throttle("login")){

			return self::msg("error", "Demasiados intentos. Espera 15 minutos e inténtalo de nuevo.");
		}

		$login = CurlController::request("admins?login=true&suffix=admin", "POST", array(
			"email_admin" => $email,
			"password_admin" => $password
		));

		if(!isset($login->status) || $login->status != 200){

			return self::msg("error", "Correo o contraseña incorrectos.");
		}

		if((int)$login->results[0]->status_admin === 0){

			return self::msg("error", "Tu usuario está desactivado. Contacta al administrador.");
		}

		session_regenerate_id(true);
		unset($_SESSION["auth_hits"]["login"]);

		$_SESSION["admin"] = $login->results[0];

		return array("type" => "login", "text" => "", "token" => $login->results[0]->token_admin);

	}

	/*=============================================
	Solicitar enlace de recuperación por correo
	=============================================*/

	static public function requestReset(){

		$email = trim((string)($_POST["reset_email"] ?? ""));
		$generic = self::msg("success", "Si el correo está registrado, te enviamos un enlace para crear una nueva contraseña. Revisa también la carpeta de spam. El enlace vence en ".self::RESET_MINUTES." minutos.");

		if(!filter_var($email, FILTER_VALIDATE_EMAIL)){

			return self::msg("error", "Escribe un correo válido.");
		}

		if(!self::throttle("reset", 4)){

			return self::msg("error", "Ya solicitaste varios enlaces. Espera 15 minutos.");
		}

		$stmt = self::pdo()->prepare("SELECT id_admin, email_admin, status_admin FROM admins WHERE email_admin = ? LIMIT 1");
		$stmt->execute(array($email));
		$admin = $stmt->fetch();

		/* No revelar si el correo existe */

		if(!$admin || (int)$admin["status_admin"] === 0){

			return $generic;
		}

		$token = bin2hex(random_bytes(32));

		$stmt = self::pdo()->prepare("UPDATE admins SET reset_token_admin = ?, reset_exp_admin = ? WHERE id_admin = ?");
		$stmt->execute(array(hash("sha256", $token), date("Y-m-d H:i:s", time() + self::RESET_MINUTES * 60), $admin["id_admin"]));

		$link = rtrim(cms_base_url(), "/")."/?reset=".$token;

		$message = '<p style="color:#555;font-size:15px;padding:0 20px">Recibimos una solicitud para cambiar la contraseña de <strong>'.htmlspecialchars($email).'</strong>.</p>'.
		           '<p style="color:#555;font-size:15px;padding:0 20px">Pulsa el botón para crear una nueva. El enlace vence en '.self::RESET_MINUTES.' minutos y solo se puede usar una vez.</p>';

		$send = TemplateController::sendEmail("Restablecer tu contraseña", $email, "RESTABLECER CONTRASEÑA", $message, $link);

		if($send !== "ok"){

			error_log("Recuperar contraseña: no se pudo enviar el correo a ".$email.": ".$send);

			return self::msg("error", "No pudimos enviar el correo desde este servidor. Pide al superadministrador que cambie tu contraseña desde Admins, o revisa la configuración de correo del hosting.");
		}

		return $generic;

	}

	/*=============================================
	Validar enlace de recuperación
	=============================================*/

	static public function findReset($token){

		if(!preg_match('/^[a-f0-9]{64}$/', (string)$token)){

			return null;
		}

		$stmt = self::pdo()->prepare("SELECT id_admin, email_admin FROM admins WHERE reset_token_admin = ? AND reset_exp_admin >= ? LIMIT 1");
		$stmt->execute(array(hash("sha256", $token), date("Y-m-d H:i:s")));

		return $stmt->fetch() ?: null;

	}

	static private function checkNew($password, $confirm){

		if(strlen($password) < self::MIN_LENGTH){

			return "La contraseña debe tener al menos ".self::MIN_LENGTH." caracteres.";
		}

		if($password !== $confirm){

			return "Las contraseñas no coinciden.";
		}

		return "";

	}

	/*=============================================
	Guardar nueva contraseña desde el enlace
	=============================================*/

	static public function applyReset($token){

		$admin = self::findReset($token);

		if(!$admin){

			return self::msg("error", "El enlace no es válido o ya venció. Solicita uno nuevo.");
		}

		$error = self::checkNew((string)($_POST["new_password"] ?? ""), (string)($_POST["confirm_password"] ?? ""));

		if($error){

			return self::msg("error", $error);
		}

		$stmt = self::pdo()->prepare("UPDATE admins SET password_admin = ?, reset_token_admin = NULL, reset_exp_admin = NULL, token_admin = NULL, token_exp_admin = NULL WHERE id_admin = ?");
		$stmt->execute(array(self::hash($_POST["new_password"]), $admin["id_admin"]));

		return self::msg("success", "¡Listo! Tu contraseña fue actualizada. Ya puedes iniciar sesión.");

	}

	/*=============================================
	Cambiar contraseña conociendo la actual
	=============================================*/

	static public function changePassword(){

		$email = trim((string)($_POST["change_email"] ?? ""));
		$current = (string)($_POST["current_password"] ?? "");

		if(!self::throttle("change", 6)){

			return self::msg("error", "Demasiados intentos. Espera 15 minutos.");
		}

		$stmt = self::pdo()->prepare("SELECT id_admin, password_admin FROM admins WHERE email_admin = ? LIMIT 1");
		$stmt->execute(array($email));
		$admin = $stmt->fetch();

		if(!$admin || !hash_equals((string)$admin["password_admin"], self::hash($current))){

			return self::msg("error", "El correo o la contraseña actual no son correctos.");
		}

		$error = self::checkNew((string)($_POST["new_password"] ?? ""), (string)($_POST["confirm_password"] ?? ""));

		if($error){

			return self::msg("error", $error);
		}

		if($_POST["new_password"] === $current){

			return self::msg("error", "La nueva contraseña debe ser diferente a la actual.");
		}

		$stmt = self::pdo()->prepare("UPDATE admins SET password_admin = ?, reset_token_admin = NULL, reset_exp_admin = NULL WHERE id_admin = ?");
		$stmt->execute(array(self::hash($_POST["new_password"]), $admin["id_admin"]));

		return self::msg("success", "Contraseña cambiada. Ya puedes iniciar sesión con la nueva.");

	}

}
