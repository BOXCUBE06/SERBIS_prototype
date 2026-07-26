<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Traits\TracksHistory;

#[Table('tbl_services', key: 'service_id')]
#[Fillable(['service_name', 'description'])]
class Service extends Model
{
    use HasFactory, TracksHistory;

    protected $ignoreLogging = ['created_at', 'updated_at'];

    public function translations(): HasMany
    {
        return $this->hasMany(ServiceTranslation::class, 'service_id', 'service_id');
    }

    /**
     * The service's name in [$locale], falling back to English and finally to
     * the `service_name` column. The column is the last resort rather than the
     * first because a service added through the admin panel has no translation
     * rows at all, and a blank label is worse than an untranslated one.
     */
    public function nameForLocale(string $locale): string
    {
        $translations = $this->relationLoaded('translations')
            ? $this->translations
            : $this->translations()->get();

        $match = $translations->firstWhere('locale', $locale)
            ?? $translations->firstWhere('locale', ServiceTranslation::fallbackLocale());

        return $match->name ?? $this->service_name;
    }
}