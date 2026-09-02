<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The first API resource in this codebase; everything else still serializes
 * models directly. It was introduced for `name_localized`, a field that had no
 * column behind it, and that field is gone — the app translates service names
 * itself now, off `code`.
 *
 * It stays because `code` is worth stating explicitly: this payload is the one
 * place a client is told which field is safe to key behaviour on, and a raw
 * model serialization would hand back every column with nothing to distinguish
 * the stable identifier from the display text. The collection's `{data}`
 * wrapper is also part of the endpoint's contract now.
 */
class ServiceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'service_id' => $this->service_id,
            // The stable identifier. `service_id` is positional and
            // `service_name` is display text an admin can rewrite, so this is
            // the only field in this payload a client may key behaviour on.
            'code' => $this->code,
            'service_name' => $this->service_name,
            'description' => $this->description,
            'is_active' => $this->is_active,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
