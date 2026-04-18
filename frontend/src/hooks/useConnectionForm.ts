import { useState, useEffect } from 'react';
import { useParams, useSearchParams } from 'react-router';
import { useConnection } from '@/hooks/useConnections';

export interface ConnectionFormData {
  enabled: boolean;
  connection_name: string;
  platform: 'line' | 'facebook' | 'testing' | 'telegram';
  primary_chat_model: string;
  fallback_chat_model: string;
  decision_model: string;
  fallback_decision_model: string;
  line_channel_secret: string;
  line_channel_access_token: string;
  telegram_bot_token: string;
  webhook_forwarder_enabled: boolean;
  auto_handover: boolean;
  use_confidence_cascade: boolean;
  cascade_cheap_model: string;
  cascade_expensive_model: string;
}

export const DEFAULT_FORM_DATA: ConnectionFormData = {
  enabled: true,
  connection_name: '',
  platform: 'testing',
  primary_chat_model: 'google/gemini-2.5-flash-preview',
  fallback_chat_model: 'google/gemini-2.0-flash-001',
  decision_model: 'openai/gpt-4o-mini',
  fallback_decision_model: 'openai/gpt-4o',
  line_channel_secret: '',
  line_channel_access_token: '',
  telegram_bot_token: '',
  webhook_forwarder_enabled: false,
  auto_handover: false,
  use_confidence_cascade: false,
  cascade_cheap_model: 'openai/gpt-4o-mini',
  cascade_expensive_model: 'openai/gpt-5-mini',
};

export function useConnectionForm() {
  const { botId } = useParams();
  const [searchParams] = useSearchParams();

  const isEditMode = !!botId;
  const botIdNumber = botId ? parseInt(botId, 10) : null;
  const platformFromUrl = searchParams.get('platform') as ConnectionFormData['platform'] | null;

  const { data: existingBot, isLoading: isLoadingBot } = useConnection(botIdNumber);

  const [formData, setFormData] = useState<ConnectionFormData>({
    ...DEFAULT_FORM_DATA,
    platform: platformFromUrl || 'testing',
  });

  // Populate form when existing bot data is loaded
  useEffect(() => {
    if (existingBot) {
      setFormData({
        enabled: existingBot.status === 'active',
        connection_name: existingBot.name,
        platform: existingBot.channel_type,
        primary_chat_model: existingBot.primary_chat_model || DEFAULT_FORM_DATA.primary_chat_model,
        fallback_chat_model: existingBot.fallback_chat_model || DEFAULT_FORM_DATA.fallback_chat_model,
        decision_model: existingBot.decision_model || DEFAULT_FORM_DATA.decision_model,
        fallback_decision_model: existingBot.fallback_decision_model || DEFAULT_FORM_DATA.fallback_decision_model,
        line_channel_secret: '',
        line_channel_access_token: '',
        telegram_bot_token: '',
        webhook_forwarder_enabled: existingBot.webhook_forwarder_enabled || false,
        auto_handover: existingBot.auto_handover || false,
        use_confidence_cascade: existingBot.use_confidence_cascade || false,
        cascade_cheap_model: existingBot.cascade_cheap_model || DEFAULT_FORM_DATA.cascade_cheap_model,
        cascade_expensive_model: existingBot.cascade_expensive_model || DEFAULT_FORM_DATA.cascade_expensive_model,
      });
    }
  }, [existingBot]);

  const handleChange = <K extends keyof ConnectionFormData>(
    field: K,
    value: ConnectionFormData[K]
  ) => {
    setFormData((prev) => ({ ...prev, [field]: value }));
  };

  return {
    formData,
    handleChange,
    existingBot,
    isLoadingBot,
    isEditMode,
    botIdNumber,
  };
}
