<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Appends;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
#[Hidden(['password', 'remember_token', 'photo'])]
#[Appends(['has_photo'])]
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
    ];

    /**
     * Without the cast, MySQL hands back 1 and 0 and every client has to guess
     * whether the preference is a number or a boolean. The blast query compares
     * against the column, not this value, so the cast is for the API only.
     */
    protected $casts = [
        'sms_opt_in' => 'boolean',
    ];

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