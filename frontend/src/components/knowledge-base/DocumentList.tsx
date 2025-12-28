import { useState } from 'react';
import { Button } from '@/components/ui/button';
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
import { Badge } from '@/components/ui/badge';
import { cn } from '@/lib/utils';
import type { Document } from '@/types/api';
import {
  FileText,
  File,
  Trash2,
  RefreshCw,
  Loader2,
  CheckCircle2,
  Clock,
  AlertCircle,
  Layers,
} from 'lucide-react';

interface DocumentListProps {
  documents: Document[];
  isLoading: boolean;
  isDeleting: boolean;
  onDelete: (documentId: number) => Promise<void>;
  onRefresh: () => void;
}

const STATUS_CONFIG = {
  pending: {
    label: 'รอดำเนินการ',
    icon: Clock,
    className: 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900/30 dark:text-yellow-400 border-yellow-200 dark:border-yellow-800',
  },
  processing: {
    label: 'กำลังประมวลผล',
    icon: Loader2,
    className: 'bg-blue-100 text-blue-800 dark:bg-blue-900/30 dark:text-blue-400 border-blue-200 dark:border-blue-800',
    animate: true,
  },
  completed: {
    label: 'สำเร็จ',
    icon: CheckCircle2,
    className: 'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-400 border-green-200 dark:border-green-800',
  },
  failed: {
    label: 'ล้มเหลว',
    icon: AlertCircle,
    className: 'bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-400 border-red-200 dark:border-red-800',
  },
};

function getFileIcon(mimeType: string) {
  if (mimeType === 'application/pdf') {
    return <File className="h-5 w-5 text-red-500" />;
  }
  return <FileText className="h-5 w-5 text-blue-500" />;
}

export function DocumentList({
  documents,
  isLoading,
  isDeleting,
  onDelete,
  onRefresh,
}: DocumentListProps) {
  const [deleteDialogOpen, setDeleteDialogOpen] = useState(false);
  const [documentToDelete, setDocumentToDelete] = useState<Document | null>(null);

  const handleDeleteClick = (document: Document) => {
    setDocumentToDelete(document);
    setDeleteDialogOpen(true);
  };

  const handleConfirmDelete = async () => {
    if (documentToDelete) {
      await onDelete(documentToDelete.id);
      setDeleteDialogOpen(false);
      setDocumentToDelete(null);
    }
  };

  const handleCancelDelete = () => {
    setDeleteDialogOpen(false);
    setDocumentToDelete(null);
  };

  // Loading state
  if (isLoading) {
    return (
      <div className="flex items-center justify-center py-12">
        <Loader2 className="h-8 w-8 animate-spin text-muted-foreground" />
      </div>
    );
  }

  // Empty state
  if (documents.length === 0) {
    return (
      <div className="flex flex-col items-center justify-center py-12 text-center">
        <div className="mb-4 rounded-full bg-muted p-4">
          <FileText className="h-8 w-8 text-muted-foreground" />
        </div>
        <p className="text-sm font-medium text-foreground mb-1">
          ยังไม่มีเอกสาร
        </p>
        <p className="text-xs text-muted-foreground max-w-xs">
          เพิ่มเอกสารแรกของคุณด้านบนเพื่อให้ Bot เริ่มเรียนรู้
        </p>
      </div>
    );
  }

  return (
    <>
      <div className="flex justify-end mb-3">
        <Button variant="outline" size="sm" onClick={onRefresh}>
          <RefreshCw className="mr-2 h-4 w-4" />
          รีเฟรช
        </Button>
      </div>

      <div className="divide-y rounded-lg border">
        {documents.map((doc) => {
          const status = STATUS_CONFIG[doc.status] || STATUS_CONFIG.pending;
          const StatusIcon = status.icon;

          return (
            <div
              key={doc.id}
              className="flex items-center justify-between p-4 hover:bg-muted/50 transition-colors"
            >
              <div className="flex items-center gap-3 min-w-0 flex-1">
                {getFileIcon(doc.mime_type)}
                <div className="min-w-0 flex-1">
                  <p className="text-sm font-medium truncate">
                    {doc.original_filename}
                  </p>
                  <div className="flex flex-wrap items-center gap-2 mt-1">
                    <span className="text-xs text-muted-foreground">
                      {doc.file_size_formatted}
                    </span>

                    <Badge
                      variant="outline"
                      className={cn(
                        'text-xs font-normal gap-1',
                        status.className
                      )}
                    >
                      <StatusIcon
                        className={cn(
                          'h-3 w-3',
                          (status as { animate?: boolean }).animate && 'animate-spin'
                        )}
                      />
                      {status.label}
                    </Badge>

                    {doc.chunk_count > 0 && (
                      <span className="flex items-center gap-1 text-xs text-muted-foreground">
                        <Layers className="h-3 w-3" />
                        {doc.chunk_count} chunks
                      </span>
                    )}
                  </div>

                  {doc.error_message && (
                    <p className="mt-1 text-xs text-destructive flex items-center gap-1">
                      <AlertCircle className="h-3 w-3" />
                      {doc.error_message}
                    </p>
                  )}
                </div>
              </div>

              <Button
                variant="ghost"
                size="icon"
                onClick={() => handleDeleteClick(doc)}
                disabled={isDeleting}
                className="text-muted-foreground hover:text-destructive hover:bg-destructive/10 flex-shrink-0 cursor-pointer"
              >
                <Trash2 className="h-4 w-4" />
              </Button>
            </div>
          );
        })}
      </div>

      <AlertDialog open={deleteDialogOpen} onOpenChange={setDeleteDialogOpen}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>ลบเอกสาร</AlertDialogTitle>
            <AlertDialogDescription>
              คุณต้องการลบ "{documentToDelete?.original_filename}" หรือไม่?
              การกระทำนี้ไม่สามารถยกเลิกได้
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel onClick={handleCancelDelete}>
              ยกเลิก
            </AlertDialogCancel>
            <AlertDialogAction
              onClick={handleConfirmDelete}
              className="bg-destructive text-white hover:bg-destructive/90"
            >
              {isDeleting ? (
                <>
                  <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                  กำลังลบ...
                </>
              ) : (
                'ลบเอกสาร'
              )}
            </AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    </>
  );
}
