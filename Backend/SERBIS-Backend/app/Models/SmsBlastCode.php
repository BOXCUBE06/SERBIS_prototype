<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A singleton: exactly one row, the one shared code every admin enters
 * before sending a text blast. `code_hash` never leaves this class — the
 * panel is shown who last changed it and when, never the code or its hash.
 */
#[Table('tbl_sms_blast_code')]
#[Fillable(['code_hash', 'updated_by'])]
#[Hidden(['code_hash'])]
class SmsBlastCode extends Model
{
    public function updatedByAdmin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by', 'admin_id');
    }
}
