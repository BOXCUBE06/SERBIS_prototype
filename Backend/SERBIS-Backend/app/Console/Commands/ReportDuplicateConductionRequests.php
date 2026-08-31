<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Read-only. Written for the prod duplicate check ahead of the
 * tbl_conduction_requests.service_request_id unique index — run this from
 * the Render shell against Aiven before that migration ships, since local
 * dev data cannot stand in for it (its own copy, per serbis-environment).
 *
 * Lists every service_request_id with more than one conduction request
 * filed against it. Nothing here writes.
 */
class ReportDuplicateConductionRequests extends Command
{
    protected $signature = 'serbis:report-duplicate-conduction-requests';

    protected $description = 'List service_request_id values with more than one conduction request filed against them';

    public function handle(): int
    {
        $this->line(sprintf(
            'Connected to %s@%s:%s/%s',
            config('database.connections.'.config('database.default').'.username'),
            config('database.connections.'.config('database.default').'.host'),
            config('database.connections.'.config('database.default').'.port'),
            DB::getDatabaseName(),
        ));
        $this->newLine();

        $duplicates = DB::table('tbl_conduction_requests')
            ->whereNotNull('service_request_id')
            ->select('service_request_id', DB::raw('COUNT(*) as trip_count'))
            ->groupBy('service_request_id')
            ->having('trip_count', '>', 1)
            ->orderByDesc('trip_count')
            ->get();

        if ($duplicates->isEmpty()) {
            $this->info('No duplicates: every service_request_id has at most one conduction request.');
            $this->line('Safe to run the unique index migration.');

            return self::SUCCESS;
        }

        $this->warn("{$duplicates->count()} service_request_id(s) have more than one conduction request:");
        $this->newLine();

        foreach ($duplicates as $row) {
            $tripIds = DB::table('tbl_conduction_requests')
                ->where('service_request_id', $row->service_request_id)
                ->orderBy('conduction_request_id')
                ->pluck('conduction_request_id')
                ->implode(', ');

            $this->line("  service_request_id={$row->service_request_id}  trip_count={$row->trip_count}  conduction_request_ids=[{$tripIds}]");
        }

        $this->newLine();
        $this->error('Do not run the unique index migration until these are resolved (keep one, decide what happens to the rest).');

        return self::FAILURE;
    }
}
