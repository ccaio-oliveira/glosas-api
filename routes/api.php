<?php

use App\Http\Controllers\AppealController;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\ClaimController;
use App\Http\Controllers\ClinicController;
use App\Http\Controllers\DenialController;
use App\Http\Controllers\PayerController;
use App\Http\Controllers\TissUploadController;
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

    Route::get('/tiss-uploads', [TissUploadController::class, 'index']);
    Route::post('/tiss-uploads', [TissUploadController::class, 'store']);

    Route::get('/denials/summary', [DenialController::class, 'summary']);
    Route::get('/denials', [DenialController::class, 'index']);
    Route::get('/denials/{denial}', [DenialController::class, 'show']);
    Route::put('/denials/{denial}', [DenialController::class, 'update']);
    Route::post('/denials/{denial}/appeal', [AppealController::class, 'store']);
    Route::post('/denials/{denial}/appeal/submit', [AppealController::class, 'submit']);
});
