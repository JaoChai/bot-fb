<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return response()->json([
        'name' => config('app.name'),
        'status' => 'ok',
    ]);
});

/*
|--------------------------------------------------------------------------
| Webhook Routes
|--------------------------------------------------------------------------
|
| Webhook routes are defined in routes/api.php with /api/webhook/* paths.
| All messaging platforms should use the API path format:
|
| - LINE: POST /api/webhook/{token}
| - Telegram: POST /api/webhook/telegram/{token}
| - Facebook: POST /api/webhook/facebook/{token}
|
*/
