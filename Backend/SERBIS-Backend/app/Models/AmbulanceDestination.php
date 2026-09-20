<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * The ambulance form's destination dropdown (MDRRMO feedback, 2026-09-19) —
 * a short, hand-maintained list, not a synced copy of any external
 * directory. `tbl_ambulance_bookings.destination` stays a free-text column
 * regardless: this table only supplies suggestions, never a foreign key.
 */
#[Table('tbl_ambulance_destinations')]
#[Fillable(['name'])]
class AmbulanceDestination extends Model
{
    //
}
