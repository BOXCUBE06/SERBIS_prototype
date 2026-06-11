<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BarangayController;
use App\Http\Controllers\ResidentController;
use App\Http\Controllers\ServiceController;
use App\Http\Controllers\ServiceRequestController;

Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);

    Route::get('/user', function (Request $request) {
        return $request->user();
    });

    Route::middleware('is.admin')->group(function () {
        Route::apiResource('barangays', BarangayController::class);
        Route::apiResource('residents', ResidentController::class);
        Route::apiResource('services', ServiceController::class);
        Route::apiResource('service-requests', ServiceRequestController::class)->only(['update', 'destroy']);
    });

    Route::apiResource('service-requests', ServiceRequestController::class)->only(['index', 'store', 'show']);
});
