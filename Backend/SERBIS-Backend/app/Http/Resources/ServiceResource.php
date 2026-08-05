<?php

namespace App\Http\Resources;

use App\Models\ServiceTranslation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The first API resource in this codebase; everything else still serializes
 * models directly. Introduced because `name_localized` is not a column — it
 * depends on the caller's `?locale=`, so it cannot come from the model's
 * default serialization.
 *
 * `service_name` is still emitted unchanged. Older clients read it, and it is
 * the untranslated name an admin sees in the panel.
 */
class ServiceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $locale = self::localeFrom($request);

        return [
            'service_id' => $this->service_id,
            'service_name' => $this->service_name,
            'name_localized' => $this->nameForLocale($locale),
            'description' => $this->description,
            'description_localized' => $this->descriptionForLocale($locale),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }

    /**
     * Reads `?locale=`, defaulting to English. Anything that is not a plausible
     * language subtag is ignored rather than rejected: a bad locale should give
     * the resident an English label, not a 422 in front of an emergency form.
     */
    public static function localeFrom(Request $request): string
    {
        $locale = $request->query('locale');

        if (!is_string($locale) || !preg_match('/^[A-Za-z]{2,3}(-[A-Za-z0-9]{2,8})?$/', $locale)) {
            return ServiceTranslation::fallbackLocale();
        }

        return $locale;
    }
}
