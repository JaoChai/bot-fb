<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Smalot\PdfParser\Parser as PdfParser;

class DocumentParserService
{
    protected array $supportedMimeTypes = [
        'application/pdf',
        'text/plain',
        'text/markdown',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    ];

    public function parse(string $storagePath, string $mimeType, ?string $disk = null): string
    {
        $disk = $disk ?? $this->getStorageDisk();

        if (!Storage::disk($disk)->exists($storagePath)) {
            throw new RuntimeException("File not found: {$storagePath}");
        }

        $content = Storage::disk($disk)->get($storagePath);

        return match ($mimeType) {
            'application/pdf' => $this->parsePdf($content),
            'text/plain', 'text/markdown' => $this->parseText($content),
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => $this->parseDocx($content),
            default => throw new RuntimeException("Unsupported file type: {$mimeType}"),
        };
    }

    protected function parsePdf(string $content): string
    {
        try {
            $parser = new PdfParser();
            $pdf = $parser->parseContent($content);

            $text = $pdf->getText();
            $text = $this->cleanText($text);

            if (empty(trim($text))) {
                throw new RuntimeException('PDF contains no extractable text (possibly image-based)');
            }

            return $text;
        } catch (\Exception $e) {
            Log::error('PDF parsing failed', ['error' => $e->getMessage()]);
            throw new RuntimeException('Failed to parse PDF: ' . $e->getMessage());
        }
    }

    protected function parseText(string $content): string
    {
        $encoding = mb_detect_encoding($content, ['UTF-8', 'ISO-8859-1', 'ASCII'], true);
        if ($encoding && $encoding !== 'UTF-8') {
            $content = mb_convert_encoding($content, 'UTF-8', $encoding);
        }

        return $this->cleanText($content);
    }

    protected function parseDocx(string $content): string
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'docx_');
        file_put_contents($tempFile, $content);

        try {
            $zip = new \ZipArchive();
            if ($zip->open($tempFile) !== true) {
                throw new RuntimeException('Failed to open DOCX file');
            }

            $xml = $zip->getFromName('word/document.xml');
            $zip->close();

            if ($xml === false) {
                throw new RuntimeException('Invalid DOCX structure');
            }

            $dom = new \DOMDocument();
            @$dom->loadXML($xml);

            $paragraphs = $dom->getElementsByTagNameNS(
                'http://schemas.openxmlformats.org/wordprocessingml/2006/main',
                'p'
            );

            $text = '';
            foreach ($paragraphs as $paragraph) {
                $text .= $paragraph->textContent . "\n";
            }

            return $this->cleanText($text);
        } finally {
            @unlink($tempFile);
        }
    }

    protected function cleanText(string $text): string
    {
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $text);
        $text = preg_replace('/\r\n|\r/', "\n", $text);
        $text = preg_replace('/[ \t]+/', ' ', $text);
        $text = preg_replace('/\n{3,}/', "\n\n", $text);

        return trim($text);
    }

    protected function getStorageDisk(): string
    {
        if (config('filesystems.disks.r2.key')) {
            return 'r2';
        }

        return 'local';
    }

    public function getSupportedMimeTypes(): array
    {
        return $this->supportedMimeTypes;
    }
}
