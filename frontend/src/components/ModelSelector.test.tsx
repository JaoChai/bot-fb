import { render, screen } from '@testing-library/react';
import { describe, it, expect } from 'vitest';
import { ModelConfiguration } from './ModelSelector';

describe('ModelSelector decisions warning', () => {
  it('warns inline when a chat-model value ends with -decisions', () => {
    render(
      <ModelConfiguration
        primaryModel="openai/gpt-6-luna-decisions"
        fallbackModel="google/gemini-2.0-flash-001"
        utilityModel=""
        onPrimaryChange={() => {}}
        onFallbackChange={() => {}}
        onUtilityChange={() => {}}
      />
    );

    expect(
      screen.getByText(
        'โมเดล Decisions ใช้ตอบแชทไม่ได้ — ใส่ได้เฉพาะช่องคัดแยกส่ง Support'
      )
    ).toBeInTheDocument();
  });

  it('shows no warning for normal chat models', () => {
    render(
      <ModelConfiguration
        primaryModel="openai/gpt-4o-mini"
        fallbackModel="google/gemini-2.0-flash-001"
        utilityModel=""
        onPrimaryChange={() => {}}
        onFallbackChange={() => {}}
        onUtilityChange={() => {}}
      />
    );

    expect(
      screen.queryByText(
        'โมเดล Decisions ใช้ตอบแชทไม่ได้ — ใส่ได้เฉพาะช่องคัดแยกส่ง Support'
      )
    ).not.toBeInTheDocument();
  });
});
