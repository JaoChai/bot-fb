import * as React from 'react';
import { useState, useCallback, useRef } from 'react';
import { Upload, File, X, Loader2, CheckCircle, FileText, AlertCircle } from 'lucide-react';
import { Button } from '@/Components/ui/button';
import { Card, CardContent } from '@/Components/ui/card';
import { Progress } from '@/Components/ui/progress';
import { cn } from '@/Lib/utils';

interface DocumentUploadProps {
  knowledgeBaseId: number;
  onUploadComplete?: () => void;
  onError?: (error: string) => void;
  maxFileSize?: number; // MB, default 10
  acceptedTypes?: string[]; // default ['.pdf', '.txt', '.docx']
}

interface FileWithPreview {
  file: File;
  id: string;
  preview: {
    name: string;
    size: string;
    type: string;
  };
}

type UploadStatus = 'idle' | 'uploading' | 'success' | 'error';

interface UploadState {
  status: UploadStatus;
  progress: number;
  error?: string;
}

const FILE_TYPE_LABELS: Record<string, string> = {
  'application/pdf': 'PDF',
  'text/plain': 'TXT',
  'application/vnd.openxmlformats-officedocument.wordprocessingml.document': 'DOCX',
};

const ACCEPTED_MIME_TYPES: Record<string, string[]> = {
  '.pdf': ['application/pdf'],
  '.txt': ['text/plain'],
  '.docx': ['application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
};

function formatFileSize(bytes: number): string {
  if (bytes === 0) return '0 Bytes';
  const k = 1024;
  const sizes = ['Bytes', 'KB', 'MB', 'GB'];
  const i = Math.floor(Math.log(bytes) / Math.log(k));
  return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
}

function generateId(): string {
  return Math.random().toString(36).substring(2, 9);
}

function getCsrfToken(): string {
  return document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content || '';
}

export function DocumentUpload({
  knowledgeBaseId,
  onUploadComplete,
  onError,
  maxFileSize = 10,
  acceptedTypes = ['.pdf', '.txt', '.docx'],
}: DocumentUploadProps) {
  const [files, setFiles] = useState<FileWithPreview[]>([]);
  const [uploadState, setUploadState] = useState<UploadState>({
    status: 'idle',
    progress: 0,
  });
  const [isDragOver, setIsDragOver] = useState(false);
  const fileInputRef = useRef<HTMLInputElement>(null);
  const abortControllerRef = useRef<AbortController | null>(null);

  const acceptedMimeTypes = acceptedTypes.flatMap((ext) => ACCEPTED_MIME_TYPES[ext] || []);
  const acceptString = acceptedTypes.join(',');
  const maxFileSizeBytes = maxFileSize * 1024 * 1024;

  const validateFile = useCallback(
    (file: File): string | null => {
      // Check file type
      const isValidType = acceptedMimeTypes.includes(file.type);
      if (!isValidType) {
        const allowedExtensions = acceptedTypes.join(', ').toUpperCase();
        return `ประเภทไฟล์ไม่รองรับ กรุณาใช้ไฟล์ ${allowedExtensions}`;
      }

      // Check file size
      if (file.size > maxFileSizeBytes) {
        return `ไฟล์มีขนาดใหญ่เกินไป (สูงสุด ${maxFileSize} MB)`;
      }

      return null;
    },
    [acceptedMimeTypes, acceptedTypes, maxFileSize, maxFileSizeBytes]
  );

  const addFiles = useCallback(
    (newFiles: FileList | File[]) => {
      const fileArray = Array.from(newFiles);
      const validFiles: FileWithPreview[] = [];
      const errors: string[] = [];

      for (const file of fileArray) {
        const error = validateFile(file);
        if (error) {
          errors.push(`${file.name}: ${error}`);
        } else {
          // Check for duplicates
          const isDuplicate = files.some((f) => f.file.name === file.name && f.file.size === file.size);
          if (!isDuplicate) {
            validFiles.push({
              file,
              id: generateId(),
              preview: {
                name: file.name,
                size: formatFileSize(file.size),
                type: FILE_TYPE_LABELS[file.type] || file.type.split('/')[1]?.toUpperCase() || 'FILE',
              },
            });
          }
        }
      }

      if (errors.length > 0) {
        onError?.(errors.join('\n'));
      }

      if (validFiles.length > 0) {
        setFiles((prev) => [...prev, ...validFiles]);
        setUploadState({ status: 'idle', progress: 0 });
      }
    },
    [files, validateFile, onError]
  );

  const removeFile = useCallback((id: string) => {
    setFiles((prev) => prev.filter((f) => f.id !== id));
    setUploadState({ status: 'idle', progress: 0 });
  }, []);

  const handleDragOver = useCallback((e: React.DragEvent) => {
    e.preventDefault();
    e.stopPropagation();
    setIsDragOver(true);
  }, []);

  const handleDragLeave = useCallback((e: React.DragEvent) => {
    e.preventDefault();
    e.stopPropagation();
    setIsDragOver(false);
  }, []);

  const handleDrop = useCallback(
    (e: React.DragEvent) => {
      e.preventDefault();
      e.stopPropagation();
      setIsDragOver(false);

      const droppedFiles = e.dataTransfer.files;
      if (droppedFiles.length > 0) {
        addFiles(droppedFiles);
      }
    },
    [addFiles]
  );

  const handleFileSelect = useCallback(
    (e: React.ChangeEvent<HTMLInputElement>) => {
      const selectedFiles = e.target.files;
      if (selectedFiles && selectedFiles.length > 0) {
        addFiles(selectedFiles);
      }
      // Reset input value to allow selecting the same file again
      e.target.value = '';
    },
    [addFiles]
  );

  const handleUpload = useCallback(async () => {
    if (files.length === 0) return;

    const formData = new FormData();
    files.forEach((fileWithPreview, index) => {
      formData.append(`documents[${index}]`, fileWithPreview.file);
    });

    abortControllerRef.current = new AbortController();

    setUploadState({ status: 'uploading', progress: 0 });

    try {
      const xhr = new XMLHttpRequest();

      // Create a promise to handle the upload
      const uploadPromise = new Promise<void>((resolve, reject) => {
        xhr.upload.addEventListener('progress', (event) => {
          if (event.lengthComputable) {
            const progress = Math.round((event.loaded / event.total) * 100);
            setUploadState((prev) => ({ ...prev, progress }));
          }
        });

        xhr.addEventListener('load', () => {
          if (xhr.status >= 200 && xhr.status < 300) {
            resolve();
          } else {
            let errorMessage = 'เกิดข้อผิดพลาดในการอัปโหลด';
            try {
              const response = JSON.parse(xhr.responseText);
              errorMessage = response.message || response.error || errorMessage;
            } catch {
              // Use default error message
            }
            reject(new Error(errorMessage));
          }
        });

        xhr.addEventListener('error', () => {
          reject(new Error('เกิดข้อผิดพลาดในการเชื่อมต่อ'));
        });

        xhr.addEventListener('abort', () => {
          reject(new Error('การอัปโหลดถูกยกเลิก'));
        });
      });

      // Handle abort
      abortControllerRef.current.signal.addEventListener('abort', () => {
        xhr.abort();
      });

      xhr.open('POST', `/api/knowledge-bases/${knowledgeBaseId}/documents`);
      xhr.setRequestHeader('X-CSRF-TOKEN', getCsrfToken());
      xhr.setRequestHeader('Accept', 'application/json');
      xhr.send(formData);

      await uploadPromise;

      setUploadState({ status: 'success', progress: 100 });
      setFiles([]);
      onUploadComplete?.();

      // Reset to idle after showing success
      setTimeout(() => {
        setUploadState({ status: 'idle', progress: 0 });
      }, 2000);
    } catch (error) {
      const errorMessage = error instanceof Error ? error.message : 'เกิดข้อผิดพลาดที่ไม่ทราบสาเหตุ';
      setUploadState({ status: 'error', progress: 0, error: errorMessage });
      onError?.(errorMessage);
    } finally {
      abortControllerRef.current = null;
    }
  }, [files, knowledgeBaseId, onUploadComplete, onError]);

  const handleCancel = useCallback(() => {
    abortControllerRef.current?.abort();
    setUploadState({ status: 'idle', progress: 0 });
  }, []);

  const openFileDialog = useCallback(() => {
    fileInputRef.current?.click();
  }, []);

  const isUploading = uploadState.status === 'uploading';
  const isSuccess = uploadState.status === 'success';
  const isError = uploadState.status === 'error';

  return (
    <div className="space-y-4">
      {/* Drop Zone */}
      <Card
        className={cn(
          'relative cursor-pointer border-2 border-dashed transition-colors',
          isDragOver && 'border-primary bg-primary/5',
          isError && 'border-destructive',
          !isDragOver && !isError && 'hover:border-primary/50'
        )}
        onDragOver={handleDragOver}
        onDragLeave={handleDragLeave}
        onDrop={handleDrop}
        onClick={openFileDialog}
      >
        <CardContent className="flex flex-col items-center justify-center py-10">
          <input
            ref={fileInputRef}
            type="file"
            accept={acceptString}
            multiple
            onChange={handleFileSelect}
            className="hidden"
            disabled={isUploading}
          />

          <div
            className={cn(
              'mb-4 rounded-full p-4 transition-colors',
              isDragOver ? 'bg-primary/10' : 'bg-muted'
            )}
          >
            <Upload
              className={cn('h-8 w-8', isDragOver ? 'text-primary' : 'text-muted-foreground')}
            />
          </div>

          <p className="mb-1 text-sm font-medium">
            {isDragOver ? 'วางไฟล์ที่นี่' : 'ลากไฟล์มาวางที่นี่ หรือคลิกเพื่อเลือก'}
          </p>
          <p className="text-xs text-muted-foreground">
            รองรับ {acceptedTypes.map((t) => t.toUpperCase().replace('.', '')).join(', ')} (สูงสุด{' '}
            {maxFileSize} MB)
          </p>
        </CardContent>
      </Card>

      {/* File Preview List */}
      {files.length > 0 && (
        <div className="space-y-2">
          <p className="text-sm font-medium">
            ไฟล์ที่เลือก ({files.length} ไฟล์)
          </p>
          <div className="space-y-2">
            {files.map((fileWithPreview) => (
              <Card key={fileWithPreview.id} className="py-3">
                <CardContent className="flex items-center gap-3 py-0">
                  <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-muted">
                    <FileText className="h-5 w-5 text-muted-foreground" />
                  </div>
                  <div className="min-w-0 flex-1">
                    <p className="truncate text-sm font-medium">{fileWithPreview.preview.name}</p>
                    <p className="text-xs text-muted-foreground">
                      {fileWithPreview.preview.type} - {fileWithPreview.preview.size}
                    </p>
                  </div>
                  <Button
                    variant="ghost"
                    size="icon-sm"
                    onClick={(e) => {
                      e.stopPropagation();
                      removeFile(fileWithPreview.id);
                    }}
                    disabled={isUploading}
                    className="shrink-0"
                  >
                    <X className="h-4 w-4" />
                    <span className="sr-only">ลบไฟล์</span>
                  </Button>
                </CardContent>
              </Card>
            ))}
          </div>
        </div>
      )}

      {/* Upload Progress */}
      {isUploading && (
        <div className="space-y-2">
          <div className="flex items-center justify-between text-sm">
            <span className="flex items-center gap-2 text-muted-foreground">
              <Loader2 className="h-4 w-4 animate-spin" />
              กำลังอัปโหลด...
            </span>
            <span className="font-medium">{uploadState.progress}%</span>
          </div>
          <Progress value={uploadState.progress} />
        </div>
      )}

      {/* Success State */}
      {isSuccess && (
        <div className="flex items-center gap-2 text-sm text-green-600 dark:text-green-400">
          <CheckCircle className="h-4 w-4" />
          <span>อัปโหลดสำเร็จ</span>
        </div>
      )}

      {/* Error State */}
      {isError && uploadState.error && (
        <div className="flex items-center gap-2 text-sm text-destructive">
          <AlertCircle className="h-4 w-4" />
          <span>{uploadState.error}</span>
        </div>
      )}

      {/* Action Buttons */}
      {files.length > 0 && (
        <div className="flex gap-2">
          <Button
            onClick={handleUpload}
            disabled={isUploading || isSuccess}
            className="flex-1"
          >
            {isUploading ? (
              <>
                <Loader2 className="h-4 w-4 animate-spin" />
                กำลังอัปโหลด...
              </>
            ) : (
              <>
                <Upload className="h-4 w-4" />
                อัปโหลด {files.length} ไฟล์
              </>
            )}
          </Button>
          {isUploading && (
            <Button variant="outline" onClick={handleCancel}>
              ยกเลิก
            </Button>
          )}
        </div>
      )}
    </div>
  );
}

export default DocumentUpload;
