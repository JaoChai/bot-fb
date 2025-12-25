import { useState, useCallback } from 'react';
import { Button } from '@/components/ui/button';
import {
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from '@/components/ui/card';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import { useBots, useKnowledgeBaseOperations } from '@/hooks/useKnowledgeBase';
import { DocumentUpload } from '@/components/knowledge-base/DocumentUpload';
import { DocumentList } from '@/components/knowledge-base/DocumentList';
import { SemanticSearch } from '@/components/knowledge-base/SemanticSearch';

export function KnowledgeBasePage() {
  const [selectedBotId, setSelectedBotId] = useState<number | null>(null);
  const { data: botsResponse, isLoading: isLoadingBots } = useBots();
  const bots = botsResponse?.data ?? [];

  const {
    knowledgeBase,
    documents,
    isLoading,
    isUploading,
    isDeleting,
    error,
    uploadDocument,
    deleteDocument,
    refetch,
  } = useKnowledgeBaseOperations(selectedBotId);

  const handleBotChange = useCallback((value: string) => {
    setSelectedBotId(Number(value));
  }, []);

  const handleUpload = useCallback(
    async (file: File) => {
      if (uploadDocument) {
        await uploadDocument(file);
      }
    },
    [uploadDocument]
  );

  const handleDelete = useCallback(
    async (documentId: number) => {
      if (deleteDocument) {
        await deleteDocument(documentId);
      }
    },
    [deleteDocument]
  );

  // No bots yet
  if (!isLoadingBots && bots.length === 0) {
    return (
      <div className="space-y-6">
        <div>
          <h1 className="text-2xl font-bold tracking-tight">Knowledge Base</h1>
          <p className="text-muted-foreground">
            Upload and manage documents for your bots
          </p>
        </div>

        <Card>
          <CardHeader className="text-center">
            <div className="mx-auto mb-4 flex h-12 w-12 items-center justify-center rounded-full bg-muted">
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
                <path d="M12 8V4H8" />
                <rect width="16" height="12" x="4" y="8" rx="2" />
                <path d="M2 14h2" />
                <path d="M20 14h2" />
                <path d="M15 13v2" />
                <path d="M9 13v2" />
              </svg>
            </div>
            <CardTitle>No bots yet</CardTitle>
            <CardDescription>
              Create a bot first to upload documents to its knowledge base
            </CardDescription>
          </CardHeader>
          <CardContent className="text-center">
            <Button asChild>
              <a href="/bots">Create your first bot</a>
            </Button>
          </CardContent>
        </Card>
      </div>
    );
  }

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-bold tracking-tight">Knowledge Base</h1>
          <p className="text-muted-foreground">
            Upload and manage documents for your bots
          </p>
        </div>
      </div>

      {/* Bot selector */}
      <Card>
        <CardHeader>
          <CardTitle className="text-lg">Select Bot</CardTitle>
          <CardDescription>
            Choose a bot to manage its knowledge base
          </CardDescription>
        </CardHeader>
        <CardContent>
          <Select
            value={selectedBotId?.toString() ?? ''}
            onValueChange={handleBotChange}
          >
            <SelectTrigger className="w-full md:w-[300px]">
              <SelectValue placeholder="Select a bot..." />
            </SelectTrigger>
            <SelectContent>
              {bots.map((bot) => (
                <SelectItem key={bot.id} value={bot.id.toString()}>
                  {bot.name}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
        </CardContent>
      </Card>

      {/* Knowledge Base content - only show when bot is selected */}
      {selectedBotId && (
        <>
          {/* Stats */}
          {knowledgeBase && (
            <div className="grid gap-4 md:grid-cols-3">
              <Card>
                <CardHeader className="pb-2">
                  <CardDescription>Documents</CardDescription>
                  <CardTitle className="text-3xl">
                    {knowledgeBase.document_count}
                  </CardTitle>
                </CardHeader>
              </Card>
              <Card>
                <CardHeader className="pb-2">
                  <CardDescription>Chunks</CardDescription>
                  <CardTitle className="text-3xl">
                    {knowledgeBase.chunk_count}
                  </CardTitle>
                </CardHeader>
              </Card>
              <Card>
                <CardHeader className="pb-2">
                  <CardDescription>Embedding Model</CardDescription>
                  <CardTitle className="text-lg">
                    {knowledgeBase.embedding_model}
                  </CardTitle>
                </CardHeader>
              </Card>
            </div>
          )}

          {/* Semantic Search */}
          <SemanticSearch
            botId={selectedBotId}
            hasChunks={(knowledgeBase?.chunk_count ?? 0) > 0}
          />

          {/* Upload section */}
          <DocumentUpload
            onUpload={handleUpload}
            isUploading={isUploading}
          />

          {/* Document list */}
          <DocumentList
            documents={documents}
            isLoading={isLoading}
            isDeleting={isDeleting}
            onDelete={handleDelete}
            onRefresh={refetch}
          />
        </>
      )}

      {/* Prompt to select bot */}
      {!selectedBotId && (
        <Card>
          <CardHeader className="text-center">
            <div className="mx-auto mb-4 flex h-12 w-12 items-center justify-center rounded-full bg-muted">
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
                <path d="M4 19.5v-15A2.5 2.5 0 0 1 6.5 2H19a1 1 0 0 1 1 1v18a1 1 0 0 1-1 1H6.5a1 1 0 0 1 0-5H20" />
                <path d="M8 11h8" />
                <path d="M8 7h6" />
              </svg>
            </div>
            <CardTitle>Select a bot</CardTitle>
            <CardDescription>
              Choose a bot from the dropdown above to view and manage its
              knowledge base documents
            </CardDescription>
          </CardHeader>
        </Card>
      )}

      {/* Error display */}
      {error && (
        <div className="rounded-md bg-destructive/10 p-4 text-sm text-destructive">
          {(error as Error).message || 'An error occurred'}
        </div>
      )}
    </div>
  );
}
