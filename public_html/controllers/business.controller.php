<?php

Class BusinessController{

	/*=============================================
	Bots que continúan cuando WhatsApp confirma que se
	envió un mensaje del negocio ($message = ese mensaje)
	=============================================*/

	static public function responseBusiness($message,$getApiWS,$phone_message){

		if(empty($message) || $message->type_message != "business" || $message->status_message === "failed"){

			return null;
		}

		/* Pedido a domicilio: primero la dirección (para verificar la cobertura
		   antes de pedir el resto de los datos) */

		if($message->template_message == '{"type":"bot","title":"delivery"}'){

			return BotsController::responseBots("address",$getApiWS,$phone_message,$message->order_message,null);
		}

		/* Confirmar orden */

		if($message->template_message == '{"type":"bot","title":"process"}'){

			return BotsController::responseBots("confirmation",$getApiWS,$phone_message,$message->order_message,$message->id_conversation_message);
		}

		return null;

	}

}
