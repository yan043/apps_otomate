<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\TelegramController;

Route::prefix('telegram')->group(function ()
{
    Route::get('webhookOtomateBot', [TelegramController::class, 'webhookOtomateBot'])->name('telegram.webhookOtomateBot');

    Route::post('otomateBot', [TelegramController::class, 'otomateBot'])->name('telegram.otomateBot');
});
