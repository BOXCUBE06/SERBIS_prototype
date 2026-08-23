<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Traits\TracksHistory;
use RuntimeException;

#[Table('tbl_services', key: 'service_id')]
#[Fillable(['service_name', 'description'])]
class Service extends Model
{
    use HasFactory, TracksHistory;

    protected $ignoreLogging = ['created_at', 'updated_at'];

    /**
     * `code` is the service's stable identifier and is deliberately absent from
     * #[Fillable] above, so neither Service::create($validated) nor
     * $service->update($validated) can set it however the request was shaped.
     * ServiceController's rules omit it as well; the two together are what make
     * renaming a service leave its code alone.
     */
    protected static function booted(): void
    {
        static::creating(function (Service $service): void {
            if (is_string($service->code) && $service->code !== '') {
                return;
            }

            $service->code = self::slugify($service->service_name ?? '');
        });
    }

    /**
     * Lowercase, every run of non-alphanumerics collapsed to one hyphen, no
     * leading or trailing hyphen. "Ambulance/Medical Response" becomes
     * "ambulance-medical-response".
     *
     * Only ever consulted at creation. A name that slugifies to nothing throws
     * rather than storing an empty code, and a name that slugifies onto an
     * existing code is rejected by the unique index — a second service called
     * "Road Clearing" is a naming problem for a person to resolve, not
     * something to paper over with a numeric suffix that would then be
     * permanent.
     */
    public static function slugify(string $name): string
    {
        $code = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($name)), '-');

        if ($code === '') {
            throw new RuntimeException(
                'Cannot derive a service code from "' . $name . '": it has no alphanumeric characters.'
            );
        }

        return $code;
    }

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
        $match = $this->translationFor($locale);

        return $match->name ?? $this->service_name;
    }

    /**
     * Resolved separately from the name: `description` is nullable, so a locale
     * can carry a translated name and no blurb. Each field falls back on its
     * own rather than forcing the pair to come from the same row.
     */
    public function descriptionForLocale(string $locale): ?string
    {
        $match = $this->translationFor($locale);

        if ($match !== null && $match->description !== null && $match->description !== '') {
            return $match->description;
        }

        $english = $this->translationFor(ServiceTranslation::fallbackLocale());

        if ($english !== null && $english->description !== null && $english->description !== '') {
            return $english->description;
        }

        return $this->description;
    }

    private function translationFor(string $locale): ?ServiceTranslation
    {
        $translations = $this->relationLoaded('translations')
            ? $this->translations
            : $this->translations()->get();

        return $translations->firstWhere('locale', $locale)
            ?? $translations->firstWhere('locale', ServiceTranslation::fallbackLocale());
    }
}