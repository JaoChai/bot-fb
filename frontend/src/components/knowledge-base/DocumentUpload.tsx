import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { Label } from '@/components/ui/label';
import { FileText, Upload, Loader2, CheckCircle2, AlertCircle, Send } from 'lucide-react';
import { cn } from '@/lib/utils';

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
  const [success, setSuccess] = useState(false);
  const [activeTab, setActiveTab] = useState('text');

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setError(null);
    setSuccess(false);

    if (!title.trim()) {
      setError('กรุณาระบุชื่อเอกสาร');
      return;
    }

    if (!content.trim()) {
      setError('กรุณาระบุเนื้อหา');
      return;
    }

    if (content.length > MAX_CONTENT_LENGTH) {
      setError(`เนื้อหาต้องไม่เกิน ${MAX_CONTENT_LENGTH.toLocaleString()} ตัวอักษร`);
      return;
    }

    try {
      await onSubmit({ title: title.trim(), content: content.trim() });
      // Clear form on success
      setTitle('');
      setContent('');
      setSuccess(true);
      setTimeout(() => setSuccess(false), 3000);
    } catch (err) {
      setError((err as Error).message || 'ไม่สามารถบันทึกเอกสารได้');
    }
  };

  const characterCount = content.length;
  const characterPercentage = (characterCount / MAX_CONTENT_LENGTH) * 100;

  return (
    <div className="space-y-4">
      {/* Mode Toggle */}
      <div className="flex items-center gap-2 mb-4">
        <button
          type="button"
          onClick={() => setActiveTab('text')}
          className={cn(
            'flex items-center gap-2 px-4 py-2 rounded-lg text-sm font-medium transition-all',
            activeTab === 'text'
              ? 'bg-primary text-primary-foreground'
              : 'bg-muted/50 text-muted-foreground hover:bg-muted'
          )}
        >
          <FileText className="h-4 w-4" />
          พิมพ์ข้อความ
        </button>
        <button
          type="button"
          disabled
          className="flex items-center gap-2 px-4 py-2 rounded-lg text-sm font-medium bg-muted/30 text-muted-foreground cursor-not-allowed opacity-50"
        >
          <Upload className="h-4 w-4" />
          อัพโหลดไฟล์
          <span className="text-xs">(เร็วๆ นี้)</span>
        </button>
      </div>

      {activeTab === 'text' && (
        <form onSubmit={handleSubmit} className="space-y-4">
          {/* Title Input */}
          <div className="space-y-2">
            <Label htmlFor="title" className="text-sm font-medium">ชื่อเอกสาร</Label>
            <Input
              id="title"
              value={title}
              onChange={(e) => setTitle(e.target.value)}
              placeholder="เช่น: FAQ, ข้อมูลสินค้า, นโยบายการคืนสินค้า"
              disabled={isSubmitting}
              maxLength={255}
              className="max-w-lg"
            />
          </div>

          {/* Content Textarea */}
          <div className="space-y-2">
            <div className="flex items-center justify-between">
              <Label htmlFor="content" className="text-sm font-medium">เนื้อหา</Label>
              <span
                className={cn(
                  'text-xs tabular-nums px-2 py-0.5 rounded',
                  characterPercentage > 90
                    ? 'text-red-600 bg-red-50 dark:text-red-400 dark:bg-red-950'
                    : characterPercentage > 75
                    ? 'text-foreground bg-muted font-medium'
                    : 'text-muted-foreground bg-muted/50'
                )}
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
              rows={6}
              className="resize-y min-h-[150px] text-sm"
            />
            <p className="text-xs text-muted-foreground">
              เนื้อหาจะถูกแบ่งเป็น chunks และแปลงเป็น vector เพื่อให้ Bot ค้นหาความหมายได้
            </p>
          </div>

          {/* Messages */}
          {error && (
            <div className="flex items-center gap-2 rounded-lg bg-destructive/10 border border-destructive/20 p-3 text-sm text-destructive">
              <AlertCircle className="h-4 w-4 flex-shrink-0" />
              {error}
            </div>
          )}

          {success && (
            <div className="flex items-center gap-2 rounded-lg bg-green-50 dark:bg-green-950/50 border border-green-200 dark:border-green-800 p-3 text-sm text-green-700 dark:text-green-300">
              <CheckCircle2 className="h-4 w-4 flex-shrink-0" />
              บันทึกเอกสารสำเร็จ! กำลังประมวลผล...
            </div>
          )}

          {/* Submit Button */}
          <Button
            type="submit"
            variant="cta"
            disabled={isSubmitting || !title.trim() || !content.trim()}
            className="min-w-[160px]"
          >
            {isSubmitting ? (
              <>
                <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                กำลังบันทึก...
              </>
            ) : (
              <>
                <Send className="mr-2 h-4 w-4" />
                บันทึกเอกสาร
              </>
            )}
          </Button>
        </form>
      )}
    </div>
  );
}
