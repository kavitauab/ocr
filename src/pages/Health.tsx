import { useQuery } from "@tanstack/react-query";
import api from "@/api/client";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Skeleton } from "@/components/ui/skeleton";
import { Table, TableHeader, TableBody, TableRow, TableHead, TableCell } from "@/components/ui/table";
import { formatDateTime } from "@/lib/ui-utils";
import {
  Activity, CheckCircle, AlertTriangle, Clock, Zap, RotateCcw, TrendingUp, Shield,
  DollarSign, Cpu, Gauge, Bot,
} from "lucide-react";

function formatSeconds(s: number | null) {
  if (s === null || s === undefined) return "—";
  if (s < 60) return `${s.toFixed(1)}s`;
  return `${Math.floor(s / 60)}m ${Math.round(s % 60)}s`;
}

function fmtCost(usd: number) {
  return `$${usd.toFixed(4)}`;
}

function fmtTokens(v: number): string {
  if (v >= 1_000_000) return `${(v / 1_000_000).toFixed(1)}M`;
  if (v >= 1_000) return `${(v / 1_000).toFixed(1)}K`;
  return String(v);
}

function fmtPct(v: number | null): string {
  if (v === null || v === undefined) return "—";
  return `${(v * 100).toFixed(1)}%`;
}

function ConfidenceBar({ value, label }: { value: number | null; label: string }) {
  if (value === null) return null;
  const pct = Math.round(value * 100);
  const color = pct >= 95 ? "bg-emerald-500" : pct >= 80 ? "bg-amber-500" : "bg-red-500";
  return (
    <div className="flex items-center gap-2">
      <span className="text-xs text-muted-foreground w-28 shrink-0">{label}</span>
      <div className="flex-1 h-2 bg-muted rounded-full overflow-hidden">
        <div className={`h-full ${color} rounded-full transition-all`} style={{ width: `${pct}%` }} />
      </div>
      <span className="text-xs font-medium tabular-nums w-12 text-right">{pct}%</span>
    </div>
  );
}

export default function Health() {
  const { data, isLoading } = useQuery({
    queryKey: ["health"],
    queryFn: () => api.get("/invoices/health").then((r) => r.data),
    refetchInterval: 15000,
  });

  const overview = data?.overview;
  const queue = data?.queue;
  const confidence = data?.confidence;
  const models: any[] = data?.models || [];
  const daily: any[] = data?.daily || [];
  const topErrors: any[] = data?.topErrors || [];
  const rateLimits: any[] = data?.rateLimits || [];
  const maxDaily = Math.max(1, ...daily.map((d: any) => d.completed + d.failed));

  return (
    <div className="space-y-4">
      <div className="flex items-center gap-3">
        <Activity className="h-6 w-6 text-primary" />
        <h1 className="text-2xl font-bold tracking-tight">System Health</h1>
        <span className="text-xs text-muted-foreground ml-auto">Auto-refreshes every 15s</span>
      </div>

      {/* Overview cards */}
      <div className="grid grid-cols-2 xl:grid-cols-4 gap-3">
        {isLoading ? (
          Array.from({ length: 4 }).map((_, i) => (
            <Card key={i}><CardContent className="p-4"><Skeleton className="h-3 w-20 mb-2" /><Skeleton className="h-7 w-14" /></CardContent></Card>
          ))
        ) : (
          <>
            {[
              { label: "Success Rate", value: `${overview?.successRate ?? 0}%`, icon: CheckCircle, color: "text-emerald-600", bg: "bg-emerald-50", border: "border-l-emerald-500" },
              { label: "Avg Processing", value: formatSeconds(overview?.avgProcessingSeconds), icon: Clock, color: "text-blue-600", bg: "bg-blue-50", border: "border-l-blue-500" },
              { label: "Total Cost", value: fmtCost(overview?.totalCostUsd ?? 0), icon: DollarSign, color: "text-purple-600", bg: "bg-purple-50", border: "border-l-purple-500" },
              { label: "Total Tokens", value: fmtTokens(overview?.totalTokens ?? 0), icon: Cpu, color: "text-amber-600", bg: "bg-amber-50", border: "border-l-amber-500" },
            ].map(({ label, value, icon: Icon, color, bg, border }) => (
              <Card key={label} className={`border-l-4 ${border}`}>
                <CardContent className="p-4">
                  <div className="flex items-start justify-between">
                    <div>
                      <p className="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">{label}</p>
                      <p className="text-2xl font-bold tabular-nums mt-0.5 leading-none">{value}</p>
                    </div>
                    <div className={`flex h-8 w-8 items-center justify-center rounded-lg ${bg}`}><Icon className={`h-4 w-4 ${color}`} /></div>
                  </div>
                </CardContent>
              </Card>
            ))}
          </>
        )}
      </div>

      {/* Confidence + Models row */}
      <div className="grid grid-cols-1 lg:grid-cols-2 gap-4">
        {/* Confidence Scores */}
        <Card>
          <CardHeader className="pb-3">
            <CardTitle className="text-base flex items-center gap-2">
              <Gauge className="h-4 w-4 text-muted-foreground" />
              Average Confidence Scores
            </CardTitle>
          </CardHeader>
          <CardContent>
            {isLoading ? (
              <Skeleton className="h-24 w-full" />
            ) : confidence?.totalWithConfidence === 0 ? (
              <p className="text-sm text-muted-foreground py-4 text-center">No confidence data yet</p>
            ) : (
              <div className="space-y-2.5">
                <ConfidenceBar value={confidence?.avgInvoiceNumber} label="Invoice Number" />
                <ConfidenceBar value={confidence?.avgVendorName} label="Vendor Name" />
                <ConfidenceBar value={confidence?.avgTotalAmount} label="Total Amount" />
                <ConfidenceBar value={confidence?.avgCurrency} label="Currency" />
                <p className="text-[10px] text-muted-foreground pt-1">Based on {confidence?.totalWithConfidence} processed invoices</p>
              </div>
            )}
          </CardContent>
        </Card>

        {/* Model Usage */}
        <Card>
          <CardHeader className="pb-3">
            <CardTitle className="text-base flex items-center gap-2">
              <Bot className="h-4 w-4 text-muted-foreground" />
              Model Usage
            </CardTitle>
          </CardHeader>
          <CardContent>
            {isLoading ? (
              <Skeleton className="h-24 w-full" />
            ) : models.length === 0 ? (
              <p className="text-sm text-muted-foreground py-4 text-center">No model data yet</p>
            ) : (
              <div className="space-y-2">
                {models.map((m: any) => {
                  const total = models.reduce((s: number, x: any) => s + x.count, 0);
                  const pct = total > 0 ? Math.round((m.count / total) * 100) : 0;
                  return (
                    <div key={m.model} className="flex items-center gap-3">
                      <div className="flex-1">
                        <div className="flex items-center justify-between mb-1">
                          <span className="text-xs font-medium">{m.model}</span>
                          <span className="text-xs text-muted-foreground">{m.count} invoices ({pct}%)</span>
                        </div>
                        <div className="h-2 bg-muted rounded-full overflow-hidden">
                          <div className="h-full bg-primary rounded-full" style={{ width: `${pct}%` }} />
                        </div>
                      </div>
                      {m.escalatedCount > 0 && (
                        <span className="text-[10px] text-amber-600 whitespace-nowrap">{m.escalatedCount} escalated</span>
                      )}
                    </div>
                  );
                })}
              </div>
            )}
          </CardContent>
        </Card>
      </div>

      {/* Queue + Rate Limits row */}
      <div className="grid grid-cols-1 lg:grid-cols-2 gap-4">
        <Card>
          <CardHeader className="pb-3">
            <CardTitle className="text-base flex items-center gap-2">
              <RotateCcw className="h-4 w-4 text-muted-foreground" />
              Queue Status
            </CardTitle>
          </CardHeader>
          <CardContent>
            {isLoading ? <Skeleton className="h-20 w-full" /> : (
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
                {queue?.oldestQueuedAt && <p className="text-xs text-muted-foreground">Oldest queued: {formatDateTime(queue.oldestQueuedAt)}</p>}
                <div className="flex gap-3 text-xs text-muted-foreground">
                  <span>Completed: <strong className="text-foreground">{overview?.completedJobs ?? 0}</strong></span>
                  <span>Failed: <strong className="text-foreground">{overview?.failedJobs ?? 0}</strong></span>
                  <span>Total: <strong className="text-foreground">{overview?.totalJobs ?? 0}</strong></span>
                </div>
              </div>
            )}
          </CardContent>
        </Card>

        <Card>
          <CardHeader className="pb-3">
            <CardTitle className="text-base flex items-center gap-2">
              <Shield className="h-4 w-4 text-muted-foreground" />
              Rate Limits
            </CardTitle>
          </CardHeader>
          <CardContent>
            {isLoading ? <Skeleton className="h-20 w-full" /> : rateLimits.length === 0 ? (
              <p className="text-sm text-muted-foreground py-4 text-center">No rate limits configured</p>
            ) : (
              <div className="space-y-2">
                {rateLimits.map((rl: any) => (
                  <div key={rl.companyId} className="rounded-lg border p-3">
                    <p className="text-sm font-medium">{rl.companyName}</p>
                    <div className="flex gap-4 mt-1">
                      {rl.hourlyLimit !== null && (
                        <span className="text-xs"><span className="text-muted-foreground">Hourly: </span><span className={rl.hourlyUsed >= rl.hourlyLimit ? "text-red-600 font-medium" : ""}>{rl.hourlyUsed}/{rl.hourlyLimit}</span></span>
                      )}
                      {rl.dailyLimit !== null && (
                        <span className="text-xs"><span className="text-muted-foreground">Daily: </span><span className={rl.dailyUsed >= rl.dailyLimit ? "text-red-600 font-medium" : ""}>{rl.dailyUsed}/{rl.dailyLimit}</span></span>
                      )}
                    </div>
                  </div>
                ))}
              </div>
            )}
          </CardContent>
        </Card>
      </div>

      {/* Daily Trend */}
      <Card>
        <CardHeader className="pb-3">
          <CardTitle className="text-base flex items-center gap-2">
            <TrendingUp className="h-4 w-4 text-muted-foreground" />
            Daily Trend (Last 30 Days)
          </CardTitle>
        </CardHeader>
        <CardContent>
          {isLoading ? <Skeleton className="h-40 w-full" /> : daily.length === 0 ? (
            <p className="text-sm text-muted-foreground py-8 text-center">No data yet</p>
          ) : (
            <div className="space-y-3">
              <div className="flex items-end gap-[2px] h-32">
                {[...daily].reverse().map((d: any) => {
                  const total = d.completed + d.failed;
                  const height = total > 0 ? Math.max(4, (total / maxDaily) * 100) : 0;
                  const failedHeight = d.failed > 0 ? Math.max(2, (d.failed / maxDaily) * 100) : 0;
                  return (
                    <div key={d.date} className="flex-1 flex flex-col justify-end group relative"
                      title={`${d.date}: ${d.completed} completed, ${d.failed} failed, avg ${formatSeconds(d.avgSeconds)}, cost ${fmtCost(d.totalCostUsd)}`}>
                      {(height - failedHeight) > 0 && <div className="bg-emerald-400 rounded-t-sm hover:bg-emerald-500" style={{ height: `${height - failedHeight}%` }} />}
                      {failedHeight > 0 && <div className="bg-red-400 hover:bg-red-500" style={{ height: `${failedHeight}%` }} />}
                    </div>
                  );
                })}
              </div>
              <div className="flex justify-between text-[10px] text-muted-foreground">
                <span>{daily[daily.length - 1]?.date}</span>
                <span>{daily[0]?.date}</span>
              </div>
              <div className="flex gap-4 text-xs text-muted-foreground">
                <span className="flex items-center gap-1.5"><span className="inline-block w-3 h-3 rounded-sm bg-emerald-400" /> Completed</span>
                <span className="flex items-center gap-1.5"><span className="inline-block w-3 h-3 rounded-sm bg-red-400" /> Failed</span>
              </div>
            </div>
          )}
        </CardContent>
      </Card>

      {/* Daily table */}
      {daily.length > 0 && (
        <Card>
          <CardHeader className="pb-3"><CardTitle className="text-base">Daily Breakdown</CardTitle></CardHeader>
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
                    <TableCell className="text-center tabular-nums">{d.failed > 0 ? <span className="text-red-600 font-medium">{d.failed}</span> : <span className="text-muted-foreground">0</span>}</TableCell>
                    <TableCell className="text-right tabular-nums">{formatSeconds(d.avgSeconds)}</TableCell>
                    <TableCell className="text-right tabular-nums">{fmtCost(d.totalCostUsd)}</TableCell>
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
              <AlertTriangle className="h-4 w-4 text-red-500" />Top Errors (Last 30 Days)
            </CardTitle>
          </CardHeader>
          <CardContent className="p-0">
            <Table>
              <TableHeader>
                <TableRow className="bg-muted/30">
                  <TableHead className="font-semibold">Error</TableHead>
                  <TableHead className="text-center font-semibold w-20">Count</TableHead>
                  <TableHead className="text-right font-semibold w-40">Last Seen</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {topErrors.map((err: any, i: number) => (
                  <TableRow key={i}>
                    <TableCell className="font-mono text-xs text-red-700 max-w-md truncate">{err.message}</TableCell>
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
