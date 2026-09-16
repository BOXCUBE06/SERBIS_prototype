<?php

// App\Models\Equipment.php

namespace App\Models;

use App\Traits\InvalidatesAnalyticsCache;
use App\Traits\TracksHistory;
use Illuminate\Database\Eloquent\Model;

class Equipment extends Model
{
    use InvalidatesAnalyticsCache, TracksHistory;

    protected $table = 'tbl_equipments';

    protected $primaryKey = 'equipment_id';

    protected $ignoreLogging = ['created_at', 'updated_at'];

    protected $fillable = [
        'item_name',
        'total_quantity',
        'available_quantity',
        'status',
    ];
}
