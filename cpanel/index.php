<?php

/*
| cPanel front controller. Upload THIS file as public_html/fcom-manager/index.php (replacing public/index.php).
| The application itself lives outside the web root; change APP_ROOT only if you move it.
*/

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

const APP_ROOT = '/home/sheracar/Fcom-manager';

if (file_exists($maintenance = APP_ROOT.'/storage/framework/maintenance.php')) {
    require $maintenance;
}

require APP_ROOT.'/vendor/autoload.php';

/** @var Application $app */
$app = require_once APP_ROOT.'/bootstrap/app.php';

// public/ is not inside the app folder here: build/, sw.js, offline.html and the storage link live next to this file.
$app->usePublicPath(__DIR__);

$app->handleRequest(Request::capture());
