<?php

require_once __DIR__."/connection.php";
require_once __DIR__."/get.model.php";

class DeleteModel{

	/*=============================================
	Peticion Delete para eliminar datos de forma dinámica
	=============================================*/

	static public function deleteData($table, $id, $nameId){

		if(!Connection::validIdentifier($table) || !Connection::validIdentifier($nameId)){

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
		Eliminamos registros
		=============================================*/

		try{

			$stmt = Connection::connect()->prepare("DELETE FROM $table WHERE $nameId = :__id");

			$stmt->bindValue(":__id", $id, PDO::PARAM_STR);

			$stmt -> execute();

			return array(

				"comment" => "The process was successful"
			);

		}catch(PDOException $e){

			error_log("DeleteModel SQL error: ".$e->getMessage());

			return null;

		}

	}

}
