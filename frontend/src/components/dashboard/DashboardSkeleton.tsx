import { Skeleton } from '@/components/ui/skeleton';

export function DashboardSkeleton() {
  return (
    <div className="space-y-6">
      {/* Health Bar */}
      <Skeleton className="h-12 w-full rounded-xl" />

      {/* Key Metrics (4 cards) */}
      <div className="grid gap-4 grid-cols-2 lg:grid-cols-4">
        {[...Array(4)].map((_, i) => (
          <div key={i} className="rounded-xl border bg-card p-5 shadow-sm">
            <div className="flex items-start justify-between">
              <div className="space-y-2 flex-1">
                <Skeleton className="h-4 w-20" />
                <Skeleton className="h-8 w-28" />
                <Skeleton className="h-3 w-16" />
              </div>
              <Skeleton className="h-10 w-10 rounded-lg" />
            </div>
          </div>
        ))}
      </div>

      {/* Dual Axis Chart */}
      <div className="rounded-xl border bg-card p-6 shadow-sm">
        <Skeleton className="h-5 w-48 mb-4" />
        <Skeleton className="h-[300px] w-full rounded-lg" />
      </div>

      {/* Bots + Products (2 columns) */}
      <div className="grid gap-4 lg:grid-cols-2">
        <div className="rounded-xl border bg-card p-6 shadow-sm">
          <Skeleton className="h-5 w-32 mb-4" />
          <div className="space-y-2">
            {[...Array(3)].map((_, i) => (
              <div key={i} className="flex items-center gap-3 rounded-lg px-3 py-2.5">
                <Skeleton className="h-2 w-2 rounded-full" />
                <Skeleton className="h-4 w-4 rounded" />
                <Skeleton className="h-4 flex-1" />
                <Skeleton className="h-4 w-12" />
              </div>
            ))}
          </div>
        </div>
        <div className="rounded-xl border bg-card p-6 shadow-sm">
          <Skeleton className="h-5 w-36 mb-4" />
          <div className="grid gap-6 md:grid-cols-2">
            <div className="space-y-3">
              {[...Array(5)].map((_, i) => (
                <div key={i} className="flex items-center gap-3">
                  <Skeleton className="h-7 w-7 rounded-full" />
                  <Skeleton className="h-4 flex-1" />
                  <Skeleton className="h-4 w-16" />
                </div>
              ))}
            </div>
            <Skeleton className="h-[200px] w-full rounded-lg" />
          </div>
        </div>
      </div>

      {/* Cost + Activity (2 columns) */}
      <div className="grid gap-4 lg:grid-cols-2">
        <div className="rounded-xl border bg-card p-6 shadow-sm">
          <div className="flex items-center gap-2 mb-4">
            <Skeleton className="h-8 w-8 rounded-lg" />
            <Skeleton className="h-5 w-16" />
          </div>
          <div className="grid grid-cols-3 gap-4">
            {[...Array(3)].map((_, i) => (
              <div key={i} className="rounded-lg bg-accent/50 p-3">
                <Skeleton className="h-3 w-12 mb-2" />
                <Skeleton className="h-6 w-20" />
              </div>
            ))}
          </div>
        </div>
        <div className="rounded-xl border bg-card p-6 shadow-sm">
          <Skeleton className="h-5 w-28 mb-4" />
          <div className="space-y-4">
            {[...Array(3)].map((_, i) => (
              <div key={i} className="flex gap-3">
                <Skeleton className="h-8 w-8 rounded-full flex-shrink-0" />
                <div className="flex-1 space-y-1">
                  <Skeleton className="h-4 w-32" />
                  <Skeleton className="h-3 w-16" />
                </div>
              </div>
            ))}
          </div>
        </div>
      </div>

      {/* Recent Orders */}
      <div className="rounded-xl border bg-card p-6 shadow-sm">
        <Skeleton className="h-5 w-28 mb-4" />
        <div className="rounded-lg border overflow-hidden">
          <div className="bg-accent/30 px-4 py-3">
            <Skeleton className="h-4 w-full" />
          </div>
          <div className="space-y-0">
            {[...Array(5)].map((_, i) => (
              <div key={i} className="flex items-center gap-4 border-t px-4 py-3">
                <Skeleton className="h-4 w-16" />
                <Skeleton className="h-4 w-20" />
                <Skeleton className="h-4 flex-1" />
                <Skeleton className="h-4 w-16" />
                <Skeleton className="h-5 w-14 rounded-full" />
              </div>
            ))}
          </div>
        </div>
      </div>
    </div>
  );
}
