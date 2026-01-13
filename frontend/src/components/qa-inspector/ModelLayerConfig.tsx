import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Badge } from '@/components/ui/badge';
import { Cpu, Shield } from 'lucide-react';

interface ModelLayerConfigProps {
  layerName: string;
  layerDescription: string;
  primaryModel: string;
  fallbackModel: string;
  primaryPlaceholder: string;
  fallbackPlaceholder: string;
  onChange: (field: 'primary' | 'fallback', value: string) => void;
  disabled?: boolean;
}

/**
 * Reusable component for configuring AI models for each QA Inspector layer.
 * Each layer has a primary model and a fallback model for reliability.
 */
export function ModelLayerConfig({
  layerName,
  layerDescription,
  primaryModel,
  fallbackModel,
  primaryPlaceholder,
  fallbackPlaceholder,
  onChange,
  disabled = false,
}: ModelLayerConfigProps) {
  // Validate model format: provider/model-name
  const isValidFormat = (value: string) => {
    if (!value) return true; // Empty is valid (will use default)
    return /^[a-z0-9-]+\/[a-z0-9._-]+$/i.test(value);
  };

  const primaryValid = isValidFormat(primaryModel);
  const fallbackValid = isValidFormat(fallbackModel);

  // Determine layer badge text
  const getLayerBadge = () => {
    if (layerName.includes('Real-time')) return 'Layer 1';
    if (layerName.includes('Deep')) return 'Layer 2';
    return 'Layer 3';
  };

  return (
    <div className="space-y-4 p-4 border rounded-lg">
      <div>
        <div className="flex items-center gap-2 mb-1">
          <h4 className="font-medium">{layerName}</h4>
          <Badge variant="outline" className="text-xs">
            {getLayerBadge()}
          </Badge>
        </div>
        <p className="text-sm text-muted-foreground">{layerDescription}</p>
      </div>

      <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
        {/* Primary Model */}
        <div className="space-y-2">
          <Label htmlFor={`${layerName}-primary`} className="flex items-center gap-1.5">
            <Cpu className="h-3.5 w-3.5" />
            Primary Model
          </Label>
          <Input
            id={`${layerName}-primary`}
            value={primaryModel}
            onChange={(e) => onChange('primary', e.target.value)}
            placeholder={primaryPlaceholder}
            className={!primaryValid ? 'border-destructive' : ''}
            disabled={disabled}
          />
          {!primaryValid && (
            <p className="text-xs text-destructive">
              Invalid format. Use: provider/model-name
            </p>
          )}
        </div>

        {/* Fallback Model */}
        <div className="space-y-2">
          <Label htmlFor={`${layerName}-fallback`} className="flex items-center gap-1.5">
            <Shield className="h-3.5 w-3.5" />
            Fallback Model
          </Label>
          <Input
            id={`${layerName}-fallback`}
            value={fallbackModel}
            onChange={(e) => onChange('fallback', e.target.value)}
            placeholder={fallbackPlaceholder}
            className={!fallbackValid ? 'border-destructive' : ''}
            disabled={disabled}
          />
          {!fallbackValid && (
            <p className="text-xs text-destructive">
              Invalid format. Use: provider/model-name
            </p>
          )}
        </div>
      </div>
    </div>
  );
}

export default ModelLayerConfig;
