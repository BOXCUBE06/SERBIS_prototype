<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Laravel\Sanctum\HasApiTokens;

#[Table('tbl_residents', key: 'resident_id')]
#[Fillable(['barangay_id', 'first_name', 'middle_name', 'last_name', 'phone_number', 'password', 'photo', 'status', 'email_address', 'otp', 'otp_verified_at'])]
class Resident extends Authenticatable
{
    use HasApiTokens, HasFactory;

    public function barangay(): BelongsTo
    {
        return $this->belongsTo(Barangay::class, 'barangay_id', 'barangay_id');
    }
}