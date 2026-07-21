<?php

use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\ClaimController;
use App\Http\Controllers\PayerController;
use Illuminate\Support\Facades\Route;

Route::post('/login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/user', [AuthController::class, 'me']);

    Route::apiResource('payers', PayerController::class)
        ->only(['index', 'store', 'update', 'destroy'])
        ->parameters(['payers' => 'clinicPayer']);

    Route::apiResource('claims', ClaimController::class);
});
