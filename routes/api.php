<?php

use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\ClaimController;
use App\Http\Controllers\ClinicController;
use App\Http\Controllers\PayerController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

Route::post('/login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/user', [AuthController::class, 'me']);

    Route::get('/clinic', [ClinicController::class, 'show']);
    Route::put('/clinic', [ClinicController::class, 'update']);
    Route::put('/clinic/plan', [ClinicController::class, 'updatePlan']);

    Route::apiResource('users', UserController::class)->only(['index', 'store', 'update', 'destroy']);

    Route::apiResource('payers', PayerController::class)
        ->only(['index', 'store', 'update', 'destroy'])
        ->parameters(['payers' => 'clinicPayer']);

    Route::apiResource('claims', ClaimController::class);
});
