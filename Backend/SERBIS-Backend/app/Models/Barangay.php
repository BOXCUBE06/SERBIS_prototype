<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Traits\TracksHistory;

#[Table('tbl_barangay', key: 'barangay_id')]
#[Fillable(['barangay_name'])]
class Barangay extends Model
{
    use HasFactory;

    public function residents(): HasMany
    {
        return $this->hasMany(Resident::class, 'barangay_id', 'barangay_id');
    }
}
