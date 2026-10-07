import { describe, it, expect, vi } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { http, HttpResponse } from 'msw';
import { SupportRouterSection } from './SupportRouterSection';
import { server } from '@/test/mocks/server';
import type { ConnectionFormData } from '@/hooks/useConnectionForm';
import type { AvailableModel } from '@/types/api';

const API_URL = 'http://localhost:8000/api';

const LUNA_MODEL: AvailableModel = {
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

function makeFormData(overrides: Partial<ConnectionFormData> = {}): ConnectionFormData {
  return {
    enabled: true,
    connection_name: 'Bot ทดสอบ',
    platform: 'testing',
    primary_chat_model: '',
    fallback_chat_model: '',
    utility_model: '',
    reasoning_effort: 'medium',
    line_channel_secret: '',
    line_channel_access_token: '',
    telegram_bot_token: '',
    auto_handover: false,
    auto_delivery_enabled: false,
    support_router_mode: 'off',
    support_router_model: '',
    support_handover_message: '',
    ...overrides,
  };
}

type HandleChange = React.ComponentProps<typeof SupportRouterSection>['handleChange'];

function renderSection(props: {
  formData?: ConnectionFormData;
  handleChange?: HandleChange;
} = {}) {
  const handleChange = props.handleChange ?? vi.fn();
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  });
  render(
    <QueryClientProvider client={queryClient}>
      <SupportRouterSection
        formData={props.formData ?? makeFormData()}
        handleChange={handleChange}
      />
    </QueryClientProvider>
  );
  return { handleChange };
}

async function openSelect(label: string) {
  const user = userEvent.setup();
  const trigger = screen.getByLabelText(label);
  await user.click(trigger);
  return user;
}

describe('SupportRouterSection', () => {
  it('renders the three mode options', async () => {
    renderSection();
    await openSelect('โหมดทำงาน');

    expect(screen.getByRole('option', { name: 'ปิด' })).toBeInTheDocument();
    expect(
      screen.getByRole('option', { name: 'ทดลองเก็บคะแนนอย่างเดียว' })
    ).toBeInTheDocument();
    expect(screen.getByRole('option', { name: 'เปิดใช้งาน' })).toBeInTheDocument();
  });

  it('hides the model and handover message fields when mode is off', () => {
    renderSection({ formData: makeFormData({ support_router_mode: 'off' }) });

    expect(screen.queryByLabelText('โมเดล Decisions')).not.toBeInTheDocument();
    expect(screen.queryByLabelText(/ข้อความแจ้งลูกค้า/)).not.toBeInTheDocument();
  });

  it('shows the model and handover message fields when mode is shadow', () => {
    renderSection({ formData: makeFormData({ support_router_mode: 'shadow' }) });

    expect(screen.getByLabelText('โมเดล Decisions')).toBeInTheDocument();
    expect(screen.getByLabelText(/ข้อความแจ้งลูกค้า/)).toBeInTheDocument();
  });

  it('shows a client-side error when mode is on and the handover message is empty', () => {
    renderSection({
      formData: makeFormData({ support_router_mode: 'on', support_handover_message: '' }),
    });

    const error = screen.getByRole('alert');
    expect(error).toHaveTextContent('กรุณากรอกข้อความแจ้งลูกค้าก่อนส่งต่อทีม Support');
    expect(screen.getByLabelText(/ข้อความแจ้งลูกค้า/)).toHaveAttribute('aria-invalid', 'true');
  });

  it('does not require the handover message in shadow mode', () => {
    renderSection({ formData: makeFormData({ support_router_mode: 'shadow' }) });

    expect(screen.queryByRole('alert')).not.toBeInTheDocument();
  });

  it('lists the decisions model from the API and shows its price hint', async () => {
    server.use(
      http.get(`${API_URL}/models`, ({ request }) => {
        expect(new URL(request.url).searchParams.get('search')).toBe('decisions');
        return HttpResponse.json({ data: [LUNA_MODEL] });
      })
    );

    renderSection({
      formData: makeFormData({
        support_router_mode: 'shadow',
        support_router_model: 'openai/gpt-6-luna-decisions',
      }),
    });

    // Price hint of the selected model is visible without opening the select.
    expect(await screen.findByText('$0.10 / 1M input tokens')).toBeInTheDocument();

    await openSelect('โมเดล Decisions');
    expect(
      await screen.findByRole('option', { name: /GPT-6 Luna Decisions/ })
    ).toBeInTheDocument();
  });

  it('falls back to the built-in Luna Decisions option when the API request fails', async () => {
    server.use(http.get(`${API_URL}/models`, () => HttpResponse.json({}, { status: 500 })));

    renderSection({ formData: makeFormData({ support_router_mode: 'shadow' }) });

    await waitFor(() => {
      expect(screen.getByLabelText('โมเดล Decisions')).toBeInTheDocument();
    });
    await openSelect('โมเดล Decisions');

    expect(
      await screen.findByRole('option', { name: /GPT-6 Luna Decisions/ })
    ).toBeInTheDocument();
  });

  it('reports changes with the right form field keys', async () => {
    const SECOND_MODEL: AvailableModel = {
      ...LUNA_MODEL,
      model_id: 'anthropic/claude-6-decisions',
      name: 'Claude 6 Decisions',
      provider: 'anthropic',
      pricing_prompt: 0.25,
    };
    server.use(
      http.get(`${API_URL}/models`, () =>
        HttpResponse.json({ data: [LUNA_MODEL, SECOND_MODEL] })
      )
    );

    const { handleChange } = renderSection({
      formData: makeFormData({
        support_router_mode: 'shadow',
        // Start on a different model so selecting Luna is a real change
        // (re-selecting the current value of a controlled Select fires no onChange).
        support_router_model: 'anthropic/claude-6-decisions',
      }),
    });
    await screen.findByLabelText('โมเดล Decisions');

    // Mode change
    await openSelect('โหมดทำงาน');
    await userEvent.click(screen.getByRole('option', { name: 'เปิดใช้งาน' }));
    expect(handleChange).toHaveBeenCalledWith('support_router_mode', 'on');

    // Model change
    await openSelect('โมเดล Decisions');
    await userEvent.click(
      await screen.findByRole('option', { name: /GPT-6 Luna Decisions/ })
    );
    expect(handleChange).toHaveBeenCalledWith(
      'support_router_model',
      'openai/gpt-6-luna-decisions'
    );

    // Handover message change
    const user = userEvent.setup();
    await user.type(screen.getByLabelText(/ข้อความแจ้งลูกค้า/), 'สวัสดีครับ');
    expect(handleChange).toHaveBeenCalledWith('support_handover_message', 'ส');
  });
});
