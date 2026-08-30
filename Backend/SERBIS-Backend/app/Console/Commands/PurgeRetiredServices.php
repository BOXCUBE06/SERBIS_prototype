<?php

namespace App\Console\Commands;

use App\Models\Service;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * One-off cleanup for the three services removed from ServiceSeeder.
 *
 * A seeder change only fixes fresh installs; every database that has already
 * run it still carries the rows. This deletes them, in an order the foreign
 * keys accept, on whichever database the current environment points at.
 *
 * Matched by name, not by id: auto-increment ids differ between the local
 * database and production, and hardcoding them here is the same mistake this
 * cleanup exists to undo. Idempotent -- a second run finds nothing and says so.
 *
 * Deliberately NOT a migration. A migration runs unattended on every deploy;
 * this deletes resident-submitted records and must be run by a person who has
 * read what it is about to remove.
 */
class PurgeRetiredServices extends Command
{
    protected $signature = 'serbis:purge-retired-services {--force : Skip the confirmation prompt}';

    protected $description = 'Delete the three retired services (Flood Evacuation, Fire Rescue, Search and Rescue) and their requests';

    /**
     * Matched on `service_name` rather than `code`, because the panel can
     * rename a service and `code` is generated from the name at creation --
     * so on any database seeded before the code column existed, the name is
     * the only reliable handle.
     */
    private const RETIRED = [
        'Flood Evacuation',
        'Fire Rescue',
        'Search and Rescue',
    ];

    public function handle(): int
    {
        // Always, before anything else. "Nothing to do" is the same sentence
        // whether this ran against production or fell back to the local .env
        // because an environment variable did not take -- and those two mean
        // opposite things. Print the connection so the operator can tell.
        $this->line(sprintf(
            'Connected to %s@%s:%s/%s',
            config('database.connections.'.config('database.default').'.username'),
            config('database.connections.'.config('database.default').'.host'),
            config('database.connections.'.config('database.default').'.port'),
            DB::getDatabaseName(),
        ));
        $this->newLine();

        $services = Service::whereIn('service_name', self::RETIRED)->get();

        if ($services->isEmpty()) {
            $this->info('Nothing to do: none of the three retired services exist on this database.');

            // Diagnostic, read-only. If the three are absent this either
            // already ran, or this is not the database that was meant -- and
            // the catalogue that IS here settles which.
            $present = Service::orderBy('service_id')->pluck('service_name', 'service_id');

            $this->newLine();
            $this->line('Services on this database (' . $present->count() . '):');
            foreach ($present as $id => $name) {
                $this->line("  id={$id}  {$name}");
            }

            return self::SUCCESS;
        }

        $serviceIds = $services->pluck('service_id')->all();
        $requestIds = DB::table('tbl_service_request')
            ->whereIn('service_id', $serviceIds)
            ->pluck('request_id')
            ->all();

        // Pre-flight. tbl_conduction_requests.service_request_id is ON DELETE
        // SET NULL, so a conduction record pointing at a request deleted below
        // would survive with its origin silently blanked -- a trip log that no
        // longer says what it was for. That is a data-loss shape nobody would
        // notice afterwards, so it aborts here instead.
        $conductions = empty($requestIds) ? 0 : DB::table('tbl_conduction_requests')
            ->whereIn('service_request_id', $requestIds)
            ->count();

        if ($conductions > 0) {
            $this->error("ABORTED: {$conductions} conduction request(s) point at requests this would delete.");
            $this->line('The FK is ON DELETE SET NULL, so those trip logs would survive with no origin.');
            $this->line('Decide what happens to them before running this again.');

            return self::FAILURE;
        }

        $logIds = empty($requestIds) ? [] : DB::table('tbl_system_logs')
            ->where('auditable_type', 'like', '%ServiceRequest')
            ->whereIn('auditable_id', $requestIds)
            ->pluck('log_id')
            ->all();

        $files = empty($requestIds) ? [] : DB::table('tbl_service_request')
            ->whereIn('request_id', $requestIds)
            ->get(['valid_id', 'site_photo'])
            ->flatMap(fn ($row) => [$row->valid_id, $row->site_photo])
            ->filter()
            ->values()
            ->all();

        $this->table(['What', 'Rows'], [
            ['services', count($serviceIds)],
            ['service requests', count($requestIds)],
            ['audit log entries', count($logIds)],
            ['uploaded files', count($files)],
        ]);

        foreach ($services as $service) {
            $this->line("  - {$service->service_name} (id {$service->service_id})");
        }

        if (! $this->option('force') && ! $this->confirm('Delete all of the above? This cannot be undone.')) {
            $this->warn('Cancelled. Nothing was deleted.');

            return self::SUCCESS;
        }

        // Rows first, in an order the foreign keys accept: audit entries (no FK,
        // but they reference request ids), then the requests that RESTRICT the
        // services, then the services themselves.
        DB::transaction(function () use ($logIds, $requestIds, $serviceIds) {
            if (! empty($logIds)) {
                DB::table('tbl_system_logs')->whereIn('log_id', $logIds)->delete();
            }

            if (! empty($requestIds)) {
                DB::table('tbl_service_request')->whereIn('request_id', $requestIds)->delete();
            }

            DB::table('tbl_services')->whereIn('service_id', $serviceIds)->delete();
        });

        // Files last, and outside the transaction: object storage does not roll
        // back. Deleting them before the commit would strand a live row whose
        // ID scan is gone, which is the worse of the two failures -- an orphaned
        // object in a bucket costs money, an orphaned row loses evidence.
        $disk = config('filesystems.uploads.private');
        $deleted = 0;

        foreach ($files as $path) {
            try {
                // Storage, not unlink(): production points this disk at the R2
                // bucket, where a filesystem call is a silent no-op. delete()
                // returns true for a path that is already gone, so a re-run and
                // a half-finished earlier run both behave.
                if (Storage::disk($disk)->delete($path)) {
                    $deleted++;
                }
            } catch (\Throwable $e) {
                $this->warn("  could not delete {$path}: {$e->getMessage()}");
            }
        }

        $this->info(sprintf(
            'Deleted %d service(s), %d request(s), %d log entr(ies), %d of %d file(s) on disk "%s".',
            count($serviceIds), count($requestIds), count($logIds), $deleted, count($files), $disk
        ));

        return self::SUCCESS;
    }
}
