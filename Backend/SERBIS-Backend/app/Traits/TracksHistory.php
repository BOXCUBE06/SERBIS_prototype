<?php

namespace App\Traits;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * @method static created(\Closure $callback)
 * @method static updated(\Closure $callback)
 * @method static deleted(\Closure $callback)
 */
trait TracksHistory
{
    public static function bootTracksHistory()
    {
        static::created(function ($model) {
            $model->logAction('created', null, $model->getAttributes());
        });

        static::updated(function ($model) {
            $model->logAction('updated', $model->getOriginal(), $model->getChanges());
        });

        static::deleted(function ($model) {
            $model->logAction('deleted', $model->getAttributes(), null);
        });
    }

   protected function logAction($action, $oldValues, $newValues)
    {
        $ignore = $this->ignoreLogging ?? ['created_at', 'updated_at', 'password', 'remember_token'];

        $filteredOld = $oldValues ? Arr::except($oldValues, $ignore) : null;
        $filteredNew = $newValues ? Arr::except($newValues, $ignore) : null;

        if ($action === 'updated' && empty($filteredNew)) {
            return;
        }

        $user = Auth::user();
        $adminId = null;
        $residentId = null;

        // Check which Model class the logged-in user belongs to
        if ($user) {
            if ($user instanceof \App\Models\User) {
                $adminId = $user->getKey();
            } elseif ($user instanceof \App\Models\Resident) {
                $residentId = $user->getKey();
            }
        }

        DB::table('tbl_system_logs')->insert([
            'admin_id' => $adminId, 
            'resident_id' => $residentId, 
            'action_type' => $action, 
            'auditable_type' => get_class($this),
            'auditable_id' => $this->getKey(),
            'old_values' => $filteredOld ? json_encode($filteredOld) : null,
            'new_values' => $filteredNew ? json_encode($filteredNew) : null,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}