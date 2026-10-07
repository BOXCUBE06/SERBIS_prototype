<?php

use App\Models\ServiceRequest;
use Database\Seeders\EquipmentSeeder;
use Database\Seeders\VehicleSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * One-time cleanup of equipment and vehicles not in the MDRRMO's catalogue.
 * The seeders used to do this on every boot; they are create-only now, so a
 * row the office adds in the panel later is never retired by a redeploy.
 *
 * Equipment: an item with an open loan (Pending, Approved, Released) is left
 * as it is; one with only finished loans is marked Unavailable; one with no
 * loans at all is deleted. Never deleted while referenced — the borrowing FK
 * cascades, so a delete would erase loan history.
 *
 * Vehicles: a Dispatched unit, or one with an unfinished service request or a
 * trip not yet back at the office, is left as it is; one with only finished
 * history is set to Maintenance (tbl_vehicles has no Unavailable); one never
 * referenced is deleted.
 *
 * "Wheel Chair" is matched as spelled in EquipmentSeeder; pending the office's
 * confirmation, nothing is renamed.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->retireEquipment();
        $this->retireVehicles();
    }

    private function retireEquipment(): void
    {
        $old = DB::table('tbl_equipments')
            ->whereNotIn('item_name', array_keys(EquipmentSeeder::ITEMS))
            ->pluck('equipment_id');

        foreach ($old as $id) {
            $loans = DB::table('tbl_equipment_borrowing')->where('equipment_id', $id);

            if ((clone $loans)->whereIn('status', ['Pending', 'Approved', 'Released'])->exists()) {
                continue;
            }

            $item = DB::table('tbl_equipments')->where('equipment_id', $id);
            $loans->exists() ? $item->update(['status' => 'Unavailable']) : $item->delete();
        }
    }

    private function retireVehicles(): void
    {
        $old = DB::table('tbl_vehicles')
            ->whereNotIn('unit_identifier', array_keys(VehicleSeeder::units()))
            ->get(['vehicle_id', 'status']);

        foreach ($old as $vehicle) {
            $requests = DB::table('tbl_service_request')->where('vehicle_id', $vehicle->vehicle_id);
            $trips = DB::table('tbl_conduction_requests')->where('vehicle_id', $vehicle->vehicle_id);

            $openWork = $vehicle->status === 'Dispatched'
                || (clone $requests)->whereNotIn('status', ServiceRequest::TERMINAL_STATUSES)->exists()
                || (clone $trips)->whereNull('returned_office_at')->exists();

            if ($openWork) {
                continue;
            }

            $unit = DB::table('tbl_vehicles')->where('vehicle_id', $vehicle->vehicle_id);
            $requests->exists() || $trips->exists() ? $unit->update(['status' => 'Maintenance']) : $unit->delete();
        }
    }

    /** Deleted rows cannot be restored; nothing to undo. */
    public function down(): void {}
};
