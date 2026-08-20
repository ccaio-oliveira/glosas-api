<?php

use App\Http\Controllers\Admin\ErrorLogController;
use App\Http\Controllers\AppealController;
use App\Http\Controllers\AppealListController;
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

    // ---- Leitura: qualquer usuário da clínica ----
    Route::get('/clinic', [ClinicController::class, 'show']);
    Route::get('/users', [UserController::class, 'index']);
    Route::get('/payers', [PayerController::class, 'index']);
    Route::get('/claims', [ClaimController::class, 'index']);
    Route::get('/claims/{claim}', [ClaimController::class, 'show']);
    Route::get('/tiss-uploads', [TissUploadController::class, 'index']);
    Route::get('/denials/summary', [DenialController::class, 'summary']);
    Route::get('/denials/template-gaps', [DenialController::class, 'templateGaps']);
    Route::get('/denials', [DenialController::class, 'index']);
    Route::get('/denials/{denial}', [DenialController::class, 'show']);
    Route::get('/denials/{denial}/appeal/pdf', [AppealController::class, 'pdf']);
    Route::get('/appeals/summary', [AppealListController::class, 'summary']);
    Route::get('/appeals', [AppealListController::class, 'index']);

    // ---- Operação: owner e biller ----
    Route::middleware('can:operate')->group(function () {
        Route::post('/payers', [PayerController::class, 'store']);
        Route::put('/payers/{clinicPayer}', [PayerController::class, 'update']);
        Route::delete('/payers/{clinicPayer}', [PayerController::class, 'destroy']);

        Route::post('/claims', [ClaimController::class, 'store']);
        Route::put('/claims/{claim}', [ClaimController::class, 'update']);
        Route::delete('/claims/{claim}', [ClaimController::class, 'destroy']);

        Route::post('/tiss-uploads', [TissUploadController::class, 'store']);

        Route::put('/denials/{denial}', [DenialController::class, 'update']);
        Route::post('/denials/{denial}/appeal', [AppealController::class, 'store']);
        Route::post('/denials/{denial}/appeal/generate', [AppealController::class, 'generate']);
        Route::post('/denials/{denial}/appeal/submit', [AppealController::class, 'submit']);
        Route::put('/appeals/{appeal}/status', [AppealListController::class, 'updateStatus']);
    });

    // ---- Administração da clínica: só owner ----
    Route::middleware('can:manage-clinic')->put('/clinic', [ClinicController::class, 'update']);
    Route::middleware('can:manage-billing')->put('/clinic/plan', [ClinicController::class, 'updatePlan']);

    Route::middleware('can:manage-users')->group(function () {
        Route::post('/users', [UserController::class, 'store']);
        Route::put('/users/{user}', [UserController::class, 'update']);
        Route::delete('/users/{user}', [UserController::class, 'destroy']);
    });

    Route::middleware(['auth:sanctum', 'super_admin'])->prefix('admin')->group(function () {
        Route::get('/error-logs/summary', [ErrorLogController::class, 'summary']);
        Route::get('/error-logs', [ErrorLogController::class, 'index']);
        Route::post('error-logs/resolve-group', [ErrorLogController::class, 'resolveGroup']);
        Route::post('/error-logs/{errorLog}/resolve', [ErrorLogController::class, 'resolve']);
        Route::post('/error-logs/{errorLog}/unresolve', [ErrorLogController::class, 'unresolve']);
    });
});
