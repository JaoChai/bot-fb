import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { Label } from '@/components/ui/label';
import { Progress } from '@/components/ui/progress';
import {
  Tabs,
  TabsContent,
  TabsList,
  TabsTrigger,
} from '@/components/ui/tabs';
import { FileText, Upload, Loader2, CheckCircle2, AlertCircle } from 'lucide-react';
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
      <Tabs value={activeTab} onValueChange={setActiveTab} className="w-full">
        <TabsList className="grid w-full grid-cols-2 max-w-[300px]">
          <TabsTrigger value="text" className="flex items-center gap-2">
            <FileText className="h-4 w-4" />
            พิมพ์ข้อความ
          </TabsTrigger>
          <TabsTrigger value="file" className="flex items-center gap-2" disabled>
            <Upload className="h-4 w-4" />
            อัพโหลดไฟล์
          </TabsTrigger>
        </TabsList>

        <TabsContent value="text" className="mt-4">
          <form onSubmit={handleSubmit} className="space-y-4">
            <div className="space-y-2">
              <Label htmlFor="title">ชื่อเอกสาร</Label>
              <Input
                id="title"
                value={title}
                onChange={(e) => setTitle(e.target.value)}
                placeholder="เช่น: คำถามที่พบบ่อย, ข้อมูลสินค้า, นโยบายบริษัท"
                disabled={isSubmitting}
                maxLength={255}
                className="max-w-xl"
              />
            </div>

            <div className="space-y-2">
              <div className="flex items-center justify-between">
                <Label htmlFor="content">เนื้อหา</Label>
                <span
                  className={cn(
                    'text-xs tabular-nums',
                    characterPercentage > 90
                      ? 'text-destructive font-medium'
                      : characterPercentage > 75
                      ? 'text-yellow-600 dark:text-yellow-400'
                      : 'text-muted-foreground'
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
                rows={8}
                className="resize-y min-h-[180px] font-mono text-sm"
              />
              {characterPercentage > 0 && (
                <Progress
                  value={characterPercentage}
                  className={cn(
                    'h-1',
                    characterPercentage > 90 && '[&>div]:bg-destructive',
                    characterPercentage > 75 && characterPercentage <= 90 && '[&>div]:bg-yellow-500'
                  )}
                />
              )}
              <p className="text-xs text-muted-foreground">
                เนื้อหาจะถูกแบ่งเป็น chunks และแปลงเป็น vector เพื่อให้ Bot ค้นหาได้
              </p>
            </div>

            {/* Error */}
            {error && (
              <div className="flex items-center gap-2 rounded-lg bg-destructive/10 border border-destructive/20 p-3 text-sm text-destructive">
                <AlertCircle className="h-4 w-4 flex-shrink-0" />
                {error}
              </div>
            )}

            {/* Success */}
            {success && (
              <div className="flex items-center gap-2 rounded-lg bg-green-50 dark:bg-green-950 border border-green-200 dark:border-green-800 p-3 text-sm text-green-700 dark:text-green-300">
                <CheckCircle2 className="h-4 w-4 flex-shrink-0" />
                บันทึกเอกสารสำเร็จ กำลังประมวลผล...
              </div>
            )}

            <Button
              type="submit"
              disabled={isSubmitting || !title.trim() || !content.trim()}
              className="min-w-[140px]"
            >
              {isSubmitting ? (
                <>
                  <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                  กำลังบันทึก...
                </>
              ) : (
                <>
                  <FileText className="mr-2 h-4 w-4" />
                  บันทึกเอกสาร
                </>
              )}
            </Button>
          </form>
        </TabsContent>

        <TabsContent value="file" className="mt-4">
          <div className="flex flex-col items-center justify-center py-10 border-2 border-dashed rounded-lg bg-muted/30">
            <Upload className="h-10 w-10 text-muted-foreground mb-3" />
            <p className="text-sm text-muted-foreground mb-1">
              ลากไฟล์มาวางที่นี่ หรือคลิกเพื่อเลือกไฟล์
            </p>
            <p className="text-xs text-muted-foreground">
              รองรับ: PDF, TXT, DOCX (เร็วๆ นี้)
            </p>
          </div>
        </TabsContent>
      </Tabs>
    </div>
  );
}
