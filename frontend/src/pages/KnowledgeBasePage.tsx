import { useState, useCallback, useMemo } from 'react';
import { useQueryClient } from '@tanstack/react-query';
import { Button } from '@/components/ui/button';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
  DialogTrigger,
} from '@/components/ui/dialog';
import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { Badge } from '@/components/ui/badge';
import { PageHeader } from '@/components/connections';
import { Panel, Metric, EmptyState } from '@/components/common';
import {
  useAllKnowledgeBases,
  useKnowledgeBase,
  useDocuments,
  useCreateKnowledgeBase,
  useDeleteKnowledgeBase,
  useCreateDocument,
  useDeleteDocument,
} from '@/hooks/useKnowledgeBase';
import { DocumentUpload } from '@/components/knowledge-base/DocumentUpload';
import { DocumentList } from '@/components/knowledge-base/DocumentList';
import { SemanticSearch } from '@/components/knowledge-base/SemanticSearch';
import { useKnowledgeBaseChannel } from '@/hooks/useEcho';
import { queryKeys } from '@/lib/query';
import type { DocumentStatusUpdatedEvent } from '@/types/realtime';
import type { Document, PaginatedResponse } from '@/types/api';
import {
  FileText,
  Layers,
  BookOpen,
  Plus,
  Database,
  Trash2,
  Loader2,
  Calendar,
} from 'lucide-react';

export function KnowledgeBasePage() {
  const [selectedKbId, setSelectedKbId] = useState<number | null>(null);
  const [isCreateDialogOpen, setIsCreateDialogOpen] = useState(false);
  const [isDeleteDialogOpen, setIsDeleteDialogOpen] = useState(false);
  const [newKbName, setNewKbName] = useState('');
  const [newKbDescription, setNewKbDescription] = useState('');

  const queryClient = useQueryClient();

  const { data: knowledgeBases, isLoading: isLoadingList } = useAllKnowledgeBases();
  const { data: knowledgeBase } = useKnowledgeBase(selectedKbId);
  const {
    data: documentsResponse,
    isLoading: isLoadingDocuments,
    refetch: refetchDocuments,
  } = useDocuments(selectedKbId);
  const documents = documentsResponse?.data ?? [];

  const createKbMutation = useCreateKnowledgeBase();
  const deleteKbMutation = useDeleteKnowledgeBase();
  const createDocMutation = useCreateDocument(selectedKbId);
  const deleteDocMutation = useDeleteDocument(selectedKbId);

  const handleDocumentStatusUpdate = useCallback(
    (event: DocumentStatusUpdatedEvent) => {
      if (!selectedKbId) return;
      const queryKey = [...queryKeys.knowledgeBase.detail(selectedKbId), 'documents'];
      queryClient.setQueryData<PaginatedResponse<Document>>(queryKey, (old) => {
        if (!old) return old;
        return {
          ...old,
          data: old.data.map((doc) =>
            doc.id === event.id
              ? {
                  ...doc,
                  status: event.status,
                  chunk_count: event.chunk_count ?? doc.chunk_count,
                  error_message: event.error_message,
                }
              : doc,
          ),
        };
      });
      if (event.status === 'completed') {
        queryClient.invalidateQueries({
          queryKey: queryKeys.knowledgeBase.detail(selectedKbId),
        });
      }
    },
    [selectedKbId, queryClient],
  );

  const realtimeCallbacks = useMemo(
    () => ({ onDocumentStatusUpdate: handleDocumentStatusUpdate }),
    [handleDocumentStatusUpdate],
  );
  useKnowledgeBaseChannel(selectedKbId, realtimeCallbacks);

  const handleCreateKb = useCallback(async () => {
    if (!newKbName.trim()) return;
    await createKbMutation.mutateAsync({
      name: newKbName.trim(),
      description: newKbDescription.trim() || undefined,
    });
    setNewKbName('');
    setNewKbDescription('');
    setIsCreateDialogOpen(false);
  }, [newKbName, newKbDescription, createKbMutation]);

  const handleDeleteKb = useCallback(async () => {
    if (!selectedKbId) return;
    await deleteKbMutation.mutateAsync(selectedKbId);
    setSelectedKbId(null);
    setIsDeleteDialogOpen(false);
  }, [selectedKbId, deleteKbMutation]);

  const handleUploadDocument = useCallback(
    async (data: { title: string; content: string }) => {
      await createDocMutation.mutateAsync(data);
    },
    [createDocMutation],
  );

  const handleDeleteDocument = useCallback(
    async (documentId: number) => {
      await deleteDocMutation.mutateAsync(documentId);
    },
    [deleteDocMutation],
  );

  // ----- Detail view -----
  if (selectedKbId && knowledgeBase) {
    return (
      <div className="space-y-6">
        <PageHeader
          title={knowledgeBase.name}
          description={knowledgeBase.description ?? undefined}
          breadcrumb={[
            { label: 'ฐานความรู้', to: '/knowledge-base' },
            { label: knowledgeBase.name },
          ]}
          actions={
            <Button
              variant="outline"
              size="sm"
              onClick={() => setIsDeleteDialogOpen(true)}
            >
              <Trash2 className="h-4 w-4 mr-2" strokeWidth={1.5} />
              ลบฐานความรู้
            </Button>
          }
        />

        <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
          <Metric label="เอกสาร" value={knowledgeBase.document_count ?? 0} icon={FileText} />
          <Metric label="Chunks" value={knowledgeBase.chunk_count ?? 0} icon={Layers} />
          <Metric
            label="Embedding"
            value={knowledgeBase.embedding_model ?? 'default'}
            icon={Database}
          />
          <Metric
            label="อัพเดทล่าสุด"
            value={new Date(knowledgeBase.updated_at).toLocaleDateString('th-TH')}
            icon={Calendar}
          />
        </div>

        <Panel title="เพิ่มเอกสาร" description="อัพโหลดเนื้อหาเพื่อให้ Bot ใช้ในการตอบคำถาม">
          <DocumentUpload
            onSubmit={handleUploadDocument}
            isSubmitting={createDocMutation.isPending}
          />
        </Panel>

        <Panel title="เอกสารทั้งหมด" description={`${documents.length} เอกสาร`}>
          <DocumentList
            documents={documents}
            isLoading={isLoadingDocuments}
            isDeleting={deleteDocMutation.isPending}
            onDelete={handleDeleteDocument}
            onRefresh={refetchDocuments}
          />
        </Panel>

        <Panel title="ทดสอบการค้นหา" description="ค้นหาเนื้อหาในฐานความรู้ด้วย Semantic Search">
          <SemanticSearch
            kbId={selectedKbId}
            hasChunks={(knowledgeBase.chunk_count ?? 0) > 0}
          />
        </Panel>

        <AlertDialog open={isDeleteDialogOpen} onOpenChange={setIsDeleteDialogOpen}>
          <AlertDialogContent>
            <AlertDialogHeader>
              <AlertDialogTitle>ยืนยันการลบฐานความรู้</AlertDialogTitle>
              <AlertDialogDescription>
                คุณแน่ใจหรือไม่ที่จะลบ "{knowledgeBase.name}"?
                การดำเนินการนี้ไม่สามารถย้อนกลับได้ และจะลบเอกสารทั้งหมดในฐานความรู้นี้ด้วย
              </AlertDialogDescription>
            </AlertDialogHeader>
            <AlertDialogFooter>
              <AlertDialogCancel>ยกเลิก</AlertDialogCancel>
              <AlertDialogAction
                onClick={handleDeleteKb}
                className="bg-destructive text-destructive-foreground hover:bg-destructive/90"
                disabled={deleteKbMutation.isPending}
              >
                {deleteKbMutation.isPending ? (
                  <Loader2 className="h-4 w-4 animate-spin mr-2" />
                ) : (
                  <Trash2 className="h-4 w-4 mr-2" strokeWidth={1.5} />
                )}
                ลบ
              </AlertDialogAction>
            </AlertDialogFooter>
          </AlertDialogContent>
        </AlertDialog>
      </div>
    );
  }

  // ----- List view -----
  return (
    <div className="space-y-6">
      <PageHeader
        title="ฐานความรู้"
        description="จัดการฐานความรู้สำหรับ Bot ของคุณ"
        actions={
          <Dialog open={isCreateDialogOpen} onOpenChange={setIsCreateDialogOpen}>
            <DialogTrigger asChild>
              <Button>
                <Plus className="h-4 w-4 mr-2" strokeWidth={2} />
                สร้างฐานความรู้
              </Button>
            </DialogTrigger>
            <DialogContent>
              <DialogHeader>
                <DialogTitle>สร้างฐานความรู้ใหม่</DialogTitle>
                <DialogDescription>
                  กรอกข้อมูลเพื่อสร้างฐานความรู้สำหรับเก็บเอกสารและข้อมูลของคุณ
                </DialogDescription>
              </DialogHeader>
              <div className="space-y-4">
                <div className="space-y-2">
                  <Label htmlFor="kb-name">ชื่อ *</Label>
                  <Input
                    id="kb-name"
                    placeholder="เช่น คู่มือผลิตภัณฑ์, FAQ"
                    value={newKbName}
                    onChange={(e) => setNewKbName(e.target.value)}
                  />
                </div>
                <div className="space-y-2">
                  <Label htmlFor="kb-description">คำอธิบาย</Label>
                  <Textarea
                    id="kb-description"
                    placeholder="อธิบายเกี่ยวกับฐานความรู้นี้..."
                    value={newKbDescription}
                    onChange={(e) => setNewKbDescription(e.target.value)}
                    rows={3}
                  />
                </div>
              </div>
              <DialogFooter>
                <Button variant="outline" onClick={() => setIsCreateDialogOpen(false)}>
                  ยกเลิก
                </Button>
                <Button
                  onClick={handleCreateKb}
                  disabled={!newKbName.trim() || createKbMutation.isPending}
                >
                  {createKbMutation.isPending ? (
                    <Loader2 className="h-4 w-4 animate-spin mr-2" />
                  ) : (
                    <Plus className="h-4 w-4 mr-2" strokeWidth={2} />
                  )}
                  สร้าง
                </Button>
              </DialogFooter>
            </DialogContent>
          </Dialog>
        }
      />

      {isLoadingList && (
        <div className="flex items-center justify-center py-12">
          <Loader2 className="h-6 w-6 animate-spin text-muted-foreground" />
        </div>
      )}

      {!isLoadingList && (!knowledgeBases || knowledgeBases.length === 0) && (
        <EmptyState
          icon={BookOpen}
          title="ยังไม่มีฐานความรู้"
          description="สร้างฐานความรู้เพื่อเก็บเอกสารและข้อมูลที่ Bot จะใช้ในการตอบคำถาม"
          action={
            <Button onClick={() => setIsCreateDialogOpen(true)}>
              <Plus className="h-4 w-4 mr-2" strokeWidth={2} />
              สร้างฐานความรู้แรก
            </Button>
          }
        />
      )}

      {!isLoadingList && knowledgeBases && knowledgeBases.length > 0 && (
        <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
          {knowledgeBases.map((kb) => (
            <button
              key={kb.id}
              onClick={() => setSelectedKbId(kb.id)}
              className="group flex flex-col rounded-lg border bg-card p-4 text-left transition-colors hover:bg-muted/40"
            >
              <div className="flex items-center gap-2">
                <BookOpen className="h-4 w-4 text-muted-foreground" strokeWidth={1.5} />
                <h3 className="font-medium">{kb.name}</h3>
              </div>
              {kb.description && (
                <p className="mt-1 text-sm text-muted-foreground line-clamp-2">
                  {kb.description}
                </p>
              )}
              <div className="mt-3 flex items-center gap-2">
                <Badge variant="secondary" className="text-xs tabular-nums">
                  <FileText className="h-3 w-3 mr-1" strokeWidth={1.5} />
                  {kb.document_count}
                </Badge>
                <Badge variant="outline" className="text-xs tabular-nums">
                  <Layers className="h-3 w-3 mr-1" strokeWidth={1.5} />
                  {kb.chunk_count}
                </Badge>
              </div>
              <p className="mt-3 text-xs text-muted-foreground">
                อัพเดท {new Date(kb.updated_at).toLocaleDateString('th-TH')}
              </p>
            </button>
          ))}
        </div>
      )}
    </div>
  );
}
