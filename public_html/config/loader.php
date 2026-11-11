<?php

/*=============================================
Cargador de configuración única (CMS + API)
-----------------------------------------------
NO edites este archivo. La configuración se hace
desde el navegador en:  https://tudominio.com/setup/
y se guarda en config/config.php
=============================================*/

$defaults = array(

	/* Base de datos */
	"db_host"  => "localhost",
	"db_port"  => 3306,
	"db_name"  => "",
	"db_user"  => "",
	"db_pass"  => "",

	/* Seguridad (se generan automáticamente en la instalación) */
	"api_key"       => "",
	"jwt_secret"    => "",
	"password_salt" => '$2a$07$azybxcags23425sdg23sdfhsd$',
	"token_ttl"     => 86400,

	/* API (vacío = misma web en /api/) */
	"api_url"        => "",
	"api_verify_ssl" => true,
	"api_timeout"    => 30,
	"public_tables"  => array(),
	"cors_origins"   => array("*"),

	/* WhatsApp / IA */
	"webhook_verify_token" => "",
	"webhook_log"          => false,
	"graph_version"        => "v22.0",
	"openai_model"         => "gpt-4o-mini",

	/* Asistente de IA: "openai" (ChatGPT), "anthropic" (Claude) o "none" */
	"ai_provider" => "openai",
	"ai_api_key"  => "",
	"ai_model"    => "",
	"ai_prompt"   => "",
	"ai_history"  => 20,

	/* Avisos al negocio: plantilla aprobada para cuando el número
	   no escribió al bot en las últimas 24 h (cuerpo con una variable {{1}}) */
	"notify_template"      => "",
	"notify_template_lang" => "es",

	/* Límites de consumo (0 = sin límite) */
	"limit_client_per_min" => 8,       /* mensajes por minuto de un cliente que la IA responde */
	"limit_send_per_min"   => 60,      /* mensajes por minuto que el sistema envía en total */
	"limit_ai_tokens_day"  => 0,
	"limit_ai_tokens_month"=> 0,
	"limit_ai_message"     => "En este momento un asesor continuará la atención. ¡Gracias por tu paciencia! 🙌",
	"reply_wait_seconds"   => 4,       /* espera antes de responder para juntar mensajes seguidos */
	"ai_error_message"     => "Disculpa, tuve un pequeño inconveniente 🙏 ¿Me repites tu último mensaje, por favor? Si prefieres, un asesor te escribe en breve.",

	/* Costos para estimar el gasto (USD) */
	"ai_price_in"          => 0.15,    /* por 1 millón de tokens de entrada */
	"ai_price_out"         => 0.60,    /* por 1 millón de tokens de salida */
	"wa_price_template"    => 0.02,    /* por mensaje de plantilla */

	/* Cierre de conversación después de una orden */
	"followup_enabled"     => true,
	"followup_ask_min"     => 10,
	"followup_close_min"   => 10,
	"followup_ask_text"    => "¿Te puedo ayudar en algo más? 😊",
	"followup_close_text"  => "¡Gracias por tu preferencia! 🙌 Si necesitas algo más, escríbenos cuando quieras.",

	/* Llave para el cron del hosting (se genera sola) */
	"cron_key"        => "",

	/* General */
	"timezone"        => "America/Lima",
	"currency_code"   => "PEN",
	"currency_symbol" => "S/",
	"debug"           => false,

);

$file = __DIR__."/config.php";

$config = is_file($file) ? (array)(include $file) : array();

$config = array_merge($defaults, $config);

$config["installed"] = is_file($file) && $config["db_name"] !== "" && $config["api_key"] !== "";

return $config;
