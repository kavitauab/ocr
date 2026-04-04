import { useMemo, useState } from "react";
import { Navigate } from "react-router-dom";
import { useQuery } from "@tanstack/react-query";
import api from "@/api/client";
import { useAuth } from "@/lib/auth";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Table, TableHeader, TableBody, TableRow, TableHead, TableCell } from "@/components/ui/table";
import { Skeleton } from "@/components/ui/skeleton";
import { Button } from "@/components/ui/button";
import { BarChart3, FileText, Cpu, DollarSign, HardDrive, Download } from "lucide-react";

function fmtNum(v: any): string {
  if (v === null || v === undefined || v === "" || v === 0) return "—";
  return new Intl.NumberFormat("en-US").format(Number(v));
}

function fmtUsd(v: any): string {
  if (v === null || v === undefined || v === "" || v === 0) return "$0.00";
  return new Intl.NumberFormat("en-US", { style: "currency", currency: "USD", minimumFractionDigits: 2, maximumFractionDigits: 4 }).format(Number(v));
}

function fmtBytes(v: any): string {
  if (!v || v === 0) return "0 B";
  const n = Math.abs(Number(v));
  const units = ["B", "KB", "MB", "GB"];
  let i = 0, val = n;
  while (val >= 1024 && i < units.length - 1) { val /= 1024; i++; }
  return `${val.toFixed(i === 0 ? 0 : 1)} ${units[i]}`;
}

function fmtTokens(v: any): string {
  const n = Number(v || 0);
  if (n >= 1_000_000) return `${(n / 1_000_000).toFixed(1)}M`;
  if (n >= 1_000) return `${(n / 1_000).toFixed(1)}K`;
  return fmtNum(n);
}

function fmtMonth(m: string): string {
  const [y, mo] = m.split("-");
  const months = ["", "January", "February", "March", "April", "May", "June", "July", "August", "September", "October", "November", "December"];
  return `${months[parseInt(mo)] || mo} ${y}`;
}

export default function Billing() {
  const { user } = useAuth();
  const [selectedMonth, setSelectedMonth] = useState("");
  const [selectedCompany, setSelectedCompany] = useState("");

  if (user?.role !== "superadmin") return <Navigate to="/dashboard" replace />;

  const { data, isLoading } = useQuery({
    queryKey: ["usage", selectedMonth, selectedCompany],
    queryFn: () => {
      const params: Record<string, string> = {};
      if (selectedMonth) params.month = selectedMonth;
      if (selectedCompany) params.companyId = selectedCompany;
      return api.get("/invoices/usage", { params }).then((r) => r.data);
    },
  });

  const usage = data?.usage || [];
  const months = data?.months || [];
  const companies = data?.companies || [];

  const totals = useMemo(() => {
    return usage.reduce((acc: any, u: any) => ({
      invoices: acc.invoices + (u.invoicesProcessed || 0),
      tokens: acc.tokens + (u.ocrTotalTokens || 0),
      cost: acc.cost + parseFloat(u.ocrCostUsd || 0),
      storage: acc.storage + (u.storageUsedBytes || 0),
      apiCalls: acc.apiCalls + (u.apiCallsCount || 0),
      ocrJobs: acc.ocrJobs + (u.ocrJobsCount || 0),
    }), { invoices: 0, tokens: 0, cost: 0, storage: 0, apiCalls: 0, ocrJobs: 0 });
  }, [usage]);

  const statCards = [
    { label: "Invoices Processed", value: fmtNum(totals.invoices), icon: FileText, color: "text-blue-600", bg: "bg-blue-50" },
    { label: "OCR Jobs", value: fmtNum(totals.ocrJobs), icon: Cpu, color: "text-purple-600", bg: "bg-purple-50" },
    { label: "Total Cost", value: fmtUsd(totals.cost), icon: DollarSign, color: "text-emerald-600", bg: "bg-emerald-50" },
    { label: "Storage Used", value: fmtBytes(totals.storage), icon: HardDrive, color: "text-amber-600", bg: "bg-amber-50" },
  ];

  const handleExport = () => {
    const rows = usage.map((u: any) => [
      u.month, u.companyName, u.companyCode,
      u.invoicesProcessed, u.ocrJobsCount, u.apiCallsCount,
      u.ocrInputTokens, u.ocrOutputTokens, u.ocrTotalTokens,
      u.ocrCostUsd, u.storageUsedBytes,
    ]);
    const header = ["Month", "Company", "Code", "Invoices", "OCR Jobs", "API Calls", "Input Tokens", "Output Tokens", "Total Tokens", "OCR Cost (USD)", "Storage (bytes)"];
    const csv = [header, ...rows].map(r => r.join(",")).join("\n");
    const blob = new Blob([csv], { type: "text/csv" });
    const url = URL.createObjectURL(blob);
    const a = document.createElement("a");
    a.href = url;
    a.download = `usage${selectedMonth ? `-${selectedMonth}` : ""}${selectedCompany ? `-${selectedCompany}` : ""}.csv`;
    a.click();
  };

  return (
    <div className="space-y-4">
      <div className="flex items-center justify-between">
        <div>
          <h2 className="text-2xl font-bold tracking-tight text-foreground">Usage & Billing</h2>
          <p className="text-sm text-muted-foreground mt-0.5">Monthly usage breakdown per company for invoicing</p>
        </div>
        <Button variant="outline" size="sm" onClick={handleExport} disabled={!usage.length} className="gap-1">
          <Download className="h-3.5 w-3.5" />Export CSV
        </Button>
      </div>

      {/* Filters */}
      <div className="flex flex-wrap gap-3">
        <select
          value={selectedMonth}
          onChange={(e) => setSelectedMonth(e.target.value)}
          className="h-9 rounded-md border border-border bg-card px-3 text-sm text-foreground"
        >
          <option value="">All months</option>
          {months.map((m: string) => (
            <option key={m} value={m}>{fmtMonth(m)}</option>
          ))}
        </select>
        <select
          value={selectedCompany}
          onChange={(e) => setSelectedCompany(e.target.value)}
          className="h-9 rounded-md border border-border bg-card px-3 text-sm text-foreground"
        >
          <option value="">All companies</option>
          {companies.map((c: any) => (
            <option key={c.id} value={c.id}>{c.name} ({c.code})</option>
          ))}
        </select>
        {(selectedMonth || selectedCompany) && (
          <Button variant="ghost" size="sm" onClick={() => { setSelectedMonth(""); setSelectedCompany(""); }} className="text-muted-foreground">
            Clear filters
          </Button>
        )}
      </div>

      {/* Summary cards */}
      <div className="grid grid-cols-2 xl:grid-cols-4 gap-3">
        {isLoading ? (
          Array.from({ length: 4 }).map((_, i) => (
            <Card key={i}><CardContent className="p-3.5"><Skeleton className="h-3 w-20 mb-2" /><Skeleton className="h-7 w-14" /></CardContent></Card>
          ))
        ) : (
          statCards.map(({ label, value, icon: Icon, color, bg }) => (
            <Card key={label}>
              <CardContent className="p-3.5">
                <div className="flex items-start justify-between">
                  <div>
                    <p className="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">{label}</p>
                    <p className="text-2xl font-bold tabular-nums mt-0.5 leading-none text-foreground">{value}</p>
                  </div>
                  <div className={`flex h-8 w-8 items-center justify-center rounded-lg ${bg}`}>
                    <Icon className={`h-4 w-4 ${color}`} />
                  </div>
                </div>
              </CardContent>
            </Card>
          ))
        )}
      </div>

      {/* Usage table */}
      <Card className="overflow-hidden">
        <CardContent className="p-0">
          <div className="overflow-x-auto">
            <Table>
              <TableHeader>
                <TableRow className="bg-muted/30">
                  <TableHead className="font-semibold">Month</TableHead>
                  <TableHead className="font-semibold">Company</TableHead>
                  <TableHead className="text-right font-semibold">Invoices</TableHead>
                  <TableHead className="text-right font-semibold">OCR Jobs</TableHead>
                  <TableHead className="text-right font-semibold">Input Tokens</TableHead>
                  <TableHead className="text-right font-semibold">Output Tokens</TableHead>
                  <TableHead className="text-right font-semibold">Total Tokens</TableHead>
                  <TableHead className="text-right font-semibold">Cost (USD)</TableHead>
                  <TableHead className="text-right font-semibold">Storage</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {isLoading && (
                  Array.from({ length: 3 }).map((_, i) => (
                    <TableRow key={i}>
                      {Array.from({ length: 9 }).map((_, j) => (
                        <TableCell key={j}><Skeleton className="h-4 w-16" /></TableCell>
                      ))}
                    </TableRow>
                  ))
                )}
                {!isLoading && usage.map((u: any, idx: number) => (
                  <TableRow key={`${u.companyId}-${u.month}-${idx}`} className="hover:bg-primary/[0.03] transition-colors">
                    <TableCell className="font-medium">{fmtMonth(u.month)}</TableCell>
                    <TableCell>
                      <div className="font-medium text-foreground">{u.companyName}</div>
                      <div className="text-xs text-muted-foreground">{u.companyCode}</div>
                    </TableCell>
                    <TableCell className="text-right tabular-nums">{fmtNum(u.invoicesProcessed)}</TableCell>
                    <TableCell className="text-right tabular-nums">{fmtNum(u.ocrJobsCount)}</TableCell>
                    <TableCell className="text-right tabular-nums text-muted-foreground">{fmtTokens(u.ocrInputTokens)}</TableCell>
                    <TableCell className="text-right tabular-nums text-muted-foreground">{fmtTokens(u.ocrOutputTokens)}</TableCell>
                    <TableCell className="text-right tabular-nums">{fmtTokens(u.ocrTotalTokens)}</TableCell>
                    <TableCell className="text-right tabular-nums font-medium">{fmtUsd(u.ocrCostUsd)}</TableCell>
                    <TableCell className="text-right tabular-nums text-muted-foreground">{fmtBytes(u.storageUsedBytes)}</TableCell>
                  </TableRow>
                ))}
                {!isLoading && usage.length > 0 && (
                  <TableRow className="bg-muted/20 font-semibold border-t-2">
                    <TableCell colSpan={2}>Total</TableCell>
                    <TableCell className="text-right tabular-nums">{fmtNum(totals.invoices)}</TableCell>
                    <TableCell className="text-right tabular-nums">{fmtNum(totals.ocrJobs)}</TableCell>
                    <TableCell></TableCell>
                    <TableCell></TableCell>
                    <TableCell className="text-right tabular-nums">{fmtTokens(totals.tokens)}</TableCell>
                    <TableCell className="text-right tabular-nums">{fmtUsd(totals.cost)}</TableCell>
                    <TableCell className="text-right tabular-nums">{fmtBytes(totals.storage)}</TableCell>
                  </TableRow>
                )}
                {!isLoading && usage.length === 0 && (
                  <TableRow>
                    <TableCell colSpan={9} className="py-12">
                      <div className="flex flex-col items-center justify-center text-center">
                        <div className="rounded-full bg-muted p-3 mb-3">
                          <BarChart3 className="h-5 w-5 text-muted-foreground" />
                        </div>
                        <p className="text-sm font-medium text-foreground">No usage data</p>
                        <p className="text-sm text-muted-foreground mt-0.5">Usage will appear once invoices are processed</p>
                      </div>
                    </TableCell>
                  </TableRow>
                )}
              </TableBody>
            </Table>
          </div>
        </CardContent>
      </Card>
    </div>
  );
}
