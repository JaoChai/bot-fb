<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Pgvector\Laravel\HasNeighbors;
use Pgvector\Laravel\Vector;

class DocumentChunk extends Model
{
    use HasNeighbors;

    protected $fillable = [
        'document_id',
        'content',
        'chunk_index',
        'start_char',
        'end_char',
        'embedding',
        'metadata',
    ];

    protected $casts = [
        'embedding' => Vector::class,
        'metadata' => 'array',
    ];

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }
}
