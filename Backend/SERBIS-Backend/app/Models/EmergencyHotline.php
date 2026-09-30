<?php

namespace App\Models;

use App\Traits\TracksHistory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;

#[Table('tbl_emergency_hotlines', key: 'hotline_id')]
#[Fillable(['label', 'label_fil', 'numbers', 'sort_order'])]
class EmergencyHotline extends Model
{
    use TracksHistory;

    protected $ignoreLogging = ['created_at', 'updated_at'];

    protected $casts = [
        'numbers' => 'array',
        'sort_order' => 'integer',
    ];
}
