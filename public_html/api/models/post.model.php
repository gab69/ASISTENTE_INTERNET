<?php

require_once __DIR__."/connection.php";

class PostModel{

	/*=============================================
	Peticion POST para crear datos de forma dinámica
	=============================================*/

	static public function postData($table, $data){

		if(empty($data) || !Connection::validIdentifier($table)){

			return null;
		}

		$columns = array();
		$params = array();

		foreach ($data as $key => $value) {

			if(!Connection::validIdentifier($key)){

				return null;
			}

			$columns[] = $key;
			$params[] = ":".$key;

		}

		$sql = "INSERT INTO $table (".implode(",", $columns).") VALUES (".implode(",", $params).")";

		try{

			$link = Connection::connect();
			$stmt = $link->prepare($sql);

			foreach ($data as $key => $value) {

				$stmt->bindValue(":".$key, is_array($value) ? json_encode($value) : $value, PDO::PARAM_STR);

			}

			$stmt -> execute();

			return array(

				"lastId" => $link->lastInsertId(),
				"comment" => "The process was successful"
			);

		}catch(PDOException $e){

			error_log("PostModel SQL error: ".$e->getMessage());

			return null;

		}

	}

}
