import { Button } from '@/components/ui/button';
import {
  Bold,
  Italic,
  Strikethrough,
  List,
  ListOrdered,
  Link2,
  Code2,
  Eye,
  EyeOff,
  Maximize2,
  BookOpen,
} from 'lucide-react';

interface MarkdownToolbarProps {
  onBold: () => void;
  onItalic: () => void;
  onStrikethrough: () => void;
  onHeading: (level: 1 | 2 | 3) => void;
  onBulletList: () => void;
  onNumberedList: () => void;
  onLink: () => void;
  onCode: () => void;
  onPreviewToggle: () => void;
  onFullscreen: () => void;
  isPreviewMode: boolean;
}

export function MarkdownToolbar({
  onBold,
  onItalic,
  onStrikethrough,
  onHeading,
  onBulletList,
  onNumberedList,
  onLink,
  onCode,
  onPreviewToggle,
  onFullscreen,
  isPreviewMode,
}: MarkdownToolbarProps) {
  return (
    <div className="flex items-center gap-1 p-2 bg-muted border-b rounded-t-lg flex-wrap">
      {/* Text Formatting */}
      <div className="flex items-center gap-1 border-r pr-2">
        <Button
          variant="ghost"
          size="sm"
          onClick={onBold}
          title="Bold (Ctrl+B)"
          aria-label="Bold"
          className="size-8 p-0"
        >
          <Bold className="size-4" />
        </Button>
        <Button
          variant="ghost"
          size="sm"
          onClick={onItalic}
          title="Italic (Ctrl+I)"
          aria-label="Italic"
          className="size-8 p-0"
        >
          <Italic className="size-4" />
        </Button>
        <Button
          variant="ghost"
          size="sm"
          onClick={onStrikethrough}
          title="Strikethrough"
          aria-label="Strikethrough"
          className="size-8 p-0"
        >
          <Strikethrough className="size-4" />
        </Button>
      </div>

      {/* Headings */}
      <div className="flex items-center gap-1 border-r px-2">
        <Button
          variant="ghost"
          size="sm"
          onClick={() => onHeading(1)}
          title="Heading 1"
          aria-label="Heading 1"
          className="size-8 p-0 text-sm font-bold"
        >
          H1
        </Button>
        <Button
          variant="ghost"
          size="sm"
          onClick={() => onHeading(2)}
          title="Heading 2"
          aria-label="Heading 2"
          className="size-8 p-0 text-sm font-bold"
        >
          H2
        </Button>
        <Button
          variant="ghost"
          size="sm"
          onClick={() => onHeading(3)}
          title="Heading 3"
          aria-label="Heading 3"
          className="size-8 p-0 text-sm font-bold"
        >
          H3
        </Button>
      </div>

      {/* Lists */}
      <div className="flex items-center gap-1 border-r px-2">
        <Button
          variant="ghost"
          size="sm"
          onClick={onBulletList}
          title="Bullet List"
          aria-label="Bullet List"
          className="size-8 p-0"
        >
          <List className="size-4" />
        </Button>
        <Button
          variant="ghost"
          size="sm"
          onClick={onNumberedList}
          title="Numbered List"
          aria-label="Numbered List"
          className="size-8 p-0"
        >
          <ListOrdered className="size-4" />
        </Button>
      </div>

      {/* Code & Links */}
      <div className="flex items-center gap-1 border-r px-2">
        <Button
          variant="ghost"
          size="sm"
          onClick={onCode}
          title="Code Block"
          aria-label="Code Block"
          className="size-8 p-0"
        >
          <Code2 className="size-4" />
        </Button>
        <Button
          variant="ghost"
          size="sm"
          onClick={onLink}
          title="Link"
          aria-label="Link"
          className="size-8 p-0"
        >
          <Link2 className="size-4" />
        </Button>
      </div>

      {/* Preview & Help */}
      <div className="flex items-center gap-1 ml-auto">
        <Button
          variant="ghost"
          size="sm"
          onClick={onPreviewToggle}
          title={isPreviewMode ? 'Edit Mode' : 'Preview Mode'}
          aria-label={isPreviewMode ? 'Edit Mode' : 'Preview Mode'}
          className="size-8 p-0"
        >
          {isPreviewMode ? <EyeOff className="size-4" /> : <Eye className="size-4" />}
        </Button>
        <Button
          variant="ghost"
          size="sm"
          onClick={onFullscreen}
          title="Fullscreen"
          aria-label="Fullscreen"
          className="size-8 p-0"
        >
          <Maximize2 className="size-4" />
        </Button>
        <Button
          variant="ghost"
          size="sm"
          asChild
          title="Markdown Guide"
          aria-label="Markdown Guide"
          className="size-8 p-0"
        >
          <a href="https://www.markdownguide.org" target="_blank" rel="noopener noreferrer">
            <BookOpen className="size-4" />
          </a>
        </Button>
      </div>
    </div>
  );
}
