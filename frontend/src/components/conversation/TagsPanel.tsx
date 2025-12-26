import { useState, useRef, useEffect } from 'react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Badge } from '@/components/ui/badge';
import {
  useBotTags,
  useAddTags,
  useRemoveTag,
} from '@/hooks/useConversations';
import { useToast } from '@/hooks/use-toast';
import {
  Loader2,
  Plus,
  X,
  Tag,
  Check,
} from 'lucide-react';
import { cn } from '@/lib/utils';

interface TagsPanelProps {
  botId: number;
  conversationId: number;
  currentTags: string[];
}

export function TagsPanel({ botId, conversationId, currentTags }: TagsPanelProps) {
  const { toast } = useToast();
  const [isAdding, setIsAdding] = useState(false);
  const [inputValue, setInputValue] = useState('');
  const [showSuggestions, setShowSuggestions] = useState(false);
  const inputRef = useRef<HTMLInputElement>(null);
  const suggestionsRef = useRef<HTMLDivElement>(null);

  const { data: allTags } = useBotTags(botId);
  const addTags = useAddTags(botId);
  const removeTag = useRemoveTag(botId);

  // Filter suggestions based on input
  const suggestions = allTags?.filter(
    (tag) =>
      tag.toLowerCase().includes(inputValue.toLowerCase()) &&
      !currentTags.includes(tag)
  ) || [];

  // Handle click outside to close suggestions
  useEffect(() => {
    function handleClickOutside(event: MouseEvent) {
      if (
        suggestionsRef.current &&
        !suggestionsRef.current.contains(event.target as Node) &&
        inputRef.current &&
        !inputRef.current.contains(event.target as Node)
      ) {
        setShowSuggestions(false);
      }
    }

    document.addEventListener('mousedown', handleClickOutside);
    return () => document.removeEventListener('mousedown', handleClickOutside);
  }, []);

  const handleAddTag = async (tag: string) => {
    const trimmedTag = tag.trim();
    if (!trimmedTag || currentTags.includes(trimmedTag)) return;

    try {
      await addTags.mutateAsync({
        conversationId,
        data: { tags: [trimmedTag] },
      });
      toast({ title: 'Tag added', description: `"${trimmedTag}" has been added.` });
      setInputValue('');
      setShowSuggestions(false);
    } catch {
      toast({
        title: 'Error',
        description: 'Failed to add tag.',
        variant: 'destructive',
      });
    }
  };

  const handleRemoveTag = async (tag: string) => {
    try {
      await removeTag.mutateAsync({ conversationId, tag });
      toast({ title: 'Tag removed', description: `"${tag}" has been removed.` });
    } catch {
      toast({
        title: 'Error',
        description: 'Failed to remove tag.',
        variant: 'destructive',
      });
    }
  };

  const handleKeyDown = (e: React.KeyboardEvent<HTMLInputElement>) => {
    if (e.key === 'Enter' && inputValue.trim()) {
      e.preventDefault();
      handleAddTag(inputValue);
    } else if (e.key === 'Escape') {
      setShowSuggestions(false);
      setIsAdding(false);
      setInputValue('');
    }
  };

  return (
    <div className="space-y-3">
      <div className="flex items-center justify-between">
        <h4 className="font-medium text-sm text-muted-foreground uppercase tracking-wide">
          Tags
        </h4>
        {!isAdding && (
          <Button
            variant="outline"
            size="sm"
            onClick={() => {
              setIsAdding(true);
              setTimeout(() => inputRef.current?.focus(), 100);
            }}
          >
            <Plus className="h-4 w-4 mr-1" />
            Add
          </Button>
        )}
      </div>

      {/* Current tags */}
      <div className="flex flex-wrap gap-2">
        {currentTags.length > 0 ? (
          currentTags.map((tag) => (
            <Badge
              key={tag}
              variant="secondary"
              className="group flex items-center gap-1 pr-1"
            >
              <Tag className="h-3 w-3" />
              {tag}
              <button
                onClick={() => handleRemoveTag(tag)}
                disabled={removeTag.isPending}
                className="ml-1 rounded-full p-0.5 hover:bg-destructive/20 transition-colors"
                title="Remove tag"
              >
                {removeTag.isPending ? (
                  <Loader2 className="h-3 w-3 animate-spin" />
                ) : (
                  <X className="h-3 w-3" />
                )}
              </button>
            </Badge>
          ))
        ) : (
          !isAdding && (
            <p className="text-sm text-muted-foreground">No tags</p>
          )
        )}
      </div>

      {/* Add tag input with autocomplete */}
      {isAdding && (
        <div className="relative">
          <div className="flex gap-2">
            <div className="relative flex-1">
              <Input
                ref={inputRef}
                type="text"
                placeholder="Type to search or create tag..."
                value={inputValue}
                onChange={(e) => {
                  setInputValue(e.target.value);
                  setShowSuggestions(true);
                }}
                onFocus={() => setShowSuggestions(true)}
                onKeyDown={handleKeyDown}
                className="pr-8"
              />
              {addTags.isPending && (
                <Loader2 className="absolute right-2 top-1/2 -translate-y-1/2 h-4 w-4 animate-spin text-muted-foreground" />
              )}
            </div>
            <Button
              variant="ghost"
              size="icon"
              onClick={() => {
                setIsAdding(false);
                setInputValue('');
                setShowSuggestions(false);
              }}
            >
              <X className="h-4 w-4" />
            </Button>
          </div>

          {/* Suggestions dropdown */}
          {showSuggestions && (suggestions.length > 0 || inputValue.trim()) && (
            <div
              ref={suggestionsRef}
              className="absolute z-10 mt-1 w-full bg-popover border rounded-md shadow-lg max-h-48 overflow-y-auto"
            >
              {/* Create new tag option */}
              {inputValue.trim() && !allTags?.includes(inputValue.trim()) && (
                <button
                  onClick={() => handleAddTag(inputValue)}
                  className="w-full px-3 py-2 text-left text-sm hover:bg-muted flex items-center gap-2"
                >
                  <Plus className="h-4 w-4 text-primary" />
                  Create "<span className="font-medium">{inputValue.trim()}</span>"
                </button>
              )}

              {/* Existing tag suggestions */}
              {suggestions.map((tag) => (
                <button
                  key={tag}
                  onClick={() => handleAddTag(tag)}
                  className={cn(
                    'w-full px-3 py-2 text-left text-sm hover:bg-muted flex items-center justify-between',
                    currentTags.includes(tag) && 'opacity-50'
                  )}
                  disabled={currentTags.includes(tag)}
                >
                  <span className="flex items-center gap-2">
                    <Tag className="h-4 w-4 text-muted-foreground" />
                    {tag}
                  </span>
                  {currentTags.includes(tag) && (
                    <Check className="h-4 w-4 text-primary" />
                  )}
                </button>
              ))}

              {/* No matches message */}
              {suggestions.length === 0 && !inputValue.trim() && (
                <div className="px-3 py-2 text-sm text-muted-foreground">
                  Start typing to search or create tags
                </div>
              )}
            </div>
          )}
        </div>
      )}

      {/* Quick add popular tags */}
      {allTags && allTags.length > 0 && !isAdding && (
        <div className="pt-2">
          <p className="text-xs text-muted-foreground mb-2">Quick add:</p>
          <div className="flex flex-wrap gap-1">
            {allTags
              .filter((tag) => !currentTags.includes(tag))
              .slice(0, 5)
              .map((tag) => (
                <Button
                  key={tag}
                  variant="outline"
                  size="sm"
                  className="h-6 text-xs"
                  onClick={() => handleAddTag(tag)}
                  disabled={addTags.isPending}
                >
                  <Plus className="h-3 w-3 mr-1" />
                  {tag}
                </Button>
              ))}
          </div>
        </div>
      )}
    </div>
  );
}
