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
import {
  FileText,
  Layers,
  BookOpen,
  Search,
  Plus,
  Database,
  ArrowLeft,
  Trash2,
  Loader2,
  Calendar,
} from 'lucide-react';

export function KnowledgeBasePage() {
  // State
  const [selectedKbId, setSelectedKbId] = useState<number | null>(null);
  const [isCreateDialogOpen, setIsCreateDialogOpen] = useState(false);
  const [isDeleteDialogOpen, setIsDeleteDialogOpen] = useState(false);
  const [newKbName, setNewKbName] = useState('');
  const [newKbDescription, setNewKbDescription] = useState('');

  // Queries
  const { data: knowledgeBases, isLoading: isLoadingList } = useAllKnowledgeBases();
  const { data: knowledgeBase } = useKnowledgeBase(selectedKbId);
  const { data: documentsResponse, isLoading: isLoadingDocuments, refetch: refetchDocuments } = useDocuments(selectedKbId);
  const documents = documentsResponse?.data ?? [];

  // Mutations
  const createKbMutation = useCreateKnowledgeBase();
  const deleteKbMutation = useDeleteKnowledgeBase();
  const createDocMutation = useCreateDocument(selectedKbId);
  const deleteDocMutation = useDeleteDocument(selectedKbId);

  // Handlers
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
    [createDocMutation]
  );

  const handleDeleteDocument = useCallback(
    async (documentId: number) => {
      await deleteDocMutation.mutateAsync(documentId);
    },
    [deleteDocMutation]
  );

  const handleBack = useCallback(() => {
    setSelectedKbId(null);
  }, []);

  const handleSelectKb = useCallback((kbId: number) => {
    setSelectedKbId(kbId);
  }, []);

  // Detail View
  if (selectedKbId && knowledgeBase) {
    return (
      <div className="space-y-6">
        {/* Header with Back and Delete buttons */}
        <div className="flex items-center justify-between">
          <div className="flex items-center gap-4">
            <Button variant="ghost" size="sm" onClick={handleBack}>
              <ArrowLeft className="h-4 w-4 mr-2" />
              กลับ
            </Button>
            <div>
              <h1 className="text-2xl font-bold tracking-tight">{knowledgeBase.name}</h1>
              {knowledgeBase.description && (
                <p className="text-muted-foreground">{knowledgeBase.description}</p>
              )}
            </div>
          </div>
          <Button
            variant="destructive"
            size="sm"
            onClick={() => setIsDeleteDialogOpen(true)}
          >
            <Trash2 className="h-4 w-4 mr-2" />
            ลบฐานความรู้
          </Button>
        </div>

        {/* Stats */}
        <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
          <Card>
            <CardContent className="pt-4">
              <div className="flex items-center gap-3">
                <div className="rounded-full bg-primary/10 p-2">
                  <FileText className="h-4 w-4 text-primary" />
                </div>
                <div>
                  <p className="text-2xl font-bold">{knowledgeBase.document_count ?? 0}</p>
                  <p className="text-xs text-muted-foreground">เอกสาร</p>
                </div>
              </div>
            </CardContent>
          </Card>
          <Card>
            <CardContent className="pt-4">
              <div className="flex items-center gap-3">
                <div className="rounded-full bg-warning/10 p-2">
                  <Layers className="h-4 w-4 text-warning" />
                </div>
                <div>
                  <p className="text-2xl font-bold">{knowledgeBase.chunk_count ?? 0}</p>
                  <p className="text-xs text-muted-foreground">Chunks</p>
                </div>
              </div>
            </CardContent>
          </Card>
          <Card>
            <CardContent className="pt-4">
              <div className="flex items-center gap-3">
                <div className="rounded-full bg-info/10 p-2">
                  <Database className="h-4 w-4 text-info" />
                </div>
                <div>
                  <p className="text-sm font-medium truncate max-w-[120px]">
                    {knowledgeBase.embedding_model ?? 'default'}
                  </p>
                  <p className="text-xs text-muted-foreground">Embedding</p>
                </div>
              </div>
            </CardContent>
          </Card>
          <Card>
            <CardContent className="pt-4">
              <div className="flex items-center gap-3">
                <div className="rounded-full bg-muted p-2">
                  <Calendar className="h-4 w-4 text-muted-foreground" />
                </div>
                <div>
                  <p className="text-sm font-medium">
                    {new Date(knowledgeBase.updated_at).toLocaleDateString('th-TH')}
                  </p>
                  <p className="text-xs text-muted-foreground">อัพเดทล่าสุด</p>
                </div>
              </div>
            </CardContent>
          </Card>
        </div>

        {/* Document Upload */}
        <Card>
          <CardHeader>
            <CardTitle className="flex items-center gap-2 text-lg">
              <Plus className="h-5 w-5" />
              เพิ่มเอกสาร
            </CardTitle>
            <CardDescription>
              อัพโหลดเนื้อหาเพื่อให้ Bot ใช้ในการตอบคำถาม
            </CardDescription>
          </CardHeader>
          <CardContent>
            <DocumentUpload
              onSubmit={handleUploadDocument}
              isSubmitting={createDocMutation.isPending}
            />
          </CardContent>
        </Card>

        {/* Document List */}
        <Card>
          <CardHeader>
            <CardTitle className="flex items-center gap-2 text-lg">
              <FileText className="h-5 w-5" />
              เอกสารทั้งหมด
            </CardTitle>
            <CardDescription>
              {documents.length} เอกสาร
            </CardDescription>
          </CardHeader>
          <CardContent>
            <DocumentList
              documents={documents}
              isLoading={isLoadingDocuments}
              isDeleting={deleteDocMutation.isPending}
              onDelete={handleDeleteDocument}
              onRefresh={refetchDocuments}
            />
          </CardContent>
        </Card>

        {/* Semantic Search */}
        <Card>
          <CardHeader>
            <CardTitle className="flex items-center gap-2 text-lg">
              <Search className="h-5 w-5" />
              ทดสอบการค้นหา
            </CardTitle>
            <CardDescription>
              ค้นหาเนื้อหาในฐานความรู้ด้วย Semantic Search
            </CardDescription>
          </CardHeader>
          <CardContent>
            <SemanticSearch
              kbId={selectedKbId}
              hasChunks={(knowledgeBase.chunk_count ?? 0) > 0}
            />
          </CardContent>
        </Card>

        {/* Delete Confirmation Dialog */}
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
                  <Trash2 className="h-4 w-4 mr-2" />
                )}
                ลบ
              </AlertDialogAction>
            </AlertDialogFooter>
          </AlertDialogContent>
        </AlertDialog>
      </div>
    );
  }

  // List View (Cards Grid)
  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-bold tracking-tight">ฐานความรู้</h1>
          <p className="text-muted-foreground">
            จัดการฐานความรู้สำหรับ Bot ของคุณ
          </p>
        </div>
        <Dialog open={isCreateDialogOpen} onOpenChange={setIsCreateDialogOpen}>
          <DialogTrigger asChild>
            <Button>
              <Plus className="h-4 w-4 mr-2" />
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
              <Button
                variant="outline"
                onClick={() => setIsCreateDialogOpen(false)}
              >
                ยกเลิก
              </Button>
              <Button
                onClick={handleCreateKb}
                disabled={!newKbName.trim() || createKbMutation.isPending}
              >
                {createKbMutation.isPending ? (
                  <Loader2 className="h-4 w-4 animate-spin mr-2" />
                ) : (
                  <Plus className="h-4 w-4 mr-2" />
                )}
                สร้าง
              </Button>
            </DialogFooter>
          </DialogContent>
        </Dialog>
      </div>

      {/* Loading State */}
      {isLoadingList && (
        <div className="flex items-center justify-center py-12">
          <Loader2 className="h-8 w-8 animate-spin text-muted-foreground" />
        </div>
      )}

      {/* Empty State */}
      {!isLoadingList && (!knowledgeBases || knowledgeBases.length === 0) && (
        <Card className="border-dashed border-2">
          <CardContent className="flex flex-col items-center justify-center py-16">
            <div className="mb-6 flex h-20 w-20 items-center justify-center rounded-full bg-muted">
              <BookOpen className="h-10 w-10 text-muted-foreground" />
            </div>
            <h3 className="text-xl font-semibold mb-2">ยังไม่มีฐานความรู้</h3>
            <p className="text-muted-foreground text-center max-w-sm mb-6">
              สร้างฐานความรู้เพื่อเก็บเอกสารและข้อมูลที่ Bot จะใช้ในการตอบคำถาม
            </p>
            <Button onClick={() => setIsCreateDialogOpen(true)}>
              <Plus className="h-4 w-4 mr-2" />
              สร้างฐานความรู้แรก
            </Button>
          </CardContent>
        </Card>
      )}

      {/* Knowledge Base Cards Grid */}
      {!isLoadingList && knowledgeBases && knowledgeBases.length > 0 && (
        <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
          {knowledgeBases.map((kb) => (
            <Card
              key={kb.id}
              className="cursor-pointer hover:border-primary/50 hover:shadow-md transition-all"
              onClick={() => handleSelectKb(kb.id)}
            >
              <CardHeader className="pb-2">
                <div className="flex items-start justify-between">
                  <div className="flex items-center gap-3">
                    <div className="rounded-full bg-primary/10 p-2">
                      <BookOpen className="h-5 w-5 text-primary" />
                    </div>
                    <div>
                      <CardTitle className="text-lg">{kb.name}</CardTitle>
                      {kb.description && (
                        <CardDescription className="line-clamp-1">
                          {kb.description}
                        </CardDescription>
                      )}
                    </div>
                  </div>
                </div>
              </CardHeader>
              <CardContent>
                <div className="flex items-center gap-3">
                  <Badge variant="secondary" className="text-xs">
                    <FileText className="h-3 w-3 mr-1" />
                    {kb.document_count} เอกสาร
                  </Badge>
                  <Badge variant="outline" className="text-xs">
                    <Layers className="h-3 w-3 mr-1" />
                    {kb.chunk_count} chunks
                  </Badge>
                </div>
                <p className="text-xs text-muted-foreground mt-3">
                  อัพเดท: {new Date(kb.updated_at).toLocaleDateString('th-TH')}
                </p>
              </CardContent>
            </Card>
          ))}
        </div>
      )}
    </div>
  );
}
