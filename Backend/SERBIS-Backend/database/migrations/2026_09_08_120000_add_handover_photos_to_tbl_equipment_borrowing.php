<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the item looked like when it left, and when it came back.
 *
 * A borrowing recorded `released_at` and `returned_at` and nothing about
 * condition, so a chainsaw that came back with a cracked housing and one that
 * came back fine were the same two timestamps. There was no way to settle it
 * afterwards, and nothing for staff to point at.
 *
 * One photo per stage, both nullable, both optional. A missing photo never
 * blocks a release or a return: the office releases equipment in conditions
 * where stopping to photograph it is the wrong advice, and a hard requirement
 * would be answered with a photo of the floor. The same reasoning as
 * `site_photo` on tbl_service_request.
 *
 * These hold disk-relative paths on the PRIVATE disk, never URLs. A handover
 * photo shows a named resident's item and often their doorway, and it is
 * evidence in a dispute between them and the office — so it is served through
 * a controller that checks who is asking, exactly like `valid_id` and
 * `site_photo`, and never by a guessable public link. See the column comments
 * in EquipmentBorrowingController::uploadPhoto() for why these two are
 * deliberately NOT in the model's $fillable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tbl_equipment_borrowing', function (Blueprint $table) {
            $table->string('release_photo_path', 255)->nullable()->after('released_at');
            $table->string('return_photo_path', 255)->nullable()->after('returned_at');
        });
    }

    public function down(): void
    {
        // Drops the columns and orphans whatever they pointed at on disk. That
        // is deliberate: deleting a resident's handover evidence because a
        // migration was rolled back would be the worse mistake, and an orphaned
        // file under storage/app/private costs nothing but space.
        Schema::table('tbl_equipment_borrowing', function (Blueprint $table) {
            $table->dropColumn(['release_photo_path', 'return_photo_path']);
        });
    }
};
