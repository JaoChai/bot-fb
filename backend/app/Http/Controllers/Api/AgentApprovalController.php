<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AgentSafetyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * AgentApprovalController
 *
 * Handles Human-in-the-Loop (HITL) approval requests
 * for dangerous agent actions.
 */
class AgentApprovalController extends Controller
{
    public function __construct(
        protected AgentSafetyService $safetyService
    ) {}

    /**
     * Get pending approval details.
     */
    public function show(Request $request, string $approvalId): JsonResponse
    {
        $approval = $this->safetyService->getApproval($approvalId);

        if (! $approval) {
            return response()->json([
                'error' => 'Approval not found or expired',
            ], 404);
        }

        return response()->json([
            'data' => $approval,
        ]);
    }

    /**
     * Approve an action.
     */
    public function approve(Request $request, string $approvalId): JsonResponse
    {
        $request->validate([
            'reason' => 'nullable|string|max:500',
        ]);

        $user = $request->user();
        $success = $this->safetyService->approve(
            $approvalId,
            $user->id,
            $request->input('reason')
        );

        if (! $success) {
            return response()->json([
                'error' => 'Cannot approve: approval not found, expired, or already responded',
            ], 400);
        }

        return response()->json([
            'message' => 'Action approved',
            'approval_id' => $approvalId,
        ]);
    }

    /**
     * Reject an action.
     */
    public function reject(Request $request, string $approvalId): JsonResponse
    {
        $request->validate([
            'reason' => 'nullable|string|max:500',
        ]);

        $user = $request->user();
        $success = $this->safetyService->reject(
            $approvalId,
            $user->id,
            $request->input('reason')
        );

        if (! $success) {
            return response()->json([
                'error' => 'Cannot reject: approval not found, expired, or already responded',
            ], 400);
        }

        return response()->json([
            'message' => 'Action rejected',
            'approval_id' => $approvalId,
        ]);
    }
}
