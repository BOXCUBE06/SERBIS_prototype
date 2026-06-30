<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Traits\TracksHistory;

class InfoMaterial extends Model
{
    use TracksHistory;

    protected $table = 'tbl_info_materials';
    protected $primaryKey = 'files_id';

    protected $fillable = [
        'uploader_id',
        'title',
        'file_path',
        'file_type',
        'file_size'
    ];

    protected $ignoreLogging = ['created_at', 'updated_at'];
}