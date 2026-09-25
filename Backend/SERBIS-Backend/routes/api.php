<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\AmbulanceAvailabilityController;
use App\Http\Controllers\AmbulanceDestinationController;
use App\Http\Controllers\AnalyticsController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BarangayController;
use App\Http\Controllers\ConductionRequestController;
use App\Http\Controllers\DeviceTokenController;
use App\Http\Controllers\EquipmentBorrowingController;
use App\Http\Controllers\EquipmentController;
use App\Http\Controllers\ExportLogController;
use App\Http\Controllers\InfoMaterialController;
use App\Http\Controllers\PasswordResetController;
use App\Http\Controllers\PhoneChangeController;
use App\Http\Controllers\ProcurementReferenceController;
use App\Http\Controllers\ResidentController;
use App\Http\Controllers\ResponderController;
use App\Http\Controllers\ServiceAudienceController;
use App\Http\Controllers\ServiceController;
use App\Http\Controllers\ServiceRequestController;
use App\Http\Controllers\ServiceVehicleTypeController;
use App\Http\Controllers\SmsController;
use App\Http\Controllers\SmsDeliveryController;
use App\Http\Controllers\SystemLogController;
use App\Http\Controllers\VehicleController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// Public + resident/shared-authenticated routes: the 'api' limiter (60/min,
// keyed on the authenticated user or IP — AppServiceProvider.php). The
// is.admin group below is a sibling of this, not nested inside it, so it can
// carry 'admin-api' instead without stacking both limiters on one request.
Route::middleware('throttle:api')->group(function () {
    Route::post('/admin/login', [AuthController::class, 'adminLogin'])->middleware('throttle:login');
    Route::post('/resident/login', [AuthController::class, 'residentLogin'])->middleware('throttle:login');
    // Second half of each login: password already checked, an MFA code is what's
    // left. These carry a challenge_id, not an email_address, so they get their
    // own 'mfa' limiter (keyed on challenge_id) rather than 'login' — see the
    // comment on RateLimiter::for('mfa', ...) in AppServiceProvider.
    Route::post('/admin/login/verify', [AuthController::class, 'adminLoginVerify'])->middleware('throttle:mfa');
    Route::post('/resident/login/verify', [AuthController::class, 'residentLoginVerify'])->middleware('throttle:mfa');
    Route::post('/resident/login/resend', [AuthController::class, 'resendLoginCode'])->middleware('throttle:mfa');
    // Resident sign-up for the mobile app. Its own 'register' limiter: every
    // registration is a new number, so a limiter keyed on the number would
    // never repeat.
    Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:register');
    // Second half of registration, by phone. Both share the 'login' limiter,
    // which keys on the submitted number: verify is a guessing target (a million
    // codes, six digits) and resend sends a billed text. The per-sign-up cooldown
    // in resendVerificationCode is the other half of that — the limiter bounds
    // one caller, the cooldown bounds one number.
    Route::post('/resident/verify-phone', [AuthController::class, 'verifyPhone'])->middleware('throttle:login');
    Route::post('/resident/verify-phone/resend', [AuthController::class, 'resendVerificationCode'])->middleware('throttle:login');
    // Forgotten password, by text. Public, and keyed on the phone number by the
    // 'password-reset' limiter so a number with an account and one without are
    // limited — and answered — the same way.
    Route::post('/resident/password/forgot', [PasswordResetController::class, 'forgot'])->middleware('throttle:password-reset');
    Route::post('/resident/password/verify', [PasswordResetController::class, 'verify'])->middleware('throttle:password-reset');
    Route::post('/resident/password/reset', [PasswordResetController::class, 'reset'])->middleware('throttle:password-reset');
    // The email routes the app before phone login called. Answer 410 with an
    // "update the app" message in both languages; remove in a later release.
    Route::post('/resident/verify-email', [AuthController::class, 'emailVerificationRemoved']);
    Route::post('/resident/verify-email/resend', [AuthController::class, 'emailVerificationRemoved']);
    // Public on purpose: the mobile register screen must show a barangay picker
    // before the resident has an account, and barangay_id is required to sign up.
    // The row is nothing but an id and a name, and the write routes stay admin-only.
    Route::get('barangays', [BarangayController::class, 'index']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::get('/me', [AuthController::class, 'me']);
        // Outside is.admin on purpose: a staff account holding a temporary
        // password reaches nothing else until it has used this (IsAdmin). Named
        // limiter, per the 'sms-blast' note in AppServiceProvider.
        Route::post('/admin/change-password', [AuthController::class, 'adminChangePassword'])
            ->middleware('throttle:password-change');
        // Resident-scoped profile edit. Cannot touch barangay_id, status or role —
        // see the controller for why each one is excluded.
        Route::patch('/me', [AuthController::class, 'updateMe']);
        // Moving the phone number, which is the login: the current password and
        // a code texted to the NEW number, in two steps. Limited per resident —
        // every step but the last sends a billed text or guesses a code.
        Route::post('/me/phone', [PhoneChangeController::class, 'start'])->middleware('throttle:phone-change');
        Route::post('/me/phone/resend', [PhoneChangeController::class, 'resend'])->middleware('throttle:phone-change');
        Route::post('/me/phone/verify', [PhoneChangeController::class, 'verify'])->middleware('throttle:phone-change');
        // The resident's own profile photo. Kept off PATCH /me because it is a
        // multipart upload, not a column the resident types into.
        Route::post('/me/photo', [ResidentController::class, 'uploadMyPhoto']);
        Route::delete('/me/photo', [ResidentController::class, 'deleteMyPhoto']);
        // Read is wider than write: staff render one photo per row in the resident
        // list, so this sits outside the is.admin group and does its own check.
        // Registered before the admin apiResource so it is never shadowed by it.
        // Staff need it on every page that shows a person, so it takes any of
        // those sections; a resident is not staff and passes `section` untouched.
        Route::get('residents/{id}/photo', [ResidentController::class, 'photo'])
            ->middleware('section:residents,requests,ambulance,borrowings');

        // Endpoints requiring read/write access from the mobile application
        Route::get('equipments', [EquipmentController::class, 'index']);
        Route::get('services', [ServiceController::class, 'index']);
        // The ambulance form's destination dropdown (MDRRMO feedback,
        // 2026-09-19). Read-only — see AmbulanceDestinationSeeder.
        Route::get('ambulance-destinations', [AmbulanceDestinationController::class, 'index']);
        // The routes the mobile app and the panel both use. `section` only ever
        // restricts staff here (see EnsureSection): a resident is scoped to their
        // own rows by the controller, as before. Which of the two sections an
        // admin holds also decides which rows they see — see ServiceRequestController.
        Route::apiResource('service-requests', ServiceRequestController::class)->only(['index', 'store', 'show'])
            ->middleware('section:requests,ambulance');
        Route::get('service-requests/{id}/valid-id', [ServiceRequestController::class, 'validId'])
            ->middleware('section:requests,ambulance');
        Route::get('service-requests/{id}/site-photo', [ServiceRequestController::class, 'sitePhoto'])
            ->middleware('section:requests,ambulance');
        Route::get('service-requests/{id}/letter', [ServiceRequestController::class, 'letter'])
            ->middleware('section:requests,ambulance');
        // What the MDRRMO has texted to this resident's barangay. Scoped to blasts
        // they were actually a recipient of, not to their barangay membership.
        Route::get('advisories', [SmsController::class, 'advisories']);
        // Shared between the resident booking picker and the admin calendar —
        // both need "which ambulances are free", neither gets patient details.
        Route::get('ambulance-availability', [AmbulanceAvailabilityController::class, 'index']);
        // Owner-scoped cancel. The general update() stays admin-only below.
        Route::patch('service-requests/{id}/cancel', [ServiceRequestController::class, 'cancel'])
            ->middleware('section:requests,ambulance');
        // Owner-scoped, same shape as the service-request cancel above. Registered
        // before the apiResource so the literal segment is never read as an {id}.
        Route::patch('borrowings/{id}/cancel', [EquipmentBorrowingController::class, 'cancel'])
            ->middleware('section:borrowings');
        // Read is wider than write: the upload sits in the admin group below,
        // because staff take the photo, but the borrower can read their own back —
        // evidence only one side of a dispute can see is not evidence.
        // scopeToOwner() inside does the narrowing. Registered before the
        // apiResource for the same reason cancel is.
        Route::get('borrowings/{id}/photo/{stage}', [EquipmentBorrowingController::class, 'photo'])
            ->middleware('section:borrowings');
        Route::apiResource('borrowings', EquipmentBorrowingController::class)->only(['index', 'store', 'show'])
            ->middleware('section:borrowings');

        // Mobile endpoint to fetch published materials
        Route::get('info-materials', [InfoMaterialController::class, 'index']);

        // Push notification device registration — upsert by token, so login and
        // a later refresh both hit the same endpoint.
        Route::post('device-tokens', [DeviceTokenController::class, 'store']);
        Route::delete('device-tokens', [DeviceTokenController::class, 'destroy']);
    });
});

// Admin panel routes. A sibling of the throttle:api group above, not nested
// inside it — 'admin-api' (300/min per admin_id, AppServiceProvider.php)
// applies instead of 'api', not on top of it. See the P1 rate-limit audit,
// 2026-09-15.
//
// Middleware order matters here and is not the array order below on its
// own: Laravel priority-sorts 'auth:sanctum' (Authenticate, implements
// AuthenticatesRequests, priority index 5) ahead of 'throttle:admin-api'
// (ThrottleRequests, index 6) regardless of how they're listed —
// vendor/laravel/framework/.../Foundation/Http/Kernel.php:103-115 for the
// priority list, vendor/.../Routing/SortedMiddleware.php:33-64 for the sort
// itself. 'is.admin' (App\Http\Middleware\IsAdmin) isn't in that priority
// list at all, so SortedMiddleware never moves it — it keeps its literal
// position in this array, between the two. Net effect: auth:sanctum runs
// first, then is.admin, then throttle:admin-api — a non-admin is rejected
// (403) before the admin-api limiter's closure ever sees the request. See
// the comment on RateLimiter::for('admin-api', ...) for why its key
// function is still written defensively despite that.
Route::middleware(['auth:sanctum', 'is.admin', 'throttle:admin-api'])->group(function () {
    // Administrative Operations
    // GET /admin/analytics used to be registered here against
    // AnalyticsController::getAdvancedAnalytics, a method that does not
    // exist and never did — the route 500'd on any request. No client ever
    // called it; /admin/dashboard below is the panel's analytics source.
    //
    // Every route below sits under a `section:` middleware (EnsureSection): the
    // admin panel's sections, one per sidebar item, each granted per account by
    // a super admin. See App\Support\AdminSections for the keys. A route that
    // two pages need lists both, and is open to whoever holds either.

    // Both boards read and write service requests through the same routes, so a
    // route can only ask for "either". Which rows a caller may see, and which
    // records they may change, is decided per request by the controller from the
    // section their service belongs to (ServiceRequestController).
    Route::middleware('section:requests,ambulance')->group(function () {
        Route::get('/admin/service-requests', [ServiceRequestController::class, 'adminIndex']);
        // Walk-in requests, filed by staff at the counter — separate from the
        // resident-facing POST /service-requests above.
        Route::post('/admin/service-requests', [ServiceRequestController::class, 'adminStore']);
        Route::apiResource('service-requests', ServiceRequestController::class)->only(['update', 'destroy']);
        // Their own routes, not update(): both re-check ambulance availability
        // under a lock, which update()/syncFleet() were never built to do.
        Route::patch('service-requests/{id}/approve', [ServiceRequestController::class, 'approve']);
        Route::patch('service-requests/{id}/reschedule', [ServiceRequestController::class, 'reschedule']);
        Route::patch('service-requests/{id}/responders', [ServiceRequestController::class, 'assignResponders']);
    });

    Route::get('/admin/dashboard', [AnalyticsController::class, 'index'])->middleware('section:dashboard');
    // The retrospective page. This path existed once against a method that
    // never did and 500'd on every call; it now points at real code. Same
    // admin-only group as the dashboard.
    Route::get('/admin/analytics', [AnalyticsController::class, 'report'])->middleware('section:analytics');
    Route::get('/admin/analytics/barangays', [AnalyticsController::class, 'barangays'])->middleware('section:analytics');

    // The panel prints and exports in the browser; these only record that it
    // happened. One route per record type so each is gated by the section that
    // owns the list, and throttled per account (the 'export-log' limiter).
    foreach (['request' => 'requests', 'booking' => 'ambulance', 'trip' => 'ambulance', 'borrowing' => 'borrowings', 'vehicle' => 'vehicles'] as $type => $section) {
        Route::post("/admin/export-logs/{$type}", [ExportLogController::class, 'store'])
            ->defaults('type', $type)
            ->middleware(["section:{$section}", 'throttle:export-log']);
    }

    // Info Materials Administrative CRUD Routes
    Route::middleware('section:files')->group(function () {
        Route::get('/admin/info-materials', [InfoMaterialController::class, 'index']);
        Route::post('/admin/info-materials', [InfoMaterialController::class, 'store']);
        // Admin-only, unlike the read above: residents see the flag, only the
        // office sets it.
        Route::patch('/admin/info-materials/{id}/verify', [InfoMaterialController::class, 'verify']);
        Route::delete('/admin/info-materials/{id}', [InfoMaterialController::class, 'destroy']);
    });

    Route::middleware('section:logs')->group(function () {
        Route::get('/logs/system', [SystemLogController::class, 'index']);
        // The Logs page's second tab. It had been fetching this since the page
        // was written; the route simply never existed. It lives in SmsController
        // but belongs to Activity Logs, not to Text Blast.
        Route::get('/logs/sms', [SmsController::class, 'history']);
    });

    Route::middleware('section:sms')->group(function () {
        // The only endpoint that spends money: SkySMS bills per credit and has no
        // sandbox, so a repeated submit is real pesos, not a retry. 3/hour per admin.
        Route::post('/sms/blast', [SmsController::class, 'sendBlast'])->middleware('throttle:sms-blast');
        // The shared 6-digit code that gates a blast, on top of holding this
        // section: knowing the code is what distinguishes "may send" from "may
        // open the page". Rotating requires the current code, so both share
        // sendBlast's rate limit — see SmsController::assertCurrentCode().
        Route::get('/sms/blast-code', [SmsController::class, 'blastCodeStatus']);
        Route::post('/sms/blast-code', [SmsController::class, 'rotateBlastCode']);
        // Read-only and unbilled — but it is still an outbound vendor call on
        // every visit to the page, not free.
        Route::get('/sms/balance', [SmsController::class, 'balance'])->middleware('throttle:30,1');
        // Fires on every change to the barangay picker, so it is allowed to run
        // far more often than the send it previews. Touches only the local
        // database; no vendor call, nothing billed.
        Route::get('/sms/recipient-count', [SmsController::class, 'recipientCount'])->middleware('throttle:120,1');
        // What became of a blast after SkySMS queued it. The list is local; the
        // check reads SkySMS's GET /sms/messages, which is unbilled but is an
        // outbound call on a rate limit the sends share, so it is capped per admin
        // and the controller answers a repeat press from what it already stored.
        Route::get('/sms/deliveries', [SmsDeliveryController::class, 'index'])->middleware('throttle:60,1');
        Route::post('/sms/deliveries/{smsLog}/check', [SmsDeliveryController::class, 'check'])->middleware('throttle:20,1');
    });

    // The fleet list is read by the two boards that dispatch a unit as well as by
    // the Vehicles page; only that page may change it.
    Route::apiResource('vehicles', VehicleController::class)->only(['index', 'show'])
        ->middleware('section:vehicles,requests,ambulance');
    Route::apiResource('vehicles', VehicleController::class)->except(['index', 'show'])
        ->middleware('section:vehicles');

    // Read-only, for the resident detail panel. Registered before the
    // apiResource so the literal segment is never read as another {id} action.
    Route::get('residents/{id}/return-history', [ResidentController::class, 'returnHistory'])
        ->middleware('section:residents');
    // Just id, name and barangay, for the request boards' walk-in picker. The
    // literal segment goes before the apiResource so `lookup` is never read as
    // a resident id. The full directory below stays with the Residents page.
    Route::get('residents/lookup', [ResidentController::class, 'lookup'])
        ->middleware('section:residents,requests,ambulance');
    Route::apiResource('residents', ResidentController::class)->middleware('section:residents');

    // MDRRMO staff accounts (audit #29). Super admins only: the page creates
    // accounts, resets passwords and edits who may open what, so `staff` is not
    // a section that can be handed out (AdminSections::ASSIGNABLE). The refusals
    // in the controller still apply to them.
    Route::middleware('section:staff')->group(function () {
        Route::apiResource('admins', AdminController::class);
        // Registered after the resource so `admins/{id}` never shadows it.
        Route::patch('admins/{id}/reactivate', [AdminController::class, 'reactivate']);
        // Temporary password for a colleague who cannot sign in; refused for
        // yourself. See AdminController::resetPassword().
        Route::post('admins/{id}/reset-password', [AdminController::class, 'resetPassword']);
        // Which sections an account may open, and whether it is a super admin.
        Route::put('admins/{id}/permissions', [AdminController::class, 'updatePermissions']);
    });

    // Admin-only write access for shared resources. The reads stay open to every
    // signed-in user above; only the writes are a section.
    Route::apiResource('barangays', BarangayController::class)->except(['index', 'show'])
        ->middleware('section:residents');
    Route::apiResource('equipments', EquipmentController::class)->except(['index', 'show'])
        ->middleware('section:inventory');
    Route::apiResource('services', ServiceController::class)->except(['index', 'show'])
        ->middleware('section:services');
    // Which account types may request each service. Keyed on the service code,
    // and includes the two entries that are not services (equipment-borrowing,
    // others) — see the tbl_service_audience migration.
    Route::middleware('section:service_audience')->group(function () {
        Route::get('service-audience', [ServiceAudienceController::class, 'index']);
        Route::put('service-audience/{code}', [ServiceAudienceController::class, 'update']);
    });
    // Which kinds of unit may be sent on each service. The dispatch picker reads
    // it and ServiceRequestController::update enforces it — see the
    // tbl_service_vehicle_types migration. Read by the boards too; written only
    // from its own page.
    Route::get('service-vehicle-types', [ServiceVehicleTypeController::class, 'index'])
        ->middleware('section:service_vehicles,requests,ambulance');
    Route::put('service-vehicle-types/{code}', [ServiceVehicleTypeController::class, 'update'])
        ->middleware('section:service_vehicles');

    Route::middleware('section:borrowings')->group(function () {
        // Its own route rather than a field on update(): update() takes JSON
        // and a file needs multipart, so folding it in would make every status
        // change carry a multipart encoder for a field it never sends.
        Route::post('borrowings/{id}/photo', [EquipmentBorrowingController::class, 'uploadPhoto']);
        // Removal is admin-only and stage-gated more tightly than the upload —
        // see PHOTO_STAGES. The matching GET sits outside this group, because
        // the borrower reads their own photos back.
        Route::delete('borrowings/{id}/photo/{stage}', [EquipmentBorrowingController::class, 'destroyPhoto']);
        // update() only. `destroy` was in this list with no destroy() on the
        // controller behind it, so DELETE /borrowings/{id} was a live 500, and
        // it is not implemented rather than fixed: a borrowing is a ledger row
        // — it moved stock, it may carry handover photographs, and it is the
        // only record of who held an item and when. The endings it needs
        // already exist and all of them keep the row (Denied, Cancelled,
        // Returned), so nothing in the panel or the app has ever called this.
        Route::apiResource('borrowings', EquipmentBorrowingController::class)->only(['update']);
    });

    // The uncatalogued borrow requests, and nothing else about borrowings. Its
    // own route so Procurement does not need the borrowings list (and the
    // borrower details on it) to draw a page of item names.
    Route::get('procurement/other-equipment', [ProcurementReferenceController::class, 'index'])
        ->middleware('section:procurement');

    Route::middleware('section:responders')->group(function () {
        Route::apiResource('responders', ResponderController::class);
        Route::post('responders/{id}/photo', [ResponderController::class, 'uploadPhoto']);
        Route::delete('responders/{id}/photo', [ResponderController::class, 'deletePhoto']);
    });

    // MDRRMO Conduction Request Form (Echague Rescue EMS). Filed and
    // tracked entirely by staff — there is no resident-facing route, the
    // same way tbl_vehicles has none.
    Route::middleware('section:ambulance')->group(function () {
        Route::apiResource('conduction-requests', ConductionRequestController::class)->only(['index', 'store', 'show']);
        Route::patch('conduction-requests/{id}/trip-log', [ConductionRequestController::class, 'tripLog']);
        Route::get('conduction-requests/{id}/print', [ConductionRequestController::class, 'print']);
    });
});
