<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AdminController;
use App\Http\Controllers\AmbulanceAvailabilityController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BarangayController;
use App\Http\Controllers\ConductionRequestController;
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
// Second half of registration. Both share the 'login' limiter: verify is a
// guessing target (a million codes, six digits) and resend sends real mail.
// The per-account cooldown in resendVerificationCode is the other half of that
// — the limiter bounds one caller, the cooldown bounds one account.
Route::post('/resident/verify-email', [AuthController::class, 'verifyEmail'])->middleware('throttle:login');
Route::post('/resident/verify-email/resend', [AuthController::class, 'resendVerificationCode'])->middleware('throttle:login');
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
    // The resident's own profile photo. Kept off PATCH /me because it is a
    // multipart upload, not a column the resident types into.
    Route::post('/me/photo', [ResidentController::class, 'uploadMyPhoto']);
    Route::delete('/me/photo', [ResidentController::class, 'deleteMyPhoto']);
    // Read is wider than write: staff render one photo per row in the resident
    // list, so this sits outside the is.admin group and does its own check.
    // Registered before the admin apiResource so it is never shadowed by it.
    Route::get('residents/{id}/photo', [ResidentController::class, 'photo']);

    // Endpoints requiring read/write access from the mobile application
    Route::get('equipments', [EquipmentController::class, 'index']);
    Route::get('services', [ServiceController::class, 'index']);
    Route::apiResource('service-requests', ServiceRequestController::class)->only(['index', 'store', 'show']);
    Route::get('service-requests/{id}/valid-id', [ServiceRequestController::class, 'validId']);
    Route::get('service-requests/{id}/site-photo', [ServiceRequestController::class, 'sitePhoto']);
    // What the MDRRMO has texted to this resident's barangay. Scoped to blasts
    // they were actually a recipient of, not to their barangay membership.
    Route::get('advisories', [SmsController::class, 'advisories']);
    // Shared between the resident booking picker and the admin calendar —
    // both need "which ambulances are free", neither gets patient details.
    Route::get('ambulance-availability', [AmbulanceAvailabilityController::class, 'index']);
    // Owner-scoped cancel. The general update() stays admin-only below.
    Route::patch('service-requests/{id}/cancel', [ServiceRequestController::class, 'cancel']);
    Route::apiResource('borrowings', EquipmentBorrowingController::class)->only(['index', 'store', 'show']);
    
    // Mobile endpoint to fetch published materials
    Route::get('info-materials', [InfoMaterialController::class, 'index']);

    Route::middleware('is.admin')->group(function () {
        // Administrative Operations
        // GET /admin/analytics used to be registered here against
        // AnalyticsController::getAdvancedAnalytics, a method that does not
        // exist and never did — the route 500'd on any request. No client ever
        // called it; /admin/dashboard below is the panel's analytics source.
        Route::get('/admin/service-requests', [ServiceRequestController::class, 'adminIndex']);
        // Walk-in requests, filed by staff at the counter — separate from the
        // resident-facing POST /service-requests above.
        Route::post('/admin/service-requests', [ServiceRequestController::class, 'adminStore']);
        Route::get('/admin/dashboard', [\App\Http\Controllers\AnalyticsController::class, 'index']);

        // Info Materials Administrative CRUD Routes
        Route::get('/admin/info-materials', [InfoMaterialController::class, 'index']);
        Route::post('/admin/info-materials', [InfoMaterialController::class, 'store']);
        Route::delete('/admin/info-materials/{id}', [InfoMaterialController::class, 'destroy']);

        Route::get('/logs/system', [SystemLogController::class, 'index']);
        // The Logs page's second tab. It had been fetching this since the page
        // was written; the route simply never existed.
        Route::get('/logs/sms', [SmsController::class, 'history']);
        
        Route::post('/sms/blast', [SmsController::class, 'sendBlast'])->middleware('throttle:3,60');
        Route::apiResource('vehicles', VehicleController::class);
        Route::apiResource('residents', ResidentController::class);
        // MDRRMO staff accounts (audit #29). Every admin may manage every other
        // — see the controller for why there is no super-admin tier, and for
        // the two deletions it refuses.
        Route::apiResource('admins', AdminController::class);
        // Registered after the resource so `admins/{id}` never shadows it.
        Route::patch('admins/{id}/reactivate', [AdminController::class, 'reactivate']);

        // Admin-only write access for shared resources
        Route::apiResource('barangays', BarangayController::class)->except(['index', 'show']);
        Route::apiResource('equipments', EquipmentController::class)->except(['index', 'show']);    
        Route::apiResource('services', ServiceController::class)->except(['index', 'show']);
        Route::apiResource('service-requests', ServiceRequestController::class)->only(['update', 'destroy']);
        Route::apiResource('borrowings', EquipmentBorrowingController::class)->only(['update', 'destroy']);

        // MDRRMO Conduction Request Form (Echague Rescue EMS). Filed and
        // tracked entirely by staff — there is no resident-facing route, the
        // same way tbl_vehicles has none.
        Route::apiResource('conduction-requests', ConductionRequestController::class)->only(['index', 'store', 'show']);
        Route::patch('conduction-requests/{id}/trip-log', [ConductionRequestController::class, 'tripLog']);
    });
});