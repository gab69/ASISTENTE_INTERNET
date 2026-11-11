<?php

require_once __DIR__."/connection.php";

class GetModel{

	/*=============================================
	Ejecutar una consulta preparada
	=============================================*/

	static private function run($sql, $params = array()){

		try{

			$stmt = Connection::connect()->prepare($sql);

			foreach ($params as $key => $value) {

				$stmt -> bindValue($key, $value, PDO::PARAM_STR);
			}

			$stmt -> execute();

			return $stmt -> fetchAll(PDO::FETCH_CLASS);

		}catch(PDOException $e){

			error_log("GetModel SQL error: ".$e->getMessage()." | ".$sql);

			return null;

		}

	}

	/*=============================================
	Construir los INNER JOIN de tablas relacionadas
	=============================================*/

	static private function joins($rel, $type){

		$relArray = array_map("trim", explode(",", (string)$rel));
		$typeArray = array_map("trim", explode(",", (string)$type));

		if(count($relArray) < 2 || count($relArray) != count($typeArray)){

			return false;
		}

		$innerJoinText = "";

		foreach ($relArray as $key => $value) {

			/*=============================================
			Validar existencia de la tabla
			=============================================*/

			if(!Connection::validIdentifier($typeArray[$key]) || empty(Connection::getColumnsData($value, ["*"]))){

				return false;
			}

			if($key > 0){

				$innerJoinText .= " INNER JOIN ".$value." ON ".$relArray[0].".id_".$typeArray[$key]."_".$typeArray[0]." = ".$value.".id_".$typeArray[$key];
			}
		}

		return array("from" => $relArray[0], "joins" => $innerJoinText);

	}

	/*=============================================
	Construir filtros de igualdad: a = :p0 AND b = :p1
	=============================================*/

	static private function equalFilter($linkTo, $equalTo, &$params){

		$linkToArray = array_map("trim", explode(",", (string)$linkTo));
		$equalToArray = explode(",", (string)$equalTo);
		$conditions = array();

		foreach ($linkToArray as $key => $column) {

			if(!Connection::validIdentifier($column, true)){

				return false;
			}

			$conditions[] = $column." = :p".$key;
			$params[":p".$key] = $equalToArray[$key] ?? null;
		}

		return " WHERE ".implode(" AND ", $conditions);

	}

	/*=============================================
	Construir filtros de búsqueda: a LIKE :p0 AND b = :p1
	=============================================*/

	static private function searchFilter($linkTo, $search, &$params){

		$linkToArray = array_map("trim", explode(",", (string)$linkTo));
		$searchArray = explode(",", (string)$search);
		$conditions = array();

		foreach ($linkToArray as $key => $column) {

			if(!Connection::validIdentifier($column, true)){

				return false;
			}

			if($key == 0){

				$conditions[] = $column." LIKE :p0";
				$params[":p0"] = "%".($searchArray[0] ?? "")."%";

			}else{

				$conditions[] = $column." = :p".$key;
				$params[":p".$key] = $searchArray[$key] ?? null;
			}
		}

		return " WHERE ".implode(" AND ", $conditions);

	}

	/*=============================================
	Construir filtros de rango: a BETWEEN :b1 AND :b2 [AND f IN (...)]
	=============================================*/

	static private function rangeFilter($linkTo, $between1, $between2, $filterTo, $inTo, &$params){

		if(!Connection::validIdentifier(trim((string)$linkTo), true)){

			return false;
		}

		$sql = " WHERE ".trim($linkTo)." BETWEEN :between1 AND :between2";
		$params[":between1"] = $between1;
		$params[":between2"] = $between2;

		if($filterTo != null && $inTo != null){

			if(!Connection::validIdentifier(trim($filterTo), true)){

				return false;
			}

			$placeholders = array();

			foreach (explode(",", $inTo) as $key => $value) {

				$placeholders[] = ":in".$key;
				$params[":in".$key] = trim(trim($value), "'\"");
			}

			$sql .= " AND ".trim($filterTo)." IN (".implode(",", $placeholders).")";
		}

		return $sql;

	}

	/*=============================================
	Validar select + columnas en una sola tabla
	=============================================*/

	static private function validateColumns($table, $select, $extra = array()){

		if(!Connection::validIdentifierList($select, true)){

			return false;
		}

		$columns = array_merge(explode(",", $select), $extra);

		return !empty(Connection::getColumnsData($table, $columns));

	}

	/*=============================================
	Peticiones GET sin filtro
	=============================================*/

	static public function getData($table, $select,$orderBy,$orderMode,$startAt,$endAt){

		if(!GetModel::validateColumns($table, $select)){

			return null;
		}

		$orderLimit = Connection::orderLimit($orderBy, $orderMode, $startAt, $endAt);

		if($orderLimit === false){

			return null;
		}

		return GetModel::run("SELECT $select FROM $table".$orderLimit);

	}

	/*=============================================
	Peticiones GET con filtro
	=============================================*/

	static public function getDataFilter($table, $select, $linkTo, $equalTo, $orderBy,$orderMode,$startAt,$endAt){

		if(!GetModel::validateColumns($table, $select, explode(",", (string)$linkTo))){

			return null;
		}

		$params = array();
		$where = GetModel::equalFilter($linkTo, $equalTo, $params);
		$orderLimit = Connection::orderLimit($orderBy, $orderMode, $startAt, $endAt);

		if($where === false || $orderLimit === false){

			return null;
		}

		return GetModel::run("SELECT $select FROM $table".$where.$orderLimit, $params);

	}

	/*=============================================
	Peticiones GET sin filtro entre tablas relacionadas
	=============================================*/

	static public function getRelData($rel, $type, $select, $orderBy,$orderMode,$startAt,$endAt){

		$joins = GetModel::joins($rel, $type);
		$orderLimit = Connection::orderLimit($orderBy, $orderMode, $startAt, $endAt);

		if($joins === false || $orderLimit === false || !Connection::validIdentifierList($select, true)){

			return null;
		}

		return GetModel::run("SELECT $select FROM ".$joins["from"].$joins["joins"].$orderLimit);

	}

	/*=============================================
	Peticiones GET con filtro entre tablas relacionadas
	=============================================*/

	static public function getRelDataFilter($rel, $type, $select, $linkTo, $equalTo, $orderBy,$orderMode,$startAt,$endAt){

		$params = array();
		$joins = GetModel::joins($rel, $type);
		$where = GetModel::equalFilter($linkTo, $equalTo, $params);
		$orderLimit = Connection::orderLimit($orderBy, $orderMode, $startAt, $endAt);

		if($joins === false || $where === false || $orderLimit === false || !Connection::validIdentifierList($select, true)){

			return null;
		}

		return GetModel::run("SELECT $select FROM ".$joins["from"].$joins["joins"].$where.$orderLimit, $params);

	}

	/*=============================================
	Peticiones GET para el buscador sin relaciones
	=============================================*/

	static public function getDataSearch($table, $select, $linkTo, $search,$orderBy,$orderMode,$startAt,$endAt){

		if(!GetModel::validateColumns($table, $select, explode(",", (string)$linkTo))){

			return null;
		}

		$params = array();
		$where = GetModel::searchFilter($linkTo, $search, $params);
		$orderLimit = Connection::orderLimit($orderBy, $orderMode, $startAt, $endAt);

		if($where === false || $orderLimit === false){

			return null;
		}

		return GetModel::run("SELECT $select FROM $table".$where.$orderLimit, $params);

	}

	/*=============================================
	Peticiones GET para el buscador entre tablas relacionadas
	=============================================*/

	static public function getRelDataSearch($rel, $type, $select, $linkTo, $search, $orderBy,$orderMode,$startAt,$endAt){

		$params = array();
		$joins = GetModel::joins($rel, $type);
		$where = GetModel::searchFilter($linkTo, $search, $params);
		$orderLimit = Connection::orderLimit($orderBy, $orderMode, $startAt, $endAt);

		if($joins === false || $where === false || $orderLimit === false || !Connection::validIdentifierList($select, true)){

			return null;
		}

		return GetModel::run("SELECT $select FROM ".$joins["from"].$joins["joins"].$where.$orderLimit, $params);

	}

	/*=============================================
	Peticiones GET para selección de rangos
	=============================================*/

	static public function getDataRange($table,$select,$linkTo,$between1,$between2,$orderBy,$orderMode,$startAt,$endAt, $filterTo, $inTo){

		$extra = explode(",", (string)$linkTo);

		if($filterTo != null){

			$extra = array_merge($extra, explode(",", $filterTo));
		}

		if(!GetModel::validateColumns($table, $select, $extra)){

			return null;
		}

		$params = array();
		$where = GetModel::rangeFilter($linkTo, $between1, $between2, $filterTo, $inTo, $params);
		$orderLimit = Connection::orderLimit($orderBy, $orderMode, $startAt, $endAt);

		if($where === false || $orderLimit === false){

			return null;
		}

		return GetModel::run("SELECT $select FROM $table".$where.$orderLimit, $params);

	}

	/*=============================================
	Peticiones GET para selección de rangos con relaciones
	=============================================*/

	static public function getRelDataRange($rel,$type,$select,$linkTo,$between1,$between2,$orderBy,$orderMode,$startAt,$endAt, $filterTo, $inTo){

		$params = array();
		$joins = GetModel::joins($rel, $type);
		$where = GetModel::rangeFilter($linkTo, $between1, $between2, $filterTo, $inTo, $params);
		$orderLimit = Connection::orderLimit($orderBy, $orderMode, $startAt, $endAt);

		if($joins === false || $where === false || $orderLimit === false || !Connection::validIdentifierList($select, true)){

			return null;
		}

		return GetModel::run("SELECT $select FROM ".$joins["from"].$joins["joins"].$where.$orderLimit, $params);

	}

}
