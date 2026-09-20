<?php

namespace App\Models;

use App\Traits\InvalidatesAnalyticsCache;
use App\Traits\TracksHistory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

#[Table('tbl_services', key: 'service_id')]
#[Fillable(['service_name', 'description', 'category', 'is_active'])]
class Service extends Model
{
    use HasFactory, InvalidatesAnalyticsCache, TracksHistory;

    /**
     * What kind of service this is, set in Manage Services. It groups the list
     * and decides which services the admin queue treats as programs (a plain
     * approve, no dispatch). Never derived from the name.
     */
    public const CATEGORIES = ['rescue', 'medical', 'relief', 'infrastructure', 'programs'];

    protected $ignoreLogging = ['created_at', 'updated_at'];

    protected $casts = [
        'is_active' => 'boolean',
    ];

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
                'Cannot derive a service code from "'.$name.'": it has no alphanumeric characters.'
            );
        }

        return $code;
    }
}
