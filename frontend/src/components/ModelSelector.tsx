import { useState, useMemo } from 'react';
import { ChevronsUpDown, Check, Eye, Brain, Braces } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import {
  Popover,
  PopoverContent,
  PopoverTrigger,
} from '@/components/ui/popover';
import {
  Command,
  CommandEmpty,
  CommandGroup,
  CommandInput,
  CommandItem,
  CommandList,
} from '@/components/ui/command';
import { useModels, type LLMModel } from '@/hooks/useModels';
import { cn } from '@/lib/utils';

interface ModelSelectorProps {
  label: string;
  value: string;
  onChange: (value: string) => void;
  placeholder?: string;
}

function formatPrice(price: number): string {
  if (price === 0) return 'Free';
  if (price < 0.01) return '<$0.01';
  if (price < 1) return `$${price.toFixed(2)}`;
  return `$${price.toFixed(1)}`;
}

function ModelBadges({ model }: { model: LLMModel }) {
  return (
    <div className="flex gap-1">
      {model.supports_vision && (
        <Badge variant="outline" className="h-4 gap-0.5 px-1 text-[10px]">
          <Eye className="size-2.5" />
          Vision
        </Badge>
      )}
      {model.supports_reasoning && (
        <Badge variant="outline" className="h-4 gap-0.5 px-1 text-[10px]">
          <Brain className="size-2.5" />
          Reason
        </Badge>
      )}
      {model.supports_structured_output && (
        <Badge variant="outline" className="h-4 gap-0.5 px-1 text-[10px]">
          <Braces className="size-2.5" />
          JSON
        </Badge>
      )}
    </div>
  );
}

export function ModelSelector({ label, value, onChange, placeholder }: ModelSelectorProps) {
  const [open, setOpen] = useState(false);
  const { data: models, isLoading } = useModels();

  // Group models by provider
  const grouped = useMemo(() => {
    if (!models) return {};
    const groups: Record<string, LLMModel[]> = {};
    for (const model of models) {
      const provider = model.provider || 'other';
      if (!groups[provider]) groups[provider] = [];
      groups[provider].push(model);
    }
    return groups;
  }, [models]);

  // Find selected model info
  const selectedModel = useMemo(
    () => models?.find((m) => m.model_id === value),
    [models, value]
  );

  const displayValue = selectedModel
    ? `${selectedModel.name}`
    : value || placeholder || 'เลือกโมเดล...';

  return (
    <div className="space-y-2">
      <Label className="text-sm text-muted-foreground">{label}</Label>
      <Popover open={open} onOpenChange={setOpen}>
        <PopoverTrigger asChild>
          <Button
            variant="outline"
            role="combobox"
            aria-expanded={open}
            className={cn(
              'w-full justify-between font-mono text-sm',
              !value && 'text-muted-foreground'
            )}
          >
            <span className="truncate">{displayValue}</span>
            <ChevronsUpDown className="ml-2 size-4 shrink-0 opacity-50" />
          </Button>
        </PopoverTrigger>
        <PopoverContent className="w-[var(--radix-popover-trigger-width)] p-0" align="start">
          <Command>
            <CommandInput placeholder="ค้นหาโมเดล..." />
            <CommandList>
              <CommandEmpty>
                {isLoading ? 'กำลังโหลด...' : 'ไม่พบโมเดล'}
              </CommandEmpty>

              {Object.entries(grouped).map(([provider, providerModels]) => (
                <CommandGroup key={provider} heading={provider}>
                  {providerModels.map((model) => (
                    <CommandItem
                      key={model.model_id}
                      value={`${model.model_id} ${model.name} ${model.provider}`}
                      onSelect={() => {
                        onChange(model.model_id);
                        setOpen(false);
                      }}
                    >
                      <Check
                        className={cn(
                          'mr-1 size-3.5',
                          value === model.model_id ? 'opacity-100' : 'opacity-0'
                        )}
                      />
                      <div className="flex min-w-0 flex-1 flex-col gap-0.5">
                        <div className="flex items-center gap-2">
                          <span className="truncate font-medium text-sm">
                            {model.name}
                          </span>
                          <ModelBadges model={model} />
                        </div>
                        <div className="flex items-center gap-2 text-[11px] text-muted-foreground">
                          <span className="font-mono">{model.model_id}</span>
                          <span className="ml-auto whitespace-nowrap">
                            {formatPrice(model.pricing_prompt)}/{formatPrice(model.pricing_completion)}
                          </span>
                        </div>
                      </div>
                    </CommandItem>
                  ))}
                </CommandGroup>
              ))}
            </CommandList>
          </Command>
        </PopoverContent>
      </Popover>
    </div>
  );
}

// Convenience component for 4-model configuration
interface ModelConfigurationProps {
  primaryModel: string;
  fallbackModel: string;
  decisionModel: string;
  fallbackDecisionModel: string;
  onPrimaryChange: (value: string) => void;
  onFallbackChange: (value: string) => void;
  onDecisionChange: (value: string) => void;
  onFallbackDecisionChange: (value: string) => void;
  showDecisionModels?: boolean;
}

export function ModelConfiguration({
  primaryModel,
  fallbackModel,
  decisionModel,
  fallbackDecisionModel,
  onPrimaryChange,
  onFallbackChange,
  onDecisionChange,
  onFallbackDecisionChange,
  showDecisionModels = false,
}: ModelConfigurationProps) {
  return (
    <div className="space-y-4">
      {/* Chat Models Row */}
      <div className="grid grid-cols-2 gap-4">
        <ModelSelector
          label="LLM Model สำหรับสนทนา"
          value={primaryModel}
          onChange={onPrimaryChange}
        />
        <ModelSelector
          label="โมเดลสำรอง (fallback)"
          value={fallbackModel}
          onChange={onFallbackChange}
        />
      </div>

      {/* Decision Models Row - Only show when enabled */}
      {showDecisionModels && (
        <div className="grid grid-cols-2 gap-4">
          <ModelSelector
            label="LLM Model สำหรับตัดสินใจ"
            value={decisionModel}
            onChange={onDecisionChange}
          />
          <ModelSelector
            label="โมเดลตัดสินใจสำรอง (fallback)"
            value={fallbackDecisionModel}
            onChange={onFallbackDecisionChange}
          />
        </div>
      )}
    </div>
  );
}
