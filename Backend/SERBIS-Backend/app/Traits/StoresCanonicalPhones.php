<?php

namespace App\Traits;

use App\Support\PhoneNumber;

/**
 * Phone columns are stored as +639XXXXXXXXX whichever way they were typed, and
 * serialised as 09XXXXXXXXX. The model lists them in `$phoneColumns`; this is
 * the one place that converts, so no write path or API response has to.
 *
 * A value that is not a dialable number (a landline, "See resident profile")
 * is stored and shown as given.
 */
trait StoresCanonicalPhones
{
    public function setAttribute($key, $value)
    {
        if (is_string($value) && in_array($key, $this->phoneColumns ?? [], true)) {
            $canonical = PhoneNumber::normalize($value);
            $value = $canonical !== '' ? $canonical : $value;
        }

        return parent::setAttribute($key, $value);
    }

    public function attributesToArray()
    {
        $attributes = parent::attributesToArray();

        foreach ($this->phoneColumns ?? [] as $column) {
            if (isset($attributes[$column]) && is_string($attributes[$column])) {
                $attributes[$column] = PhoneNumber::display($attributes[$column]);
            }
        }

        return $attributes;
    }
}
