<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Table('tbl_service_translations', key: 'service_translation_id')]
#[Fillable(['service_id', 'locale', 'name'])]
class ServiceTranslation extends Model
{
    use HasFactory;

    /**
     * Deliberately not TracksHistory: this is reference data, and seeding it
     * would write a system-log row per translation with no admin behind it.
     */

    public static function fallbackLocale(): string
    {
        return 'en';
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class, 'service_id', 'service_id');
    }
}
