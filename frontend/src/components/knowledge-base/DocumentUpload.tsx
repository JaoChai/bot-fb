import { useState } from 'react';
import { Button } from '@/components/ui/button';
import {
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { Label } from '@/components/ui/label';
import { FileText, Loader2 } from 'lucide-react';

interface DocumentData {
  title: string;
  content: string;
}

interface DocumentUploadProps {
  onSubmit: (data: DocumentData) => Promise<void>;
  isSubmitting: boolean;
}

const MAX_CONTENT_LENGTH = 100000;

export function DocumentUpload({ onSubmit, isSubmitting }: DocumentUploadProps) {
  const [title, setTitle] = useState('');
  const [content, setContent] = useState('');
  const [error, setError] = useState<string | null>(null);

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setError(null);

    if (!title.trim()) {
      setError('Please provide a title');
      return;
    }

    if (!content.trim()) {
      setError('Please provide content');
      return;
    }

    if (content.length > MAX_CONTENT_LENGTH) {
      setError(`Content must not exceed ${MAX_CONTENT_LENGTH.toLocaleString()} characters`);
      return;
    }

    try {
      await onSubmit({ title: title.trim(), content: content.trim() });
      // Clear form on success
      setTitle('');
      setContent('');
    } catch (err) {
      setError((err as Error).message || 'Failed to create document');
    }
  };

  const characterCount = content.length;
  const characterPercentage = (characterCount / MAX_CONTENT_LENGTH) * 100;

  return (
    <Card>
      <CardHeader>
        <CardTitle className="flex items-center gap-2 text-lg">
          <FileText className="h-5 w-5" />
          เพิ่มเอกสาร
        </CardTitle>
        <CardDescription>
          เพิ่มข้อมูลความรู้ใหม่เข้าสู่ฐานความรู้ของ Bot
        </CardDescription>
      </CardHeader>
      <CardContent>
        <form onSubmit={handleSubmit} className="space-y-4">
          <div className="space-y-2">
            <Label htmlFor="title">ชื่อเอกสาร</Label>
            <Input
              id="title"
              value={title}
              onChange={(e) => setTitle(e.target.value)}
              placeholder="เช่น: คำถามที่พบบ่อย, ข้อมูลสินค้า"
              disabled={isSubmitting}
              maxLength={255}
            />
          </div>

          <div className="space-y-2">
            <div className="flex items-center justify-between">
              <Label htmlFor="content">เนื้อหา</Label>
              <span
                className={`text-xs ${
                  characterPercentage > 90
                    ? 'text-destructive'
                    : characterPercentage > 75
                    ? 'text-yellow-500'
                    : 'text-muted-foreground'
                }`}
              >
                {characterCount.toLocaleString()} / {MAX_CONTENT_LENGTH.toLocaleString()}
              </span>
            </div>
            <Textarea
              id="content"
              value={content}
              onChange={(e) => setContent(e.target.value)}
              placeholder="วางหรือพิมพ์เนื้อหาที่ต้องการให้ Bot เรียนรู้..."
              disabled={isSubmitting}
              rows={10}
              className="resize-y min-h-[200px]"
            />
          </div>

          {error && (
            <div className="rounded-md bg-destructive/10 p-3 text-sm text-destructive">
              {error}
            </div>
          )}

          <Button type="submit" disabled={isSubmitting || !title.trim() || !content.trim()}>
            {isSubmitting ? (
              <>
                <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                กำลังบันทึก...
              </>
            ) : (
              'บันทึกเอกสาร'
            )}
          </Button>
        </form>
      </CardContent>
    </Card>
  );
}
