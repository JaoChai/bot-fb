<?php

namespace App\Services;

class ChunkingService
{
    protected int $chunkSize;
    protected int $chunkOverlap;

    public function __construct()
    {
        $this->chunkSize = config('services.embeddings.chunk_size', 500);
        $this->chunkOverlap = config('services.embeddings.chunk_overlap', 50);
    }

    public function chunk(string $text): array
    {
        $text = $this->normalizeText($text);

        if (empty($text)) {
            return [];
        }

        $sentences = $this->splitIntoSentences($text);
        $chunks = [];
        $currentChunk = [];
        $currentWordCount = 0;
        $chunkIndex = 0;
        $charOffset = 0;

        foreach ($sentences as $sentence) {
            $sentenceWordCount = str_word_count($sentence);

            if ($currentWordCount + $sentenceWordCount > $this->chunkSize && !empty($currentChunk)) {
                $chunkText = implode(' ', $currentChunk);
                $chunks[] = [
                    'content' => $chunkText,
                    'chunk_index' => $chunkIndex,
                    'start_char' => $charOffset,
                    'end_char' => $charOffset + strlen($chunkText),
                    'word_count' => $currentWordCount,
                ];

                $charOffset += strlen($chunkText) + 1;
                $chunkIndex++;

                $currentChunk = $this->getOverlapSentences($currentChunk);
                $currentWordCount = $this->countWords($currentChunk);
            }

            $currentChunk[] = $sentence;
            $currentWordCount += $sentenceWordCount;
        }

        if (!empty($currentChunk)) {
            $chunkText = implode(' ', $currentChunk);
            $chunks[] = [
                'content' => $chunkText,
                'chunk_index' => $chunkIndex,
                'start_char' => $charOffset,
                'end_char' => $charOffset + strlen($chunkText),
                'word_count' => $currentWordCount,
            ];
        }

        return $chunks;
    }

    protected function normalizeText(string $text): string
    {
        $text = preg_replace('/\r\n|\r/', "\n", $text);
        $text = preg_replace('/\n{3,}/', "\n\n", $text);
        $text = preg_replace('/[ \t]+/', ' ', $text);
        $text = trim($text);

        return $text;
    }

    protected function splitIntoSentences(string $text): array
    {
        $pattern = '/(?<=[.!?])\s+(?=[A-Z])|(?<=\n)\s*(?=\S)/u';
        $sentences = preg_split($pattern, $text, -1, PREG_SPLIT_NO_EMPTY);

        return array_filter(array_map('trim', $sentences), fn ($s) => !empty($s));
    }

    protected function getOverlapSentences(array $sentences): array
    {
        $totalWords = 0;
        $overlapSentences = [];

        for ($i = count($sentences) - 1; $i >= 0; $i--) {
            $sentenceWords = str_word_count($sentences[$i]);
            if ($totalWords + $sentenceWords > $this->chunkOverlap) {
                break;
            }
            array_unshift($overlapSentences, $sentences[$i]);
            $totalWords += $sentenceWords;
        }

        return $overlapSentences;
    }

    protected function countWords(array $sentences): int
    {
        return array_sum(array_map('str_word_count', $sentences));
    }
}
