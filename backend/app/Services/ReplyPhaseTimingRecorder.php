<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Best-effort writer for reply_phase_timings rows (Wave 2 · plan #6).
 *
 * Callers invoke this AFTER the reply was already sent, so even on the slowest
 * storage the customer's reply is never delayed; and any failure (table
 * missing, DB blip) is swallowed — a timing write must never break a reply
 * and must never depend on Redis.
 */
class ReplyPhaseTimingRecorder
{
    public function write(ReplyPhaseTimingCollector $collector, array $row): void
    {
        try {
            DB::table('reply_phase_timings')->insert($row);
        } catch (\Throwable $e) {
            Log::warning('Reply phase timing row not written', [
                'bot_id' => $row['bot_id'] ?? null,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
