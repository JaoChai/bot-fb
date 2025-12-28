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
import {
  Tooltip,
  TooltipContent,
  TooltipProvider,
  TooltipTrigger,
} from '@/components/ui/tooltip';
import { useBots, useKnowledgeBaseOperations } from '@/hooks/useKnowledgeBase';
import { DocumentUpload } from '@/components/knowledge-base/DocumentUpload';
import { DocumentList } from '@/components/knowledge-base/DocumentList';
import { SemanticSearch } from '@/components/knowledge-base/SemanticSearch';
import {
  Bot,
  FileText,
  Layers,
  Cpu,
  HelpCircle,
  BookOpen,
  ArrowRight,
  Sparkles,
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
    <TooltipProvider>
      <div className="space-y-6">
        {/* Header */}
        <div className="flex items-center justify-between">
          <div>
            <h1 className="text-2xl font-bold tracking-tight">ฐานความรู้</h1>
            <p className="text-muted-foreground">
              เพิ่มข้อมูลให้ Bot เรียนรู้และตอบคำถามได้อย่างแม่นยำ
            </p>
          </div>
          {selectedBot && (
            <div className="hidden sm:flex items-center gap-2 text-sm text-muted-foreground bg-muted/50 rounded-full px-4 py-2">
              <Sparkles className="h-4 w-4 text-primary" />
              <span>Smart RAG: Hybrid + Reranking + Query Enhancement</span>
            </div>
          )}
        </div>

        {/* Step 1: เลือก Bot */}
        <Card>
          <CardHeader className="pb-3">
            <div className="flex items-center gap-2">
              <div className="flex h-8 w-8 items-center justify-center rounded-full bg-primary text-primary-foreground text-sm font-semibold">
                1
              </div>
              <div>
                <CardTitle className="text-lg">เลือก Bot</CardTitle>
                <CardDescription>
                  เลือก Bot ที่ต้องการจัดการฐานความรู้
                </CardDescription>
              </div>
            </div>
          </CardHeader>
          <CardContent>
            <Select
              value={selectedBotId?.toString() ?? ''}
              onValueChange={handleBotChange}
            >
              <SelectTrigger className="w-full md:w-[350px]">
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
          </CardContent>
        </Card>

        {/* เลือก Bot แล้ว - แสดง Content */}
        {selectedBotId && (
          <>
            {/* Stats Cards */}
            {knowledgeBase && (
              <div className="grid gap-4 md:grid-cols-3">
                <Card>
                  <CardHeader className="pb-2">
                    <div className="flex items-center justify-between">
                      <CardDescription className="flex items-center gap-1">
                        <FileText className="h-4 w-4" />
                        เอกสาร
                      </CardDescription>
                      <Tooltip>
                        <TooltipTrigger>
                          <HelpCircle className="h-4 w-4 text-muted-foreground" />
                        </TooltipTrigger>
                        <TooltipContent>
                          <p>จำนวนเอกสารทั้งหมดในฐานความรู้</p>
                        </TooltipContent>
                      </Tooltip>
                    </div>
                    <CardTitle className="text-3xl tabular-nums">
                      {knowledgeBase.document_count}
                    </CardTitle>
                  </CardHeader>
                </Card>

                <Card>
                  <CardHeader className="pb-2">
                    <div className="flex items-center justify-between">
                      <CardDescription className="flex items-center gap-1">
                        <Layers className="h-4 w-4" />
                        Chunks
                      </CardDescription>
                      <Tooltip>
                        <TooltipTrigger>
                          <HelpCircle className="h-4 w-4 text-muted-foreground" />
                        </TooltipTrigger>
                        <TooltipContent className="max-w-xs">
                          <p>
                            เอกสารถูกแบ่งเป็นชิ้นเล็กๆ (Chunks) เพื่อให้ AI ค้นหาได้แม่นยำ
                          </p>
                        </TooltipContent>
                      </Tooltip>
                    </div>
                    <CardTitle className="text-3xl tabular-nums">
                      {knowledgeBase.chunk_count}
                    </CardTitle>
                  </CardHeader>
                </Card>

                <Card>
                  <CardHeader className="pb-2">
                    <div className="flex items-center justify-between">
                      <CardDescription className="flex items-center gap-1">
                        <Cpu className="h-4 w-4" />
                        โมเดล Embedding
                      </CardDescription>
                      <Tooltip>
                        <TooltipTrigger>
                          <HelpCircle className="h-4 w-4 text-muted-foreground" />
                        </TooltipTrigger>
                        <TooltipContent className="max-w-xs">
                          <p>
                            AI Model ที่ใช้แปลงข้อความเป็น Vector เพื่อค้นหาความหมาย
                          </p>
                        </TooltipContent>
                      </Tooltip>
                    </div>
                    <CardTitle className="text-sm font-mono truncate">
                      {knowledgeBase.embedding_model || 'text-embedding-3-small'}
                    </CardTitle>
                  </CardHeader>
                </Card>
              </div>
            )}

            {/* Step 2: เพิ่มเอกสาร */}
            <Card>
              <CardHeader className="pb-3">
                <div className="flex items-center gap-2">
                  <div className="flex h-8 w-8 items-center justify-center rounded-full bg-primary text-primary-foreground text-sm font-semibold">
                    2
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

            {/* Step 3: รายการเอกสาร */}
            <Card>
              <CardHeader className="pb-3">
                <div className="flex items-center justify-between">
                  <div className="flex items-center gap-2">
                    <div className="flex h-8 w-8 items-center justify-center rounded-full bg-primary text-primary-foreground text-sm font-semibold">
                      3
                    </div>
                    <div>
                      <CardTitle className="text-lg">รายการเอกสาร</CardTitle>
                      <CardDescription>
                        {documents.length} เอกสารในฐานความรู้
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

            {/* Step 4: ทดสอบค้นหา */}
            {(knowledgeBase?.chunk_count ?? 0) > 0 && (
              <Card>
                <CardHeader className="pb-3">
                  <div className="flex items-center gap-2">
                    <div className="flex h-8 w-8 items-center justify-center rounded-full bg-primary text-primary-foreground text-sm font-semibold">
                      4
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
          </>
        )}

        {/* ยังไม่ได้เลือก Bot */}
        {!selectedBotId && (
          <Card className="border-dashed">
            <CardHeader className="text-center pb-2">
              <div className="mx-auto mb-4 flex h-16 w-16 items-center justify-center rounded-full bg-muted">
                <BookOpen className="h-8 w-8 text-muted-foreground" />
              </div>
              <CardTitle className="text-xl">เลือก Bot เพื่อเริ่มต้น</CardTitle>
              <CardDescription className="max-w-md mx-auto">
                เลือก Bot จากด้านบนเพื่อจัดการฐานความรู้ เพิ่มเอกสาร และทดสอบการค้นหา
              </CardDescription>
            </CardHeader>
            <CardContent className="text-center pb-6">
              <div className="flex items-center justify-center gap-2 text-sm text-muted-foreground">
                <span className="flex items-center gap-1">
                  <span className="flex h-6 w-6 items-center justify-center rounded-full bg-primary text-primary-foreground text-xs">1</span>
                  เลือก Bot
                </span>
                <ArrowRight className="h-4 w-4" />
                <span className="flex items-center gap-1">
                  <span className="flex h-6 w-6 items-center justify-center rounded-full bg-muted text-muted-foreground text-xs">2</span>
                  เพิ่มเอกสาร
                </span>
                <ArrowRight className="h-4 w-4" />
                <span className="flex items-center gap-1">
                  <span className="flex h-6 w-6 items-center justify-center rounded-full bg-muted text-muted-foreground text-xs">3</span>
                  ทดสอบ
                </span>
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
    </TooltipProvider>
  );
}
