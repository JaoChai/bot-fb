<?php

namespace App\Services\LineWebhook;

class ResponseEnvelope
{
    /** @param  'text'|'sticker'|'flex'  $type */
    public function __construct(
        public readonly string $type,
        public readonly mixed $payload,
    ) {}

    public static function text(string $content): self
    {
        return new self('text', $content);
    }

    public static function sticker(string $packageId, string $stickerId): self
    {
        return new self('sticker', [
            'package_id' => $packageId,
            'sticker_id' => $stickerId,
        ]);
    }

    public static function flex(array $payload): self
    {
        return new self('flex', $payload);
    }
}
