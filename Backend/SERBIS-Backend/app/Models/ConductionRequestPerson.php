<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConductionRequestPerson extends Model
{
    protected $table = 'tbl_conduction_request_people';

    protected $fillable = [
        'conduction_request_id',
        'role',
        'name',
        'position',
    ];

    public function conductionRequest(): BelongsTo
    {
        return $this->belongsTo(ConductionRequest::class, 'conduction_request_id', 'conduction_request_id');
    }
}
