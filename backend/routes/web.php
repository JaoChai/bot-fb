<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

/*
|--------------------------------------------------------------------------
| Webhook Routes - MOVED TO api.php
|--------------------------------------------------------------------------
|
| Webhook routes have been moved to routes/api.php so they work with
| custom domain proxy that only routes /api/* paths.
|
| New paths:
| - LINE: POST /api/webhook/{token}
| - Telegram: POST /api/webhook/telegram/{token}
|
*/
