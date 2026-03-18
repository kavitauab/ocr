import { useQuery } from "@tanstack/react-query";
import api from "@/api/client";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Skeleton } from "@/components/ui/skeleton";
import { Table, TableHeader, TableBody, TableRow, TableHead, TableCell } from "@/components/ui/table";
import { formatDateTime } from "@/lib/ui-utils";
import {
  Activity,
  CheckCircle,
  AlertTriangle,
  Clock,
  Zap,
  RotateCcw,
  TrendingUp,
  Shield,
} from "lucide-react";

function formatSeconds(s: number | null) {
  if (s === null || s === undefined) return "—";
  if (s < 60) return `${s.toFixed(1)}s`;
  return `${Math.floor(s / 60)}m ${Math.round(s % 60)}s`;
}

function formatCost(usd: number) {
  return `$${usd.toFixed(4)}`;
}

export default function Health() {
  const { data, isLoading } = useQuery({
    queryKey: ["health"],
    queryFn: () => api.get("/invoices/health").then((r) => r.data),
    refetchInterval: 15000, // Auto-refresh every 15s
  });

  const overview = data?.overview;
  const queue = data?.queue;
  const daily: any[] = data?.daily || [];
  const topErrors: any[] = data?.topErrors || [];
  const rateLimits: any[] = data?.rateLimits || [];

  // Find max for chart scaling
  const maxDaily = Math.max(1, ...daily.map((d: any) => d.completed + d.failed));

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex items-center gap-3">
        <Activity className="h-6 w-6 text-primary" />
        <h1 className="text-2xl font-bold tracking-tight">System Health</h1>
        <span className="text-xs text-muted-foreground ml-auto">Auto-refreshes every 15s</span>
      </div>

      {/* Overview cards */}
      <div className="grid grid-cols-2 xl:grid-cols-4 gap-3">
        {isLoading ? (
          Array.from({ length: 4 }).map((_, i) => (
            <Card key={i}>
              <CardContent className="p-4">
                <Skeleton className="h-3 w-20 mb-2" />
                <Skeleton className="h-7 w-14" />
              </CardContent>
            </Card>
          ))
        ) : (
          <>
            <Card className="border-l-4 border-l-emerald-500">
              <CardContent className="p-4">
                <div className="flex items-start justify-between">
                  <div>
                    <p className="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">
                      Success Rate
                    </p>
                    <p className="text-2xl font-bold tabular-nums mt-0.5 leading-none">
                      {overview?.successRate ?? 0}%
                    </p>
                  </div>
                  <div className="flex h-8 w-8 items-center justify-center rounded-lg bg-emerald-50">
                    <CheckCircle className="h-4 w-4 text-emerald-600" />
                  </div>
                </div>
              </CardContent>
            </Card>

            <Card className="border-l-4 border-l-blue-500">
              <CardContent className="p-4">
                <div className="flex items-start justify-between">
                  <div>
                    <p className="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">
                      Avg Processing
                    </p>
                    <p className="text-2xl font-bold tabular-nums mt-0.5 leading-none">
                      {formatSeconds(overview?.avgProcessingSeconds)}
                    </p>
                  </div>
                  <div className="flex h-8 w-8 items-center justify-center rounded-lg bg-blue-50">
                    <Clock className="h-4 w-4 text-blue-600" />
                  </div>
                </div>
              </CardContent>
            </Card>

            <Card className="border-l-4 border-l-amber-500">
              <CardContent className="p-4">
                <div className="flex items-start justify-between">
                  <div>
                    <p className="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">
                      Queue Depth
                    </p>
                    <p className="text-2xl font-bold tabular-nums mt-0.5 leading-none">
                      {queue?.depth ?? 0}
                    </p>
                    {(queue?.retrying ?? 0) > 0 && (
                      <p className="text-[10px] text-amber-600 mt-0.5">{queue.retrying} retrying</p>
                    )}
                  </div>
                  <div className="flex h-8 w-8 items-center justify-center rounded-lg bg-amber-50">
                    <Zap className="h-4 w-4 text-amber-600" />
                  </div>
                </div>
              </CardContent>
            </Card>

            <Card className="border-l-4 border-l-red-500">
              <CardContent className="p-4">
                <div className="flex items-start justify-between">
                  <div>
                    <p className="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">
                      Failed Jobs
                    </p>
                    <p className="text-2xl font-bold tabular-nums mt-0.5 leading-none">
                      {overview?.failedJobs ?? 0}
                    </p>
                  </div>
                  <div className="flex h-8 w-8 items-center justify-center rounded-lg bg-red-50">
                    <AlertTriangle className="h-4 w-4 text-red-600" />
                  </div>
                </div>
              </CardContent>
            </Card>
          </>
        )}
      </div>

      {/* Queue + Job Status row */}
      <div className="grid grid-cols-1 lg:grid-cols-2 gap-4">
        {/* Live Queue Status */}
        <Card>
          <CardHeader className="pb-3">
            <CardTitle className="text-base flex items-center gap-2">
              <RotateCcw className="h-4 w-4 text-muted-foreground" />
              Queue Status
            </CardTitle>
          </CardHeader>
          <CardContent>
            {isLoading ? (
              <Skeleton className="h-20 w-full" />
            ) : (
              <div className="space-y-3">
                <div className="grid grid-cols-3 gap-3">
                  {[
                    { label: "Queued", value: overview?.queuedJobs ?? 0, color: "text-blue-600" },
                    { label: "Processing", value: overview?.processingJobs ?? 0, color: "text-amber-600" },
                    { label: "Retrying", value: overview?.retryingJobs ?? 0, color: "text-orange-600" },
                  ].map((item) => (
                    <div key={item.label} className="rounded-lg border p-3 text-center">
                      <p className="text-xs text-muted-foreground">{item.label}</p>
                      <p className={`text-xl font-bold tabular-nums ${item.color}`}>{item.value}</p>
                    </div>
                  ))}
                </div>
                {queue?.oldestQueuedAt && (
                  <p className="text-xs text-muted-foreground">
                    Oldest queued: {formatDateTime(queue.oldestQueuedAt)}
                  </p>
                )}
                <div className="flex items-center gap-2 text-xs text-muted-foreground">
                  <span>Total completed: <strong className="text-foreground">{overview?.completedJobs ?? 0}</strong></span>
                  <span>•</span>
                  <span>Total failed: <strong className="text-foreground">{overview?.failedJobs ?? 0}</strong></span>
                  <span>•</span>
                  <span>Total jobs: <strong className="text-foreground">{overview?.totalJobs ?? 0}</strong></span>
                </div>
              </div>
            )}
          </CardContent>
        </Card>

        {/* Rate Limits */}
        <Card>
          <CardHeader className="pb-3">
            <CardTitle className="text-base flex items-center gap-2">
              <Shield className="h-4 w-4 text-muted-foreground" />
              Rate Limits
            </CardTitle>
          </CardHeader>
          <CardContent>
            {isLoading ? (
              <Skeleton className="h-20 w-full" />
            ) : rateLimits.length === 0 ? (
              <p className="text-sm text-muted-foreground py-4 text-center">
                No rate limits configured. Set limits in Billing settings.
              </p>
            ) : (
              <div className="space-y-2">
                {rateLimits.map((rl: any) => (
                  <div key={rl.companyId} className="rounded-lg border p-3">
                    <p className="text-sm font-medium">{rl.companyName}</p>
                    <div className="flex gap-4 mt-1">
                      {rl.hourlyLimit !== null && (
                        <div className="text-xs">
                          <span className="text-muted-foreground">Hourly: </span>
                          <span className={`font-medium ${rl.hourlyUsed >= rl.hourlyLimit ? "text-red-600" : "text-foreground"}`}>
                            {rl.hourlyUsed}/{rl.hourlyLimit}
                          </span>
                        </div>
                      )}
                      {rl.dailyLimit !== null && (
                        <div className="text-xs">
                          <span className="text-muted-foreground">Daily: </span>
                          <span className={`font-medium ${rl.dailyUsed >= rl.dailyLimit ? "text-red-600" : "text-foreground"}`}>
                            {rl.dailyUsed}/{rl.dailyLimit}
                          </span>
                        </div>
                      )}
                    </div>
                  </div>
                ))}
              </div>
            )}
          </CardContent>
        </Card>
      </div>

      {/* Daily Trend Chart */}
      <Card>
        <CardHeader className="pb-3">
          <CardTitle className="text-base flex items-center gap-2">
            <TrendingUp className="h-4 w-4 text-muted-foreground" />
            Daily Trend (Last 30 Days)
          </CardTitle>
        </CardHeader>
        <CardContent>
          {isLoading ? (
            <Skeleton className="h-40 w-full" />
          ) : daily.length === 0 ? (
            <p className="text-sm text-muted-foreground py-8 text-center">
              No data yet. Process some invoices to see trends.
            </p>
          ) : (
            <div className="space-y-3">
              {/* CSS bar chart */}
              <div className="flex items-end gap-[2px] h-32">
                {[...daily].reverse().map((d: any) => {
                  const total = d.completed + d.failed;
                  const height = total > 0 ? Math.max(4, (total / maxDaily) * 100) : 0;
                  const failedHeight = d.failed > 0 ? Math.max(2, (d.failed / maxDaily) * 100) : 0;
                  const completedHeight = height - failedHeight;

                  return (
                    <div
                      key={d.date}
                      className="flex-1 flex flex-col justify-end group relative"
                      title={`${d.date}: ${d.completed} completed, ${d.failed} failed, avg ${formatSeconds(d.avgSeconds)}, cost ${formatCost(d.totalCostUsd)}`}
                    >
                      {completedHeight > 0 && (
                        <div
                          className="bg-emerald-400 rounded-t-sm transition-all hover:bg-emerald-500"
                          style={{ height: `${completedHeight}%` }}
                        />
                      )}
                      {failedHeight > 0 && (
                        <div
                          className="bg-red-400 transition-all hover:bg-red-500"
                          style={{ height: `${failedHeight}%` }}
                        />
                      )}
                    </div>
                  );
                })}
              </div>
              {/* X-axis labels */}
              <div className="flex justify-between text-[10px] text-muted-foreground px-0.5">
                <span>{daily[daily.length - 1]?.date}</span>
                <span>{daily[0]?.date}</span>
              </div>
              {/* Legend */}
              <div className="flex gap-4 text-xs text-muted-foreground">
                <span className="flex items-center gap-1.5">
                  <span className="inline-block w-3 h-3 rounded-sm bg-emerald-400" /> Completed
                </span>
                <span className="flex items-center gap-1.5">
                  <span className="inline-block w-3 h-3 rounded-sm bg-red-400" /> Failed
                </span>
              </div>
            </div>
          )}
        </CardContent>
      </Card>

      {/* Detailed daily table */}
      {daily.length > 0 && (
        <Card>
          <CardHeader className="pb-3">
            <CardTitle className="text-base">Daily Breakdown</CardTitle>
          </CardHeader>
          <CardContent className="p-0">
            <Table>
              <TableHeader>
                <TableRow className="bg-muted/30">
                  <TableHead className="font-semibold">Date</TableHead>
                  <TableHead className="text-center font-semibold">Completed</TableHead>
                  <TableHead className="text-center font-semibold">Failed</TableHead>
                  <TableHead className="text-right font-semibold">Avg Time</TableHead>
                  <TableHead className="text-right font-semibold">Cost</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {daily.map((d: any) => (
                  <TableRow key={d.date}>
                    <TableCell className="font-medium">{d.date}</TableCell>
                    <TableCell className="text-center tabular-nums text-emerald-600 font-medium">{d.completed}</TableCell>
                    <TableCell className="text-center tabular-nums">
                      {d.failed > 0 ? (
                        <span className="text-red-600 font-medium">{d.failed}</span>
                      ) : (
                        <span className="text-muted-foreground">0</span>
                      )}
                    </TableCell>
                    <TableCell className="text-right tabular-nums">{formatSeconds(d.avgSeconds)}</TableCell>
                    <TableCell className="text-right tabular-nums">{formatCost(d.totalCostUsd)}</TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          </CardContent>
        </Card>
      )}

      {/* Top Errors */}
      {topErrors.length > 0 && (
        <Card>
          <CardHeader className="pb-3">
            <CardTitle className="text-base flex items-center gap-2">
              <AlertTriangle className="h-4 w-4 text-red-500" />
              Top Errors (Last 30 Days)
            </CardTitle>
          </CardHeader>
          <CardContent className="p-0">
            <Table>
              <TableHeader>
                <TableRow className="bg-muted/30">
                  <TableHead className="font-semibold">Error Message</TableHead>
                  <TableHead className="text-center font-semibold w-20">Count</TableHead>
                  <TableHead className="text-right font-semibold w-40">Last Seen</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {topErrors.map((err: any, i: number) => (
                  <TableRow key={i}>
                    <TableCell className="font-mono text-xs text-red-700 max-w-md truncate">
                      {err.message}
                    </TableCell>
                    <TableCell className="text-center tabular-nums font-medium">{err.count}</TableCell>
                    <TableCell className="text-right text-sm text-muted-foreground">{formatDateTime(err.lastSeen)}</TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          </CardContent>
        </Card>
      )}
    </div>
  );
}
