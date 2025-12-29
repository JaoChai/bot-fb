import { useState } from 'react';
import {
  LineChart,
  Line,
  XAxis,
  YAxis,
  CartesianGrid,
  Tooltip,
  ResponsiveContainer,
  PieChart,
  Pie,
  Cell,
  BarChart,
  Bar,
} from 'recharts';
import {
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from '@/components/ui/card';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import { useCostAnalytics } from '@/hooks/useCostAnalytics';
import type { CostAnalyticsFilters } from '@/types/api';
import { DollarSign, MessageSquare, TrendingUp, Coins } from 'lucide-react';

const COLORS = ['#3B82F6', '#10B981', '#F59E0B', '#EF4444', '#8B5CF6', '#EC4899'];

export function CostAnalytics() {
  const [filters, setFilters] = useState<CostAnalyticsFilters>({
    group_by: 'day',
  });

  const { data, isLoading, error } = useCostAnalytics(filters);

  if (isLoading) {
    return (
      <div className="flex items-center justify-center py-12">
        <div className="text-muted-foreground">Loading analytics...</div>
      </div>
    );
  }

  if (error) {
    return (
      <div className="flex items-center justify-center py-12">
        <div className="text-destructive">Failed to load analytics</div>
      </div>
    );
  }

  if (!data) return null;

  const formatCost = (value: number | string | null | undefined) => {
    const num = Number(value) || 0;
    return `$${num.toFixed(4)}`;
  };
  const formatCostShort = (value: number | string | null | undefined) => {
    const num = Number(value) || 0;
    return `$${num.toFixed(2)}`;
  };
  const formatTokens = (value: number | string | null | undefined) => {
    const num = Number(value) || 0;
    return num.toLocaleString();
  };

  const totalTokens =
    (Number(data.summary.total_prompt_tokens) || 0) +
    (Number(data.summary.total_completion_tokens) || 0);

  return (
    <div className="space-y-6">
      {/* Period Selector */}
      <div className="flex items-center gap-4">
        <span className="text-sm text-muted-foreground">Group by:</span>
        <Select
          value={filters.group_by}
          onValueChange={(value) =>
            setFilters({ ...filters, group_by: value as 'day' | 'week' | 'month' })
          }
        >
          <SelectTrigger className="w-[140px]">
            <SelectValue placeholder="Group by" />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value="day">Daily</SelectItem>
            <SelectItem value="week">Weekly</SelectItem>
            <SelectItem value="month">Monthly</SelectItem>
          </SelectContent>
        </Select>
      </div>

      {/* Quick Stats Cards */}
      <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-4">
        <Card>
          <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
            <CardTitle className="text-sm font-medium">Today</CardTitle>
            <DollarSign className="h-4 w-4 text-muted-foreground" />
          </CardHeader>
          <CardContent>
            <div className="text-2xl font-bold">
              {formatCostShort(data.summary.today_cost)}
            </div>
            <p className="text-xs text-muted-foreground">Cost today</p>
          </CardContent>
        </Card>

        <Card>
          <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
            <CardTitle className="text-sm font-medium">This Week</CardTitle>
            <TrendingUp className="h-4 w-4 text-muted-foreground" />
          </CardHeader>
          <CardContent>
            <div className="text-2xl font-bold">
              {formatCostShort(data.summary.week_cost)}
            </div>
            <p className="text-xs text-muted-foreground">Cost this week</p>
          </CardContent>
        </Card>

        <Card>
          <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
            <CardTitle className="text-sm font-medium">This Month</CardTitle>
            <DollarSign className="h-4 w-4 text-muted-foreground" />
          </CardHeader>
          <CardContent>
            <div className="text-2xl font-bold">
              {formatCostShort(data.summary.month_cost)}
            </div>
            <p className="text-xs text-muted-foreground">Cost this month</p>
          </CardContent>
        </Card>

        <Card>
          <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
            <CardTitle className="text-sm font-medium">Total Tokens</CardTitle>
            <Coins className="h-4 w-4 text-muted-foreground" />
          </CardHeader>
          <CardContent>
            <div className="text-2xl font-bold">{formatTokens(totalTokens)}</div>
            <p className="text-xs text-muted-foreground">
              {formatTokens(data.summary.total_prompt_tokens)} prompt /{' '}
              {formatTokens(data.summary.total_completion_tokens)} completion
            </p>
          </CardContent>
        </Card>
      </div>

      {/* Summary Stats */}
      <div className="grid gap-4 md:grid-cols-3">
        <Card>
          <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
            <CardTitle className="text-sm font-medium">Period Total</CardTitle>
            <DollarSign className="h-4 w-4 text-muted-foreground" />
          </CardHeader>
          <CardContent>
            <div className="text-2xl font-bold">
              {formatCost(data.summary.total_cost)}
            </div>
            <p className="text-xs text-muted-foreground">Last 30 days</p>
          </CardContent>
        </Card>

        <Card>
          <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
            <CardTitle className="text-sm font-medium">AI Responses</CardTitle>
            <MessageSquare className="h-4 w-4 text-muted-foreground" />
          </CardHeader>
          <CardContent>
            <div className="text-2xl font-bold">
              {data.summary.total_responses.toLocaleString()}
            </div>
            <p className="text-xs text-muted-foreground">Total responses</p>
          </CardContent>
        </Card>

        <Card>
          <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
            <CardTitle className="text-sm font-medium">Avg Cost/Response</CardTitle>
            <TrendingUp className="h-4 w-4 text-muted-foreground" />
          </CardHeader>
          <CardContent>
            <div className="text-2xl font-bold">
              {formatCost(data.summary.avg_cost_per_response)}
            </div>
            <p className="text-xs text-muted-foreground">Per AI response</p>
          </CardContent>
        </Card>
      </div>

      {/* Cost Over Time Chart */}
      {data.time_series.length > 0 && (
        <Card>
          <CardHeader>
            <CardTitle>Cost Over Time</CardTitle>
            <CardDescription>
              AI response costs by {filters.group_by}
            </CardDescription>
          </CardHeader>
          <CardContent>
            <div className="h-[300px]">
              <ResponsiveContainer width="100%" height="100%">
                <LineChart data={data.time_series}>
                  <CartesianGrid strokeDasharray="3 3" className="stroke-muted" />
                  <XAxis
                    dataKey="period"
                    className="text-xs"
                    tick={{ fill: 'currentColor' }}
                  />
                  <YAxis
                    tickFormatter={(v) => `$${(Number(v) || 0).toFixed(2)}`}
                    className="text-xs"
                    tick={{ fill: 'currentColor' }}
                  />
                  <Tooltip
                    formatter={(v) => [formatCost(Number(v) || 0), 'Cost']}
                    contentStyle={{
                      backgroundColor: 'hsl(var(--card))',
                      border: '1px solid hsl(var(--border))',
                      borderRadius: '8px',
                    }}
                  />
                  <Line
                    type="monotone"
                    dataKey="total_cost"
                    stroke="#3B82F6"
                    strokeWidth={2}
                    dot={{ fill: '#3B82F6', strokeWidth: 2 }}
                  />
                </LineChart>
              </ResponsiveContainer>
            </div>
          </CardContent>
        </Card>
      )}

      {/* Cost by Model & Bot */}
      <div className="grid gap-4 md:grid-cols-2">
        {data.by_model.length > 0 && (
          <Card>
            <CardHeader>
              <CardTitle>Cost by Model</CardTitle>
              <CardDescription>Breakdown by AI model used</CardDescription>
            </CardHeader>
            <CardContent>
              <div className="h-[300px]">
                <ResponsiveContainer width="100%" height="100%">
                  <PieChart>
                    <Pie
                      data={data.by_model.map((m) => ({
                        ...m,
                        model_used: m.model_used || 'unknown',
                      }))}
                      dataKey="total_cost"
                      nameKey="model_used"
                      cx="50%"
                      cy="50%"
                      outerRadius={100}
                      label={({ name, percent }) =>
                        `${String(name).split('/').pop()} (${((percent || 0) * 100).toFixed(0)}%)`
                      }
                      labelLine={{ stroke: 'currentColor' }}
                    >
                      {data.by_model.map((_, index) => (
                        <Cell
                          key={`cell-${index}`}
                          fill={COLORS[index % COLORS.length]}
                        />
                      ))}
                    </Pie>
                    <Tooltip
                      formatter={(v) => [formatCost(Number(v) || 0), 'Cost']}
                      contentStyle={{
                        backgroundColor: 'hsl(var(--card))',
                        border: '1px solid hsl(var(--border))',
                        borderRadius: '8px',
                      }}
                    />
                  </PieChart>
                </ResponsiveContainer>
              </div>
            </CardContent>
          </Card>
        )}

        {data.by_bot && data.by_bot.length > 0 && (
          <Card>
            <CardHeader>
              <CardTitle>Cost by Bot</CardTitle>
              <CardDescription>Breakdown by bot</CardDescription>
            </CardHeader>
            <CardContent>
              <div className="h-[300px]">
                <ResponsiveContainer width="100%" height="100%">
                  <BarChart data={data.by_bot} layout="vertical">
                    <CartesianGrid strokeDasharray="3 3" className="stroke-muted" />
                    <XAxis
                      type="number"
                      tickFormatter={(v) => `$${(Number(v) || 0).toFixed(2)}`}
                      className="text-xs"
                      tick={{ fill: 'currentColor' }}
                    />
                    <YAxis
                      type="category"
                      dataKey="bot_name"
                      width={100}
                      className="text-xs"
                      tick={{ fill: 'currentColor' }}
                    />
                    <Tooltip
                      formatter={(v) => [formatCost(Number(v) || 0), 'Cost']}
                      contentStyle={{
                        backgroundColor: 'hsl(var(--card))',
                        border: '1px solid hsl(var(--border))',
                        borderRadius: '8px',
                      }}
                    />
                    <Bar dataKey="total_cost" fill="#3B82F6" radius={[0, 4, 4, 0]} />
                  </BarChart>
                </ResponsiveContainer>
              </div>
            </CardContent>
          </Card>
        )}
      </div>

      {/* Model Details Table */}
      {data.by_model.length > 0 && (
        <Card>
          <CardHeader>
            <CardTitle>Model Usage Details</CardTitle>
            <CardDescription>Detailed breakdown by model</CardDescription>
          </CardHeader>
          <CardContent>
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <thead>
                  <tr className="border-b">
                    <th className="py-3 text-left font-medium">Model</th>
                    <th className="py-3 text-right font-medium">Responses</th>
                    <th className="py-3 text-right font-medium">Prompt Tokens</th>
                    <th className="py-3 text-right font-medium">Completion Tokens</th>
                    <th className="py-3 text-right font-medium">Total Cost</th>
                  </tr>
                </thead>
                <tbody>
                  {data.by_model.map((model, index) => (
                    <tr key={index} className="border-b last:border-0">
                      <td className="py-3">
                        <span className="font-mono text-xs">
                          {model.model_used || 'Unknown'}
                        </span>
                      </td>
                      <td className="py-3 text-right">
                        {(model.response_count ?? 0).toLocaleString()}
                      </td>
                      <td className="py-3 text-right">
                        {(model.prompt_tokens ?? 0).toLocaleString()}
                      </td>
                      <td className="py-3 text-right">
                        {(model.completion_tokens ?? 0).toLocaleString()}
                      </td>
                      <td className="py-3 text-right font-medium text-primary">
                        {formatCost(model.total_cost ?? 0)}
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </CardContent>
        </Card>
      )}

      {/* Empty State */}
      {data.summary.total_responses === 0 && (
        <Card>
          <CardContent className="flex flex-col items-center justify-center py-12">
            <MessageSquare className="h-12 w-12 text-muted-foreground mb-4" />
            <h3 className="text-lg font-medium">No cost data yet</h3>
            <p className="text-sm text-muted-foreground text-center max-w-sm mt-2">
              Cost analytics will appear here once your bots start generating AI
              responses.
            </p>
          </CardContent>
        </Card>
      )}
    </div>
  );
}
