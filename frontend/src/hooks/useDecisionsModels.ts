import { useQuery } from '@tanstack/react-query';
import { apiGet } from '@/lib/api';
import { queryKeys } from '@/lib/query';
import type { ApiResponse, AvailableModel } from '@/types/api';

// Built-in one-click option so the Luna Decisions model is always selectable,
// even when GET /models is unreachable or returns no decisions models.
export const FALLBACK_DECISIONS_MODEL: AvailableModel = {
  model_id: 'openai/gpt-6-luna-decisions',
  name: 'GPT-6 Luna Decisions',
  provider: 'openai',
  description: 'Decisions API only: returns probabilities for classification/routing.',
  supports_vision: true,
  supports_reasoning: false,
  is_decisions_model: true,
  context_length: 1050000,
  max_output_tokens: 0,
  pricing_prompt: 0.1,
  pricing_completion: 0,
  source: 'config',
};

// Models from GET /models?search=decisions, filtered to Decisions-type models
// (the ones the Support Router is allowed to use).
export function useDecisionsModels() {
  return useQuery({
    queryKey: queryKeys.models.decisions(),
    queryFn: async () => {
      const response = await apiGet<ApiResponse<AvailableModel[]>>('/models?search=decisions');
      const models = Array.isArray(response.data) ? response.data : [];
      return models.filter((model) => model.is_decisions_model === true);
    },
  });
}
