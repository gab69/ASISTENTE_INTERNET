<?php

require_once __DIR__."/get.model.php";

class Connection{

	/*=============================================
	Conexión compartida y caché de columnas
	(evita abrir una conexión nueva por consulta)
	=============================================*/

	private static $link = null;
	private static $columnsCache = [];

	/*=============================================
	Modo interno: el CMS ejecuta la API dentro del mismo
	proceso PHP (sin HTTP). No se tocan headers ni código
	de respuesta del script que la llama.
	=============================================*/

	public static $internal = false;
	public static $internalBody = null;

	static public function status($status){

		if(!self::$internal && !headers_sent()){

			http_response_code((int)$status);
		}

		return self::$internal ? (JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : (JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

	}

	/*=============================================
	Cuerpo de PUT (form-urlencoded)
	=============================================*/

	static public function inputData(){

		$raw = self::$internal ? (string)self::$internalBody : (string)file_get_contents("php://input");

		$data = array();

		parse_str($raw, $data);

		return $data;

	}

	/*=============================================
	Configuración
	=============================================*/

	static public function config($key = null){

		if(!isset($GLOBALS["API_CONFIG"])){

			$GLOBALS["API_CONFIG"] = require dirname(__DIR__, 2)."/config/loader.php";
		}

		return $key === null ? $GLOBALS["API_CONFIG"] : ($GLOBALS["API_CONFIG"][$key] ?? null);

	}

	/*=============================================
	Información de la base de datos
	=============================================*/

	static public function infoDatabase(){

		return array(
			"host"     => Connection::config("db_host") ?: "localhost",
			"port"     => Connection::config("db_port") ?: 3306,
			"database" => Connection::config("db_name"),
			"user"     => Connection::config("db_user"),
			"pass"     => Connection::config("db_pass")
		);

	}

	/*=============================================
	APIKEY
	=============================================*/

	static public function apikey(){

		return (string)Connection::config("api_key");

	}

	/*=============================================
	Llave recibida en la petición.
	Muchos hostings (cPanel con PHP-FPM, LiteSpeed,
	HTTP/2) eliminan o cambian a minúsculas el header
	Authorization, por eso se busca en todas partes.
	=============================================*/

	static public function requestApiKey(){

		$candidates = array(
			$_SERVER["HTTP_AUTHORIZATION"] ?? null,
			$_SERVER["REDIRECT_HTTP_AUTHORIZATION"] ?? null,
			$_SERVER["HTTP_X_API_KEY"] ?? null
		);

		if(function_exists("getallheaders")){

			foreach ((array)getallheaders() as $name => $value) {

				$name = strtolower($name);

				if($name == "authorization" || $name == "x-api-key"){

					$candidates[] = $value;
				}
			}
		}

		foreach ($candidates as $value) {

			if($value !== null && trim($value) !== ""){

				return trim(preg_replace('/^Bearer\s+/i', "", $value));
			}
		}

		return "";

	}

	/*=============================================
	Acceso público
	=============================================*/

	static public function publicAccess(){

		return (array)Connection::config("public_tables");

	}

	/*=============================================
	Conexión a la base de datos
	=============================================*/

	static public function connect(){

		if(self::$link instanceof PDO){

			return self::$link;
		}

		$db = Connection::infoDatabase();

		try{

			self::$link = new PDO(
				"mysql:host=".$db["host"].";port=".$db["port"].";dbname=".$db["database"].";charset=utf8mb4",
				$db["user"],
				$db["pass"],
				array(
					PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
					PDO::ATTR_EMULATE_PREPARES => true
				)
			);

			self::$link->exec("SET NAMES utf8mb4");

			/* Modo SQL tolerante (como el entorno original): valores
			   vacíos en fechas o números no rompen el INSERT */
			self::$link->exec("SET SESSION sql_mode = 'NO_ENGINE_SUBSTITUTION'");

			/* Misma zona horaria que PHP: las horas de los mensajes
			   (columnas TIMESTAMP) se guardan y se leen igual en todo el sistema */
			try{
				self::$link->exec("SET time_zone = '".date("P")."'");
			}catch(PDOException $e){}

		}catch(PDOException $e){

			error_log("DB connection error: ".$e->getMessage());

			if(self::$internal){

				throw new RuntimeException("Database connection error: ".$e->getMessage());
			}

			Connection::response(500, Connection::config("debug") ? "Database error: ".$e->getMessage() : "Database connection error");

			exit;

		}

		return self::$link;

	}

	/*=============================================
	Validar nombres de tablas y columnas
	(previene inyección SQL en identificadores)
	=============================================*/

	static public function validIdentifier($name, $allowDot = false){

		$pattern = $allowDot ? '/^[A-Za-z0-9_]+(\.[A-Za-z0-9_]+)?$/' : '/^[A-Za-z0-9_]+$/';

		return is_string($name) && preg_match($pattern, $name) === 1;

	}

	/*=============================================
	Validar lista de identificadores separados por coma
	=============================================*/

	static public function validIdentifierList($list, $allowStar = false){

		if($list === null || $list === ""){

			return false;
		}

		foreach (explode(",", $list) as $item) {

			$item = trim($item);

			if($allowStar && ($item === "*" || preg_match('/^[A-Za-z0-9_]+\.\*$/', $item))){

				continue;
			}

			if(!Connection::validIdentifier($item, true)){

				return false;
			}
		}

		return true;

	}

	/*=============================================
	Normalizar modo de orden
	=============================================*/

	static public function orderMode($orderMode){

		return strtoupper(trim((string)$orderMode)) === "DESC" ? "DESC" : "ASC";

	}

	/*=============================================
	Construir ORDER BY / LIMIT seguros
	=============================================*/

	static public function orderLimit($orderBy, $orderMode, $startAt, $endAt){

		$sql = "";

		if($orderBy != null && $orderMode != null){

			if(!Connection::validIdentifierList($orderBy)){

				return false;
			}

			$sql .= " ORDER BY ".$orderBy." ".Connection::orderMode($orderMode);
		}

		if($startAt !== null && $startAt !== "" && $endAt !== null && $endAt !== ""){

			$sql .= " LIMIT ".max(0, (int)$startAt).", ".max(0, (int)$endAt);
		}

		return $sql;

	}

	/*=============================================
	Respuesta JSON estándar
	=============================================*/

	static public function response($status, $results, $extra = array()){

		echo json_encode(array_merge(array("status" => $status, "results" => $results), $extra), Connection::status($status));

	}

	/*=============================================
	Validar existencia de una tabla en la bd
	=============================================*/

	static public function getColumnsData($table, $columns){

		if(!Connection::validIdentifier($table)){

			return null;
		}

		/*=============================================
		Traer todas las columnas de una tabla (con caché)
		=============================================*/

		if(!isset(self::$columnsCache[$table])){

			$stmt = Connection::connect()->prepare("SELECT COLUMN_NAME AS item FROM information_schema.columns WHERE table_schema = :db AND table_name = :tb");

			$stmt->execute(array(":db" => Connection::infoDatabase()["database"], ":tb" => $table));

			self::$columnsCache[$table] = $stmt->fetchAll(PDO::FETCH_OBJ);
		}

		$validate = self::$columnsCache[$table];

		/*=============================================
		Validamos existencia de la tabla
		=============================================*/

		if(empty($validate)){

			return null;

		}

		/*=============================================
		Ajuste de selección de columnas globales
		=============================================*/

		$columns = array_values(array_unique(array_map("trim", (array)$columns)));
		$columns = array_values(array_filter($columns, function($c){ return $c !== "*" && $c !== ""; }));

		/*=============================================
		Validamos existencia de columnas
		=============================================*/

		$existing = array_map(function($v){ return $v->item; }, $validate);

		foreach ($columns as $column) {

			if(!in_array($column, $existing, true)){

				return null;
			}
		}

		return $validate;

	}

	/*=============================================
	Generar Token de Autenticación
	=============================================*/

	static public function jwt($id, $email){

		$time = time();

		$token = array(

			"iat" =>  $time,
			"exp" => $time + (int)(Connection::config("token_ttl") ?: 86400),
			"data" => [

				"id" => $id,
				"email" => $email
			]

		);

		return $token;
	}

	/*=============================================
	Validar el token de seguridad
	=============================================*/

	static public function tokenValidate($token, $table, $suffix){

		if(!Connection::validIdentifier($table) || !Connection::validIdentifier($suffix) || $token === null || $token === ""){

			return "no-auth";
		}

		/*=============================================
		Traemos el usuario de acuerdo al token
		=============================================*/

		$user = GetModel::getDataFilter($table, "token_exp_".$suffix, "token_".$suffix, $token, null,null,null,null);

		if(!empty($user)){

			/*=============================================
			Validamos que el token no haya expirado
			=============================================*/

			if(time() < (int)$user[0]->{"token_exp_".$suffix}){

				return "ok";

			}else{

				return "expired";
			}

		}else{

			return "no-auth";

		}

	}

}
