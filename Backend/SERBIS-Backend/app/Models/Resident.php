<?php

namespace App\Models;

use App\Traits\InvalidatesAnalyticsCache;
use App\Traits\TracksHistory; // 1. Import the trait
use Illuminate\Database\Eloquent\Attributes\Appends;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

// `photo` is absent from Fillable and present in Hidden on purpose. It used to
// be a mass-assignable free-form string that the admin panel rendered straight
// into an <img src>, which made it an arbitrary URL fetched by every admin who
// opened the resident list. It is now a storage path on the private disk,
// written only by POST /api/me/photo, and a path handed to a client is a path a
// client can ask for — so clients get `has_photo` and the image itself comes
// from GET /api/residents/{id}/photo.
#[Table('tbl_residents', key: 'resident_id')]
#[Fillable(['barangay_id', 'street_address', 'first_name', 'middle_name', 'last_name', 'phone_number', 'password', 'status', 'sms_opt_in', 'email_address'])]
// There is no verification code on this model. A code is a short-lived
// credential that grants an account, and tbl_residents is what the admin
// panel's Users view serialises — a code in that payload is a code any
// signed-in admin can read off the wire and use. It lives in the cache instead,
// alongside the rest of the pending sign-up; see AuthController::register().
// `email_verified_at` stays visible: the panel shows it as a badge, and it
// discloses nothing. A row only exists once it is set, so it is never null on
// anything registered through the app.
#[Hidden(['password', 'remember_token', 'photo'])]
#[Appends(['has_photo', 'is_email_verified'])]
class Resident extends Authenticatable
{
    // 2. Add TracksHistory to the used traits list
    use HasApiTokens, HasFactory, InvalidatesAnalyticsCache, TracksHistory;

    // 3. Sensitive authentication columns you want to exclude from logging
    protected $ignoreLogging = [
        'created_at',
        'updated_at',
        'password',
        'remember_token',
        // A UUID under a private-disk prefix. It tells an auditor nothing the
        // updated_at does not, and logging it copies a path we keep off every
        // response into a second table.
        'photo',
        // `email_verified_at` is deliberately NOT ignored — an address
        // becoming verified is exactly the change worth recording.
    ];

    /**
     * Without the cast, MySQL hands back 1 and 0 and every client has to guess
     * whether the preference is a number or a boolean. The blast query compares
     * against the column, not this value, so the cast is for the API only.
     */
    protected $casts = [
        'sms_opt_in' => 'boolean',
        'email_verified_at' => 'datetime',
    ];

    /**
     * How long an issued code stays usable, and how long a resident must wait
     * before asking for another. Both are read by AuthController, which owns
     * the codes themselves — they live here because the mail template and the
     * MFA login path need the same two numbers.
     */
    public const CODE_TTL_MINUTES = 15;

    public const RESEND_COOLDOWN_SECONDS = 60;

    /**
     * Mirrors User::isDeactivated() in shape and deliberately not in value.
     *
     * `tbl_user.status` uses 'Inactive' for the closed state. `tbl_residents`
     * does not: here 'Inactive' is a self-registered account waiting for an
     * admin to switch it on, and 'Deactivated' is the one an admin turned off.
     * Comparing against 'inactive' — the obvious way to "mirror" the admin
     * method — would refuse every new sign-up. The two vocabularies overlap in
     * spelling and not in meaning; do not merge them.
     *
     * Not fail-closed, for the same reason the admin method is not: the column
     * is a plain varchar with no default and no constraint, so a row can say
     * 'active' in the wrong case or 'pending'. A `!== 'Active'` test would lock
     * those out with no self-serve way back in. Deactivation is an action
     * someone took; the absence of a recognised status is not.
     *
     * Lowercased because this reads whatever is stored. The write paths do not
     * need it — ResidentController validates `in:Active,Inactive,Deactivated`,
     * so the case is already exact there.
     */
    public function isDeactivated(): bool
    {
        return strtolower((string) $this->status) === 'deactivated';
    }

    public function hasVerifiedEmail(): bool
    {
        return $this->email_verified_at !== null;
    }

    /**
     * Only reached for a row that already exists and has not been claimed: one
     * written before the sign-up flow moved into the cache, or one the admin
     * panel created. A self-registration is inserted with the timestamp already
     * set, because its row is not created until the code comes back.
     */
    public function markEmailAsVerified(): void
    {
        $this->forceFill(['email_verified_at' => now()])->save();
    }

    public function getIsEmailVerifiedAttribute(): bool
    {
        return $this->hasVerifiedEmail();
    }

    /**
     * Whether this resident has uploaded a profile photo. Clients use it to
     * decide between fetching the image and drawing initials, without ever
     * seeing where the file lives.
     */
    public function getHasPhotoAttribute(): bool
    {
        return ! empty($this->photo);
    }

    public function barangay(): BelongsTo
    {
        return $this->belongsTo(Barangay::class, 'barangay_id', 'barangay_id');
    }
}
