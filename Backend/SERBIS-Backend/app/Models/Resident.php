<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Appends;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\HasApiTokens;
use App\Traits\TracksHistory; // 1. Import the trait

// `photo` is absent from Fillable and present in Hidden on purpose. It used to
// be a mass-assignable free-form string that the admin panel rendered straight
// into an <img src>, which made it an arbitrary URL fetched by every admin who
// opened the resident list. It is now a storage path on the private disk,
// written only by POST /api/me/photo, and a path handed to a client is a path a
// client can ask for — so clients get `has_photo` and the image itself comes
// from GET /api/residents/{id}/photo.
#[Table('tbl_residents', key: 'resident_id')]
#[Fillable(['barangay_id', 'first_name', 'middle_name', 'last_name', 'phone_number', 'password', 'status', 'sms_opt_in', 'email_address'])]
// The three verification_* columns are Hidden and not Fillable. The code is a
// short-lived credential that grants an account, and tbl_residents is what the
// admin panel's Users view serialises — a code in that payload is a code any
// signed-in admin can read off the wire and use. `email_verified_at` stays
// visible: the panel shows it as a badge, and it discloses nothing.
#[Hidden(['password', 'remember_token', 'photo', 'verification_code', 'verification_code_expires_at', 'verification_code_sent_at'])]
#[Appends(['has_photo', 'is_email_verified'])]
class Resident extends Authenticatable
{
    // 2. Add TracksHistory to the used traits list
    use HasApiTokens, HasFactory, TracksHistory;

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
        // A code hash plus its clock. Logging them copies a credential into a
        // second table and fills the Logs page with a row every time anyone
        // taps resend. `email_verified_at` is deliberately NOT here — an
        // address becoming verified is exactly the change worth recording.
        'verification_code',
        'verification_code_expires_at',
        'verification_code_sent_at',
    ];

    /**
     * Without the cast, MySQL hands back 1 and 0 and every client has to guess
     * whether the preference is a number or a boolean. The blast query compares
     * against the column, not this value, so the cast is for the API only.
     */
    protected $casts = [
        'sms_opt_in' => 'boolean',
        'email_verified_at' => 'datetime',
        'verification_code_expires_at' => 'datetime',
        'verification_code_sent_at' => 'datetime',
    ];

    /** How long an issued code stays usable. */
    public const CODE_TTL_MINUTES = 15;

    /** How long a resident must wait before asking for another code. */
    public const RESEND_COOLDOWN_SECONDS = 60;

    public function hasVerifiedEmail(): bool
    {
        return $this->email_verified_at !== null;
    }

    /**
     * Issues a fresh six-digit code and returns the plain text one, which is the
     * only moment it exists in readable form. Stored hashed, so a leaked database
     * row cannot be turned back into a working code.
     *
     * Issuing replaces any outstanding code rather than adding a second valid
     * one — otherwise every resend widens the window instead of moving it.
     */
    public function issueVerificationCode(): string
    {
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        $this->forceFill([
            'verification_code' => Hash::make($code),
            'verification_code_expires_at' => now()->addMinutes(self::CODE_TTL_MINUTES),
            'verification_code_sent_at' => now(),
        ])->save();

        return $code;
    }

    public function verificationCodeMatches(string $code): bool
    {
        if ($this->verification_code === null || $this->verification_code_expires_at === null) {
            return false;
        }

        if ($this->verification_code_expires_at->isPast()) {
            return false;
        }

        return Hash::check($code, $this->verification_code);
    }

    /**
     * Clears the code as it marks the address verified. Leaving a spent code in
     * the row means a second use of the same digits still matches until it
     * happens to expire.
     */
    public function markEmailAsVerified(): void
    {
        $this->forceFill([
            'email_verified_at' => now(),
            'verification_code' => null,
            'verification_code_expires_at' => null,
            'verification_code_sent_at' => null,
        ])->save();
    }

    public function secondsUntilResendAllowed(): int
    {
        if ($this->verification_code_sent_at === null) {
            return 0;
        }

        $ready = $this->verification_code_sent_at->addSeconds(self::RESEND_COOLDOWN_SECONDS);

        return $ready->isFuture() ? now()->diffInSeconds($ready) : 0;
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