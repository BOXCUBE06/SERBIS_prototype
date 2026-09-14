<?php

namespace App\Models;

use App\Traits\TracksHistory;
use Illuminate\Database\Eloquent\Model;

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
        'file_size',
        'verified',
    ];

    // Without this the column comes back as 0/1 and every consumer has to
    // decide for itself what that means.
    protected $casts = [
        'verified' => 'boolean',
    ];

    protected $ignoreLogging = ['created_at', 'updated_at'];
}
