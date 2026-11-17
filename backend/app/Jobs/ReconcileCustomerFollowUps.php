<?php

namespace App\Jobs;

use App\Services\FollowUp\FollowUpCapabilities;
use App\Services\FollowUp\FollowUpOutcomeService;
use App\Services\FollowUp\FollowUpPipeline;
use App\Services\FollowUp\FollowUpTelegramService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class ReconcileCustomerFollowUps implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 60;

    public function handle(FollowUpTelegramService $telegram, FollowUpOutcomeService $outcomes): void
    {
        $started = hrtime(true);
        $now = CarbonImmutable::now(FollowUpCapabilities::TIMEZONE)->startOfSecond();
        $nowStr = $now->format('Y-m-d H:i:s');

        // X: Close both undecided cards and approvals that never reached a first push.
        $cardsExpired = 0;
        $cards = DB::table('follow_up_approvals as a')
            ->join('lead_recovery_logs as l', 'l.id', '=', 'a.lead_recovery_log_id')
            ->where('a.expires_at', '<=', $nowStr)
            ->where(function ($pairs): void {
                $pairs->where(function ($pending): void {
                    $pending->where('a.status', 'pending')->where('l.delivery_status', 'pending');
                })->orWhere(function ($approved): void {
                    $approved->where('a.status', 'approved')->where('l.delivery_status', 'approved')->whereNull('l.first_push_at');
                });
            })
            ->orderBy('a.id')
            ->limit(50)
            ->get(['a.id', 'a.lead_recovery_log_id']);

        foreach ($cards as $card) {
            $approvalId = (int) $card->id;
            try {
                $written = DB::transaction(function () use ($card, $approvalId, $nowStr): bool {
                    // Keep the sender/tap lock order: log first, approval second.
                    $log = DB::table('lead_recovery_logs')->where('id', $card->lead_recovery_log_id)->lockForUpdate()->first();
                    $approval = DB::table('follow_up_approvals')->where('id', $approvalId)->lockForUpdate()->first();
                    if ($log === null || $approval === null || (int) $approval->lead_recovery_log_id !== (int) $log->id) {
                        return false;
                    }
                    $pending = $approval->status === 'pending' && $log->delivery_status === 'pending';
                    $approved = $approval->status === 'approved' && $log->delivery_status === 'approved' && $log->first_push_at === null;
                    if ((! $pending && ! $approved) || (string) $approval->expires_at > $nowStr) {
                        return false;
                    }

                    $changes = [
                        'delivery_status' => 'expired',
                        'reason_code' => $pending ? 'expired_unanswered' : 'expired_before_send',
                        'updated_at' => $nowStr,
                    ];
                    if ($pending) {
                        $changes['owner_action'] = 'expired';
                        $changes['owner_action_at'] = $nowStr;
                    }
                    DB::table('lead_recovery_logs')->where('id', $log->id)->update($changes);
                    DB::table('follow_up_approvals')->where('id', $approvalId)->update([
                        'status' => 'expired',
                        'updated_at' => $nowStr,
                    ]);

                    return true;
                });
            } catch (Throwable $exception) {
                Log::warning('Follow-up reconcile card failed', [
                    'approval_id' => $approvalId,
                    'exception_class' => $exception::class,
                ]);

                continue;
            }
            if (! $written) {
                continue;
            }
            $cardsExpired++;

            // The rows are already committed. Repainting is best effort, never a retry.
            try {
                $telegram->refreshCard($approvalId, $now);
            } catch (Throwable $exception) {
                Log::warning('Follow-up reconcile repaint failed', [
                    'approval_id' => $approvalId,
                    'exception_class' => $exception::class,
                ]);
            }
        }

        // Y: Stop obsolete pre-draft work and release only reservations with no draft.
        $waitingClosed = 0;
        $ageCutoff = $now->subDays(7)->format('Y-m-d H:i:s');
        $staleCutoff = $now->subMinutes(FollowUpPipeline::STALE_CLAIM_MINUTES)->format('Y-m-d H:i:s');
        $waiting = DB::table('lead_recovery_logs')
            ->where('created_at', '<', $ageCutoff)
            ->where(function ($statuses) use ($staleCutoff): void {
                $statuses->whereIn('delivery_status', ['candidate', 'sort_retry', 'deferred'])
                    ->orWhere(function ($stale) use ($staleCutoff): void {
                        $stale->whereIn('delivery_status', ['sorting', 'drafting'])->where('updated_at', '<=', $staleCutoff);
                    });
            })
            ->orderBy('id')
            ->limit(100)
            ->get(['id']);

        foreach ($waiting as $candidate) {
            $logId = (int) $candidate->id;
            try {
                $written = DB::transaction(function () use ($logId, $ageCutoff, $staleCutoff, $nowStr): bool {
                    $row = DB::table('lead_recovery_logs')->where('id', $logId)->lockForUpdate()->first();
                    if ($row === null) {
                        return false;
                    }
                    $idle = in_array($row->delivery_status, ['candidate', 'sort_retry', 'deferred'], true);
                    $stale = in_array($row->delivery_status, ['sorting', 'drafting'], true)
                        && $row->updated_at !== null && $row->updated_at <= $staleCutoff;
                    if ($row->created_at === null || $row->created_at >= $ageCutoff || (! $idle && ! $stale)) {
                        return false;
                    }
                    $changes = [
                        'delivery_status' => 'excluded',
                        'reason_code' => 'waited_too_long',
                        'next_attempt_at' => null,
                        'claim_token' => null,
                        'claimed_at' => null,
                        'updated_at' => $nowStr,
                    ];
                    if ($row->drafted_at === null) {
                        $changes['draft_reserved_at'] = null;
                        $changes['draft_day'] = null;
                    }
                    DB::table('lead_recovery_logs')->where('id', $logId)->update($changes);

                    return true;
                });
            } catch (Throwable $exception) {
                Log::warning('Follow-up reconcile waiting failed', [
                    'log_id' => $logId,
                    'exception_class' => $exception::class,
                ]);

                continue;
            }
            if ($written) {
                $waitingClosed++;
            }
        }

        // Z: Outcomes must not prevent either closing step from running.
        $outcomesFailed = false;
        try {
            $outcomes->reconcile($now);
        } catch (Throwable $exception) {
            $outcomesFailed = true;
            Log::warning('Follow-up reconcile outcomes failed', [
                'exception_class' => $exception::class,
            ]);
        }

        if ($cardsExpired > 0 || $waitingClosed > 0 || $outcomesFailed) {
            Log::info('Follow-up reconcile', [
                'cards_expired' => $cardsExpired,
                'waiting_closed' => $waitingClosed,
                'outcomes_failed' => $outcomesFailed,
                'milliseconds' => (int) ((hrtime(true) - $started) / 1_000_000),
            ]);
        }
    }
}
