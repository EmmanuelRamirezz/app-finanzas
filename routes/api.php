<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\WhatsAppController;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::get('/whatsapp-webhook', [WhatsAppController::class, 'verifyWebhook']);
Route::post('/whatsapp-webhook', [WhatsAppController::class, 'receiveMessage']);
