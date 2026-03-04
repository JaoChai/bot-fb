<?php

namespace App\Services;

use App\Models\Bot;
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class AutoAssignmentService
{
    /**
     * Assignment modes
     */
    public const MODE_ROUND_ROBIN = 'round_robin';

    public const MODE_LOAD_BALANCED = 'load_balanced';

    /**
     * Attempt to auto-assign a conversation to an admin.
     * Returns the assigned user or null if assignment is disabled/failed.
     */
    public function assignConversation(Bot $bot, Conversation $conversation): ?User
    {
        $settings = $bot->settings;

        // Check if auto-assignment is enabled
        if (! $settings?->auto_assignment_enabled) {
            return null;
        }

        // Get available admins (including bot owner)
        $admins = $this->getAvailableAdmins($bot);

        if ($admins->isEmpty()) {
            Log::info('Auto-assignment: no available admins', [
                'bot_id' => $bot->id,
                'conversation_id' => $conversation->id,
            ]);

            return null;
        }

        // Select admin based on mode
        $mode = $settings->auto_assignment_mode ?? self::MODE_LOAD_BALANCED;
        $selectedAdmin = match ($mode) {
            self::MODE_ROUND_ROBIN => $this->roundRobinAssign($bot, $admins),
            self::MODE_LOAD_BALANCED => $this->loadBalancedAssign($bot, $admins),
            default => $this->loadBalancedAssign($bot, $admins),
        };

        if ($selectedAdmin) {
            Log::info('Auto-assignment: assigned conversation', [
                'bot_id' => $bot->id,
                'conversation_id' => $conversation->id,
                'assigned_to' => $selectedAdmin->id,
                'mode' => $mode,
            ]);
        }

        return $selectedAdmin;
    }

    /**
     * Load balanced assignment: assign to admin with least active conversations.
     */
    private function loadBalancedAssign(Bot $bot, Collection $admins): User
    {
        return $admins->sortBy(function ($admin) use ($bot) {
            return $this->getActiveConversationCount($bot, $admin);
        })->first();
    }

    /**
     * Round robin assignment: rotate through admins.
     */
    private function roundRobinAssign(Bot $bot, Collection $admins): User
    {
        // Get the last assigned admin from recent conversations
        $lastAssignedUserId = $bot->conversations()
            ->whereNotNull('assigned_user_id')
            ->latest('updated_at')
            ->value('assigned_user_id');

        $adminIds = $admins->pluck('id')->values()->toArray();

        // Find next admin in rotation
        if ($lastAssignedUserId !== null) {
            $lastIndex = array_search($lastAssignedUserId, $adminIds);
            if ($lastIndex !== false) {
                $nextIndex = ($lastIndex + 1) % count($adminIds);

                return $admins->firstWhere('id', $adminIds[$nextIndex]);
            }
        }

        // Default to first admin if no previous assignment found
        return $admins->first();
    }

    /**
     * Get all available admins for a bot (owner + assigned admins).
     */
    private function getAvailableAdmins(Bot $bot): Collection
    {
        $admins = collect();

        // Add bot owner
        if ($bot->user) {
            $admins->push($bot->user);
        }

        // Add assigned admins
        $assignedAdmins = $bot->admins()->get();
        foreach ($assignedAdmins as $admin) {
            if (! $admins->contains('id', $admin->id)) {
                $admins->push($admin);
            }
        }

        return $admins;
    }

    /**
     * Get count of active/handover conversations for an admin.
     */
    private function getActiveConversationCount(Bot $bot, User $admin): int
    {
        return $bot->conversations()
            ->where('assigned_user_id', $admin->id)
            ->whereIn('status', ['active', 'handover'])
            ->count();
    }
}
