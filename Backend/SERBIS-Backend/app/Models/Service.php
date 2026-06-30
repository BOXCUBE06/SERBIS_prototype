<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Traits\TracksHistory;

#[Table('tbl_services', key: 'service_id')]
#[Fillable(['service_name', 'description'])]
class Service extends Model
{
    use HasFactory, TracksHistory;

    protected $ignoreLogging = ['created_at', 'updated_at'];
}