<?php

/*=============================================
Después de subir un zip nuevo: abre
https://tu-dominio/actualizar.php
Limpia la caché de PHP (OPcache) para que el
servidor use el código nuevo y muestra la versión.
No muestra datos del negocio.
=============================================*/

$reset = function_exists("opcache_reset") ? @opcache_reset() : null;
$build = trim((string)@file_get_contents(__DIR__."/build.txt")) ?: "sin versión";

@file_put_contents(__DIR__."/logs/build.applied", $build);

header("Content-Type: text/html; charset=utf-8");
header("Cache-Control: no-store");

?><!doctype html>
<html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Actualizar</title>
<style>body{font-family:system-ui,sans-serif;background:#f5f6f8;color:#222;display:flex;min-height:100vh;align-items:center;justify-content:center;margin:0;padding:16px}div{background:#fff;border-radius:12px;padding:28px;max-width:420px;box-shadow:0 2px 12px rgba(0,0,0,.08)}h1{font-size:20px;margin:0 0 12px}p{margin:6px 0}b{color:#0a7}</style>
</head><body><div>
<h1>✅ Sistema actualizado</h1>
<p>Versión instalada: <b><?php echo htmlspecialchars($build) ?></b></p>
<p>Caché de PHP: <?php echo $reset === true ? "limpiada" : ($reset === null ? "no está activa (no hace falta)" : "no se pudo limpiar: reinicia PHP desde cPanel") ?>.</p>
<p>PHP <?php echo PHP_VERSION ?> · <?php echo date("Y-m-d H:i:s") ?></p>
</div></body></html>
