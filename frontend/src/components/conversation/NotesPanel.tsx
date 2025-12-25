import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { Textarea } from '@/components/ui/textarea';
import { Badge } from '@/components/ui/badge';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
  AlertDialogTrigger,
} from '@/components/ui/alert-dialog';
import {
  useConversationNotes,
  useAddNote,
  useUpdateNote,
  useDeleteNote,
} from '@/hooks/useConversations';
import { useToast } from '@/hooks/use-toast';
import {
  Loader2,
  Plus,
  Pencil,
  Trash2,
  StickyNote,
  Brain,
  Bell,
  Save,
  X,
} from 'lucide-react';
import type { ConversationNote } from '@/types/api';
import { formatDistanceToNow } from 'date-fns';
import { th } from 'date-fns/locale';

interface NotesPanelProps {
  botId: number;
  conversationId: number;
}

const noteTypeIcons = {
  note: StickyNote,
  memory: Brain,
  reminder: Bell,
};

const noteTypeLabels = {
  note: 'Note',
  memory: 'Memory',
  reminder: 'Reminder',
};

const noteTypeColors = {
  note: 'bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200',
  memory: 'bg-purple-100 text-purple-800 dark:bg-purple-900 dark:text-purple-200',
  reminder: 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-200',
};

export function NotesPanel({ botId, conversationId }: NotesPanelProps) {
  const { toast } = useToast();
  const [isAdding, setIsAdding] = useState(false);
  const [editingId, setEditingId] = useState<string | null>(null);
  const [newContent, setNewContent] = useState('');
  const [newType, setNewType] = useState<'note' | 'memory' | 'reminder'>('note');
  const [editContent, setEditContent] = useState('');
  const [editType, setEditType] = useState<'note' | 'memory' | 'reminder'>('note');

  const { data: notes, isLoading } = useConversationNotes(botId, conversationId);
  const addNote = useAddNote(botId);
  const updateNote = useUpdateNote(botId);
  const deleteNote = useDeleteNote(botId);

  const handleAddNote = async () => {
    if (!newContent.trim()) return;

    try {
      await addNote.mutateAsync({
        conversationId,
        data: { content: newContent.trim(), type: newType },
      });
      toast({ title: 'Note added', description: 'Your note has been saved.' });
      setNewContent('');
      setNewType('note');
      setIsAdding(false);
    } catch {
      toast({
        title: 'Error',
        description: 'Failed to add note.',
        variant: 'destructive',
      });
    }
  };

  const handleUpdateNote = async (noteId: string) => {
    if (!editContent.trim()) return;

    try {
      await updateNote.mutateAsync({
        conversationId,
        noteId,
        data: { content: editContent.trim(), type: editType },
      });
      toast({ title: 'Note updated', description: 'Your changes have been saved.' });
      setEditingId(null);
    } catch {
      toast({
        title: 'Error',
        description: 'Failed to update note.',
        variant: 'destructive',
      });
    }
  };

  const handleDeleteNote = async (noteId: string) => {
    try {
      await deleteNote.mutateAsync({ conversationId, noteId });
      toast({ title: 'Note deleted', description: 'The note has been removed.' });
    } catch {
      toast({
        title: 'Error',
        description: 'Failed to delete note.',
        variant: 'destructive',
      });
    }
  };

  const startEditing = (note: ConversationNote) => {
    setEditingId(note.id);
    setEditContent(note.content);
    setEditType(note.type);
  };

  const cancelEditing = () => {
    setEditingId(null);
    setEditContent('');
    setEditType('note');
  };

  if (isLoading) {
    return (
      <div className="flex items-center justify-center py-4">
        <Loader2 className="h-5 w-5 animate-spin text-muted-foreground" />
      </div>
    );
  }

  return (
    <div className="space-y-4">
      <div className="flex items-center justify-between">
        <h4 className="font-medium text-sm text-muted-foreground uppercase tracking-wide">
          Notes & Memory
        </h4>
        {!isAdding && (
          <Button variant="outline" size="sm" onClick={() => setIsAdding(true)}>
            <Plus className="h-4 w-4 mr-1" />
            Add
          </Button>
        )}
      </div>

      {/* Add new note form */}
      {isAdding && (
        <div className="space-y-3 p-3 border rounded-lg bg-muted/50">
          <div className="flex items-center gap-2">
            <Select
              value={newType}
              onValueChange={(v) => setNewType(v as typeof newType)}
            >
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
          </div>
          <Textarea
            placeholder="Write your note here..."
            value={newContent}
            onChange={(e) => setNewContent(e.target.value)}
            rows={3}
            className="resize-none"
          />
          <div className="flex justify-end gap-2">
            <Button
              variant="ghost"
              size="sm"
              onClick={() => {
                setIsAdding(false);
                setNewContent('');
                setNewType('note');
              }}
            >
              Cancel
            </Button>
            <Button
              size="sm"
              onClick={handleAddNote}
              disabled={!newContent.trim() || addNote.isPending}
            >
              {addNote.isPending && <Loader2 className="h-4 w-4 mr-1 animate-spin" />}
              <Save className="h-4 w-4 mr-1" />
              Save
            </Button>
          </div>
        </div>
      )}

      {/* Notes list */}
      {notes && notes.length > 0 ? (
        <div className="space-y-3">
          {notes.map((note) => {
            const Icon = noteTypeIcons[note.type];
            const isEditing = editingId === note.id;

            return (
              <div
                key={note.id}
                className="p-3 border rounded-lg hover:bg-muted/50 transition-colors"
              >
                {isEditing ? (
                  <div className="space-y-3">
                    <Select
                      value={editType}
                      onValueChange={(v) => setEditType(v as typeof editType)}
                    >
                      <SelectTrigger className="w-32">
                        <SelectValue />
                      </SelectTrigger>
                      <SelectContent>
                        <SelectItem value="note">Note</SelectItem>
                        <SelectItem value="memory">Memory</SelectItem>
                        <SelectItem value="reminder">Reminder</SelectItem>
                      </SelectContent>
                    </Select>
                    <Textarea
                      value={editContent}
                      onChange={(e) => setEditContent(e.target.value)}
                      rows={3}
                      className="resize-none"
                    />
                    <div className="flex justify-end gap-2">
                      <Button variant="ghost" size="sm" onClick={cancelEditing}>
                        <X className="h-4 w-4 mr-1" />
                        Cancel
                      </Button>
                      <Button
                        size="sm"
                        onClick={() => handleUpdateNote(note.id)}
                        disabled={!editContent.trim() || updateNote.isPending}
                      >
                        {updateNote.isPending && (
                          <Loader2 className="h-4 w-4 mr-1 animate-spin" />
                        )}
                        <Save className="h-4 w-4 mr-1" />
                        Save
                      </Button>
                    </div>
                  </div>
                ) : (
                  <>
                    <div className="flex items-start justify-between gap-2">
                      <Badge variant="secondary" className={noteTypeColors[note.type]}>
                        <Icon className="h-3 w-3 mr-1" />
                        {noteTypeLabels[note.type]}
                      </Badge>
                      <div className="flex items-center gap-1">
                        <Button
                          variant="ghost"
                          size="icon"
                          className="h-7 w-7"
                          onClick={() => startEditing(note)}
                        >
                          <Pencil className="h-3 w-3" />
                        </Button>
                        <AlertDialog>
                          <AlertDialogTrigger asChild>
                            <Button
                              variant="ghost"
                              size="icon"
                              className="h-7 w-7 text-destructive hover:text-destructive"
                            >
                              <Trash2 className="h-3 w-3" />
                            </Button>
                          </AlertDialogTrigger>
                          <AlertDialogContent>
                            <AlertDialogHeader>
                              <AlertDialogTitle>Delete note?</AlertDialogTitle>
                              <AlertDialogDescription>
                                This action cannot be undone. The note will be permanently
                                deleted.
                              </AlertDialogDescription>
                            </AlertDialogHeader>
                            <AlertDialogFooter>
                              <AlertDialogCancel>Cancel</AlertDialogCancel>
                              <AlertDialogAction
                                onClick={() => handleDeleteNote(note.id)}
                                className="bg-destructive text-destructive-foreground hover:bg-destructive/90"
                              >
                                Delete
                              </AlertDialogAction>
                            </AlertDialogFooter>
                          </AlertDialogContent>
                        </AlertDialog>
                      </div>
                    </div>
                    <p className="mt-2 text-sm whitespace-pre-wrap">{note.content}</p>
                    <p className="mt-2 text-xs text-muted-foreground">
                      {formatDistanceToNow(new Date(note.created_at), {
                        addSuffix: true,
                        locale: th,
                      })}
                    </p>
                  </>
                )}
              </div>
            );
          })}
        </div>
      ) : (
        !isAdding && (
          <p className="text-sm text-muted-foreground text-center py-4">
            No notes yet. Add one to remember important details.
          </p>
        )
      )}
    </div>
  );
}
