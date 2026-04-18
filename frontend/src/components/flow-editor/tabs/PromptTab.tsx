import { useRef, useState } from 'react';
import { FileText, Maximize2, Star } from 'lucide-react';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { Button } from '@/components/ui/button';
import { Switch } from '@/components/ui/switch';
import {
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import {
  Tooltip,
  TooltipContent,
  TooltipProvider,
  TooltipTrigger,
} from '@/components/ui/tooltip';
import { MarkdownToolbar } from '@/components/MarkdownToolbar';
import { SettingSection, SettingRow } from '@/components/connections';
import { cn } from '@/lib/utils';

interface PromptTabProps {
  name: string;
  systemPrompt: string;
  isDefault: boolean;
  onChange: <K extends 'name' | 'system_prompt' | 'is_default'>(
    field: K,
    value: K extends 'is_default' ? boolean : string,
  ) => void;
}

export function PromptTab({ name, systemPrompt, isDefault, onChange }: PromptTabProps) {
  const textareaRef = useRef<HTMLTextAreaElement>(null);
  const fullscreenRef = useRef<HTMLTextAreaElement>(null);
  const [isFullscreen, setIsFullscreen] = useState(false);
  const [previewMode, setPreviewMode] = useState<'edit' | 'preview'>('edit');
  const [fullscreenPreview, setFullscreenPreview] = useState<'edit' | 'preview'>('edit');

  const tokenEstimate = Math.ceil(systemPrompt.length / 4);

  const handleMarkdownAction = (action: string, target: HTMLTextAreaElement | null) => {
    if (!target) return;
    const start = target.selectionStart;
    const end = target.selectionEnd;
    const text = systemPrompt;
    const selectedText = text.substring(start, end);

    let insertText = '';
    switch (action) {
      case 'bold':
        insertText = selectedText ? `**${selectedText}**` : '**bold text**';
        break;
      case 'italic':
        insertText = selectedText ? `*${selectedText}*` : '*italic text*';
        break;
      case 'strikethrough':
        insertText = selectedText ? `~~${selectedText}~~` : '~~strikethrough text~~';
        break;
      case 'h1':
        insertText = selectedText ? `# ${selectedText}` : '# Heading 1';
        break;
      case 'h2':
        insertText = selectedText ? `## ${selectedText}` : '## Heading 2';
        break;
      case 'h3':
        insertText = selectedText ? `### ${selectedText}` : '### Heading 3';
        break;
      case 'bullet':
        insertText = selectedText ? `- ${selectedText}` : '- Bullet point';
        break;
      case 'numbered':
        insertText = selectedText ? `1. ${selectedText}` : '1. Numbered item';
        break;
      case 'code':
        insertText = selectedText ? `\`${selectedText}\`` : '`code`';
        break;
      case 'link':
        insertText = selectedText ? `[${selectedText}](url)` : '[link text](url)';
        break;
    }

    onChange('system_prompt', text.substring(0, start) + insertText + text.substring(end));
  };

  const lineCount = systemPrompt.split('\n').length;
  const wordCount = systemPrompt.split(/\s+/).filter(Boolean).length;

  return (
    <div className="space-y-6">
      <div className="border rounded-lg p-5 space-y-4">
        <SettingSection
          icon={FileText}
          title="ชื่อ Flow"
          description="ระบุชื่อที่จดจำง่ายสำหรับ Flow นี้"
        >
          <SettingRow label="ชื่อ" htmlFor="flow-name" orientation="vertical">
            <TooltipProvider>
              <Tooltip>
                <TooltipTrigger asChild>
                  <div>
                    <Input
                      id="flow-name"
                      placeholder="เช่น: ตอบคำถามลูกค้าทั่วไป"
                      value={name}
                      onChange={(e) => onChange('name', e.target.value)}
                      disabled={isDefault}
                    />
                  </div>
                </TooltipTrigger>
                {isDefault && (
                  <TooltipContent side="bottom">
                    Base Flow ไม่สามารถเปลี่ยนชื่อได้ — ปิด "Set as Default" ใน Agent tab ก่อน
                  </TooltipContent>
                )}
              </Tooltip>
            </TooltipProvider>
          </SettingRow>
        </SettingSection>
      </div>

      <div className="border rounded-lg p-5 space-y-4">
        <SettingSection
          icon={FileText}
          title="System Prompt"
          description="คำสั่งหลักที่ควบคุมบุคลิกและพฤติกรรมของ AI"
          action={
            <Button
              variant="outline"
              size="sm"
              onClick={() => setIsFullscreen(true)}
              className="gap-2"
            >
              <Maximize2 className="h-3.5 w-3.5" />
              เต็มจอ
            </Button>
          }
        >
          <div className="border rounded-md overflow-hidden bg-background">
            <MarkdownToolbar
              onBold={() => handleMarkdownAction('bold', textareaRef.current)}
              onItalic={() => handleMarkdownAction('italic', textareaRef.current)}
              onStrikethrough={() => handleMarkdownAction('strikethrough', textareaRef.current)}
              onHeading={(level) => handleMarkdownAction(`h${level}`, textareaRef.current)}
              onBulletList={() => handleMarkdownAction('bullet', textareaRef.current)}
              onNumberedList={() => handleMarkdownAction('numbered', textareaRef.current)}
              onLink={() => handleMarkdownAction('link', textareaRef.current)}
              onCode={() => handleMarkdownAction('code', textareaRef.current)}
              onPreviewToggle={() =>
                setPreviewMode((m) => (m === 'edit' ? 'preview' : 'edit'))
              }
              onFullscreen={() => setIsFullscreen(true)}
              isPreviewMode={previewMode === 'preview'}
            />

            {/* Edit / Preview toggle header */}
            <div className="flex items-center justify-between gap-2 px-3 py-2 border-b bg-muted/20">
              <div className="inline-flex rounded-md border bg-background p-0.5">
                <button
                  type="button"
                  onClick={() => setPreviewMode('edit')}
                  className={cn(
                    'rounded px-2.5 py-1 text-xs font-medium transition-colors',
                    previewMode === 'edit'
                      ? 'bg-accent text-foreground'
                      : 'text-muted-foreground hover:text-foreground',
                  )}
                >
                  แก้ไข
                </button>
                <button
                  type="button"
                  onClick={() => setPreviewMode('preview')}
                  className={cn(
                    'rounded px-2.5 py-1 text-xs font-medium transition-colors',
                    previewMode === 'preview'
                      ? 'bg-accent text-foreground'
                      : 'text-muted-foreground hover:text-foreground',
                  )}
                >
                  ดูตัวอย่าง
                </button>
              </div>
              <span className="text-xs text-muted-foreground tabular-nums">
                ~{tokenEstimate} tokens · {systemPrompt.length} ตัวอักษร
              </span>
            </div>

            {previewMode === 'edit' ? (
              <Textarea
                ref={textareaRef}
                placeholder="คุณคือผู้ช่วย AI ที่เป็นมิตร..."
                className="min-h-[320px] max-h-[520px] overflow-y-auto font-mono text-sm border-0 rounded-none focus-visible:ring-0 resize-y"
                value={systemPrompt}
                onChange={(e) => onChange('system_prompt', e.target.value)}
              />
            ) : (
              <div className="min-h-[320px] max-h-[520px] overflow-y-auto p-4 text-sm whitespace-pre-wrap bg-muted/30">
                {systemPrompt || (
                  <span className="text-muted-foreground">(ไม่มีเนื้อหา)</span>
                )}
              </div>
            )}

            <div className="flex justify-end gap-4 text-xs text-muted-foreground px-4 py-2 border-t bg-muted/30">
              <span className="tabular-nums">lines: {lineCount}</span>
              <span className="tabular-nums">words: {wordCount}</span>
            </div>
          </div>
        </SettingSection>
      </div>

      <div className="border rounded-lg p-5">
        <SettingSection
          icon={Star}
          title="Flow เริ่มต้น"
          description="ใช้ Flow นี้เป็น Flow หลักของบอท"
        >
          <SettingRow label="ตั้งเป็น Flow เริ่มต้น" htmlFor="is-default-toggle">
            <Switch
              id="is-default-toggle"
              checked={isDefault}
              onCheckedChange={(checked) => onChange('is_default', checked)}
            />
          </SettingRow>
        </SettingSection>
      </div>

      <Dialog open={isFullscreen} onOpenChange={setIsFullscreen}>
        <DialogContent className="max-w-[95vw] max-h-[95vh] w-[95vw] h-[95vh] flex flex-col p-0 gap-0">
          <DialogHeader className="px-5 py-3 border-b">
            <DialogTitle className="text-base">System Prompt</DialogTitle>
          </DialogHeader>
          <div className="flex-1 flex flex-col overflow-hidden">
            <MarkdownToolbar
              onBold={() => handleMarkdownAction('bold', fullscreenRef.current)}
              onItalic={() => handleMarkdownAction('italic', fullscreenRef.current)}
              onStrikethrough={() => handleMarkdownAction('strikethrough', fullscreenRef.current)}
              onHeading={(level) => handleMarkdownAction(`h${level}`, fullscreenRef.current)}
              onBulletList={() => handleMarkdownAction('bullet', fullscreenRef.current)}
              onNumberedList={() => handleMarkdownAction('numbered', fullscreenRef.current)}
              onLink={() => handleMarkdownAction('link', fullscreenRef.current)}
              onCode={() => handleMarkdownAction('code', fullscreenRef.current)}
              onPreviewToggle={() =>
                setFullscreenPreview((m) => (m === 'edit' ? 'preview' : 'edit'))
              }
              onFullscreen={() => setIsFullscreen(false)}
              isPreviewMode={fullscreenPreview === 'preview'}
            />

            {/* Edit / Preview toggle header (fullscreen) */}
            <div className="flex items-center justify-between gap-2 px-3 py-2 border-b bg-muted/20">
              <div className="inline-flex rounded-md border bg-background p-0.5">
                <button
                  type="button"
                  onClick={() => setFullscreenPreview('edit')}
                  className={cn(
                    'rounded px-2.5 py-1 text-xs font-medium transition-colors',
                    fullscreenPreview === 'edit'
                      ? 'bg-accent text-foreground'
                      : 'text-muted-foreground hover:text-foreground',
                  )}
                >
                  แก้ไข
                </button>
                <button
                  type="button"
                  onClick={() => setFullscreenPreview('preview')}
                  className={cn(
                    'rounded px-2.5 py-1 text-xs font-medium transition-colors',
                    fullscreenPreview === 'preview'
                      ? 'bg-accent text-foreground'
                      : 'text-muted-foreground hover:text-foreground',
                  )}
                >
                  ดูตัวอย่าง
                </button>
              </div>
              <span className="text-xs text-muted-foreground tabular-nums">
                ~{tokenEstimate} tokens · {systemPrompt.length} ตัวอักษร
              </span>
            </div>

            {fullscreenPreview === 'edit' ? (
              <Textarea
                ref={fullscreenRef}
                value={systemPrompt}
                onChange={(e) => onChange('system_prompt', e.target.value)}
                className="flex-1 font-mono text-sm border-0 rounded-none focus-visible:ring-0 resize-none"
              />
            ) : (
              <div className="flex-1 overflow-y-auto p-5 text-sm whitespace-pre-wrap bg-muted/30">
                {systemPrompt || (
                  <span className="text-muted-foreground">(ไม่มีเนื้อหา)</span>
                )}
              </div>
            )}

            <div className="flex justify-end gap-4 text-xs text-muted-foreground px-5 py-2 border-t bg-muted/30">
              <span className="tabular-nums">lines: {lineCount}</span>
              <span className="tabular-nums">words: {wordCount}</span>
            </div>
          </div>
        </DialogContent>
      </Dialog>
    </div>
  );
}
