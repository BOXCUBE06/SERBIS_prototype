<?php

namespace App\Models;

use App\Traits\TracksHistory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * One row: this account type may request this service. See the migration for
 * why it is keyed on the service code and what an empty set means.
 */
#[Table('tbl_service_audience', key: 'audience_id')]
#[Fillable(['service_code', 'account_type'])]
class ServiceAudience extends Model
{
    use TracksHistory;

    /** Equipment borrowing is its own flow, not a tbl_services row. */
    public const EQUIPMENT_BORROWING = 'equipment-borrowing';

    /** "Others": a request with no service_id. */
    public const OTHERS = 'others';

    /** The two codes that are not tbl_services rows, with their display names. */
    public const PSEUDO_SERVICES = [
        self::EQUIPMENT_BORROWING => 'Equipment Borrowing',
        self::OTHERS => 'Others',
    ];

    protected $ignoreLogging = ['created_at', 'updated_at'];

    /**
     * The account types allowed to request [code]. A code with no rows is open
     * to every type — see the migration.
     *
     * @return list<string>
     */
    public static function typesFor(string $code): array
    {
        $types = static::where('service_code', $code)->pluck('account_type')->all();

        return $types === [] ? Resident::ACCOUNT_TYPES : array_values($types);
    }

    public static function allows(string $code, string $accountType): bool
    {
        return in_array($accountType, static::typesFor($code), true);
    }
}
