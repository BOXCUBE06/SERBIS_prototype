<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Borrowing something the inventory does not list.
 *
 * `equipment_id` was a required FK, so a resident could only ask for an item
 * MDRRMO had already catalogued. Anything else — a item the office owns but
 * never entered, or one they might source — had no way to be asked for at all,
 * and the request was made by phone or not at all.
 *
 * THIS MIGRATION MAKES `equipment_id` NULLABLE. That is the part to be careful
 * about: every consumer that assumed a borrowing always has an equipment row
 * has to handle null, and they are listed in the commit that carries this.
 *
 * Raw ALTERs rather than Blueprint changes, because doctrine/dbal is not
 * installed and `$table->foreignId(...)->nullable()->change()` needs it. The FK
 * is dropped and re-added around the MODIFY for the same reason MySQL requires
 * it: a column under a foreign key cannot have its nullability changed in
 * place. Re-added with the same name and the same ON DELETE CASCADE it had, so
 * the only thing that changes is the NULL.
 *
 * The CHECK constraint is what keeps the pair honest: exactly one of
 * `equipment_id` and `other_equipment_text` is set, never both and never
 * neither. Both would be a request that names an item twice and disagrees with
 * itself; neither would be a request for nothing. MariaDB has enforced CHECK
 * since 10.2.1 and this runs on 10.4, so it is a real constraint and not a
 * comment — the controller's validation is the readable error, and this is the
 * floor under it that a seeder, a tinker session or a future writer cannot get
 * beneath. Every existing row has an equipment_id and a null text, so the
 * constraint validates against current data on creation.
 *
 * `other_equipment_text` is 255 to match `purpose`, which is the other free
 * text a resident writes on this form.
 */
return new class extends Migration
{
    private const CHECK_NAME = 'chk_equipment_borrowing_item_source';
    private const FK_NAME = 'tbl_equipment_borrowing_equipment_id_foreign';

    public function up(): void
    {
        Schema::table('tbl_equipment_borrowing', function (Blueprint $table) {
            $table->string('other_equipment_text', 255)->nullable()->after('equipment_id');
        });

        DB::statement('ALTER TABLE tbl_equipment_borrowing DROP FOREIGN KEY ' . self::FK_NAME);
        DB::statement('ALTER TABLE tbl_equipment_borrowing MODIFY equipment_id BIGINT UNSIGNED NULL');
        DB::statement(
            'ALTER TABLE tbl_equipment_borrowing ADD CONSTRAINT ' . self::FK_NAME
            . ' FOREIGN KEY (equipment_id) REFERENCES tbl_equipments (equipment_id) ON DELETE CASCADE'
        );

        // (a IS NULL) <> (b IS NULL) is exactly XOR over the two nullities:
        // true when one is set and the other is not, false when both are set
        // and false when neither is.
        DB::statement(
            'ALTER TABLE tbl_equipment_borrowing ADD CONSTRAINT ' . self::CHECK_NAME
            . ' CHECK ((equipment_id IS NULL) <> (other_equipment_text IS NULL))'
        );
    }

    /**
     * Refuses rather than destroys.
     *
     * A row whose `equipment_id` is null exists only because this column does.
     * Rolling back cannot represent it: there is no equipment row to point it
     * at and no way to invent one, so restoring NOT NULL would mean deleting a
     * resident's request. The Cancelled-status migration could move its rows to
     * Denied on the way down; there is no equivalent move here, so this stops
     * and says what is in the way instead of guessing.
     */
    public function down(): void
    {
        $stranded = DB::table('tbl_equipment_borrowing')->whereNull('equipment_id')->count();

        if ($stranded > 0) {
            throw new RuntimeException(
                "Cannot roll back: {$stranded} borrowing(s) name an item that is not in the inventory "
                . '(equipment_id is null). Restoring NOT NULL would delete them. Attach a real equipment '
                . 'row to each, or delete them deliberately, then roll back again.'
            );
        }

        DB::statement('ALTER TABLE tbl_equipment_borrowing DROP CONSTRAINT ' . self::CHECK_NAME);
        DB::statement('ALTER TABLE tbl_equipment_borrowing DROP FOREIGN KEY ' . self::FK_NAME);
        DB::statement('ALTER TABLE tbl_equipment_borrowing MODIFY equipment_id BIGINT UNSIGNED NOT NULL');
        DB::statement(
            'ALTER TABLE tbl_equipment_borrowing ADD CONSTRAINT ' . self::FK_NAME
            . ' FOREIGN KEY (equipment_id) REFERENCES tbl_equipments (equipment_id) ON DELETE CASCADE'
        );

        Schema::table('tbl_equipment_borrowing', function (Blueprint $table) {
            $table->dropColumn('other_equipment_text');
        });
    }
};
