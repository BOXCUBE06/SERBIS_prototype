<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BarangayController;
use App\Http\Controllers\EquipmentController;
use App\Http\Controllers\EquipmentBorrowingController;
use App\Http\Controllers\ResidentController;
use App\Http\Controllers\ServiceController;
use App\Http\Controllers\ServiceRequestController;
use App\Http\Controllers\SmsController;
use App\Http\Controllers\SystemLogController;
use App\Http\Controllers\VehicleController;
use App\Http\Controllers\InfoMaterialController;
use App\Http\Controllers\AnalyticsController;

Route::post('/register', [AuthController::class, 'register']);
Route::post('/admin/login', [AuthController::class, 'adminLogin']);
Route::post('/resident/login', [AuthController::class, 'residentLogin']);

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);

    // Endpoints requiring read/write access from the mobile application
    Route::get('barangays', [BarangayController::class, 'index']);
    Route::get('equipments', [EquipmentController::class, 'index']);
    Route::get('services', [ServiceController::class, 'index']);
    Route::apiResource('service-requests', ServiceRequestController::class)->only(['index', 'store', 'show']);
    Route::apiResource('borrowings', EquipmentBorrowingController::class)->only(['index', 'store', 'show']);
    
    // Mobile endpoint to fetch published materials
    Route::get('info-materials', [InfoMaterialController::class, 'index']);

    Route::middleware('is.admin')->group(function () {
        // Administrative Operations
        Route::get('/admin/analytics', [AnalyticsController::class, 'getAdvancedAnalytics']);
        Route::get('/admin/service-requests', [ServiceRequestController::class, 'adminIndex']);
        Route::get('/admin/dashboard', [\App\Http\Controllers\AnalyticsController::class, 'index']);

        // Info Materials Administrative CRUD Routes
        Route::get('/admin/info-materials', [InfoMaterialController::class, 'index']);
        Route::post('/admin/info-materials', [InfoMaterialController::class, 'store']);
        Route::delete('/admin/info-materials/{id}', [InfoMaterialController::class, 'destroy']);

        Route::get('/logs/system', [SystemLogController::class, 'index']);
        
        Route::post('/sms/blast', [SmsController::class, 'sendBlast']);
        Route::apiResource('vehicles', VehicleController::class);
        Route::apiResource('residents', ResidentController::class);

        // Admin-only write access for shared resources
        Route::apiResource('barangays', BarangayController::class)->except(['index', 'show']);
        Route::apiResource('equipments', EquipmentController::class)->except(['index', 'show']);    
        Route::apiResource('services', ServiceController::class)->except(['index', 'show']);
        Route::apiResource('service-requests', ServiceRequestController::class)->only(['update', 'destroy']);
        Route::apiResource('borrowings', EquipmentBorrowingController::class)->only(['update', 'destroy']);
    });
});