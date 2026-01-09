import { useState, useCallback } from 'react';
import { Head, Link, router, usePage } from '@inertiajs/react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Button } from '@/Components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import { Badge } from '@/Components/ui/badge';
import { DocumentUpload } from '@/Components/knowledge-base/DocumentUpload';
import { DocumentStatus } from '@/Components/knowledge-base/DocumentStatus';
import { SearchTest } from '@/Components/knowledge-base/SearchTest';
import { useEcho } from '@/Hooks/useEcho';
import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
} from '@/Components/ui/alert-dialog';
import {
  ArrowLeft,
  FileText,
  Layers,
  Cpu,
  Clock,
  AlertCircle,
  Loader2,
  Pencil,
  Trash2,
  CheckCircle,
} from 'lucide-react';
import type { SharedProps } from '@/types';
import { cn } from '@/Lib/utils';

interface Document {
  id: number;
  filename: string;
  file_size: number;
  status: 'pending' | 'processing' | 'completed' | 'failed';
  chunk_count: number;
  error_message?: string | null;
  created_at: string;
  processed_at?: string | null;
}

interface Props extends SharedProps {
  knowledgeBase: {
    id: number;
    name: string;
    description: string | null;
    document_count: number;
    chunk_count: number;
    status: 'active' | 'processing' | 'error';
    embedding_model: string;
    created_at: string;
  };
  documents: {
    data: Document[];
    current_page: number;
    last_page: number;
  };
  bot: {
    id: number;
    name: string;
  };
}

export default function Show() {
  const { knowledgeBase, documents, bot, flash } = usePage<Props>().props;
  const [deleteDialogOpen, setDeleteDialogOpen] = useState(false);
  const [isDeleting, setIsDeleting] = useState(false);
  const [uploadError, setUploadError] = useState<string | null>(null);

  // Real-time updates for document status changes
  useEcho({
    channel: `knowledge-base.${knowledgeBase.id}`,
    event: 'DocumentStatusUpdated',
    reloadOnly: ['documents', 'knowledgeBase'],
  });

  const handleUploadComplete = useCallback(() => {
    router.reload({
      only: ['documents', 'knowledgeBase'],
      preserveScroll: true,
    });
  }, []);

  const handleUploadError = useCallback((error: string) => {
    setUploadError(error);
    // Clear error after 5 seconds
    setTimeout(() => setUploadError(null), 5000);
  }, []);

  const handleDeleteDocument = useCallback((documentId: number) => {
    router.delete(`/api/knowledge-bases/${knowledgeBase.id}/documents/${documentId}`, {
      preserveScroll: true,
      onSuccess: () => {
        router.reload({
          only: ['documents', 'knowledgeBase'],
          preserveScroll: true,
        });
      },
    });
  }, [knowledgeBase.id]);

  const handleRetryDocument = useCallback((documentId: number) => {
    router.post(`/api/knowledge-bases/${knowledgeBase.id}/documents/${documentId}/retry`, {}, {
      preserveScroll: true,
      onSuccess: () => {
        router.reload({
          only: ['documents', 'knowledgeBase'],
          preserveScroll: true,
        });
      },
    });
  }, [knowledgeBase.id]);

  const handleDeleteKnowledgeBase = () => {
    setIsDeleting(true);
    router.delete(`/knowledge-base/${knowledgeBase.id}`, {
      onFinish: () => {
        setIsDeleting(false);
        setDeleteDialogOpen(false);
      },
    });
  };

  const getStatusBadge = (status: Props['knowledgeBase']['status']) => {
    switch (status) {
      case 'active':
        return (
          <Badge variant="success" className="gap-1">
            <CheckCircle className="h-3 w-3" />
            พร้อมใช้งาน
          </Badge>
        );
      case 'processing':
        return (
          <Badge variant="warning" className="gap-1">
            <Loader2 className="h-3 w-3 animate-spin" />
            กำลังประมวลผล
          </Badge>
        );
      case 'error':
        return (
          <Badge variant="destructive" className="gap-1">
            <AlertCircle className="h-3 w-3" />
            ผิดพลาด
          </Badge>
        );
      default:
        return <Badge variant="outline">{status}</Badge>;
    }
  };

  const formatDate = (dateString: string) => {
    return new Date(dateString).toLocaleDateString('th-TH', {
      year: 'numeric',
      month: 'short',
      day: 'numeric',
      hour: '2-digit',
      minute: '2-digit',
    });
  };

  return (
    <AuthenticatedLayout header={knowledgeBase.name}>
      <Head title={`${knowledgeBase.name} - ฐานความรู้`} />

      <div className="space-y-6 max-w-7xl mx-auto">
        {/* Header */}
        <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
          <div className="flex items-center gap-4">
            <Button variant="ghost" size="icon" asChild>
              <Link href={`/knowledge-base?bot_id=${bot.id}`}>
                <ArrowLeft className="h-4 w-4" />
                <span className="sr-only">กลับ</span>
              </Link>
            </Button>
            <div>
              <div className="flex items-center gap-3">
                <h1 className="text-xl sm:text-2xl font-semibold tracking-tight">
                  {knowledgeBase.name}
                </h1>
                {getStatusBadge(knowledgeBase.status)}
              </div>
              {knowledgeBase.description && (
                <p className="text-muted-foreground text-sm mt-1">
                  {knowledgeBase.description}
                </p>
              )}
            </div>
          </div>

          <div className="flex items-center gap-2 ml-auto">
            <Button variant="outline" size="sm" asChild>
              <Link href={`/knowledge-base/${knowledgeBase.id}/edit`}>
                <Pencil className="h-4 w-4 mr-2" />
                แก้ไข
              </Link>
            </Button>
            <Button
              variant="destructive"
              size="sm"
              onClick={() => setDeleteDialogOpen(true)}
            >
              <Trash2 className="h-4 w-4 mr-2" />
              ลบ
            </Button>
          </div>
        </div>

        {/* Flash Messages */}
        {flash?.success && (
          <div className="rounded-lg border bg-green-50 dark:bg-green-950 p-4 text-green-700 dark:text-green-300">
            {flash.success}
          </div>
        )}
        {flash?.error && (
          <div className="rounded-lg border bg-red-50 dark:bg-red-950 p-4 text-red-700 dark:text-red-300">
            {flash.error}
          </div>
        )}
        {uploadError && (
          <div className="rounded-lg border bg-red-50 dark:bg-red-950 p-4 text-red-700 dark:text-red-300">
            {uploadError}
          </div>
        )}

        {/* Stats Row */}
        <div className="grid grid-cols-2 sm:grid-cols-4 gap-4">
          <Card>
            <CardContent className="p-4">
              <div className="flex items-center gap-3">
                <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-blue-100 dark:bg-blue-900/30">
                  <FileText className="h-5 w-5 text-blue-600 dark:text-blue-400" />
                </div>
                <div>
                  <p className="text-2xl font-semibold">{knowledgeBase.document_count}</p>
                  <p className="text-xs text-muted-foreground">เอกสาร</p>
                </div>
              </div>
            </CardContent>
          </Card>

          <Card>
            <CardContent className="p-4">
              <div className="flex items-center gap-3">
                <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-purple-100 dark:bg-purple-900/30">
                  <Layers className="h-5 w-5 text-purple-600 dark:text-purple-400" />
                </div>
                <div>
                  <p className="text-2xl font-semibold">{knowledgeBase.chunk_count}</p>
                  <p className="text-xs text-muted-foreground">Chunks</p>
                </div>
              </div>
            </CardContent>
          </Card>

          <Card>
            <CardContent className="p-4">
              <div className="flex items-center gap-3">
                <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-green-100 dark:bg-green-900/30">
                  <Cpu className="h-5 w-5 text-green-600 dark:text-green-400" />
                </div>
                <div>
                  <p className="text-sm font-semibold truncate max-w-[120px]" title={knowledgeBase.embedding_model}>
                    {knowledgeBase.embedding_model}
                  </p>
                  <p className="text-xs text-muted-foreground">โมเดล</p>
                </div>
              </div>
            </CardContent>
          </Card>

          <Card>
            <CardContent className="p-4">
              <div className="flex items-center gap-3">
                <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-orange-100 dark:bg-orange-900/30">
                  <Clock className="h-5 w-5 text-orange-600 dark:text-orange-400" />
                </div>
                <div>
                  <p className="text-sm font-semibold">{formatDate(knowledgeBase.created_at).split(' ').slice(0, 3).join(' ')}</p>
                  <p className="text-xs text-muted-foreground">สร้างเมื่อ</p>
                </div>
              </div>
            </CardContent>
          </Card>
        </div>

        {/* Two Column Layout */}
        <div className="grid gap-6 lg:grid-cols-2">
          {/* Left Column - Documents */}
          <div className="space-y-6">
            {/* Document Upload */}
            <Card>
              <CardHeader>
                <CardTitle className="text-lg">อัปโหลดเอกสาร</CardTitle>
              </CardHeader>
              <CardContent>
                <DocumentUpload
                  knowledgeBaseId={knowledgeBase.id}
                  onUploadComplete={handleUploadComplete}
                  onError={handleUploadError}
                />
              </CardContent>
            </Card>

            {/* Document Status */}
            <DocumentStatus
              documents={documents.data}
              onDelete={handleDeleteDocument}
              onRetry={handleRetryDocument}
            />

            {/* Pagination */}
            {documents.last_page > 1 && (
              <div className="flex items-center justify-center gap-2">
                <Button
                  variant="outline"
                  size="sm"
                  disabled={documents.current_page === 1}
                  onClick={() => router.get(`/knowledge-base/${knowledgeBase.id}`, {
                    page: documents.current_page - 1,
                  }, { preserveScroll: true })}
                >
                  ก่อนหน้า
                </Button>
                <span className="text-sm text-muted-foreground">
                  หน้า {documents.current_page} จาก {documents.last_page}
                </span>
                <Button
                  variant="outline"
                  size="sm"
                  disabled={documents.current_page === documents.last_page}
                  onClick={() => router.get(`/knowledge-base/${knowledgeBase.id}`, {
                    page: documents.current_page + 1,
                  }, { preserveScroll: true })}
                >
                  ถัดไป
                </Button>
              </div>
            )}
          </div>

          {/* Right Column - Search Test */}
          <div>
            <SearchTest knowledgeBaseId={knowledgeBase.id} />
          </div>
        </div>

        {/* Delete Confirmation Dialog */}
        <AlertDialog open={deleteDialogOpen} onOpenChange={setDeleteDialogOpen}>
          <AlertDialogContent>
            <AlertDialogHeader>
              <AlertDialogTitle>ลบฐานความรู้</AlertDialogTitle>
              <AlertDialogDescription>
                คุณแน่ใจหรือไม่ว่าต้องการลบ "{knowledgeBase.name}"?
                <br />
                <br />
                การดำเนินการนี้ไม่สามารถยกเลิกได้ และเอกสารทั้งหมด ({knowledgeBase.document_count} ไฟล์)
                รวมถึง chunks ({knowledgeBase.chunk_count} รายการ) จะถูกลบถาวร
              </AlertDialogDescription>
            </AlertDialogHeader>
            <AlertDialogFooter>
              <AlertDialogCancel>ยกเลิก</AlertDialogCancel>
              <AlertDialogAction
                onClick={handleDeleteKnowledgeBase}
                disabled={isDeleting}
                className="bg-destructive text-destructive-foreground hover:bg-destructive/90"
              >
                {isDeleting ? (
                  <>
                    <Loader2 className="h-4 w-4 animate-spin mr-2" />
                    กำลังลบ...
                  </>
                ) : (
                  <>
                    <Trash2 className="h-4 w-4 mr-2" />
                    ลบฐานความรู้
                  </>
                )}
              </AlertDialogAction>
            </AlertDialogFooter>
          </AlertDialogContent>
        </AlertDialog>
      </div>
    </AuthenticatedLayout>
  );
}
