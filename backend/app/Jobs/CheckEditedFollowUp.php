<?php

namespace App\Jobs;

use App\Services\FollowUp\FollowUpCapabilities;
use App\Services\FollowUp\FollowUpDecisionsService;
use App\Services\FollowUp\FollowUpPrompts;
use App\Services\FollowUp\FollowUpTelegramService;
use App\Support\FollowUpRedactor;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class CheckEditedFollowUp implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(public int $approvalId, public int $revision) {}

    public function handle(FollowUpDecisionsService $decisions, FollowUpTelegramService $telegram): void
    {
        $started = hrtime(true);
        $approval = DB::table('follow_up_approvals')->where('id', $this->approvalId)->first();
        $log = $approval === null ? null : DB::table('lead_recovery_logs')->where('id', $approval->lead_recovery_log_id)->first();
        if (! $this->open($approval, $log, CarbonImmutable::now(FollowUpCapabilities::TIMEZONE)->startOfSecond())) {
            return;
        }

        $lines = json_decode((string) $log->context_redacted);
        $lines = is_array($lines) && count(array_filter($lines, 'is_string')) === count($lines) ? $lines : [];
        $check = $decisions->check($lines, (string) $log->group_code, FollowUpRedactor::redact((string) $approval->text));
        $answers = json_encode([
            'scores' => $check['scores'],
            'failed' => $check['failed'],
            'error' => $check['error'],
            'version' => FollowUpPrompts::CHECK_VERSION,
        ], JSON_UNESCAPED_UNICODE);

        // Model I/O is outside the transaction. Lock in the same order as editReply.
        $now = DB::transaction(function () use ($log, $check, $answers): ?CarbonImmutable {
            $now = CarbonImmutable::now(FollowUpCapabilities::TIMEZONE)->startOfSecond();
            $lockedLog = DB::table('lead_recovery_logs')->where('id', $log->id)->lockForUpdate()->first();
            $lockedApproval = DB::table('follow_up_approvals')->where('id', $this->approvalId)->lockForUpdate()->first();
            if (! $this->open($lockedApproval, $lockedLog, $now)) {
                return null;
            }
            $nowStr = $now->format('Y-m-d H:i:s');
            DB::table('follow_up_approvals')->where('id', $this->approvalId)->update([
                'check_answers' => $answers,
                'updated_at' => $nowStr,
            ]);
            if ($check['cost'] !== null) {
                DB::table('lead_recovery_logs')->where('id', $log->id)->update([
                    'model_cost' => ($lockedLog->model_cost === null ? 0.0 : (float) $lockedLog->model_cost) + $check['cost'],
                    'updated_at' => $nowStr,
                ]);
            }

            return $now;
        });

        if ($now !== null) {
            try {
                $telegram->syncCard($this->approvalId, $now);
            } catch (Throwable $exception) {
                Log::warning('Follow-up edit check repaint failed', [
                    'approval_id' => $this->approvalId,
                    'exception_class' => $exception::class,
                ]);
            }
        }
        Log::info('Follow-up edit check', [
            'approval_id' => $this->approvalId,
            'log_id' => (int) $log->id,
            'revision' => $this->revision,
            'result' => $now === null ? 'dropped' : ($check['error'] ? 'unreadable' : ($check['failed'] === [] ? 'clean' : 'flagged')),
            'milliseconds' => (int) ((hrtime(true) - $started) / 1_000_000),
        ]);
    }

    private function open(?object $approval, ?object $log, CarbonImmutable $now): bool
    {
        return $approval !== null && $log !== null
            && $approval->status === 'pending'
            && $log->delivery_status === 'pending'
            && (int) $approval->revision === $this->revision
            && $approval->check_answers === null
            && (string) $approval->expires_at > $now->format('Y-m-d H:i:s');
    }
}
