<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FlowPluginResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'flow_id' => $this->flow_id,
            'type' => $this->type,
            'name' => $this->name,
            'enabled' => $this->enabled,
            'trigger_condition' => $this->trigger_condition,
            'config' => $this->config,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
