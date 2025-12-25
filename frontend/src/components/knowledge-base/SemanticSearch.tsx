import { useState, useCallback } from 'react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from '@/components/ui/card';
import { cn } from '@/lib/utils';
import { useSemanticSearch } from '@/hooks/useKnowledgeBase';
import type { SearchResult } from '@/types/api';

interface SemanticSearchProps {
  botId: number;
  hasChunks: boolean;
}

function getSimilarityColor(similarity: number): string {
  if (similarity >= 0.9) return 'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-400';
  if (similarity >= 0.8) return 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/30 dark:text-emerald-400';
  if (similarity >= 0.7) return 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900/30 dark:text-yellow-400';
  return 'bg-orange-100 text-orange-800 dark:bg-orange-900/30 dark:text-orange-400';
}

function highlightMatch(content: string, maxLength: number = 300): string {
  if (content.length <= maxLength) return content;
  return content.slice(0, maxLength) + '...';
}

export function SemanticSearch({ botId, hasChunks }: SemanticSearchProps) {
  const [query, setQuery] = useState('');
  const [results, setResults] = useState<SearchResult[]>([]);
  const [searchedQuery, setSearchedQuery] = useState('');
  const searchMutation = useSemanticSearch(botId);

  const handleSearch = useCallback(async () => {
    if (!query.trim()) return;

    try {
      const response = await searchMutation.mutateAsync({ query: query.trim() });
      setResults(response.results);
      setSearchedQuery(query.trim());
    } catch {
      setResults([]);
    }
  }, [query, searchMutation]);

  const handleKeyDown = useCallback(
    (e: React.KeyboardEvent) => {
      if (e.key === 'Enter' && !searchMutation.isPending) {
        handleSearch();
      }
    },
    [handleSearch, searchMutation.isPending]
  );

  const handleClear = useCallback(() => {
    setQuery('');
    setResults([]);
    setSearchedQuery('');
  }, []);

  if (!hasChunks) {
    return (
      <Card>
        <CardHeader>
          <CardTitle className="flex items-center gap-2 text-lg">
            <svg
              xmlns="http://www.w3.org/2000/svg"
              width="20"
              height="20"
              viewBox="0 0 24 24"
              fill="none"
              stroke="currentColor"
              strokeWidth="2"
              strokeLinecap="round"
              strokeLinejoin="round"
            >
              <circle cx="11" cy="11" r="8" />
              <path d="m21 21-4.3-4.3" />
            </svg>
            Semantic Search
          </CardTitle>
          <CardDescription>
            Upload and process documents first to enable semantic search
          </CardDescription>
        </CardHeader>
        <CardContent>
          <div className="flex flex-col items-center justify-center py-6 text-center">
            <div className="mb-4 rounded-full bg-muted p-3">
              <svg
                xmlns="http://www.w3.org/2000/svg"
                width="24"
                height="24"
                viewBox="0 0 24 24"
                fill="none"
                stroke="currentColor"
                strokeWidth="2"
                strokeLinecap="round"
                strokeLinejoin="round"
                className="text-muted-foreground"
              >
                <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" />
                <polyline points="14 2 14 8 20 8" />
                <line x1="12" y1="11" x2="12" y2="17" />
                <line x1="9" y1="14" x2="15" y2="14" />
              </svg>
            </div>
            <p className="text-sm text-muted-foreground">
              No processed documents available
            </p>
            <p className="text-xs text-muted-foreground">
              Upload documents and wait for processing to complete
            </p>
          </div>
        </CardContent>
      </Card>
    );
  }

  return (
    <Card>
      <CardHeader>
        <CardTitle className="flex items-center gap-2 text-lg">
          <svg
            xmlns="http://www.w3.org/2000/svg"
            width="20"
            height="20"
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            strokeWidth="2"
            strokeLinecap="round"
            strokeLinejoin="round"
          >
            <circle cx="11" cy="11" r="8" />
            <path d="m21 21-4.3-4.3" />
          </svg>
          Semantic Search
        </CardTitle>
        <CardDescription>
          Search your documents using natural language
        </CardDescription>
      </CardHeader>
      <CardContent className="space-y-4">
        {/* Search input */}
        <div className="flex gap-2">
          <div className="relative flex-1">
            <Input
              placeholder="Ask a question about your documents..."
              value={query}
              onChange={(e) => setQuery(e.target.value)}
              onKeyDown={handleKeyDown}
              disabled={searchMutation.isPending}
            />
            {query && (
              <button
                onClick={handleClear}
                className="absolute right-3 top-1/2 -translate-y-1/2 text-muted-foreground hover:text-foreground"
              >
                <svg
                  xmlns="http://www.w3.org/2000/svg"
                  width="16"
                  height="16"
                  viewBox="0 0 24 24"
                  fill="none"
                  stroke="currentColor"
                  strokeWidth="2"
                  strokeLinecap="round"
                  strokeLinejoin="round"
                >
                  <circle cx="12" cy="12" r="10" />
                  <path d="m15 9-6 6" />
                  <path d="m9 9 6 6" />
                </svg>
              </button>
            )}
          </div>
          <Button
            onClick={handleSearch}
            disabled={!query.trim() || searchMutation.isPending}
          >
            {searchMutation.isPending ? (
              <svg
                className="h-4 w-4 animate-spin"
                xmlns="http://www.w3.org/2000/svg"
                fill="none"
                viewBox="0 0 24 24"
              >
                <circle
                  className="opacity-25"
                  cx="12"
                  cy="12"
                  r="10"
                  stroke="currentColor"
                  strokeWidth="4"
                />
                <path
                  className="opacity-75"
                  fill="currentColor"
                  d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"
                />
              </svg>
            ) : (
              'Search'
            )}
          </Button>
        </div>

        {/* Error state */}
        {searchMutation.isError && (
          <div className="rounded-md bg-destructive/10 p-3 text-sm text-destructive">
            Search failed. Please try again.
          </div>
        )}

        {/* Results */}
        {searchedQuery && !searchMutation.isPending && (
          <div className="space-y-3">
            <div className="flex items-center justify-between">
              <p className="text-sm text-muted-foreground">
                {results.length === 0
                  ? `No results for "${searchedQuery}"`
                  : `${results.length} result${results.length !== 1 ? 's' : ''} for "${searchedQuery}"`}
              </p>
            </div>

            {results.length > 0 && (
              <div className="divide-y rounded-md border">
                {results.map((result) => (
                  <div key={result.id} className="p-4">
                    <div className="mb-2 flex items-center justify-between">
                      <div className="flex items-center gap-2">
                        <svg
                          xmlns="http://www.w3.org/2000/svg"
                          width="16"
                          height="16"
                          viewBox="0 0 24 24"
                          fill="none"
                          stroke="currentColor"
                          strokeWidth="2"
                          strokeLinecap="round"
                          strokeLinejoin="round"
                          className="text-muted-foreground"
                        >
                          <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z" />
                          <polyline points="14 2 14 8 20 8" />
                        </svg>
                        <span className="text-sm font-medium">
                          {result.document_name}
                        </span>
                        <span className="text-xs text-muted-foreground">
                          (chunk {result.chunk_index + 1})
                        </span>
                      </div>
                      <span
                        className={cn(
                          'rounded-full px-2 py-0.5 text-xs font-medium',
                          getSimilarityColor(result.similarity)
                        )}
                      >
                        {Math.round(result.similarity * 100)}% match
                      </span>
                    </div>
                    <p className="text-sm text-muted-foreground leading-relaxed">
                      {highlightMatch(result.content)}
                    </p>
                  </div>
                ))}
              </div>
            )}
          </div>
        )}

        {/* Initial state */}
        {!searchedQuery && !searchMutation.isPending && (
          <div className="flex flex-col items-center justify-center py-6 text-center">
            <p className="text-sm text-muted-foreground">
              Enter a question to search your knowledge base
            </p>
            <p className="text-xs text-muted-foreground">
              Results are ranked by semantic similarity
            </p>
          </div>
        )}
      </CardContent>
    </Card>
  );
}
