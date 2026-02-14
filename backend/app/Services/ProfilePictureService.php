<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ProfilePictureService
{
    private const MAX_FILE_SIZE = 5 * 1024 * 1024; // 5MB

    /**
     * Download profile picture from platform CDN and store in R2/local storage.
     *
     * Returns the stored URL, or null if download/store fails.
     */
    public function downloadAndStore(string $channelType, string $externalId, ?string $sourceUrl): ?string
    {
        if (empty($sourceUrl)) {
            return null;
        }

        try {
            $response = Http::timeout(10)
                ->retry(2, fn ($attempt) => $attempt * 200, throw: false)
                ->get($sourceUrl);

            if ($response->failed()) {
                Log::warning('Profile picture download failed', [
                    'channel_type' => $channelType,
                    'external_id' => $externalId,
                    'source_url' => $sourceUrl,
                    'status' => $response->status(),
                ]);

                return null;
            }

            // Validate Content-Type is an image
            $contentType = $response->header('Content-Type');
            $extension = $this->extensionFromContentType($contentType);

            if ($extension === null) {
                Log::warning('Profile picture has invalid content type', [
                    'channel_type' => $channelType,
                    'external_id' => $externalId,
                    'content_type' => $contentType,
                ]);

                return null;
            }

            // Guard against oversized files
            $body = $response->body();

            if (strlen($body) > self::MAX_FILE_SIZE) {
                Log::warning('Profile picture too large', [
                    'channel_type' => $channelType,
                    'external_id' => $externalId,
                    'size' => strlen($body),
                ]);

                return null;
            }

            // Sanitize external ID to prevent path traversal
            $safeId = preg_replace('/[^a-zA-Z0-9_-]/', '_', $externalId);

            $path = "profile-pictures/{$channelType}/{$safeId}.{$extension}";
            $disk = config('filesystems.default');

            Storage::disk($disk)->put($path, $body);

            return $this->generateStorageUrl($disk, $path);
        } catch (\Exception $e) {
            Log::warning('Profile picture download/store failed', [
                'channel_type' => $channelType,
                'external_id' => $externalId,
                'source_url' => $sourceUrl,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Determine file extension from Content-Type header.
     * Returns null if Content-Type is not a recognized image type.
     */
    protected function extensionFromContentType(?string $contentType): ?string
    {
        $normalized = strtolower($contentType ?? '');

        return match (true) {
            str_starts_with($normalized, 'image/png') => 'png',
            str_starts_with($normalized, 'image/webp') => 'webp',
            str_starts_with($normalized, 'image/gif') => 'gif',
            str_starts_with($normalized, 'image/jpeg'),
            str_starts_with($normalized, 'image/jpg') => 'jpg',
            default => null,
        };
    }

    /**
     * Generate storage URL - use R2_URL directly if R2 disk to avoid config cache issues.
     */
    protected function generateStorageUrl(string $disk, string $path): string
    {
        if ($disk === 'r2') {
            // Use env() directly as workaround for Railway config cache issues (see TelegramService.php)
            $r2Url = env('R2_URL') ?: config('filesystems.disks.r2.url');
            if ($r2Url) {
                return rtrim($r2Url, '/') . '/' . $path;
            }
        }

        return Storage::disk($disk)->url($path);
    }
}
