import { useMemo, useState } from "react";
import { useQuery } from "@tanstack/react-query";
import { Link, useNavigate } from "react-router-dom";
import { useCompany } from "@/lib/company";
import { useAuth } from "@/lib/auth";
import api from "@/api/client";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Table, TableHeader, TableBody, TableRow, TableHead, TableCell } from "@/components/ui/table";
import { SortableTableHead } from "@/components/ui/sortable-table-head";
import { Skeleton } from "@/components/ui/skeleton";
import { formatDateTime, formatNumber, formatUsd, getStatusClasses } from "@/lib/ui-utils";
import {
  FileText,
  CheckCircle,
  Loader2,
  AlertTriangle,
  Building2,
  Search,
  BarChart3,
  ArrowRight,
} from "lucide-react";

function getCompanyId(company: any): string {
  return String(company?.companyId ?? company?.id ?? "");
}
function getCompanyName(company: any): string {
  return String(company?.companyName ?? company?.name ?? "Unknown company");
}
function getCompanyCode(company: any): string {
  return String(company?.companyCode ?? company?.code ?? "");
}
function getTokenUsage(company: any): number | string | null {
  return company?.tokenUsage ?? company?.totalTokens ?? company?.tokensUsed ?? null;
}
function getOcrCost(company: any): number | string | null {
  return company?.ocrCostUsd ?? company?.costUsd ?? null;
}
function getLastSent(company: any): string | null {
  return company?.lastOcrSentAt ?? company?.lastSentAt ?? company?.ocrSentAt ?? company?.ocrStartedAt ?? null;
}
function getLastReturned(company: any): string | null {
  return company?.lastOcrReturnedAt ?? company?.lastReturnedAt ?? company?.ocrReturnedAt ?? company?.returnedAt ?? null;
}

const statConfig = [
  { key: "totalInvoices", label: "Total Invoices", icon: FileText, borderColor: "border-l-blue-500", iconBg: "bg-blue-50", iconColor: "text-blue-600", statusFilter: "" },
  { key: "completedCount", label: "Completed", icon: CheckCircle, borderColor: "border-l-emerald-500", iconBg: "bg-emerald-50", iconColor: "text-emerald-600", statusFilter: "completed" },
  { key: "processingCount", label: "Processing", icon: Loader2, borderColor: "border-l-amber-500", iconBg: "bg-amber-50", iconColor: "text-amber-600", statusFilter: "processing" },
  { key: "failedCount", label: "Failed", icon: AlertTriangle, borderColor: "border-l-red-500", iconBg: "bg-red-50", iconColor: "text-red-600", statusFilter: "failed" },
] as const;

export default function Dashboard() {
  const { user } = useAuth();
  const { selectedCompany } = useCompany();
  const navigate = useNavigate();
  const [superadminMode, setSuperadminMode] = useState<"global" | "company">("global");
  const [period, setPeriod] = useState<"daily" | "weekly" | "monthly">("monthly");
  const [companySearch, setCompanySearch] = useState("");
  const [recentSort, setRecentSort] = useState("-createdAt");

  const isSuperadmin = user?.role === "superadmin";
  const effectiveCompanyId = isSuperadmin
    ? superadminMode === "company"
      ? selectedCompany?.id || ""
      : ""
    : selectedCompany?.id || "";
  const statsParams = new URLSearchParams();
  if (effectiveCompanyId) statsParams.set("companyId", effectiveCompanyId);
  statsParams.set("period", period);
  const statsUrl = `/invoices/stats${statsParams.toString() ? `?${statsParams}` : ""}`;

  const { data: stats, isLoading: statsLoading } = useQuery({
    queryKey: ["stats", effectiveCompanyId, superadminMode, period],
    queryFn: () => api.get(statsUrl).then((r) => r.data),
  });

  const { data: invoicesData } = useQuery({
    queryKey: ["recent-invoices", effectiveCompanyId],
    queryFn: () => api.get(`/invoices?companyId=${effectiveCompanyId}&limit=20`).then((r) => r.data),
    enabled: !!effectiveCompanyId,
  });

  const showCompanyOverview = isSuperadmin && superadminMode === "global";
  const companies: any[] = stats?.companies || [];
  const filteredCompanies = useMemo(() => {
    const term = companySearch.trim().toLowerCase();
    if (!term) return companies;
    return companies.filter((company) => {
      const searchable = [getCompanyName(company), getCompanyCode(company)].join(" ").toLowerCase();
      return searchable.includes(term);
    });
  }, [companies, companySearch]);
  const showRecentInvoices = !!effectiveCompanyId && (!isSuperadmin || superadminMode === "company");
  const activeRangeLabel = period === "daily" ? "Today" : period === "weekly" ? "Last 7 days" : "Last 30 days";

  const sortedRecentInvoices = useMemo(() => {
    const list = invoicesData?.invoices || [];
    if (!list.length) return list;
    const field = recentSort.replace(/^-/, "");
    const dir = recentSort.startsWith("-") ? -1 : 1;
    return [...list].sort((a: any, b: any) => {
      const va = a[field] ?? "";
      const vb = b[field] ?? "";
      if (typeof va === "number" && typeof vb === "number") return (va - vb) * dir;
      return String(va).localeCompare(String(vb)) * dir;
    });
  }, [invoicesData?.invoices, recentSort]);

  const greeting = (() => {
    const hour = new Date().getHours();
    if (hour < 12) return "Good morning";
    if (hour < 18) return "Good afternoon";
    return "Good evening";
  })();

  const formattedDate = new Date().toLocaleDateString("en-US", {
    weekday: "long",
    month: "long",
    day: "numeric",
    year: "numeric",
  });

  return (
    <div className="space-y-6">
      {/* Page header */}
      <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
        <div className="flex flex-wrap items-end gap-x-3 gap-y-1">
          <h1 className="text-2xl font-bold tracking-tight text-foreground">
            {greeting}, {user?.name?.split(" ")[0] || "there"}
          </h1>
          <p className="text-sm text-muted-foreground">{formattedDate}</p>
        </div>
        {isSuperadmin && (
          <div className="flex items-center rounded-lg border border-border bg-card p-0.5">
            <button
              onClick={() => setSuperadminMode("global")}
              className={`px-3 py-1.5 rounded-md text-sm font-medium transition-all duration-150 ${
                superadminMode === "global"
                  ? "bg-primary text-primary-foreground shadow-sm"
                  : "text-muted-foreground hover:text-foreground"
              }`}
            >
              Global Clients
            </button>
            <button
              onClick={() => setSuperadminMode("company")}
              className={`px-3 py-1.5 rounded-md text-sm font-medium transition-all duration-150 ${
                superadminMode === "company"
                  ? "bg-primary text-primary-foreground shadow-sm"
                  : "text-muted-foreground hover:text-foreground"
              }`}
            >
              Selected Company
            </button>
          </div>
        )}
      </div>

      <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div className="flex items-center rounded-lg border border-border bg-card p-0.5">
          {(["daily", "weekly", "monthly"] as const).map((option) => (
            <button
              key={option}
              onClick={() => setPeriod(option)}
              className={`px-3 py-1.5 rounded-md text-sm font-medium transition-all duration-150 ${
                period === option
                  ? "bg-primary text-primary-foreground shadow-sm"
                  : "text-muted-foreground hover:text-foreground"
              }`}
            >
              {option === "daily" ? "Daily" : option === "weekly" ? "Weekly" : "Monthly"}
            </button>
          ))}
        </div>
        <p className="text-xs text-muted-foreground">Showing metrics for {activeRangeLabel}.</p>
      </div>

      {/* Stat cards */}
      <div className="grid grid-cols-2 xl:grid-cols-4 gap-3">
        {statsLoading
          ? Array.from({ length: 4 }).map((_, i) => (
              <Card key={i}>
                <CardContent className="p-3.5">
                  <Skeleton className="h-3 w-20 mb-2" />
                  <Skeleton className="h-7 w-14" />
                </CardContent>
              </Card>
            ))
          : statConfig.map(({ key, label, icon: Icon, borderColor, iconBg, iconColor, statusFilter }) => {
              const params = new URLSearchParams();
              if (statusFilter) params.set("status", statusFilter);
              if (effectiveCompanyId) params.set("companyId", effectiveCompanyId);
              const href = `/invoices${params.toString() ? `?${params}` : ""}`;
              return (
                <Card
                  key={key}
                  className={`border-l-4 ${borderColor} hover:shadow-md transition-shadow duration-200 cursor-pointer`}
                  onClick={() => navigate(href)}
                >
                  <CardContent className="p-3.5">
                    <div className="flex items-start justify-between">
                      <div>
                        <p className="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">
                          {label}
                        </p>
                        <p className="text-2xl font-bold tabular-nums mt-0.5 leading-none text-foreground">
                          {(stats as any)?.[key] || 0}
                        </p>
                      </div>
                      <div className={`flex h-8 w-8 items-center justify-center rounded-lg ${iconBg}`}>
                        <Icon className={`h-4 w-4 ${iconColor}`} />
                      </div>
                    </div>
                  </CardContent>
                </Card>
              );
            })}
      </div>

      {/* Company overview */}
      {showCompanyOverview && (
        <Card className="overflow-hidden">
          <CardHeader className="pb-4">
            <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
              <CardTitle className="flex items-center gap-2 text-lg">
                <Building2 className="h-5 w-5 text-muted-foreground" />
                Client Overview
              </CardTitle>
              <div className="relative w-full sm:w-72">
                <Search className="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-muted-foreground" />
                <Input
                  value={companySearch}
                  onChange={(e) => setCompanySearch(e.target.value)}
                  placeholder="Search clients..."
                  className="pl-9"
                />
              </div>
            </div>
          </CardHeader>
          <CardContent className="p-0">
            <div className="overflow-x-auto">
              <Table>
                <TableHeader>
                  <TableRow className="bg-muted/30">
                    <TableHead className="font-semibold">Company</TableHead>
                    <TableHead className="text-center font-semibold">Scanned</TableHead>
                    <TableHead className="text-right font-semibold">Tokens</TableHead>
                    <TableHead className="text-right font-semibold">OCR Cost</TableHead>
                    <TableHead className="text-right font-semibold hidden md:table-cell">Last Sent</TableHead>
                    <TableHead className="text-right font-semibold hidden md:table-cell">Last Returned</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {filteredCompanies.map((c: any) => {
                    const companyId = getCompanyId(c);
                    return (
                      <TableRow
                        key={companyId || `${getCompanyName(c)}-${getCompanyCode(c)}`}
                        className={`transition-colors duration-150 ${companyId ? "cursor-pointer hover:bg-primary/[0.03]" : ""}`}
                        onClick={() => companyId && navigate(`/invoices?companyId=${companyId}`)}
                      >
                        <TableCell>
                          <div>
                            <div className="font-medium text-foreground">{getCompanyName(c)}</div>
                            {getCompanyCode(c) && (
                              <div className="text-xs text-muted-foreground">{getCompanyCode(c)}</div>
                            )}
                          </div>
                        </TableCell>
                        <TableCell className="text-center tabular-nums font-medium">{formatNumber(c.totalInvoices)}</TableCell>
                        <TableCell className="text-right tabular-nums">{formatNumber(getTokenUsage(c))}</TableCell>
                        <TableCell className="text-right tabular-nums font-medium">{formatUsd(getOcrCost(c))}</TableCell>
                        <TableCell className="text-right text-sm text-muted-foreground hidden md:table-cell">{formatDateTime(getLastSent(c))}</TableCell>
                        <TableCell className="text-right text-sm text-muted-foreground hidden md:table-cell">{formatDateTime(getLastReturned(c))}</TableCell>
                      </TableRow>
                    );
                  })}
                  {filteredCompanies.length === 0 && (
                    <TableRow>
                      <TableCell colSpan={6} className="py-12">
                        <div className="flex flex-col items-center justify-center text-center">
                          <div className="rounded-full bg-muted p-3 mb-3">
                            <Building2 className="h-6 w-6 text-muted-foreground" />
                          </div>
                          <p className="text-sm font-medium text-foreground">No clients found</p>
                          <p className="text-xs text-muted-foreground mt-0.5">Try a different search term</p>
                        </div>
                      </TableCell>
                    </TableRow>
                  )}
                </TableBody>
              </Table>
            </div>
          </CardContent>
        </Card>
      )}

      {/* No company selected */}
      {isSuperadmin && superadminMode === "company" && !selectedCompany && (
        <Card>
          <CardContent className="py-12">
            <div className="flex flex-col items-center justify-center text-center">
              <div className="rounded-full bg-muted p-3 mb-3">
                <Building2 className="h-6 w-6 text-muted-foreground" />
              </div>
              <p className="text-sm font-medium text-foreground">No company selected</p>
              <p className="text-xs text-muted-foreground mt-0.5">Select a company in the sidebar to view metrics</p>
            </div>
          </CardContent>
        </Card>
      )}

      {/* Recent invoices */}
      {showRecentInvoices && (
        <Card className="overflow-hidden">
          <CardHeader className="pb-4">
            <div className="flex items-center justify-between">
              <CardTitle className="text-lg">Recent Invoices</CardTitle>
              <Link to="/invoices">
                <Button variant="ghost" size="sm" className="text-muted-foreground hover:text-foreground gap-1">
                  View all
                  <ArrowRight className="h-3.5 w-3.5" />
                </Button>
              </Link>
            </div>
          </CardHeader>
          <CardContent className="p-0">
            <div className="overflow-x-auto">
              <Table>
                <TableHeader>
                  <TableRow className="bg-muted/30">
                    <SortableTableHead field="invoiceNumber" current={recentSort} onSort={setRecentSort}>Invoice #</SortableTableHead>
                    <SortableTableHead field="vendorName" current={recentSort} onSort={setRecentSort}>Vendor</SortableTableHead>
                    <SortableTableHead field="invoiceDate" current={recentSort} onSort={setRecentSort} className="hidden sm:table-cell">Date</SortableTableHead>
                    <SortableTableHead field="totalAmount" current={recentSort} onSort={setRecentSort} className="text-right">Amount</SortableTableHead>
                    <SortableTableHead field="createdAt" current={recentSort} onSort={setRecentSort} className="hidden md:table-cell">Scanned</SortableTableHead>
                    <SortableTableHead field="status" current={recentSort} onSort={setRecentSort}>Status</SortableTableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {sortedRecentInvoices.map((inv: any) => (
                    <TableRow key={inv.id} className="transition-colors duration-150 hover:bg-primary/[0.03] cursor-pointer" onClick={() => navigate(`/invoices/${inv.id}`)}>
                      <TableCell>
                        <span className="text-primary font-medium">
                          {inv.invoiceNumber || inv.originalFilename}
                        </span>
                      </TableCell>
                      <TableCell className="text-foreground">{inv.vendorName || "\u2014"}</TableCell>
                      <TableCell className="text-muted-foreground hidden sm:table-cell">{inv.invoiceDate || "\u2014"}</TableCell>
                      <TableCell className="text-right tabular-nums font-medium">
                        {inv.totalAmount ? `${inv.totalAmount} ${inv.currency || ""}` : "\u2014"}
                      </TableCell>
                      <TableCell className="text-muted-foreground hidden md:table-cell">{formatDateTime(inv.createdAt)}</TableCell>
                      <TableCell>
                        <span className={`inline-flex items-center rounded-full border px-2 py-0.5 text-xs font-medium ${getStatusClasses(inv.status)}`}>
                          {inv.status}
                        </span>
                      </TableCell>
                    </TableRow>
                  ))}
                  {sortedRecentInvoices.length === 0 && (
                    <TableRow>
                      <TableCell colSpan={6} className="py-12">
                        <div className="flex flex-col items-center justify-center text-center">
                          <div className="rounded-full bg-muted p-3 mb-3">
                            <BarChart3 className="h-6 w-6 text-muted-foreground" />
                          </div>
                          <p className="text-sm font-medium text-foreground">No invoices yet</p>
                          <p className="text-xs text-muted-foreground mt-0.5">Upload your first invoice to get started</p>
                          <Link to="/upload">
                            <Button size="sm" className="mt-3">Upload Invoice</Button>
                          </Link>
                        </div>
                      </TableCell>
                    </TableRow>
                  )}
                </TableBody>
              </Table>
            </div>
          </CardContent>
        </Card>
      )}
    </div>
  );
}
