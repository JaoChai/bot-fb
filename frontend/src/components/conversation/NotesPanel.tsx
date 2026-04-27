import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
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
} from 'lucide-react';
import { NoteForm } from './NoteForm';
import { formatDistanceToNow, isValid } from 'date-fns';
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
  note: 'bg-muted text-foreground',
  memory: 'bg-foreground text-background',
  reminder: 'bg-accent text-accent-foreground border border-border',
};

export function NotesPanel({ botId, conversationId }: NotesPanelProps) {
  const { toast } = useToast();
  const [isAdding, setIsAdding] = useState(false);
  const [editingId, setEditingId] = useState<string | null>(null);

  const { data: notes, isLoading } = useConversationNotes(botId, conversationId);
  const addNote = useAddNote(botId);
  const updateNote = useUpdateNote(botId);
  const deleteNote = useDeleteNote(botId);

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
        <div className="p-3 border rounded-lg bg-muted/50">
          <NoteForm
            onSave={async (content, type) => {
              try {
                await addNote.mutateAsync({
                  conversationId,
                  data: { content, type },
                });
                toast({ title: 'Note added', description: 'Your note has been saved.' });
                setIsAdding(false);
              } catch {
                toast({
                  title: 'Error',
                  description: 'Failed to add note.',
                  variant: 'destructive',
                });
              }
            }}
            onCancel={() => setIsAdding(false)}
            isPending={addNote.isPending}
          />
        </div>
      )}

      {/* Notes list */}
      {notes && notes.length > 0 ? (
        <div className="space-y-3">
          {notes.map((note) => {
            const Icon = noteTypeIcons[note.type];

            return (
              <div
                key={note.id}
                className="p-3 border rounded-lg hover:bg-muted/50 transition-colors"
              >
                {editingId === note.id ? (
                  <NoteForm
                    initialContent={note.content}
                    initialType={note.type}
                    onSave={async (content, type) => {
                      try {
                        await updateNote.mutateAsync({
                          conversationId,
                          noteId: note.id,
                          data: { content, type },
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
                    }}
                    onCancel={() => setEditingId(null)}
                    isPending={updateNote.isPending}
                  />
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
                          onClick={() => setEditingId(note.id)}
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
                      {note.created_at && isValid(new Date(note.created_at))
                        ? formatDistanceToNow(new Date(note.created_at), {
                            addSuffix: true,
                            locale: th,
                          })
                        : ''}
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
