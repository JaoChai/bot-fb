import * as React from 'react';
import { Card, CardContent, CardHeader, CardTitle } from '@/Components/ui/card';
import { cn } from '@/Lib/utils';

interface ConversationTrendChartProps {
  data: Array<{ date: string; count: number }>;
}

function formatDate(dateString: string): string {
  const date = new Date(dateString);
  return date.toLocaleDateString('th-TH', {
    day: 'numeric',
    month: 'short',
  });
}

function ConversationTrendChart({ data }: ConversationTrendChartProps) {
  const maxCount = Math.max(...data.map((d) => d.count), 1);
  const chartHeight = 160;
  const chartPadding = 24;

  // Generate points for the area/line chart
  const points = data.map((item, index) => {
    const x = (index / Math.max(data.length - 1, 1)) * 100;
    const y = 100 - (item.count / maxCount) * 100;
    return { x, y, ...item };
  });

  // Create SVG path for line
  const linePath = points
    .map((point, index) => {
      const command = index === 0 ? 'M' : 'L';
      return `${command} ${point.x} ${point.y}`;
    })
    .join(' ');

  // Create SVG path for area (closed polygon)
  const areaPath = `${linePath} L 100 100 L 0 100 Z`;

  return (
    <Card>
      <CardHeader className="pb-2">
        <CardTitle className="text-base font-medium">
          จำนวนการสนทนารายวัน
        </CardTitle>
      </CardHeader>
      <CardContent>
        {data.length > 0 ? (
          <div className="space-y-2">
            <div
              className="relative w-full"
              style={{ height: chartHeight + chartPadding }}
            >
              {/* Y-axis labels */}
              <div className="absolute top-0 left-0 flex h-full flex-col justify-between py-1 pr-2 text-xs text-muted-foreground">
                <span>{maxCount}</span>
                <span>{Math.round(maxCount / 2)}</span>
                <span>0</span>
              </div>

              {/* Chart area */}
              <div className="ml-8 h-full">
                <svg
                  viewBox="0 0 100 100"
                  preserveAspectRatio="none"
                  className="h-full w-full"
                  style={{ height: chartHeight }}
                >
                  {/* Grid lines */}
                  <line
                    x1="0"
                    y1="0"
                    x2="100"
                    y2="0"
                    className="stroke-muted"
                    strokeWidth="0.3"
                  />
                  <line
                    x1="0"
                    y1="50"
                    x2="100"
                    y2="50"
                    className="stroke-muted"
                    strokeWidth="0.3"
                  />
                  <line
                    x1="0"
                    y1="100"
                    x2="100"
                    y2="100"
                    className="stroke-muted"
                    strokeWidth="0.3"
                  />

                  {/* Area fill */}
                  <path
                    d={areaPath}
                    className="fill-primary/20"
                  />

                  {/* Line */}
                  <path
                    d={linePath}
                    className="stroke-primary"
                    fill="none"
                    strokeWidth="2"
                    vectorEffect="non-scaling-stroke"
                  />

                  {/* Data points */}
                  {points.map((point, index) => (
                    <circle
                      key={index}
                      cx={point.x}
                      cy={point.y}
                      r="3"
                      className="fill-primary"
                      vectorEffect="non-scaling-stroke"
                    />
                  ))}
                </svg>

                {/* X-axis labels */}
                <div className="mt-2 flex justify-between text-xs text-muted-foreground">
                  {data.length <= 7
                    ? data.map((item, index) => (
                        <span key={index}>{formatDate(item.date)}</span>
                      ))
                    : [
                        <span key="start">{formatDate(data[0].date)}</span>,
                        <span key="mid">
                          {formatDate(data[Math.floor(data.length / 2)].date)}
                        </span>,
                        <span key="end">
                          {formatDate(data[data.length - 1].date)}
                        </span>,
                      ]}
                </div>
              </div>
            </div>

            {/* Summary */}
            <div className="flex items-center justify-between pt-2 text-sm">
              <span className="text-muted-foreground">รวมทั้งหมด</span>
              <span className="font-semibold">
                {data.reduce((sum, d) => sum + d.count, 0).toLocaleString()} การสนทนา
              </span>
            </div>
          </div>
        ) : (
          <div className="flex h-[160px] items-center justify-center">
            <p className="text-muted-foreground text-sm">ยังไม่มีข้อมูล</p>
          </div>
        )}
      </CardContent>
    </Card>
  );
}

export { ConversationTrendChart };
export type { ConversationTrendChartProps };
