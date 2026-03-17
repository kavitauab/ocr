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
import { Badge } from "@/components/ui/badge";
import { FileText, CheckCircle, Loader2, AlertTriangle, Building2 } from "lucide-react";

function formatDateTime(value: string | null | undefined): string {
  if (!value) return "—";
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return "—";
  return date.toLocaleString("lt-LT", { dateStyle: "short", timeStyle: "short" });
}

function formatNumber(value: number | string | null | undefined): string {
  if (value === null || value === undefined || value === "") return "—";
  const num = typeof value === "number" ? value : Number(value);
  if (Number.isNaN(num)) return "—";
  return new Intl.NumberFormat("en-US").format(num);
}

function formatUsd(value: number | string | null | undefined): string {
  if (value === null || value === undefined || value === "") return "—";
  const num = typeof value === "number" ? value : Number(value);
  if (Number.isNaN(num)) return "—";
  return new Intl.NumberFormat("en-US", { style: "currency", currency: "USD", maximumFractionDigits: 4 }).format(num);
}

function getCompanyId(company: any): string {
  return String(company?.companyId ?? company?.id ?? "");
}

function getCompanyName(company: any): string {
  return String(company?.companyName ?? company?.name ?? "Unknown company");
}

function getCompanyCode(company: any): string {
  return String(company?.companyCode ?? company?.code ?? "");
}

function getCompanyStatus(company: any): string {
  if (typeof company?.isActive === "boolean") return company.isActive ? "active" : "inactive";
  return String(company?.status ?? company?.companyStatus ?? "—");
}

function getCompanyPlan(company: any): string {
  return String(company?.plan ?? company?.subscriptionPlan ?? "—");
}

function getBillingStatus(company: any): string {
  return String(company?.billingStatus ?? company?.subscriptionStatus ?? "—");
}

function getBillingVariant(status: string): "default" | "secondary" | "destructive" | "outline" {
  const normalized = status.toLowerCase();
  if (["active", "paid", "current", "ok"].includes(normalized)) return "default";
  if (["past_due", "overdue", "failed", "unpaid"].includes(normalized)) return "destructive";
  if (normalized === "—") return "outline";
  return "secondary";
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

export default function Dashboard() {
  const { user } = useAuth();
  const { selectedCompany } = useCompany();
  const navigate = useNavigate();
  const [superadminMode, setSuperadminMode] = useState<"global" | "company">("global");
  const [companySearch, setCompanySearch] = useState("");
  const [companyStatusFilter, setCompanyStatusFilter] = useState("all");
  const [billingFilter, setBillingFilter] = useState("all");

  const isSuperadmin = user?.role === "superadmin";
  const effectiveCompanyId = isSuperadmin
    ? superadminMode === "company"
      ? selectedCompany?.id || ""
      : ""
    : selectedCompany?.id || "";
  const companyParam = effectiveCompanyId ? `?companyId=${effectiveCompanyId}` : "";

  const { data: stats } = useQuery({
    queryKey: ["stats", effectiveCompanyId, superadminMode],
    queryFn: () => api.get(`/invoices/stats${companyParam}`).then((r) => r.data),
  });

  const { data: invoicesData } = useQuery({
    queryKey: ["recent-invoices", effectiveCompanyId],
    queryFn: () => api.get(`/invoices?companyId=${effectiveCompanyId}&limit=5`).then((r) => r.data),
    enabled: !!effectiveCompanyId,
  });

  const statCards = [
    { label: "Total Invoices", value: stats?.totalInvoices || 0, icon: FileText, color: "text-blue-600" },
    { label: "Completed", value: stats?.completedCount || 0, icon: CheckCircle, color: "text-green-600" },
    { label: "Processing", value: stats?.processingCount || 0, icon: Loader2, color: "text-yellow-600" },
    { label: "Failed", value: stats?.failedCount || 0, icon: AlertTriangle, color: "text-red-600" },
  ];

  const showCompanyOverview = isSuperadmin && superadminMode === "global";
  const companies: any[] = stats?.companies || [];
  const statusOptions = useMemo(() => {
    const allStatuses = companies.map(getCompanyStatus).filter((value) => value && value !== "—");
    return [...new Set(allStatuses)].sort((a, b) => a.localeCompare(b));
  }, [companies]);
  const billingOptions = useMemo(() => {
    const allStatuses = companies.map(getBillingStatus).filter((value) => value && value !== "—");
    return [...new Set(allStatuses)].sort((a, b) => a.localeCompare(b));
  }, [companies]);
  const filteredCompanies = useMemo(() => {
    const term = companySearch.trim().toLowerCase();
    return companies.filter((company) => {
      const status = getCompanyStatus(company);
      const plan = getCompanyPlan(company);
      const billingStatus = getBillingStatus(company);
      const searchable = [getCompanyName(company), getCompanyCode(company), status, plan].join(" ").toLowerCase();
      const matchesSearch = !term || searchable.includes(term);
      const matchesStatus = companyStatusFilter === "all" || status.toLowerCase() === companyStatusFilter.toLowerCase();
      const matchesBilling = billingFilter === "all" || billingStatus.toLowerCase() === billingFilter.toLowerCase();
      return matchesSearch && matchesStatus && matchesBilling;
    });
  }, [companies, companySearch, companyStatusFilter, billingFilter]);
  const showRecentInvoices = !!effectiveCompanyId && (!isSuperadmin || superadminMode === "company");

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <h1 className="text-2xl font-bold">Dashboard</h1>
        {isSuperadmin && (
          <div className="flex items-center gap-2">
            <Button
              size="sm"
              variant={superadminMode === "global" ? "default" : "outline"}
              onClick={() => setSuperadminMode("global")}
            >
              Global Clients
            </Button>
            <Button
              size="sm"
              variant={superadminMode === "company" ? "default" : "outline"}
              onClick={() => setSuperadminMode("company")}
            >
              Selected Company
            </Button>
          </div>
        )}
      </div>

      {/* Stats cards */}
      <div className="grid grid-cols-2 xl:grid-cols-4 gap-4">
        {statCards.map(({ label, value, icon: Icon, color }) => (
          <Card key={label}>
            <CardContent className="p-4">
              <div className="flex items-center justify-between">
                <div>
                  <p className="text-sm text-gray-500">{label}</p>
                  <p className="text-2xl font-bold">{value}</p>
                </div>
                <Icon className={`h-8 w-8 ${color}`} />
              </div>
            </CardContent>
          </Card>
        ))}
      </div>

      {/* Superadmin: Customers overview table */}
      {showCompanyOverview && (
        <Card>
          <CardHeader>
            <CardTitle className="flex items-center gap-2">
              <Building2 className="h-5 w-5" />
              Global Client Overview
            </CardTitle>
          </CardHeader>
          <CardContent className="space-y-4">
            <div className="flex flex-wrap gap-2">
              <Input
                value={companySearch}
                onChange={(e) => setCompanySearch(e.target.value)}
                placeholder="Search by company, code, status, plan..."
                className="w-full sm:w-80"
              />
              <select
                value={companyStatusFilter}
                onChange={(e) => setCompanyStatusFilter(e.target.value)}
                className="h-10 rounded-md border px-3 text-sm"
              >
                <option value="all">All statuses</option>
                {statusOptions.map((status) => (
                  <option key={status} value={status}>{status}</option>
                ))}
              </select>
              <select
                value={billingFilter}
                onChange={(e) => setBillingFilter(e.target.value)}
                className="h-10 rounded-md border px-3 text-sm"
              >
                <option value="all">All billing states</option>
                {billingOptions.map((status) => (
                  <option key={status} value={status}>{status}</option>
                ))}
              </select>
            </div>
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>Company</TableHead>
                  <TableHead>Status</TableHead>
                  <TableHead>Plan</TableHead>
                  <TableHead>Billing</TableHead>
                  <TableHead className="text-center">Scanned</TableHead>
                  <TableHead className="text-right">Tokens</TableHead>
                  <TableHead className="text-right">OCR Cost</TableHead>
                  <TableHead className="text-right">Last Sent</TableHead>
                  <TableHead className="text-right">Last Returned</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {filteredCompanies.map((c: any) => {
                  const companyId = getCompanyId(c);
                  const status = getCompanyStatus(c);
                  const plan = getCompanyPlan(c);
                  const billingStatus = getBillingStatus(c);
                  return (
                  <TableRow
                    key={companyId || `${getCompanyName(c)}-${getCompanyCode(c)}`}
                    className={companyId ? "cursor-pointer hover:bg-gray-50" : ""}
                    onClick={() => companyId && navigate(`/invoices?companyId=${companyId}`)}
                  >
                    <TableCell>
                      <div>
                        <span className="font-medium">{getCompanyName(c)}</span>
                        {getCompanyCode(c) && <span className="text-xs text-gray-400 ml-2">{getCompanyCode(c)}</span>}
                      </div>
                    </TableCell>
                    <TableCell><Badge variant="outline">{status}</Badge></TableCell>
                    <TableCell>{plan}</TableCell>
                    <TableCell><Badge variant={getBillingVariant(billingStatus)}>{billingStatus}</Badge></TableCell>
                    <TableCell className="text-center font-mono">{formatNumber(c.totalInvoices)}</TableCell>
                    <TableCell className="text-right font-mono">{formatNumber(getTokenUsage(c))}</TableCell>
                    <TableCell className="text-right font-mono">{formatUsd(getOcrCost(c))}</TableCell>
                    <TableCell className="text-right text-sm text-gray-500">{formatDateTime(getLastSent(c))}</TableCell>
                    <TableCell className="text-right text-sm text-gray-500">{formatDateTime(getLastReturned(c))}</TableCell>
                  </TableRow>
                )})}
                {filteredCompanies.length === 0 && (
                  <TableRow>
                    <TableCell colSpan={9} className="text-center text-gray-500 py-8">
                      No companies match current filters
                    </TableCell>
                  </TableRow>
                )}
              </TableBody>
            </Table>
          </CardContent>
        </Card>
      )}

      {isSuperadmin && superadminMode === "company" && !selectedCompany && (
        <Card>
          <CardContent className="py-6 text-sm text-gray-600">
            Select a company in the sidebar to view company-specific metrics and recent invoices.
          </CardContent>
        </Card>
      )}

      {/* Company-specific view: Recent Invoices */}
      {showRecentInvoices && (
        <Card>
          <CardHeader><CardTitle>Recent Invoices</CardTitle></CardHeader>
          <CardContent className="p-0">
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>Invoice #</TableHead>
                  <TableHead>Vendor</TableHead>
                  <TableHead>Date</TableHead>
                  <TableHead>Amount</TableHead>
                  <TableHead>Status</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {invoicesData?.invoices?.map((inv: any) => (
                  <TableRow key={inv.id}>
                    <TableCell>
                      <Link to={`/invoices/${inv.id}`} className="text-blue-600 hover:underline font-medium">
                        {inv.invoiceNumber || inv.originalFilename}
                      </Link>
                    </TableCell>
                    <TableCell>{inv.vendorName || "—"}</TableCell>
                    <TableCell>{inv.invoiceDate || "—"}</TableCell>
                    <TableCell>{inv.totalAmount ? `${inv.totalAmount} ${inv.currency || ""}` : "—"}</TableCell>
                    <TableCell>
                      <Badge variant={inv.status === "completed" ? "default" : inv.status === "failed" ? "destructive" : "secondary"}>
                        {inv.status}
                      </Badge>
                    </TableCell>
                  </TableRow>
                ))}
                {(!invoicesData?.invoices || invoicesData.invoices.length === 0) && (
                  <TableRow>
                    <TableCell colSpan={5} className="text-center text-gray-500 py-8">No invoices yet</TableCell>
                  </TableRow>
                )}
              </TableBody>
            </Table>
          </CardContent>
        </Card>
      )}
    </div>
  );
}
