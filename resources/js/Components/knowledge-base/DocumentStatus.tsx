import * as React from "react";
import {
  File,
  FileText,
  Trash2,
  RefreshCw,
  CheckCircle,
  XCircle,
  Clock,
  Loader2,
} from "lucide-react";
import { Card, CardContent, CardHeader, CardTitle } from "@/Components/ui/card";
import { Badge } from "@/Components/ui/badge";
import { Button } from "@/Components/ui/button";
import { Progress } from "@/Components/ui/progress";
import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
  AlertDialogTrigger,
} from "@/Components/ui/alert-dialog";
import { cn } from "@/Lib/utils";

interface Document {
  id: number;
  filename: string;
  file_size: number;
  status: "pending" | "processing" | "completed" | "failed";
  chunk_count: number;
  error_message?: string | null;
  created_at: string;
  processed_at?: string | null;
}

interface DocumentStatusProps {
  documents: Document[];
  onDelete?: (documentId: number) => void;
  onRetry?: (documentId: number) => void;
}

function formatFileSize(bytes: number): string {
  if (bytes === 0) return "0 B";
  const k = 1024;
  const sizes = ["B", "KB", "MB", "GB"];
  const i = Math.floor(Math.log(bytes) / Math.log(k));
  return `${parseFloat((bytes / Math.pow(k, i)).toFixed(1))} ${sizes[i]}`;
}

function formatDate(dateString: string): string {
  const date = new Date(dateString);
  return date.toLocaleDateString("th-TH", {
    year: "numeric",
    month: "short",
    day: "numeric",
    hour: "2-digit",
    minute: "2-digit",
  });
}

const statusConfig = {
  pending: {
    label: "รอดำเนินการ",
    variant: "warning" as const,
    icon: Clock,
  },
  processing: {
    label: "กำลังประมวลผล",
    variant: "info" as const,
    icon: Loader2,
  },
  completed: {
    label: "เสร็จสิ้น",
    variant: "success" as const,
    icon: CheckCircle,
  },
  failed: {
    label: "ล้มเหลว",
    variant: "destructive" as const,
    icon: XCircle,
  },
};

function DocumentItem({
  document,
  onDelete,
  onRetry,
}: {
  document: Document;
  onDelete?: (documentId: number) => void;
  onRetry?: (documentId: number) => void;
}) {
  const config = statusConfig[document.status];
  const StatusIcon = config.icon;
  const isProcessing = document.status === "processing";
  const isFailed = document.status === "failed";

  return (
    <div className="flex items-start gap-4 rounded-lg border bg-card p-4 transition-colors hover:bg-accent/50">
      <div className="flex size-10 shrink-0 items-center justify-center rounded-lg bg-muted">
        <FileText className="size-5 text-muted-foreground" />
      </div>

      <div className="min-w-0 flex-1">
        <div className="flex items-start justify-between gap-2">
          <div className="min-w-0 flex-1">
            <p className="truncate font-medium text-foreground">
              {document.filename}
            </p>
            <p className="text-sm text-muted-foreground">
              {formatFileSize(document.file_size)} | อัปโหลดเมื่อ{" "}
              {formatDate(document.created_at)}
            </p>
          </div>

          <Badge variant={config.variant} className="shrink-0">
            <StatusIcon
              className={cn("size-3", isProcessing && "animate-spin")}
            />
            {config.label}
          </Badge>
        </div>

        {isProcessing && (
          <div className="mt-3">
            <Progress value={undefined} className="h-1.5" />
            <p className="mt-1 text-xs text-muted-foreground">
              กำลังประมวลผลเอกสาร...
            </p>
          </div>
        )}

        {document.status === "completed" && (
          <p className="mt-2 text-sm text-muted-foreground">
            <File className="mr-1 inline-block size-3" />
            {document.chunk_count} chunks |{" "}
            {document.processed_at && (
              <>ประมวลผลเมื่อ {formatDate(document.processed_at)}</>
            )}
          </p>
        )}

        {isFailed && document.error_message && (
          <p className="mt-2 text-sm text-destructive">
            {document.error_message}
          </p>
        )}

        <div className="mt-3 flex items-center gap-2">
          {isFailed && onRetry && (
            <Button
              variant="outline"
              size="sm"
              onClick={() => onRetry(document.id)}
            >
              <RefreshCw className="size-3" />
              ลองใหม่
            </Button>
          )}

          {onDelete && (
            <AlertDialog>
              <AlertDialogTrigger asChild>
                <Button variant="ghost" size="sm" className="text-destructive hover:text-destructive">
                  <Trash2 className="size-3" />
                  ลบ
                </Button>
              </AlertDialogTrigger>
              <AlertDialogContent>
                <AlertDialogHeader>
                  <AlertDialogTitle>ยืนยันการลบเอกสาร</AlertDialogTitle>
                  <AlertDialogDescription>
                    คุณต้องการลบเอกสาร "{document.filename}" หรือไม่?
                    การดำเนินการนี้ไม่สามารถย้อนกลับได้
                  </AlertDialogDescription>
                </AlertDialogHeader>
                <AlertDialogFooter>
                  <AlertDialogCancel>ยกเลิก</AlertDialogCancel>
                  <AlertDialogAction
                    onClick={() => onDelete(document.id)}
                    className="bg-destructive text-white hover:bg-destructive/90"
                  >
                    ลบเอกสาร
                  </AlertDialogAction>
                </AlertDialogFooter>
              </AlertDialogContent>
            </AlertDialog>
          )}
        </div>
      </div>
    </div>
  );
}

export function DocumentStatus({
  documents,
  onDelete,
  onRetry,
}: DocumentStatusProps) {
  if (documents.length === 0) {
    return (
      <Card>
        <CardContent className="flex flex-col items-center justify-center py-12">
          <div className="flex size-12 items-center justify-center rounded-full bg-muted">
            <File className="size-6 text-muted-foreground" />
          </div>
          <p className="mt-4 text-sm text-muted-foreground">
            ยังไม่มีเอกสารในฐานความรู้นี้
          </p>
        </CardContent>
      </Card>
    );
  }

  const stats = {
    total: documents.length,
    completed: documents.filter((d) => d.status === "completed").length,
    processing: documents.filter((d) => d.status === "processing").length,
    pending: documents.filter((d) => d.status === "pending").length,
    failed: documents.filter((d) => d.status === "failed").length,
  };

  return (
    <Card>
      <CardHeader className="pb-4">
        <CardTitle className="flex items-center justify-between">
          <span>เอกสาร ({stats.total})</span>
          <div className="flex gap-2">
            {stats.completed > 0 && (
              <Badge variant="success">{stats.completed} เสร็จสิ้น</Badge>
            )}
            {stats.processing > 0 && (
              <Badge variant="info">{stats.processing} กำลังประมวลผล</Badge>
            )}
            {stats.pending > 0 && (
              <Badge variant="warning">{stats.pending} รอดำเนินการ</Badge>
            )}
            {stats.failed > 0 && (
              <Badge variant="destructive">{stats.failed} ล้มเหลว</Badge>
            )}
          </div>
        </CardTitle>
      </CardHeader>
      <CardContent className="space-y-3">
        {documents.map((document) => (
          <DocumentItem
            key={document.id}
            document={document}
            onDelete={onDelete}
            onRetry={onRetry}
          />
        ))}
      </CardContent>
    </Card>
  );
}
