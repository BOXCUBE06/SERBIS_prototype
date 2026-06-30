<?php

// App\Models\Equipment.php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Traits\TracksHistory;

class Equipment extends Model
{
    protected $table = 'tbl_equipments';
    protected $primaryKey = 'equipment_id';

    protected $fillable = [
        'item_name',
        'total_quantity',
        'available_quantity',
        'status'
    ];
}