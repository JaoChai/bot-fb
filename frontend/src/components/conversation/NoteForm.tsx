import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { Textarea } from '@/components/ui/textarea';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import { Loader2, StickyNote, Brain, Bell, Save, X } from 'lucide-react';

type NoteType = 'note' | 'memory' | 'reminder';

interface NoteFormProps {
  initialContent?: string;
  initialType?: NoteType;
  onSave: (content: string, type: NoteType) => Promise<void>;
  onCancel: () => void;
  isPending: boolean;
}

export function NoteForm({
  initialContent = '',
  initialType = 'note',
  onSave,
  onCancel,
  isPending,
}: NoteFormProps) {
  const [content, setContent] = useState(initialContent);
  const [type, setType] = useState<NoteType>(initialType);

  const handleSave = async () => {
    if (!content.trim()) return;
    await onSave(content.trim(), type);
  };

  return (
    <div className="space-y-3">
      <Select value={type} onValueChange={(v) => setType(v as NoteType)}>
        <SelectTrigger className="w-32">
          <SelectValue />
        </SelectTrigger>
        <SelectContent>
          <SelectItem value="note">
            <div className="flex items-center gap-2">
              <StickyNote className="h-4 w-4" />
              Note
            </div>
          </SelectItem>
          <SelectItem value="memory">
            <div className="flex items-center gap-2">
              <Brain className="h-4 w-4" />
              Memory
            </div>
          </SelectItem>
          <SelectItem value="reminder">
            <div className="flex items-center gap-2">
              <Bell className="h-4 w-4" />
              Reminder
            </div>
          </SelectItem>
        </SelectContent>
      </Select>
      <Textarea
        placeholder="Write your note here..."
        value={content}
        onChange={(e) => setContent(e.target.value)}
        rows={3}
        className="resize-none"
      />
      <div className="flex justify-end gap-2">
        <Button variant="ghost" size="sm" onClick={onCancel}>
          <X className="h-4 w-4 mr-1" />
          Cancel
        </Button>
        <Button
          size="sm"
          onClick={handleSave}
          disabled={!content.trim() || isPending}
        >
          {isPending && <Loader2 className="h-4 w-4 mr-1 animate-spin" />}
          <Save className="h-4 w-4 mr-1" />
          Save
        </Button>
      </div>
    </div>
  );
}
