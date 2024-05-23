<?php

use Illuminate\Support\Facades\Route;


Route::post('/alipay/webhook', [App\Extensions\Gateways\AliPay\AliPay::class, 'webhook']);
Route::get('/alipay/redirect', [App\Extensions\Gateways\AliPay\AliPay::class, 'redirect']);
