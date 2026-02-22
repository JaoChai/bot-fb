import { useQuery } from '@tanstack/react-query';
import { apiGet } from '@/lib/api';

export interface LLMModel {
  model_id: string;
  name: string;
  provider: string;
  description: string;
  supports_vision: boolean;
  supports_reasoning: boolean;
  is_mandatory_reasoning: boolean;
  default_reasoning_effort: string | null;
  supports_structured_output: boolean;
  context_length: number;
  max_output_tokens: number;
  pricing_prompt: number;
  pricing_completion: number;
  source: string;
}

interface ModelsResponse {
  data: LLMModel[];
}

export function useModels(search?: string) {
  return useQuery({
    queryKey: ['models', search ?? ''],
    queryFn: async () => {
      const params = search ? `?search=${encodeURIComponent(search)}` : '';
      const response = await apiGet<ModelsResponse>(`/models${params}`);
      return response.data;
    },
    staleTime: 6 * 60 * 60 * 1000, // 6 hours (match backend cache)
    gcTime: 24 * 60 * 60 * 1000, // 24 hours
  });
}
