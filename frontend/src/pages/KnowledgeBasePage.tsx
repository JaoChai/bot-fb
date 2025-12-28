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
import { Badge } from '@/components/ui/badge';
import { useBots, useKnowledgeBaseOperations } from '@/hooks/useKnowledgeBase';
import { DocumentUpload } from '@/components/knowledge-base/DocumentUpload';
import { DocumentList } from '@/components/knowledge-base/DocumentList';
import { SemanticSearch } from '@/components/knowledge-base/SemanticSearch';
import {
  Bot,
  FileText,
  Layers,
  Cpu,
  BookOpen,
  Search,
  Plus,
  Database,
} from 'lucide-react';

export function KnowledgeBasePage() {
  const [selectedBotId, setSelectedBotId] = useState<number | null>(null);
  const { data: botsResponse, isLoading: isLoadingBots } = useBots();
  const bots = botsResponse?.data ?? [];

  const {
    knowledgeBase,
    documents,
    isLoading,
    isSubmitting,
    isDeleting,
    error,
    createDocument,
    deleteDocument,
    refetch,
  } = useKnowledgeBaseOperations(selectedBotId);

  const handleBotChange = useCallback((value: string) => {
    setSelectedBotId(Number(value));
  }, []);

  const handleSubmit = useCallback(
    async (data: { title: string; content: string }) => {
      if (createDocument) {
        await createDocument(data);
      }
    },
    [createDocument]
  );

  const handleDelete = useCallback(
    async (documentId: number) => {
      if (deleteDocument) {
        await deleteDocument(documentId);
      }
    },
    [deleteDocument]
  );

  const selectedBot = bots.find((b) => b.id === selectedBotId);

  // ยังไม่มี Bot
  if (!isLoadingBots && bots.length === 0) {
    return (
      <div className="space-y-6">
        <div>
          <h1 className="text-2xl font-bold tracking-tight">ฐานความรู้</h1>
          <p className="text-muted-foreground">
            จัดการข้อมูลความรู้สำหรับ Bot ของคุณ
          </p>
        </div>

        <Card className="border-dashed">
          <CardHeader className="text-center pb-2">
            <div className="mx-auto mb-4 flex h-16 w-16 items-center justify-center rounded-full bg-primary/10">
              <Bot className="h-8 w-8 text-primary" />
            </div>
            <CardTitle className="text-xl">ยังไม่มี Bot</CardTitle>
            <CardDescription className="max-w-sm mx-auto">
              สร้าง Bot ก่อนเพื่อเริ่มเพิ่มข้อมูลความรู้ให้ Bot ตอบคำถามได้อย่างชาญฉลาด
            </CardDescription>
          </CardHeader>
          <CardContent className="text-center pb-6">
            <Button asChild>
              <a href="/bots">
                <Bot className="mr-2 h-4 w-4" />
                สร้าง Bot แรกของคุณ
              </a>
            </Button>
          </CardContent>
        </Card>
      </div>
    );
  }

  return (
    <div className="space-y-6">
        {/* Header */}
        <div className="flex items-center justify-between">
          <div>
            <h1 className="text-2xl font-bold tracking-tight">ฐานความรู้</h1>
            <p className="text-muted-foreground text-sm mt-1">
              เพิ่มข้อมูลให้ Bot เรียนรู้และตอบคำถามได้อย่างแม่นยำ
            </p>
          </div>
          {selectedBot && (
            <Badge variant="outline" className="hidden sm:flex gap-1.5 px-3 py-1.5">
              <Database className="h-3.5 w-3.5 text-primary" />
              Smart RAG
            </Badge>
          )}
        </div>

        {/* Bot Selector - Compact */}
        <div className="flex items-center gap-4">
          <div className="flex items-center gap-2 text-sm font-medium">
            <Bot className="h-4 w-4 text-muted-foreground" />
            เลือก Bot:
          </div>
          <Select
            value={selectedBotId?.toString() ?? ''}
            onValueChange={handleBotChange}
          >
            <SelectTrigger className="w-[280px]">
              <SelectValue placeholder="คลิกเพื่อเลือก Bot..." />
            </SelectTrigger>
            <SelectContent>
              {bots.map((bot) => (
                <SelectItem key={bot.id} value={bot.id.toString()}>
                  <div className="flex items-center gap-2">
                    <Bot className="h-4 w-4" />
                    {bot.name}
                  </div>
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
        </div>

        {/* Main Content Area */}
        {selectedBotId ? (
          <div className="space-y-6">
            {/* Stats Overview - Horizontal */}
            {knowledgeBase && (
              <div className="grid grid-cols-3 gap-4">
                <Card className="bg-gradient-to-br from-blue-50 to-white dark:from-blue-950/30 dark:to-card border-blue-100 dark:border-blue-900/50">
                  <CardContent className="p-4">
                    <div className="flex items-center gap-3">
                      <div className="flex-shrink-0 w-12 h-12 bg-blue-100 dark:bg-blue-900/50 rounded-xl flex items-center justify-center">
                        <FileText className="h-6 w-6 text-blue-600 dark:text-blue-400" />
                      </div>
                      <div>
                        <p className="text-2xl font-bold tabular-nums">{knowledgeBase.document_count}</p>
                        <p className="text-xs text-muted-foreground">เอกสาร</p>
                      </div>
                    </div>
                  </CardContent>
                </Card>

                <Card className="bg-gradient-to-br from-purple-50 to-white dark:from-purple-950/30 dark:to-card border-purple-100 dark:border-purple-900/50">
                  <CardContent className="p-4">
                    <div className="flex items-center gap-3">
                      <div className="flex-shrink-0 w-12 h-12 bg-purple-100 dark:bg-purple-900/50 rounded-xl flex items-center justify-center">
                        <Layers className="h-6 w-6 text-purple-600 dark:text-purple-400" />
                      </div>
                      <div>
                        <p className="text-2xl font-bold tabular-nums">{knowledgeBase.chunk_count}</p>
                        <p className="text-xs text-muted-foreground">Chunks</p>
                      </div>
                    </div>
                  </CardContent>
                </Card>

                <Card className="bg-gradient-to-br from-green-50 to-white dark:from-green-950/30 dark:to-card border-green-100 dark:border-green-900/50">
                  <CardContent className="p-4">
                    <div className="flex items-center gap-3">
                      <div className="flex-shrink-0 w-12 h-12 bg-green-100 dark:bg-green-900/50 rounded-xl flex items-center justify-center">
                        <Cpu className="h-6 w-6 text-green-600 dark:text-green-400" />
                      </div>
                      <div>
                        <p className="text-sm font-mono font-medium truncate max-w-[120px]">
                          {(knowledgeBase.embedding_model || 'text-embedding-3-small').split('/').pop()}
                        </p>
                        <p className="text-xs text-muted-foreground">Embedding</p>
                      </div>
                    </div>
                  </CardContent>
                </Card>
              </div>
            )}

            {/* Add Document Section */}
            <Card>
              <CardHeader className="pb-3">
                <div className="flex items-center gap-3">
                  <div className="flex-shrink-0 w-10 h-10 bg-primary/10 rounded-lg flex items-center justify-center">
                    <Plus className="h-5 w-5 text-primary" />
                  </div>
                  <div>
                    <CardTitle className="text-lg">เพิ่มข้อมูลความรู้</CardTitle>
                    <CardDescription>
                      เพิ่มข้อมูลที่ต้องการให้ Bot เรียนรู้และใช้ตอบคำถาม
                    </CardDescription>
                  </div>
                </div>
              </CardHeader>
              <CardContent>
                <DocumentUpload
                  onSubmit={handleSubmit}
                  isSubmitting={isSubmitting}
                />
              </CardContent>
            </Card>

            {/* Document List Section */}
            <Card>
              <CardHeader className="pb-3">
                <div className="flex items-center justify-between">
                  <div className="flex items-center gap-3">
                    <div className="flex-shrink-0 w-10 h-10 bg-primary/10 rounded-lg flex items-center justify-center">
                      <FileText className="h-5 w-5 text-primary" />
                    </div>
                    <div>
                      <CardTitle className="text-lg">รายการเอกสาร</CardTitle>
                      <CardDescription>
                        {documents.length === 0 ? 'ยังไม่มีเอกสาร' : `${documents.length} เอกสารในฐานความรู้`}
                      </CardDescription>
                    </div>
                  </div>
                </div>
              </CardHeader>
              <CardContent>
                <DocumentList
                  documents={documents}
                  isLoading={isLoading}
                  isDeleting={isDeleting}
                  onDelete={handleDelete}
                  onRefresh={refetch}
                />
              </CardContent>
            </Card>

            {/* Search Section */}
            {(knowledgeBase?.chunk_count ?? 0) > 0 && (
              <Card>
                <CardHeader className="pb-3">
                  <div className="flex items-center gap-3">
                    <div className="flex-shrink-0 w-10 h-10 bg-primary/10 rounded-lg flex items-center justify-center">
                      <Search className="h-5 w-5 text-primary" />
                    </div>
                    <div>
                      <CardTitle className="text-lg">ทดสอบค้นหา</CardTitle>
                      <CardDescription>
                        ทดสอบว่า Bot สามารถค้นหาข้อมูลจากฐานความรู้ได้หรือไม่
                      </CardDescription>
                    </div>
                  </div>
                </CardHeader>
                <CardContent>
                  <SemanticSearch
                    botId={selectedBotId}
                    hasChunks={(knowledgeBase?.chunk_count ?? 0) > 0}
                  />
                </CardContent>
              </Card>
            )}
          </div>
        ) : (
          /* Empty State - No Bot Selected */
          <Card className="border-dashed border-2">
            <CardContent className="flex flex-col items-center justify-center py-16">
              <div className="mb-6 flex h-20 w-20 items-center justify-center rounded-full bg-gradient-to-br from-primary/10 to-primary/5">
                <BookOpen className="h-10 w-10 text-primary" />
              </div>
              <h2 className="text-xl font-semibold mb-2">เลือก Bot เพื่อเริ่มต้น</h2>
              <p className="text-muted-foreground text-center max-w-md mb-6">
                เลือก Bot จากเมนูด้านบนเพื่อจัดการฐานความรู้ เพิ่มเอกสาร และทดสอบการค้นหา
              </p>
              <div className="flex items-center gap-6 text-sm text-muted-foreground">
                <div className="flex items-center gap-2">
                  <div className="w-8 h-8 rounded-full bg-primary/10 flex items-center justify-center">
                    <Bot className="h-4 w-4 text-primary" />
                  </div>
                  <span>เลือก Bot</span>
                </div>
                <div className="w-8 h-px bg-border" />
                <div className="flex items-center gap-2">
                  <div className="w-8 h-8 rounded-full bg-muted flex items-center justify-center">
                    <Plus className="h-4 w-4 text-muted-foreground" />
                  </div>
                  <span>เพิ่มเอกสาร</span>
                </div>
                <div className="w-8 h-px bg-border" />
                <div className="flex items-center gap-2">
                  <div className="w-8 h-8 rounded-full bg-muted flex items-center justify-center">
                    <Search className="h-4 w-4 text-muted-foreground" />
                  </div>
                  <span>ทดสอบ</span>
                </div>
              </div>
            </CardContent>
          </Card>
        )}

        {/* Error */}
        {error && (
          <div className="rounded-lg bg-destructive/10 border border-destructive/20 p-4 text-sm text-destructive">
            {(error as Error).message || 'เกิดข้อผิดพลาด กรุณาลองใหม่อีกครั้ง'}
          </div>
        )}
    </div>
  );
}
