<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class KnowledgeBaseResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'bot_id' => $this->bot_id,
            'name' => $this->name,
            'description' => $this->description,
            'document_count' => $this->document_count,
            'chunk_count' => $this->chunk_count,
            'embedding_model' => $this->embedding_model,
            'embedding_dimensions' => $this->embedding_dimensions,
            'documents' => DocumentResource::collection($this->whenLoaded('documents')),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
