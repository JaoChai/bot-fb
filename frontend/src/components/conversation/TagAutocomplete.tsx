import { useState, useRef, useEffect } from 'react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Loader2, Plus, X, Tag, Check } from 'lucide-react';
import { cn } from '@/lib/utils';

interface TagAutocompleteProps {
  allTags: string[];
  currentTags: string[];
  onAddTag: (tag: string) => Promise<void>;
  onClose: () => void;
  isPending: boolean;
}

export function TagAutocomplete({
  allTags,
  currentTags,
  onAddTag,
  onClose,
  isPending,
}: TagAutocompleteProps) {
  const [inputValue, setInputValue] = useState('');
  const [showSuggestions, setShowSuggestions] = useState(false);
  const inputRef = useRef<HTMLInputElement>(null);
  const suggestionsRef = useRef<HTMLDivElement>(null);

  const suggestions = allTags.filter(
    (tag) =>
      tag.toLowerCase().includes(inputValue.toLowerCase()) &&
      !currentTags.includes(tag)
  );

  useEffect(() => {
    inputRef.current?.focus();
  }, []);

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

  const handleAdd = async (tag: string) => {
    const trimmedTag = tag.trim();
    if (!trimmedTag || currentTags.includes(trimmedTag)) return;
    await onAddTag(trimmedTag);
    setInputValue('');
    setShowSuggestions(false);
  };

  const handleKeyDown = (e: React.KeyboardEvent<HTMLInputElement>) => {
    if (e.key === 'Enter' && inputValue.trim()) {
      e.preventDefault();
      handleAdd(inputValue);
    } else if (e.key === 'Escape') {
      setShowSuggestions(false);
      onClose();
    }
  };

  return (
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
          {isPending && (
            <Loader2 className="absolute right-2 top-1/2 -translate-y-1/2 h-4 w-4 animate-spin text-muted-foreground" />
          )}
        </div>
        <Button variant="ghost" size="icon" onClick={onClose}>
          <X className="h-4 w-4" />
        </Button>
      </div>

      {showSuggestions && (suggestions.length > 0 || inputValue.trim()) && (
        <div
          ref={suggestionsRef}
          className="absolute z-10 mt-1 w-full bg-popover border rounded-md shadow-lg max-h-48 overflow-y-auto"
        >
          {inputValue.trim() && !allTags.includes(inputValue.trim()) && (
            <button
              onClick={() => handleAdd(inputValue)}
              className="w-full px-3 py-2 text-left text-sm hover:bg-muted flex items-center gap-2"
            >
              <Plus className="h-4 w-4 text-primary" />
              Create "<span className="font-medium">{inputValue.trim()}</span>"
            </button>
          )}

          {suggestions.map((tag) => {
            const isSelected = currentTags.includes(tag);
            return (
              <button
                key={tag}
                onClick={() => handleAdd(tag)}
                className={cn(
                  'w-full px-3 py-2 text-left text-sm hover:bg-muted flex items-center justify-between',
                  isSelected && 'opacity-50'
                )}
                disabled={isSelected}
              >
                <span className="flex items-center gap-2">
                  <Tag className="h-4 w-4 text-muted-foreground" />
                  {tag}
                </span>
                {isSelected && (
                  <Check className="h-4 w-4 text-primary" />
                )}
              </button>
            );
          })}

          {suggestions.length === 0 && !inputValue.trim() && (
            <div className="px-3 py-2 text-sm text-muted-foreground">
              Start typing to search or create tags
            </div>
          )}
        </div>
      )}
    </div>
  );
}
