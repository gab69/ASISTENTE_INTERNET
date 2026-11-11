<?php 

class DynamicController{

	/* Mensaje claro cuando la API no guardó (antes el aviso "Procesando..." quedaba girando) */

	static public function errorText($response){

		$status = (int)($response->status ?? 0);

		if(in_array($status, array(303, 401), true) || stripos(json_encode($response->results ?? ""), "token") !== false){

			return "Tu sesión del panel venció. Cierra sesión, vuelve a entrar e inténtalo de nuevo.";
		}

		$detail = is_string($response->results ?? null) ? $response->results : json_encode($response->results ?? "Sin respuesta", JSON_UNESCAPED_UNICODE);

		return "No se pudo guardar (".($status ?: "sin respuesta")."): ".mb_substr(strip_tags((string)$detail), 0, 200);

	}

	/*=============================================
	Gestión de datos dinámicos
	=============================================*/	

	public function manage(){

		if(isset($_POST["module"])){

			echo '<script>

				fncMatPreloader("on");
			    fncSweetAlert("loading", "Procesando...", "");

			</script>';

			$module = json_decode($_POST["module"]);

			/*=============================================
			Editar datos
			=============================================*/

			if(isset($_POST["idItem"])){

				/*=============================================
				Actualizar datos
				=============================================*/

				$url = $module->title_module."?id=".base64_decode($_POST["idItem"])."&nameId=id_".$module->suffix_module."&token=".$_SESSION["admin"]->token_admin."&table=admins&suffix=admin";
				$method = "PUT";
				$fields = "";
				$count = 0;

				foreach ($module->columns as $key => $value) {

					if($value->type_column == "password" && !empty($_POST[$value->title_column])){

						$fields.= $value->title_column."=".crypt(trim($_POST[$value->title_column]),'$2a$07$azybxcags23425sdg23sdfhsd$')."&";

					}else if($value->type_column == "email"){

						$fields.= $value->title_column."=".trim($_POST[$value->title_column])."&";

					}else{
					
						$fields.= $value->title_column."=".urlencode(trim($_POST[$value->title_column]))."&";

					}
					
					$count++;

					if($count == count($module->columns)){

						$fields = substr($fields,0,-1);

						$update = CurlController::request($url,$method,$fields);

						if($update->status == 200){

							echo '

								<script>

									fncMatPreloader("off");
									fncFormatInputs();
								    fncSweetAlert("success","El registro ha sido actualizado con éxito", setTimeout(()=>window.location="/'.$module->url_page.'",1000));
									

								</script>

							';

						}else{

							echo '<script>
								fncMatPreloader("off");
								fncSweetAlert("error", '.json_encode(DynamicController::errorText($update), JSON_UNESCAPED_UNICODE).', "");
							</script>';
						}
					}
				
				}


			}else{
		
				/*=============================================
				Crear datos
				=============================================*/

				$url = $module->title_module."?token=".$_SESSION["admin"]->token_admin."&table=admins&suffix=admin";
				$method = "POST";
				$fields = array();
				$count = 0;

				foreach ($module->columns as $key => $value) {

					if($value->type_column == "password"){

						$fields[$value->title_column] = crypt(trim($_POST[$value->title_column]),'$2a$07$azybxcags23425sdg23sdfhsd$');
					
					}else if($value->type_column == "email"){

						$fields[$value->title_column] = trim($_POST[$value->title_column]);
					}else{
					
						$fields[$value->title_column] = urlencode(trim($_POST[$value->title_column]));

					}
					
					$count++;

					if($count == count($module->columns)){

						$fields["date_created_".$module->suffix_module] = date("Y-m-d");

						$save = CurlController::request($url,$method,$fields);

						if($save->status == 200){

							echo '

								<script>

									fncMatPreloader("off");
									fncFormatInputs();
								    fncSweetAlert("success","El registro ha sido guardado con éxito", setTimeout(()=>window.location="/'.$module->url_page.'",1000));
									

								</script>

							';

						}else{

							echo '<script>
								fncMatPreloader("off");
								fncSweetAlert("error", '.json_encode(DynamicController::errorText($save), JSON_UNESCAPED_UNICODE).', "");
							</script>';
						}
					}
				
				}

			}

		}

	}

}