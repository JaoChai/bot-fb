<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * DB-backed per-conversation response lease (Wave 2 #4b). Replaces the 30 s
 * Redis lock on the `ai_response:{id}` fence so the guarantee survives a
 * Redis outage: at most one worker may generate a reply per conversation.
 *
 * Why 210 s: the enforced processing budget is the queue job's
 * `$timeout = 200` s (pcntl SIGALRM kills the worker at that point). A lease
 * at or below that budget could be re-acquired while the first worker is
 * still generating, producing a duplicate reply. 210 s = 200 s budget + 10 s
 * grace for the kill window and small clock skew between app servers.
 */
class ConversationResponseLease
{
    public const TTL_SECONDS = 210;

    /**
     * Claim the lease, or return null when another live holder owns it.
     */
    public function acquire(int $conversationId): ?string
    {
        // Fast rejection without row locks: the common case while a holder is
        // generating. Committed rows are visible across connections; an
        // uncommitted claim (v2 pipeline transaction) instead blocks the
        // transactional re-check below, which is the same fence.
        if ($this->isLive($this->readLease($conversationId))) {
            return null;
        }

        $token = Str::random(40);

        try {
            return DB::transaction(function () use ($conversationId, $token): ?string {
                $lease = $this->readLease($conversationId, lock: true);

                if ($this->isLive($lease)) {
                    return null;
                }

                if ($lease === null) {
                    DB::table('conversation_response_leases')->insert([
                        'conversation_id' => $conversationId,
                        'owner_token' => $token,
                        'lease_until' => now()->addSeconds(self::TTL_SECONDS),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                } else {
                    DB::table('conversation_response_leases')
                        ->where('conversation_id', $conversationId)
                        ->update([
                            'owner_token' => $token,
                            'lease_until' => now()->addSeconds(self::TTL_SECONDS),
                            'updated_at' => now(),
                        ]);
                }

                return $token;
            });
        } catch (UniqueConstraintViolationException) {
            // Two acquirers raced for the first insert: the committed one won.
            return null;
        }
    }

    /**
     * Best-effort release. Never throws: a failed release must not mask the
     * caller's primary exception, and a stuck row self-expires at
     * TTL_SECONDS while hasAlreadyResponded() guards duplicate sends.
     */
    public function release(int $conversationId, string $token): void
    {
        try {
            DB::table('conversation_response_leases')
                ->where('conversation_id', $conversationId)
                ->where('owner_token', $token)
                ->delete();
        } catch (Throwable $e) {
            Log::warning('Response lease release failed; lease will self-expire', [
                'conversation_id' => $conversationId,
                'exception_type' => $e::class,
            ]);
        }
    }

    private function readLease(int $conversationId, bool $lock = false): ?object
    {
        $query = DB::table('conversation_response_leases')
            ->where('conversation_id', $conversationId);

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->first();
    }

    private function isLive(?object $lease): bool
    {
        if ($lease === null) {
            return false;
        }

        $until = $lease->lease_until;

        return $until instanceof Carbon ? $until->isFuture() : Carbon::parse($until)->isFuture();
    }
}
