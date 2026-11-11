<?php

require_once API_DIR."/models/connection.php";
require_once API_DIR."/models/get.model.php";
require_once API_DIR."/models/post.model.php";
require_once API_DIR."/models/put.model.php";

require_once API_DIR."/vendor/autoload.php";
use Firebase\JWT\JWT;

class PostController{

	/*=============================================
	Encriptar contraseña (compatible con los datos existentes)
	=============================================*/

	static private function crypt($password){

		return crypt((string)$password, Connection::config("password_salt"));

	}

	/*=============================================
	Generar token, guardarlo en BD y responder
	=============================================*/

	static private function issueToken($table, $user, $suffix){

		$token = Connection::jwt($user->{"id_".$suffix}, $user->{"email_".$suffix});

		$jwt = JWT::encode($token, Connection::config("jwt_secret"));

		$data = array(

			"token_".$suffix => $jwt,
			"token_exp_".$suffix => $token["exp"]

		);

		$update = PutModel::putData($table, $data, $user->{"id_".$suffix}, "id_".$suffix);

		$return = new PostController();

		if(isset($update["comment"]) && $update["comment"] == "The process was successful"){

			$user->{"token_".$suffix} = $jwt;
			$user->{"token_exp_".$suffix} = $token["exp"];

			$return -> fncResponse(array($user), null, $suffix);

		}else{

			$return -> fncResponse(null, "Could not generate the session token", $suffix);

		}

	}

	/*=============================================
	Peticion POST para crear datos
	=============================================*/

	static public function postData($table, $data){

		$response = PostModel::postData($table, $data);

		$return = new PostController();
		$return -> fncResponse($response,null,null);

	}

	/*=============================================
	Peticion POST para registrar usuario
	=============================================*/

	static public function postRegister($table, $data, $suffix){

		if(isset($data["password_".$suffix]) && $data["password_".$suffix] != null){

			$data["password_".$suffix] = PostController::crypt($data["password_".$suffix]);

			$response = PostModel::postData($table, $data);

			$return = new PostController();
			$return -> fncResponse($response,null,$suffix);

		}else{

			/*=============================================
			Registro de usuarios desde APP externas
			=============================================*/

			$response = PostModel::postData($table, $data);

			if(isset($response["comment"]) && $response["comment"] == "The process was successful" && isset($data["email_".$suffix])){

				$response = GetModel::getDataFilter($table, "*", "email_".$suffix, $data["email_".$suffix], null,null,null,null);

				if(!empty($response)){

					PostController::issueToken($table, $response[0], $suffix);

					return;

				}

			}

			$return = new PostController();
			$return -> fncResponse(null, null, $suffix);

		}

	}

	/*=============================================
	Peticion POST para login de usuario
	=============================================*/

	static public function postLogin($table, $data, $suffix){

		if(!isset($data["email_".$suffix])){

			$return = new PostController();
			$return -> fncResponse(null, "Wrong email", $suffix);

			return;
		}

		/*=============================================
		Validar que el usuario exista en BD
		=============================================*/

		$response = GetModel::getDataFilter($table, "*", "email_".$suffix, $data["email_".$suffix], null,null,null,null);

		if(empty($response)){

			$return = new PostController();
			$return -> fncResponse(null, "Wrong email", $suffix);

			return;

		}

		$user = $response[0];

		if($user->{"password_".$suffix} != null){

			/*=============================================
			Validamos la contraseña
			=============================================*/

			$crypt = PostController::crypt($data["password_".$suffix] ?? "");

			if(!hash_equals((string)$user->{"password_".$suffix}, $crypt)){

				$return = new PostController();
				$return -> fncResponse(null, "Wrong password", $suffix);

				return;

			}

		}

		/*=============================================
		Usuario válido (o login desde app externa sin contraseña)
		=============================================*/

		PostController::issueToken($table, $user, $suffix);

	}

	/*=============================================
	Respuestas del controlador
	=============================================*/

	public function fncResponse($response,$error,$suffix){

		if(!empty($response)){

			/*=============================================
			Quitamos la contraseña de la respuesta
			=============================================*/

			if($suffix != null && isset($response[0]) && is_object($response[0]) && property_exists($response[0], "password_".$suffix)){

				unset($response[0]->{"password_".$suffix});

			}

			Connection::response(200, $response);

		}else if($error != null){

			Connection::response(400, $error);

		}else{

			Connection::response(404, "Not Found", array("method" => "post"));

		}

	}

}
