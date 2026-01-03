import { useEffect, useRef, useState } from 'react';
import { Loader2, Zap } from 'lucide-react';
import { useQuickReplySearch } from '@/hooks/useQuickReplies';
import { cn } from '@/lib/utils';
import type { QuickReply } from '@/types/quick-reply';

interface QuickReplyAutocompleteProps {
  inputValue: string;
  onSelect: (quickReply: QuickReply) => void;
  onClose: () => void;
}

export function QuickReplyAutocomplete({
  inputValue,
  onSelect,
  onClose,
}: QuickReplyAutocompleteProps) {
  const [selectedIndex, setSelectedIndex] = useState(0);
  const listRef = useRef<HTMLDivElement>(null);

  // Extract shortcut query from input (e.g., "/hello" -> "hello")
  const shortcutMatch = inputValue.match(/^\/([a-z0-9_-]*)$/i);
  const query = shortcutMatch?.[1] ?? '';
  const shouldShow = shortcutMatch !== null;

  const { data: quickReplies, isLoading } = useQuickReplySearch(query, shouldShow);

  // Reset selection when results change
  useEffect(() => {
    setSelectedIndex(0);
  }, [quickReplies]);

  // Keyboard navigation
  useEffect(() => {
    if (!shouldShow || !quickReplies?.length) return;

    const handleKeyDown = (e: KeyboardEvent) => {
      switch (e.key) {
        case 'ArrowDown':
          e.preventDefault();
          setSelectedIndex((prev) =>
            prev < quickReplies.length - 1 ? prev + 1 : prev
          );
          break;
        case 'ArrowUp':
          e.preventDefault();
          setSelectedIndex((prev) => (prev > 0 ? prev - 1 : 0));
          break;
        case 'Enter':
        case 'Tab':
          e.preventDefault();
          if (quickReplies[selectedIndex]) {
            onSelect(quickReplies[selectedIndex]);
          }
          break;
        case 'Escape':
          e.preventDefault();
          onClose();
          break;
      }
    };

    document.addEventListener('keydown', handleKeyDown);
    return () => document.removeEventListener('keydown', handleKeyDown);
  }, [shouldShow, quickReplies, selectedIndex, onSelect, onClose]);

  // Scroll selected item into view
  useEffect(() => {
    if (listRef.current && quickReplies?.length) {
      const selectedItem = listRef.current.children[selectedIndex] as HTMLElement;
      selectedItem?.scrollIntoView({ block: 'nearest' });
    }
  }, [selectedIndex, quickReplies]);

  if (!shouldShow) return null;

  return (
    <div
      className="absolute bottom-full left-0 right-0 mb-2 bg-popover border rounded-lg shadow-lg z-50 overflow-hidden"
    >
      {/* Header */}
      <div className="flex items-center gap-2 px-3 py-2 border-b bg-muted/50">
        <Zap className="h-4 w-4 text-primary" />
        <span className="text-sm font-medium">Quick Replies</span>
        {query && (
          <span className="text-xs text-muted-foreground">
            ค้นหา: /{query}
          </span>
        )}
      </div>

      {/* Results */}
      <div ref={listRef} className="max-h-[200px] overflow-y-auto">
        {isLoading ? (
          <div className="flex items-center justify-center py-4">
            <Loader2 className="h-4 w-4 animate-spin text-muted-foreground" />
          </div>
        ) : !quickReplies || quickReplies.length === 0 ? (
          <div className="px-3 py-4 text-sm text-muted-foreground text-center">
            {query ? `ไม่พบ /${query}` : 'พิมพ์ shortcut เพื่อค้นหา'}
          </div>
        ) : (
          quickReplies.map((qr, index) => (
            <button
              key={qr.id}
              type="button"
              className={cn(
                'w-full text-left px-3 py-2 transition-colors',
                'focus:outline-none',
                index === selectedIndex ? 'bg-accent' : 'hover:bg-accent/50'
              )}
              onClick={() => onSelect(qr)}
              onMouseEnter={() => setSelectedIndex(index)}
            >
              <div className="flex items-center gap-2">
                <span className="font-mono text-xs text-primary bg-primary/10 px-1.5 py-0.5 rounded">
                  /{qr.shortcut}
                </span>
                <span className="text-sm font-medium truncate flex-1">
                  {qr.title}
                </span>
              </div>
              <p className="text-xs text-muted-foreground mt-0.5 line-clamp-1">
                {qr.content}
              </p>
            </button>
          ))
        )}
      </div>

      {/* Footer hint */}
      <div className="px-3 py-1.5 border-t bg-muted/30 text-xs text-muted-foreground">
        <kbd className="font-mono bg-background px-1 rounded">Tab</kbd> หรือ{' '}
        <kbd className="font-mono bg-background px-1 rounded">Enter</kbd> เพื่อเลือก
      </div>
    </div>
  );
}
