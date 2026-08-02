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

Route::post('/admin/login', [AuthController::class, 'adminLogin'])->middleware('throttle:login');
Route::post('/resident/login', [AuthController::class, 'residentLogin'])->middleware('throttle:login');
// Resident sign-up for the mobile app. Shares the 'login' limiter, which keys on
// the submitted email address as well as the IP.
Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:login');
// Public on purpose: the mobile register screen must show a barangay picker
// before the resident has an account, and barangay_id is required to sign up.
// The row is nothing but an id and a name, and the write routes stay admin-only.
Route::get('barangays', [BarangayController::class, 'index']);

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);
    // Resident-scoped profile edit. Cannot touch barangay_id, status or role —
    // see the controller for why each one is excluded.
    Route::patch('/me', [AuthController::class, 'updateMe']);

    // Endpoints requiring read/write access from the mobile application
    Route::get('equipments', [EquipmentController::class, 'index']);
    Route::get('services', [ServiceController::class, 'index']);
    Route::apiResource('service-requests', ServiceRequestController::class)->only(['index', 'store', 'show']);
    Route::get('service-requests/{id}/valid-id', [ServiceRequestController::class, 'validId']);
    Route::get('service-requests/{id}/site-photo', [ServiceRequestController::class, 'sitePhoto']);
    // What the MDRRMO has texted to this resident's barangay. Scoped to blasts
    // they were actually a recipient of, not to their barangay membership.
    Route::get('advisories', [SmsController::class, 'advisories']);
    // Owner-scoped cancel. The general update() stays admin-only below.
    Route::patch('service-requests/{id}/cancel', [ServiceRequestController::class, 'cancel']);
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
        
        Route::post('/sms/blast', [SmsController::class, 'sendBlast'])->middleware('throttle:3,60');
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