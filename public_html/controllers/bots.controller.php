<?php

require_once __DIR__."/chat.service.php";

Class BotsController{

	/*=============================================
	Texto guardado en la base (urlencode + "\n" literales)
	=============================================*/

	static private function text($value, $max = null){

		$text = str_replace(array("\r\n", "\r", "\\n"), "\n", trim(urldecode((string)$value)));

		return $max ? mb_substr($text, 0, $max) : $text;

	}

	static public function responseBots($bot,$getApiWS,$phone_message,$order_message,$idListMenu){

		/*=============================================
      	Traemos la plantilla Bot
      	=============================================*/

      	$getBot = CurlController::request("bots?linkTo=title_bot&equalTo=".rawurlencode($bot), "GET", array());

      	if(!isset($getBot->status) || $getBot->status != 200){

      		error_log("Bot no encontrado: ".$bot);

      		return null;
      	}

  		$getBot = $getBot->results[0];
  		$payload = null;
  		$business_message = "";
  		$template_message = json_encode(array("type" => "bot", "title" => $bot), JSON_UNESCAPED_UNICODE);

  		/*=============================================
		Plantilla de tipo texto
		=============================================*/

  		if($getBot->type_bot == "text"){

  			$body = self::text($getBot->body_text_bot);

			/* Enviamos el checkout con el total del pedido */

			if($bot == "checkout"){

				$getOrder = CurlController::request("orders?linkTo=conversation_order&equalTo=".rawurlencode((string)$idListMenu)."&select=total_order", "GET", array());

				if(isset($getOrder->status) && $getOrder->status == 200){

					$getBot->body_text_bot .= $getOrder->results[0]->total_order;
					$body .= $getOrder->results[0]->total_order;
				}
			}

			$payload = ChatService::textPayload($phone_message, $body);
			$business_message = $getBot->body_text_bot;

  		}

  		/*=============================================
		Plantilla de tipo interactivo
		=============================================*/

		if($getBot->type_bot == "interactive"){

			$interactive = array();
			$action = null;
			$interactiveType = $getBot->interactive_bot;

			/*=============================================
			Lista del menú: productos de la categoría elegida
			=============================================*/

			if($getBot->interactive_bot == "none"){

				$interactiveType = "list";

				$getMenu = CurlController::request("relations?rel=products,categories&type=product,category&linkTo=id_category&equalTo=".rawurlencode((string)$idListMenu), "GET", array());

				if(isset($getMenu->status) && $getMenu->status == 200){

					$menu = $getMenu->results;
					$getBot->header_text_bot = $menu[0]->title_category;
					$rows = array();

					foreach (array_slice($menu, 0, 10) as $value) {

						$rows[] = array(
							"id" => (string)$value->code_product,
							"title" => self::text($value->title_product, 24),
							"description" => mb_substr(cms_money($value->price_product), 0, 72)
						);
					}

					$action = array(
						"button" => "Ver opciones",
						"sections" => array(array(
							"title" => self::text($menu[0]->title_category, 24),
							"rows" => $rows
						))
					);
				}
			}

			/*=============================================
			Confirmación: armar y guardar el pedido
			=============================================*/

			if($bot == "confirmation"){

				require_once __DIR__."/business.orders.php";

				$getBot->body_text_bot = "";
				$items = array();
				$data = array("name" => "", "contact_phone" => "", "email" => "", "address" => "");

				$getMessages = CurlController::request("messages?linkTo=id_conversation_message,type_message&equalTo=".rawurlencode((string)$idListMenu).",client", "GET", array());

				if(isset($getMessages->status) && $getMessages->status == 200){

					foreach ($getMessages->results as $value) {

						/* Productos elegidos (respuestas con código sku) */

						if(str_contains((string)$value->client_message, 'sku')){

							$sku = json_decode($value->client_message)->id ?? "";

							$getProduct = CurlController::request("products?linkTo=code_product&equalTo=".rawurlencode($sku), "GET", array());

							if(isset($getProduct->status) && $getProduct->status == 200){

								$product = $getProduct->results[0];
								$name = urldecode($product->title_product);
								$price = (float)$product->price_product;

								if(isset($items[$name])){

									$items[$name]["qty"]++;
									$items[$name]["subtotal"] = round($items[$name]["qty"] * $price, 2);

								}else{

									$items[$name] = array("qty" => 1, "name" => $name, "price" => $price, "subtotal" => $price, "matched" => true);
								}
							}
						}

						/* Datos del cliente según la pregunta que respondió */

						$fieldsMap = array(
							'{"type":"bot","title":"name"}'    => "name",
							'{"type":"bot","title":"phone"}'   => "contact_phone",
							'{"type":"bot","title":"email"}'   => "email",
							'{"type":"bot","title":"address"}' => "address"
						);

						if(isset($fieldsMap[$value->template_message])){

							$json = json_decode((string)$value->client_message);
							$data[$fieldsMap[$value->template_message]] = is_object($json) ? (string)($json->text ?? "") : (string)$value->client_message;
						}
					}
				}

				$items = array_values($items);

				if(!empty($items)){

					$result = BizOrders::create(array(
						"name" => $data["name"],
						"contact_phone" => $data["contact_phone"],
						"email" => $data["email"],
						"address" => $data["address"],
						"delivery" => "delivery",
						"items" => $items,
						"phone" => $phone_message,
						"conversation" => (string)$idListMenu,
						"source" => "bot"
					));

					$body = BizOrders::itemsText($items)."\n";
					$body .= "\n*Nombre:* ".$data["name"]."\n*Celular:* ".$data["contact_phone"]."\n*Email:* ".$data["email"]."\n*Dirección:* ".$data["address"]."\n";

					if($result["ok"]){

						$body .= (float)$result["fee"] > 0 ? "\nEnvío: ".cms_money($result["fee"]) : "";
						$body .= "\n*Total Pedido: ".cms_money($result["total"])."*\n";

						if(empty($result["duplicate"])){

							try{ BizOrders::notifyOwner($result["id"]); }catch(Throwable $e){ error_log("Pedidos: ".$e->getMessage()); }
						}
					}

					$getBot->body_text_bot = $body;
				}
			}

			/*=============================================
			Cabecera
			=============================================*/

			if(!empty($getBot->header_text_bot)){

				$interactive["header"] = array("type" => "text", "text" => self::text($getBot->header_text_bot, 60));
			}

			if(!empty($getBot->header_image_bot)){

				$interactive["header"] = array("type" => "image", "image" => array("link" => urldecode($getBot->header_image_bot)));
			}

			if(!empty($getBot->header_video_bot)){

				$interactive["header"] = array("type" => "video", "video" => array("link" => urldecode($getBot->header_video_bot)));
			}

			/* Las listas solo admiten cabecera de texto */

			if($interactiveType == "list" && isset($interactive["header"]) && $interactive["header"]["type"] != "text"){

				unset($interactive["header"]);
			}

			/*=============================================
			Cuerpo y pie (WhatsApp rechaza textos vacíos)
			=============================================*/

			$body = $bot == "confirmation" ? trim($getBot->body_text_bot) : self::text($getBot->body_text_bot);

			$interactive["body"] = array("text" => mb_substr($body !== "" ? $body : "Elige una opción", 0, 1024));

			$footer = self::text($getBot->footer_text_bot, 60);

			if($footer !== ""){

				$interactive["footer"] = array("text" => $footer);
			}

			/*=============================================
			Botones
			=============================================*/

			if($getBot->interactive_bot == "button"){

				$buttons = array();

				foreach ((array)json_decode(urldecode((string)$getBot->buttons_bot)) as $key => $value) {

					$buttons[] = array("type" => "reply", "reply" => array("id" => (string)$key, "title" => self::text($value, 20)));
				}

				$action = array("buttons" => array_slice($buttons, 0, 3));
			}

			/*=============================================
			Lista
			=============================================*/

			if($getBot->interactive_bot == "list"){

				$rows = array();

				foreach ((array)json_decode(urldecode((string)$getBot->list_bot)) as $value) {

					$row = array("id" => (string)$value->id, "title" => self::text($value->title, 24));

					if(!empty($value->description)){

						$row["description"] = self::text($value->description, 72);
					}

					$rows[] = $row;
				}

				$action = array(
					"button" => "Ver opciones",
					"sections" => array(array(
						"title" => self::text($getBot->title_list_bot ?: "Opciones", 24),
						"rows" => array_slice($rows, 0, 10)
					))
				);
			}

			$interactive = array_merge(array("type" => $interactiveType), $interactive, array("action" => $action ?: new stdClass()));

			$payload = array(
				"messaging_product" => "whatsapp",
				"recipient_type" => "individual",
				"to" => ChatService::cleanPhone($phone_message),
				"type" => "interactive",
				"interactive" => $interactive
			);

			$business_message = $bot == "confirmation" ? $getBot->body_text_bot : urldecode($getBot->body_text_bot);
		}

		if($payload === null){

			error_log("Bot sin formato válido: ".$bot);

			return null;
		}

		/*=============================================
      	Enviar a WhatsApp y guardar la respuesta del negocio
      	=============================================*/

		return ChatService::deliver($getApiWS, $phone_message, $payload, $business_message, $template_message);

	}

}
