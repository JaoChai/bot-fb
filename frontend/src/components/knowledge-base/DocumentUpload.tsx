import { useCallback, useState, useRef } from 'react';
import { Button } from '@/components/ui/button';
import {
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from '@/components/ui/card';
import { cn } from '@/lib/utils';

interface DocumentUploadProps {
  onUpload: (file: File) => Promise<void>;
  isUploading: boolean;
}

const ACCEPTED_FILE_TYPES = [
  'application/pdf',
  'text/plain',
  'text/markdown',
  'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
  'application/msword',
];

const ACCEPTED_EXTENSIONS = '.pdf,.txt,.md,.docx,.doc';
const MAX_FILE_SIZE = 10 * 1024 * 1024; // 10MB

export function DocumentUpload({ onUpload, isUploading }: DocumentUploadProps) {
  const [isDragOver, setIsDragOver] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const fileInputRef = useRef<HTMLInputElement>(null);

  const validateFile = useCallback((file: File): string | null => {
    if (!ACCEPTED_FILE_TYPES.includes(file.type) && !file.name.match(/\.(pdf|txt|md|docx|doc)$/i)) {
      return 'File type not supported. Please upload PDF, TXT, MD, or Word documents.';
    }
    if (file.size > MAX_FILE_SIZE) {
      return 'File size exceeds 10MB limit.';
    }
    return null;
  }, []);

  const handleFile = useCallback(
    async (file: File) => {
      setError(null);
      const validationError = validateFile(file);
      if (validationError) {
        setError(validationError);
        return;
      }

      try {
        await onUpload(file);
      } catch (err) {
        setError((err as Error).message || 'Upload failed');
      }
    },
    [onUpload, validateFile]
  );

  const handleDragOver = useCallback((e: React.DragEvent) => {
    e.preventDefault();
    setIsDragOver(true);
  }, []);

  const handleDragLeave = useCallback((e: React.DragEvent) => {
    e.preventDefault();
    setIsDragOver(false);
  }, []);

  const handleDrop = useCallback(
    async (e: React.DragEvent) => {
      e.preventDefault();
      setIsDragOver(false);

      const file = e.dataTransfer.files[0];
      if (file) {
        await handleFile(file);
      }
    },
    [handleFile]
  );

  const handleFileSelect = useCallback(
    async (e: React.ChangeEvent<HTMLInputElement>) => {
      const file = e.target.files?.[0];
      if (file) {
        await handleFile(file);
      }
      // Reset input to allow re-uploading same file
      if (fileInputRef.current) {
        fileInputRef.current.value = '';
      }
    },
    [handleFile]
  );

  const handleButtonClick = useCallback(() => {
    fileInputRef.current?.click();
  }, []);

  return (
    <Card>
      <CardHeader>
        <CardTitle className="text-lg">Upload Document</CardTitle>
        <CardDescription>
          Drag and drop a file or click to browse. Supports PDF, TXT, MD, and
          Word documents up to 10MB.
        </CardDescription>
      </CardHeader>
      <CardContent>
        <div
          onDragOver={handleDragOver}
          onDragLeave={handleDragLeave}
          onDrop={handleDrop}
          className={cn(
            'flex flex-col items-center justify-center rounded-lg border-2 border-dashed p-8 transition-colors',
            isDragOver
              ? 'border-primary bg-primary/5'
              : 'border-muted-foreground/25 hover:border-muted-foreground/50',
            isUploading && 'pointer-events-none opacity-50'
          )}
        >
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
              <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4" />
              <polyline points="17 8 12 3 7 8" />
              <line x1="12" y1="3" x2="12" y2="15" />
            </svg>
          </div>

          <p className="mb-2 text-sm text-muted-foreground">
            {isDragOver ? 'Drop file here' : 'Drag and drop your file here'}
          </p>

          <p className="mb-4 text-xs text-muted-foreground">or</p>

          <input
            ref={fileInputRef}
            type="file"
            accept={ACCEPTED_EXTENSIONS}
            onChange={handleFileSelect}
            className="hidden"
            disabled={isUploading}
          />

          <Button
            type="button"
            variant="outline"
            onClick={handleButtonClick}
            disabled={isUploading}
          >
            {isUploading ? (
              <>
                <svg
                  className="mr-2 h-4 w-4 animate-spin"
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
                Uploading...
              </>
            ) : (
              'Browse files'
            )}
          </Button>

          <p className="mt-4 text-xs text-muted-foreground">
            PDF, TXT, MD, DOCX (max 10MB)
          </p>
        </div>

        {error && (
          <div className="mt-4 rounded-md bg-destructive/10 p-3 text-sm text-destructive">
            {error}
          </div>
        )}
      </CardContent>
    </Card>
  );
}
