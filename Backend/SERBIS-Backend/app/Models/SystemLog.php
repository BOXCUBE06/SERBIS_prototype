<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;


class SystemLog extends Model
{
    protected $table = 'tbl_system_logs';
    protected $primaryKey = 'log_id';

    protected $fillable = [
        'admin_id',
        'action_type',
        'auditable_type',
        'auditable_id',
        'old_values',
        'new_values',
        'ip_address',
        'user_agent'
    ];

    // Automatically converts the JSON strings to arrays when retrieved
    protected $casts = [
        'old_values' => 'array',
        'new_values' => 'array',
    ];

    public function admin(): BelongsTo
    {
        // Adjust the second parameter if your User model uses a different primary key
       return $this->belongsTo(User::class, 'admin_id', 'admin_id');
    }
}