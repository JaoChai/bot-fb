<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>QA Weekly Report</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            line-height: 1.6;
            color: #333;
            max-width: 600px;
            margin: 0 auto;
            padding: 20px;
        }
        .header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 30px;
            border-radius: 8px 8px 0 0;
            text-align: center;
        }
        .header h1 {
            margin: 0;
            font-size: 24px;
        }
        .header .subtitle {
            margin-top: 8px;
            opacity: 0.9;
        }
        .content {
            background: #f9fafb;
            padding: 30px;
            border: 1px solid #e5e7eb;
            border-top: none;
        }
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 16px;
            margin: 20px 0;
        }
        .stat-card {
            background: white;
            padding: 16px;
            border-radius: 8px;
            border: 1px solid #e5e7eb;
            text-align: center;
        }
        .stat-value {
            font-size: 28px;
            font-weight: 700;
            color: #1f2937;
        }
        .stat-label {
            font-size: 13px;
            color: #6b7280;
            margin-top: 4px;
        }
        .score-good { color: #059669; }
        .score-warning { color: #d97706; }
        .score-bad { color: #dc2626; }
        .score-change {
            font-size: 14px;
            margin-top: 4px;
        }
        .score-change.positive { color: #059669; }
        .score-change.negative { color: #dc2626; }
        .button {
            display: inline-block;
            background: #667eea;
            color: white;
            padding: 12px 24px;
            border-radius: 6px;
            text-decoration: none;
            font-weight: 600;
            margin-top: 20px;
        }
        .button:hover {
            background: #5a67d8;
        }
        .footer {
            text-align: center;
            padding: 20px;
            color: #6b7280;
            font-size: 13px;
            border: 1px solid #e5e7eb;
            border-top: none;
            border-radius: 0 0 8px 8px;
            background: white;
        }
        .summary-section {
            background: white;
            padding: 20px;
            border-radius: 8px;
            border: 1px solid #e5e7eb;
            margin-top: 20px;
        }
        .summary-section h3 {
            margin-top: 0;
            color: #1f2937;
        }
        .summary-section ul {
            margin: 0;
            padding-left: 20px;
        }
        .summary-section li {
            margin-bottom: 8px;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>QA Weekly Report</h1>
        <div class="subtitle">{{ $bot->name ?? 'Bot' }} - {{ $weekRange }}</div>
    </div>

    <div class="content">
        <p>Hello,</p>
        <p>Your QA Inspector weekly report is ready. Here's a summary of your bot's performance this week:</p>

        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-value">{{ $report->total_conversations }}</div>
                <div class="stat-label">Conversations Evaluated</div>
            </div>
            <div class="stat-card">
                <div class="stat-value @if($averageScorePercent >= 80) score-good @elseif($averageScorePercent >= 60) score-warning @else score-bad @endif">
                    {{ $averageScorePercent }}%
                </div>
                <div class="stat-label">Average Score</div>
                @if($scoreChange !== null)
                    <div class="score-change {{ $scoreChange >= 0 ? 'positive' : 'negative' }}">
                        {{ $scoreChange >= 0 ? '+' : '' }}{{ $scoreChange }}% from last week
                    </div>
                @endif
            </div>
            <div class="stat-card">
                <div class="stat-value @if($report->total_flagged > 0) score-warning @else score-good @endif">
                    {{ $report->total_flagged }}
                </div>
                <div class="stat-label">Flagged Issues</div>
            </div>
            <div class="stat-card">
                <div class="stat-value">{{ $flaggedPercentage }}%</div>
                <div class="stat-label">Error Rate</div>
            </div>
        </div>

        @if(!empty($report->top_issues))
        <div class="summary-section">
            <h3>Top Issues This Week</h3>
            <ul>
                @foreach(array_slice($report->top_issues, 0, 3) as $issue)
                    <li><strong>{{ ucfirst(str_replace('_', ' ', $issue['type'] ?? 'Unknown')) }}</strong>: {{ $issue['count'] ?? 0 }} occurrences</li>
                @endforeach
            </ul>
        </div>
        @endif

        @if(!empty($report->prompt_suggestions))
        <div class="summary-section">
            <h3>Suggested Improvements</h3>
            <ul>
                @foreach(array_slice($report->prompt_suggestions, 0, 3) as $suggestion)
                    <li>{{ $suggestion['suggestion'] ?? $suggestion }}</li>
                @endforeach
            </ul>
        </div>
        @endif

        <div style="text-align: center;">
            <a href="{{ $reportUrl }}" class="button">View Full Report</a>
        </div>
    </div>

    <div class="footer">
        <p>This report was generated by QA Inspector for {{ $bot->name ?? 'your bot' }}.</p>
        <p>You're receiving this because email notifications are enabled in your QA Inspector settings.</p>
    </div>
</body>
</html>
