import { useMemo, useState } from "react";
import { Navigate, useNavigate } from "react-router-dom";
import { useQuery } from "@tanstack/react-query";
import api from "@/api/client";
import { useAuth } from "@/lib/auth";
import { Input } from "@/components/ui/input";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Table, TableHeader, TableBody, TableRow, TableHead, TableCell } from "@/components/ui/table";
import { Skeleton } from "@/components/ui/skeleton";
import { getStatusClasses } from "@/lib/ui-utils";
import { BarChart3, FileText, Cpu, DollarSign, HardDrive } from "lucide-react";

function fmtNum(v: any): string {
  if (v === null || v === undefined || v === "") return "—";
  const n = Number(v);
  if (isNaN(n)) return "—";
  return new Intl.NumberFormat("en-US").format(n);
}

function fmtUsd(v: any): string {
  if (v === null || v === undefined || v === "" || v === 0) return "$0.00";
  const n = Number(v);
  if (isNaN(n)) return "—";
  return new Intl.NumberFormat("en-US", { style: "currency", currency: "USD", minimumFractionDigits: 2, maximumFractionDigits: 4 }).format(n);
}

function fmtBytes(v: any): string {
  if (!v || v === 0) return "0 B";
  const n = Math.abs(Number(v));
  const units = ["B", "KB", "MB", "GB"];
  let i = 0;
  let val = n;
  while (val >= 1024 && i < units.length - 1) { val /= 1024; i++; }
  return `${val.toFixed(i === 0 ? 0 : 1)} ${units[i]}`;
}

function fmtTokens(v: any): string {
  const n = Number(v || 0);
  if (n >= 1_000_000) return `${(n / 1_000_000).toFixed(1)}M`;
  if (n >= 1_000) return `${(n / 1_000).toFixed(1)}K`;
  return fmtNum(n);
}

export default function Billing() {
  const { user } = useAuth();
  const navigate = useNavigate();
  const [search, setSearch] = useState("");

  if (user?.role !== "superadmin") return <Navigate to="/dashboard" replace />;

  const { data, isLoading } = useQuery({
    queryKey: ["usage-stats"],
    queryFn: () => api.get("/invoices/stats").then((r) => r.data),
  });

  const companies = data?.companies || [];

  const filtered = useMemo(() => {
    const term = search.trim().toLowerCase();
    if (!term) return companies;
    return companies.filter((c: any) =>
      `${c.companyName} ${c.companyCode}`.toLowerCase().includes(term)
    );
  }, [companies, search]);

  // Totals
  const totals = useMemo(() => {
    return companies.reduce((acc: any, c: any) => ({
      invoices: acc.invoices + (c.totalInvoices || 0),
      tokens: acc.tokens + (c.totalTokens || 0),
      cost: acc.cost + (c.ocrCostUsd || 0),
      storage: acc.storage + (c.storageUsedBytes || 0),
    }), { invoices: 0, tokens: 0, cost: 0, storage: 0 });
  }, [companies]);

  const statCards = [
    { label: "Total Invoices", value: fmtNum(totals.invoices), icon: FileText, color: "text-blue-600", bg: "bg-blue-50" },
    { label: "Total Tokens", value: fmtTokens(totals.tokens), icon: Cpu, color: "text-purple-600", bg: "bg-purple-50" },
    { label: "Total OCR Cost", value: fmtUsd(totals.cost), icon: DollarSign, color: "text-emerald-600", bg: "bg-emerald-50" },
    { label: "Total Storage", value: fmtBytes(totals.storage), icon: HardDrive, color: "text-amber-600", bg: "bg-amber-50" },
  ];

  return (
    <div className="space-y-4">
      <div>
        <h2 className="text-2xl font-bold tracking-tight text-foreground">Usage & Billing</h2>
        <p className="text-sm text-muted-foreground mt-0.5">Token consumption, invoice counts, and OCR costs per company</p>
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

      {/* Company usage table */}
      <Card className="overflow-hidden">
        <CardContent className="p-4 border-b border-border">
          <Input
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            placeholder="Search companies..."
            className="w-full sm:w-80"
          />
        </CardContent>
        <CardContent className="p-0">
          <div className="overflow-x-auto">
            <Table>
              <TableHeader>
                <TableRow className="bg-muted/30">
                  <TableHead className="font-semibold">Company</TableHead>
                  <TableHead className="font-semibold">Plan</TableHead>
                  <TableHead className="text-right font-semibold">Invoices</TableHead>
                  <TableHead className="text-right font-semibold">Completed</TableHead>
                  <TableHead className="text-right font-semibold">Failed</TableHead>
                  <TableHead className="text-right font-semibold">Tokens</TableHead>
                  <TableHead className="text-right font-semibold">OCR Cost</TableHead>
                  <TableHead className="text-right font-semibold">Storage</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {isLoading && (
                  Array.from({ length: 3 }).map((_, i) => (
                    <TableRow key={i}>
                      {Array.from({ length: 8 }).map((_, j) => (
                        <TableCell key={j}><Skeleton className="h-4 w-16" /></TableCell>
                      ))}
                    </TableRow>
                  ))
                )}
                {!isLoading && filtered.map((c: any) => (
                  <TableRow
                    key={c.companyId}
                    className="cursor-pointer hover:bg-primary/[0.03] transition-colors"
                    onClick={() => navigate(`/invoices?companyId=${c.companyId}`)}
                  >
                    <TableCell>
                      <div className="font-medium text-foreground">{c.companyName}</div>
                      <div className="text-xs text-muted-foreground">{c.companyCode}</div>
                    </TableCell>
                    <TableCell>
                      <span className={`inline-flex items-center rounded-full border px-2 py-0.5 text-xs font-medium ${getStatusClasses(c.billingStatus)}`}>
                        {c.plan || "free"}
                      </span>
                    </TableCell>
                    <TableCell className="text-right tabular-nums font-medium">{fmtNum(c.totalInvoices)}</TableCell>
                    <TableCell className="text-right tabular-nums text-emerald-600">{fmtNum(c.completedCount)}</TableCell>
                    <TableCell className="text-right tabular-nums text-red-600">{c.failedCount > 0 ? fmtNum(c.failedCount) : "—"}</TableCell>
                    <TableCell className="text-right tabular-nums text-muted-foreground">{fmtTokens(c.totalTokens)}</TableCell>
                    <TableCell className="text-right tabular-nums text-muted-foreground">{fmtUsd(c.ocrCostUsd)}</TableCell>
                    <TableCell className="text-right tabular-nums text-muted-foreground">{fmtBytes(c.storageUsedBytes)}</TableCell>
                  </TableRow>
                ))}
                {!isLoading && filtered.length === 0 && (
                  <TableRow>
                    <TableCell colSpan={8} className="py-12">
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
