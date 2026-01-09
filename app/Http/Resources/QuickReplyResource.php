<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class QuickReplyResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'shortcut' => $this->shortcut,
            'title' => $this->title,
            'content' => $this->content,
            'category' => $this->category,
            'sort_order' => $this->sort_order,
            'is_active' => $this->is_active,
            'created_by' => $this->created_by,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
