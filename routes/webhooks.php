<?php

use App\Http\Controllers\Webhooks\MetaWebhookController;
use Illuminate\Support\Facades\Route;

// Register this file with NO prefix (the paths below are already complete).
// Registered WITHOUT the "web" group: no session, no CSRF. Authenticity = HMAC signature.
Route::get('/webhooks/meta', [MetaWebhookController::class, 'verify'])->name('webhooks.meta.verify');
Route::post('/webhooks/meta', [MetaWebhookController::class, 'receive'])->name('webhooks.meta.receive');
