<?php

namespace App\Console\Commands;

use App\Models\ServiceRequest;
use App\Support\AnalyticsCache;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Recovers first_responded_at / resolved_at for requests that changed status
 * before those columns existed.
 *
 * The only surviving record of a past transition is tbl_system_logs, written
 * by TracksHistory: old_values holds the full pre-save snapshot and
 * new_values holds just the changed attributes, so a status change is a log
 * row whose new_values carries a `status` key, and its created_at is when it
 * happened.
 *
 * Deliberately a command, not a migration. A migration runs automatically on
 * every boot (docker-entrypoint.sh) and this is a data repair against rows
 * whose history may be partial — it wants to be run deliberately, with its
 * coverage read by a person. Running it is a documented manual step in
 * docs/deploy-railway.md.
 *
 * Idempotent: it only ever fills a column that is currently NULL, and it
 * derives values from log rows that do not change, so a second run writes
 * nothing. Safe to re-run after more history accumulates.
 *
 * Coverage is always partial and the output says so. The audit log begins
 * 2026-08-11, later than the oldest request; a deleted request takes its log
 * rows with it; and a request created already Booked has no first response to
 * find by design. Rows with no usable log keep NULL and are excluded from
 * every chart, which is why the analytics endpoints publish a sample size.
 */
class BackfillRequestTimestamps extends Command
{
    protected $signature = 'serbis:backfill-request-timestamps {--dry-run : Report what would change without writing}';

    protected $description = 'Fill first_responded_at/resolved_at on existing service requests from the audit log';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $requests = DB::table('tbl_service_request')
            ->select('request_id', 'first_responded_at', 'resolved_at')
            ->orderBy('request_id')
            ->get()
            ->keyBy('request_id');

        if ($requests->isEmpty()) {
            $this->info('No service requests. Nothing to do.');

            return self::SUCCESS;
        }

        // Ordered by log_id, not created_at: two transitions can share a
        // timestamp to the second, and the primary key is the only total
        // order the table guarantees. "First" has to mean first.
        $logs = DB::table('tbl_system_logs')
            ->where('auditable_type', ServiceRequest::class)
            ->where('action_type', 'updated')
            ->orderBy('log_id')
            ->get(['auditable_id', 'old_values', 'new_values', 'created_at']);

        $derived = [];

        foreach ($logs as $log) {
            $to = data_get(json_decode((string) $log->new_values, true), 'status');

            if (! is_string($to)) {
                continue;
            }

            $from = data_get(json_decode((string) $log->old_values, true), 'status');
            $id = $log->auditable_id;

            // Same two rules as ServiceRequest::stampLifecycle(), reading the
            // same constants, so the backfill and the live stamping cannot
            // diverge.
            if (! isset($derived[$id]['first_responded_at'])
                && $from === 'Pending'
                && in_array($to, ServiceRequest::RESPONSE_STATUSES, true)
            ) {
                $derived[$id]['first_responded_at'] = $log->created_at;
            }

            if (! isset($derived[$id]['resolved_at'])
                && in_array($to, ServiceRequest::TERMINAL_STATUSES, true)
            ) {
                $derived[$id]['resolved_at'] = $log->created_at;
            }
        }

        $filledResponded = 0;
        $filledResolved = 0;
        $updates = [];

        foreach ($derived as $id => $values) {
            $row = $requests->get($id);

            // A log row can outlive its request — TracksHistory logs the
            // delete too, and PurgeRetiredServices removes requests without
            // removing every log. Skip rather than write a phantom.
            if (! $row) {
                continue;
            }

            $set = [];

            if ($row->first_responded_at === null && isset($values['first_responded_at'])) {
                $set['first_responded_at'] = $values['first_responded_at'];
                $filledResponded++;
            }

            if ($row->resolved_at === null && isset($values['resolved_at'])) {
                $set['resolved_at'] = $values['resolved_at'];
                $filledResolved++;
            }

            if ($set !== []) {
                $updates[$id] = $set;
            }
        }

        if (! $dryRun && $updates !== []) {
            DB::transaction(function () use ($updates) {
                foreach ($updates as $id => $set) {
                    // Raw update on purpose: a model save would fire
                    // TracksHistory and write one audit row per request,
                    // burying the real history this command just read.
                    DB::table('tbl_service_request')->where('request_id', $id)->update($set);
                }
            });

            AnalyticsCache::flush();
        }

        $this->report($requests->count(), $filledResponded, $filledResolved, $dryRun);

        return self::SUCCESS;
    }

    private function report(int $total, int $filledResponded, int $filledResolved, bool $dryRun): void
    {
        // Counted after the write so the numbers describe the table as it now
        // stands, not as the command hoped to leave it.
        $nullResponded = DB::table('tbl_service_request')->whereNull('first_responded_at')->count();
        $nullResolved = DB::table('tbl_service_request')->whereNull('resolved_at')->count();

        $verb = $dryRun ? 'would be filled' : 'filled';

        $this->newLine();
        $this->info($dryRun ? 'Dry run — nothing was written.' : 'Backfill complete.');
        $this->newLine();

        $this->table(
            ['Column', $verb, 'still NULL', 'covered'],
            [
                [
                    'first_responded_at',
                    $filledResponded,
                    $nullResponded,
                    $this->percent($total - $nullResponded, $total),
                ],
                [
                    'resolved_at',
                    $filledResolved,
                    $nullResolved,
                    $this->percent($total - $nullResolved, $total),
                ],
            ]
        );

        $this->line("  {$total} service requests total.");
        $this->newLine();
        $this->comment('A NULL is a real state, not a failure: the audit log starts after the');
        $this->comment('oldest requests, deleted requests take their history with them, and a');
        $this->comment('request created already Booked never had a first response to record.');
        $this->comment('Charts exclude NULLs and publish their sample size.');
    }

    private function percent(int $part, int $whole): string
    {
        if ($whole === 0) {
            return 'n/a';
        }

        return round($part / $whole * 100).'%';
    }
}
