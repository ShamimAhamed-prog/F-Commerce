<?php

/*
| Where the app lives on the web server. This is the ONLY place the sub-folder is decided.
|   local / own domain:      APP_BASE_PATH=            (empty)
|   cPanel sub-folder:       APP_BASE_PATH=/fcom-manager
| Vite (vite.config.ts) reads the same variable at build time; the service worker derives it from its own scope.
*/
$base = trim((string) env('APP_BASE_PATH', ''), " /");

return [
    'base_path' => $base === '' ? '' : '/'.$base,
];
