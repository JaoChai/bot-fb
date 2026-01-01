<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ConversationResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'bot_id' => $this->bot_id,
            'customer_profile_id' => $this->customer_profile_id,
            'external_customer_id' => $this->external_customer_id,
            'channel_type' => $this->channel_type,
            'status' => $this->status,
            'is_handover' => $this->is_handover,
            'assigned_user_id' => $this->assigned_user_id,
            'memory_notes' => $this->memory_notes,
            'tags' => $this->tags ?? [],
            'context' => $this->context,
            'current_flow_id' => $this->current_flow_id,
            'message_count' => $this->message_count,
            'unread_count' => $this->unread_count ?? 0,
            'last_message_at' => $this->last_message_at?->toIso8601String(),
            'bot_auto_enable_at' => $this->bot_auto_enable_at?->toIso8601String(),
            'bot_auto_enable_remaining_seconds' => $this->getBotAutoEnableRemainingSeconds(),
            'context_cleared_at' => $this->context_cleared_at?->toIso8601String(),
            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),

            // Relationships (when loaded)
            'bot' => new BotResource($this->whenLoaded('bot')),
            'customer_profile' => new CustomerProfileResource($this->whenLoaded('customerProfile')),
            'assigned_user' => new UserResource($this->whenLoaded('assignedUser')),
            'current_flow' => new FlowResource($this->whenLoaded('currentFlow')),
            'messages' => MessageResource::collection($this->whenLoaded('messages')),

            // Computed fields
            'last_message' => new MessageResource($this->whenLoaded('lastMessage')),
            'needs_response' => $this->needs_response,
        ];
    }
}
