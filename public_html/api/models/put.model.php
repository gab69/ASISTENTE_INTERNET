<?php

require_once __DIR__."/connection.php";
require_once __DIR__."/get.model.php";

class PutModel{

	/*=============================================
	Peticion Put para editar datos de forma dinámica
	=============================================*/

	static public function putData($table, $data, $id, $nameId){

		if(empty($data) || !Connection::validIdentifier($table) || !Connection::validIdentifier($nameId)){

			return null;
		}

		/*=============================================
		Validar el ID
		=============================================*/

		$response = GetModel::getDataFilter($table, $nameId, $nameId, $id, null,null,null,null);

		if(empty($response)){

			return null;

		}

		/*=============================================
		Actualizamos registros
		=============================================*/

		$set = array();

		foreach ($data as $key => $value) {

			if(!Connection::validIdentifier($key)){

				return null;
			}

			$set[] = $key." = :".$key;

		}

		$sql = "UPDATE $table SET ".implode(",", $set)." WHERE $nameId = :__id";

		try{

			$stmt = Connection::connect()->prepare($sql);

			foreach ($data as $key => $value) {

				$stmt->bindValue(":".$key, is_array($value) ? json_encode($value) : $value, PDO::PARAM_STR);

			}

			$stmt->bindValue(":__id", $id, PDO::PARAM_STR);

			$stmt -> execute();

			return array(

				"comment" => "The process was successful"
			);

		}catch(PDOException $e){

			error_log("PutModel SQL error: ".$e->getMessage());

			return null;

		}

	}

}
