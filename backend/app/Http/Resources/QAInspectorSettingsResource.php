<?php

namespace App\Http\Resources;

use App\Services\QAInspector\QAInspectorService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class QAInspectorSettingsResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $defaults = app(QAInspectorService::class)->getDefaultModels();

        return [
            'qa_inspector_enabled' => (bool) $this->qa_inspector_enabled,
            'models' => [
                'realtime' => [
                    'primary' => $this->qa_realtime_model ?? $defaults['realtime']['primary'],
                    'fallback' => $this->qa_realtime_fallback_model ?? $defaults['realtime']['fallback'],
                ],
                'analysis' => [
                    'primary' => $this->qa_analysis_model ?? $defaults['analysis']['primary'],
                    'fallback' => $this->qa_analysis_fallback_model ?? $defaults['analysis']['fallback'],
                ],
                'report' => [
                    'primary' => $this->qa_report_model ?? $defaults['report']['primary'],
                    'fallback' => $this->qa_report_fallback_model ?? $defaults['report']['fallback'],
                ],
            ],
            'settings' => [
                'score_threshold' => (float) ($this->qa_score_threshold ?? 0.70),
                'sampling_rate' => (int) ($this->qa_sampling_rate ?? 100),
                'report_schedule' => $this->qa_report_schedule ?? 'monday_00:00',
            ],
            'notifications' => $this->qa_notifications ?? [
                'email' => true,
                'alert' => true,
                'slack' => false,
            ],
        ];
    }
}
