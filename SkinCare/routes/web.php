<?php

use Illuminate\Support\Facades\Route;

Route::get('/', static fn () => response()->json([
    'service' => 'Sharafi Skin Care API',
    'status' => 'ok',
]));
