import { useState } from 'react';
import { Input } from '@/components/ui/input';
import { ScrollArea } from '@/components/ui/scroll-area';
import { Loader2, Search, Zap } from 'lucide-react';
import { useActiveQuickReplies } from '@/hooks/useQuickReplies';
import { cn } from '@/lib/utils';
import type { QuickReply } from '@/types/quick-reply';

interface QuickReplyListProps {
  onSelect: (quickReply: QuickReply) => void;
}

export function QuickReplyList({ onSelect }: QuickReplyListProps) {
  const [search, setSearch] = useState('');
  const { data: quickReplies, isLoading, error } = useActiveQuickReplies();

  const filteredReplies = quickReplies?.filter((qr) => {
    if (!search) return true;
    const searchLower = search.toLowerCase();
    return (
      qr.shortcut.toLowerCase().includes(searchLower) ||
      qr.title.toLowerCase().includes(searchLower) ||
      qr.content.toLowerCase().includes(searchLower)
    );
  });

  return (
    <div className="flex flex-col max-h-[400px]">
      {/* Header */}
      <div className="flex items-center gap-2 px-3 py-2 border-b">
        <Zap className="h-4 w-4 text-primary" />
        <span className="font-medium text-sm">Quick Replies</span>
      </div>

      {/* Search */}
      <div className="p-2 border-b">
        <div className="relative">
          <Search className="absolute left-2.5 top-1/2 -translate-y-1/2 h-4 w-4 text-muted-foreground" />
          <Input
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            placeholder="ค้นหา..."
            className="pl-8 h-8 text-sm"
            autoFocus
          />
        </div>
      </div>

      {/* List */}
      <ScrollArea className="flex-1">
        {isLoading ? (
          <div className="flex items-center justify-center py-8">
            <Loader2 className="h-5 w-5 animate-spin text-muted-foreground" />
          </div>
        ) : error ? (
          <div className="px-3 py-4 text-sm text-destructive text-center">
            เกิดข้อผิดพลาด กรุณาลองใหม่
          </div>
        ) : !filteredReplies || filteredReplies.length === 0 ? (
          <div className="px-3 py-8 text-sm text-muted-foreground text-center">
            {search ? 'ไม่พบ Quick Reply ที่ตรงกัน' : 'ยังไม่มี Quick Reply'}
          </div>
        ) : (
          <div className="py-1">
            {filteredReplies.map((qr) => (
              <button
                key={qr.id}
                type="button"
                className={cn(
                  'w-full text-left px-3 py-2 hover:bg-accent transition-colors',
                  'focus:bg-accent focus:outline-none'
                )}
                onClick={() => onSelect(qr)}
              >
                <div className="flex items-center gap-2">
                  <span className="font-mono text-xs text-primary bg-primary/10 px-1.5 py-0.5 rounded">
                    /{qr.shortcut}
                  </span>
                  <span className="text-sm font-medium truncate flex-1">
                    {qr.title}
                  </span>
                </div>
                <p className="text-xs text-muted-foreground mt-1 line-clamp-2">
                  {qr.content}
                </p>
              </button>
            ))}
          </div>
        )}
      </ScrollArea>

      {/* Footer */}
      <div className="px-3 py-2 border-t bg-muted/50">
        <p className="text-xs text-muted-foreground">
          พิมพ์ <kbd className="font-mono bg-background px-1 rounded">/</kbd> ในช่องข้อความเพื่อใช้งาน
        </p>
      </div>
    </div>
  );
}
