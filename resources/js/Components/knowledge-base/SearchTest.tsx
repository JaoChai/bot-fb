/**
 * SearchTest - Semantic search testing component for Knowledge Base
 *
 * Allows testing semantic search against a knowledge base
 * and displays results with similarity scores.
 */

import { useState, useCallback } from 'react';
import { Input } from '@/Components/ui/input';
import { Button } from '@/Components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import { Badge } from '@/Components/ui/badge';
import { ScrollArea } from '@/Components/ui/scroll-area';
import { cn } from '@/Lib/utils';
import { Search, Loader2, FileText, Sparkles } from 'lucide-react';

interface SearchResult {
  id: number;
  content: string;
  similarity: number;
  document_name: string;
  chunk_index: number;
}

interface SearchTestProps {
  knowledgeBaseId: number;
  onSearch?: (query: string, results: SearchResult[]) => void;
}

export function SearchTest({ knowledgeBaseId, onSearch }: SearchTestProps) {
  const [query, setQuery] = useState('');
  const [results, setResults] = useState<SearchResult[]>([]);
  const [isLoading, setIsLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [hasSearched, setHasSearched] = useState(false);

  const handleSearch = useCallback(async () => {
    const trimmedQuery = query.trim();
    if (!trimmedQuery) return;

    setIsLoading(true);
    setError(null);
    setHasSearched(true);

    try {
      const response = await fetch(`/api/knowledge-bases/${knowledgeBaseId}/search`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'Accept': 'application/json',
        },
        body: JSON.stringify({
          query: trimmedQuery,
          limit: 5,
        }),
      });

      if (!response.ok) {
        const errorData = await response.json().catch(() => ({}));
        throw new Error(errorData.message || 'การค้นหาล้มเหลว');
      }

      const data = await response.json();
      const searchResults: SearchResult[] = data.data || data.results || data || [];
      setResults(searchResults);
      onSearch?.(trimmedQuery, searchResults);
    } catch (err) {
      const errorMessage = err instanceof Error ? err.message : 'เกิดข้อผิดพลาดในการค้นหา';
      setError(errorMessage);
      setResults([]);
    } finally {
      setIsLoading(false);
    }
  }, [query, knowledgeBaseId, onSearch]);

  const handleKeyDown = (e: React.KeyboardEvent<HTMLInputElement>) => {
    if (e.key === 'Enter') {
      e.preventDefault();
      handleSearch();
    }
  };

  const formatSimilarity = (similarity: number): string => {
    return `${Math.round(similarity * 100)}%`;
  };

  const getSimilarityVariant = (similarity: number): 'success' | 'warning' | 'secondary' => {
    if (similarity >= 0.8) return 'success';
    if (similarity >= 0.5) return 'warning';
    return 'secondary';
  };

  const highlightContent = (content: string, searchQuery: string): React.ReactNode => {
    if (!searchQuery.trim()) return content;

    const words = searchQuery.trim().toLowerCase().split(/\s+/);
    const regex = new RegExp(`(${words.map(w => w.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')).join('|')})`, 'gi');
    const parts = content.split(regex);

    return parts.map((part, index) => {
      const isMatch = words.some(word => part.toLowerCase() === word.toLowerCase());
      return isMatch ? (
        <mark key={index} className="bg-yellow-200 dark:bg-yellow-800 px-0.5 rounded">
          {part}
        </mark>
      ) : (
        part
      );
    });
  };

  return (
    <Card>
      <CardHeader>
        <CardTitle className="text-lg flex items-center gap-2">
          <Sparkles className="h-5 w-5" />
          ทดสอบการค้นหา Semantic
        </CardTitle>
        <p className="text-sm text-muted-foreground mt-2">
          ค้นหาข้อมูลในฐานความรู้ด้วย AI เพื่อทดสอบความแม่นยำ
        </p>
      </CardHeader>
      <CardContent className="space-y-4">
        {/* Search Input */}
        <div className="flex gap-2">
          <Input
            placeholder="พิมพ์คำถามหรือข้อความที่ต้องการค้นหา..."
            value={query}
            onChange={(e) => setQuery(e.target.value)}
            onKeyDown={handleKeyDown}
            disabled={isLoading}
            className="flex-1"
          />
          <Button
            onClick={handleSearch}
            disabled={isLoading || !query.trim()}
            className="min-w-[100px]"
          >
            {isLoading ? (
              <>
                <Loader2 className="h-4 w-4 animate-spin" />
                <span className="ml-2">กำลังค้นหา</span>
              </>
            ) : (
              <>
                <Search className="h-4 w-4" />
                <span className="ml-2">ค้นหา</span>
              </>
            )}
          </Button>
        </div>

        {/* Error State */}
        {error && (
          <div className="p-3 rounded-lg bg-destructive/10 text-destructive text-sm">
            {error}
          </div>
        )}

        {/* Results */}
        {hasSearched && !error && (
          <div className="space-y-3">
            {/* Results Header */}
            <div className="flex items-center justify-between">
              <h4 className="text-sm font-medium text-muted-foreground">
                ผลการค้นหา
              </h4>
              {results.length > 0 && (
                <Badge variant="outline">
                  {results.length} รายการ
                </Badge>
              )}
            </div>

            {/* Results List */}
            {results.length > 0 ? (
              <ScrollArea className="h-[400px] rounded-lg border">
                <div className="p-4 space-y-3">
                  {results.map((result, index) => (
                    <div
                      key={result.id}
                      className={cn(
                        "p-4 rounded-lg border bg-card transition-colors",
                        "hover:border-primary/50 hover:bg-accent/50"
                      )}
                    >
                      {/* Result Header */}
                      <div className="flex items-start justify-between gap-3 mb-2">
                        <div className="flex items-center gap-2 text-sm text-muted-foreground">
                          <FileText className="h-4 w-4 shrink-0" />
                          <span className="font-medium truncate max-w-[200px]">
                            {result.document_name}
                          </span>
                          <span className="text-xs">
                            (ส่วนที่ {result.chunk_index + 1})
                          </span>
                        </div>
                        <Badge
                          variant={getSimilarityVariant(result.similarity)}
                          className="shrink-0"
                        >
                          {formatSimilarity(result.similarity)}
                        </Badge>
                      </div>

                      {/* Result Content */}
                      <p className="text-sm leading-relaxed whitespace-pre-wrap break-words">
                        {highlightContent(result.content, query)}
                      </p>

                      {/* Result Index */}
                      <div className="mt-2 pt-2 border-t border-dashed">
                        <span className="text-xs text-muted-foreground">
                          #{index + 1} - ความเกี่ยวข้อง: {formatSimilarity(result.similarity)}
                        </span>
                      </div>
                    </div>
                  ))}
                </div>
              </ScrollArea>
            ) : (
              /* Empty State */
              <div className="flex flex-col items-center justify-center py-12 text-center rounded-lg border border-dashed">
                <Search className="h-10 w-10 text-muted-foreground/50 mb-3" />
                <h4 className="text-sm font-medium text-muted-foreground">
                  ไม่พบผลลัพธ์
                </h4>
                <p className="text-xs text-muted-foreground mt-1">
                  ลองใช้คำค้นหาอื่นหรือเพิ่มเอกสารในฐานความรู้
                </p>
              </div>
            )}
          </div>
        )}

        {/* Initial State */}
        {!hasSearched && !error && (
          <div className="flex flex-col items-center justify-center py-12 text-center rounded-lg border border-dashed">
            <Sparkles className="h-10 w-10 text-muted-foreground/50 mb-3" />
            <h4 className="text-sm font-medium text-muted-foreground">
              พร้อมค้นหา
            </h4>
            <p className="text-xs text-muted-foreground mt-1">
              พิมพ์คำถามแล้วกด Enter หรือปุ่มค้นหา
            </p>
          </div>
        )}
      </CardContent>
    </Card>
  );
}
