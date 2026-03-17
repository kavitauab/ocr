import { useMemo, useState, type FormEvent } from "react";
import { useQuery } from "@tanstack/react-query";
import { Link, useSearchParams } from "react-router-dom";
import { useCompany } from "@/lib/company";
import { useAuth } from "@/lib/auth";
import api from "@/api/client";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Table, TableHeader, TableBody, TableRow, TableHead, TableCell } from "@/components/ui/table";
import { Card, CardContent } from "@/components/ui/card";
import { Skeleton } from "@/components/ui/skeleton";
import { DropdownMenu, DropdownItem, DropdownSeparator } from "@/components/ui/dropdown-menu";
import { formatDateTime, getStatusClasses } from "@/lib/ui-utils";
import {
  Download,
  X,
  Search,
  ChevronDown,
  ChevronLeft,
  ChevronRight,
  Filter,
  FileText,
  Upload,
} from "lucide-react";

function getSentToOcrAt(invoice: any): string | null {
  return invoice?.ocrSentAt ?? invoice?.ocrStartedAt ?? invoice?.sentToOcrAt ?? invoice?.sentAt ?? invoice?.lastSentAt ?? null;
}

function getReturnedAt(invoice: any): string | null {
  return invoice?.ocrReturnedAt ?? invoice?.returnedAt ?? invoice?.lastReturnedAt ?? invoice?.completedAt ?? null;
}

export default function Invoices() {
  const { user } = useAuth();
  const isSuperadmin = user?.role === "superadmin";
  const { selectedCompany, companies } = useCompany();
  const [searchParams, setSearchParams] = useSearchParams();
  const page = parseInt(searchParams.get("page") || "1");
  const status = searchParams.get("status") || "";
  const lifecycle = searchParams.get("lifecycle") || "all";
  const sentFrom = searchParams.get("sentFrom") || "";
  const sentTo = searchParams.get("sentTo") || "";
  const returnedFrom = searchParams.get("returnedFrom") || "";
  const returnedTo = searchParams.get("returnedTo") || "";
  const urlCompanyId = searchParams.get("companyId") || "";
  const [search, setSearch] = useState(searchParams.get("search") || "");
  const [showFilters, setShowFilters] = useState(false);

  const effectiveCompanyId = urlCompanyId || selectedCompany?.id || "";
  const filterCompany = urlCompanyId ? companies.find((c) => c.id === urlCompanyId) : null;

  const { data, isLoading } = useQuery({
    queryKey: ["invoices", effectiveCompanyId, page, status, search, lifecycle, sentFrom, sentTo, returnedFrom, returnedTo],
    queryFn: () => {
      const params: Record<string, string> = { page: String(page), limit: "20" };
      if (effectiveCompanyId) params.companyId = effectiveCompanyId;
      if (status) params.status = status;
      if (search) params.search = search;
      if (lifecycle && lifecycle !== "all") params.lifecycle = lifecycle;
      if (sentFrom) params.sentFrom = sentFrom;
      if (sentTo) params.sentTo = sentTo;
      if (returnedFrom) params.returnedFrom = returnedFrom;
      if (returnedTo) params.returnedTo = returnedTo;
      return api.get("/invoices", { params }).then((r) => r.data);
    },
  });

  const [groupByCompany, setGroupByCompany] = useState(false);
  const invoices = Array.isArray(data?.invoices) ? data.invoices : [];

  const groupedInvoices = useMemo(() => {
    if (!groupByCompany || !isSuperadmin) return null;
    const groups: Record<string, { name: string; code: string; companyId: string; invoices: any[] }> = {};
    for (const inv of invoices) {
      const key = inv.companyId || "unknown";
      if (!groups[key]) {
        groups[key] = { name: inv.companyName || "Unknown", code: inv.companyCode || "", companyId: key, invoices: [] };
      }
      groups[key].invoices.push(inv);
    }
    return Object.values(groups);
  }, [invoices, groupByCompany, isSuperadmin]);

  const handleSearch = (e: FormEvent) => {
    e.preventDefault();
    setSearchParams((prev) => {
      if (search.trim()) prev.set("search", search.trim());
      else prev.delete("search");
      prev.set("page", "1");
      return prev;
    });
  };

  const clearCompanyFilter = () => {
    setSearchParams((prev) => { prev.delete("companyId"); prev.set("page", "1"); return prev; });
  };

  const setParam = (key: string, value: string) => {
    setSearchParams((prev) => {
      if (value) prev.set(key, value); else prev.delete(key);
      prev.set("page", "1");
      return prev;
    });
  };

  const clearLifecycleFilters = () => {
    setSearchParams((prev) => {
      prev.delete("lifecycle"); prev.delete("sentFrom"); prev.delete("sentTo");
      prev.delete("returnedFrom"); prev.delete("returnedTo"); prev.set("page", "1");
      return prev;
    });
  };

  const hasLifecycleFilters = lifecycle !== "all" || !!sentFrom || !!sentTo || !!returnedFrom || !!returnedTo;
  const hasAnyFilter = !!status || hasLifecycleFilters || !!searchParams.get("search");

  const handleExport = (format: "csv" | "json") => {
    const token = localStorage.getItem("token");
    const exportParams = new URLSearchParams(searchParams);
    if (!exportParams.get("companyId") && effectiveCompanyId) exportParams.set("companyId", effectiveCompanyId);
    if (token) exportParams.set("access_token", token);
    if (format === "json") exportParams.set("format", "json");
    const query = exportParams.toString();
    window.location.href = query ? `/api/invoices/export?${query}` : "/api/invoices/export";
  };

  const colCount = isSuperadmin && !groupByCompany ? 9 : 8;

  const renderInvoiceRow = (inv: any, showCompany: boolean) => (
    <TableRow key={inv.id} className="transition-colors duration-150 hover:bg-primary/[0.03] group">
      {showCompany && (
        <TableCell>
          <div className="flex items-center gap-2">
            <div className="flex h-6 w-6 shrink-0 items-center justify-center rounded bg-muted text-[10px] font-semibold text-muted-foreground">
              {(inv.companyName || "?")[0].toUpperCase()}
            </div>
            <span className="text-sm text-foreground">{inv.companyName || "\u2014"}</span>
          </div>
        </TableCell>
      )}
      <TableCell>
        <Link to={`/invoices/${inv.id}`} className="text-primary hover:text-primary-dark font-medium hover:underline">
          {inv.invoiceNumber || inv.originalFilename}
        </Link>
      </TableCell>
      <TableCell className="text-foreground">{inv.vendorName || "\u2014"}</TableCell>
      <TableCell className="text-muted-foreground hidden md:table-cell">{inv.invoiceDate || "\u2014"}</TableCell>
      <TableCell className="text-right tabular-nums font-medium hidden sm:table-cell">
        {inv.totalAmount ? `${inv.totalAmount} ${inv.currency || ""}` : "\u2014"}
      </TableCell>
      <TableCell>
        <span className={`inline-flex items-center rounded-full border px-2 py-0.5 text-xs font-medium ${getStatusClasses(inv.status)}`}>
          {inv.status}
        </span>
      </TableCell>
      <TableCell className="hidden lg:table-cell">
        <span className="inline-flex items-center rounded-full border border-border px-2 py-0.5 text-xs text-muted-foreground">
          {inv.source}
        </span>
      </TableCell>
      <TableCell className="text-sm text-muted-foreground hidden xl:table-cell">{formatDateTime(getSentToOcrAt(inv))}</TableCell>
      <TableCell className="text-sm text-muted-foreground hidden xl:table-cell">{formatDateTime(getReturnedAt(inv))}</TableCell>
    </TableRow>
  );

  return (
    <div className="space-y-4">
      {/* Header */}
      <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
        <div>
          <h1 className="text-2xl font-bold tracking-tight text-foreground">Invoices</h1>
          {data && (
            <p className="text-sm text-muted-foreground mt-0.5">
              {data.totalCount || invoices.length} total invoice{(data.totalCount || invoices.length) !== 1 ? "s" : ""}
            </p>
          )}
        </div>
        <div className="flex items-center gap-2">
          <DropdownMenu
            trigger={
              <Button variant="outline" size="sm" className="gap-1">
                <Download className="h-3.5 w-3.5" />
                Export
                <ChevronDown className="h-3 w-3" />
              </Button>
            }
          >
            <DropdownItem onClick={() => handleExport("csv")}>
              <FileText className="h-3.5 w-3.5" />
              Export as CSV
            </DropdownItem>
            <DropdownItem onClick={() => handleExport("json")}>
              <FileText className="h-3.5 w-3.5" />
              Export as JSON
            </DropdownItem>
          </DropdownMenu>
          <Link to="/upload">
            <Button size="sm" className="gap-1">
              <Upload className="h-3.5 w-3.5" />
              Upload
            </Button>
          </Link>
        </div>
      </div>

      {/* Company filter banner */}
      {filterCompany && (
        <div className="flex items-center gap-2 bg-info-light/50 border border-blue-200 rounded-lg px-4 py-2.5">
          <span className="text-sm text-blue-700">
            Filtered by <strong>{filterCompany.name}</strong>
          </span>
          <button onClick={clearCompanyFilter} className="ml-auto flex items-center gap-1 text-xs text-blue-600 hover:text-blue-800 transition-colors">
            <X className="h-3 w-3" />Clear
          </button>
        </div>
      )}

      {/* Search & filters */}
      <div className="flex flex-col sm:flex-row gap-2">
        <form onSubmit={handleSearch} className="flex gap-2 flex-1">
          <div className="relative flex-1 max-w-md">
            <Search className="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-muted-foreground" />
            <Input
              placeholder="Search invoices..."
              value={search}
              onChange={(e) => setSearch(e.target.value)}
              className="pl-9"
            />
          </div>
          <Button type="submit" variant="outline" size="sm">Search</Button>
        </form>
        <div className="flex items-center gap-2">
          <select
            value={status}
            onChange={(e) => setParam("status", e.target.value)}
            className="h-9 rounded-lg border border-border bg-card px-3 text-sm text-foreground focus:ring-2 focus:ring-ring/20 transition-colors"
          >
            <option value="">All statuses</option>
            <option value="completed">Completed</option>
            <option value="processing">Processing</option>
            <option value="failed">Failed</option>
          </select>
          <Button
            variant={showFilters || hasLifecycleFilters ? "default" : "outline"}
            size="sm"
            onClick={() => setShowFilters(!showFilters)}
            className="gap-1"
          >
            <Filter className="h-3.5 w-3.5" />
            Filters
            {hasLifecycleFilters && (
              <span className="flex h-4 w-4 items-center justify-center rounded-full bg-primary-foreground text-primary text-[10px] font-bold">!</span>
            )}
          </Button>
        </div>
      </div>

      {/* Expandable filters */}
      <div className={`overflow-hidden transition-all duration-200 ${showFilters ? "max-h-40 opacity-100" : "max-h-0 opacity-0"}`}>
        <Card>
          <CardContent className="p-4">
            <div className="flex flex-wrap items-center gap-3">
              <select
                value={lifecycle}
                onChange={(e) => setParam("lifecycle", e.target.value)}
                className="h-9 rounded-lg border border-border bg-card px-3 text-sm text-foreground"
              >
                <option value="all">All lifecycle states</option>
                <option value="sent">Sent to OCR</option>
                <option value="not-sent">Not sent to OCR</option>
                <option value="returned">Returned from OCR</option>
                <option value="pending-return">Sent, awaiting return</option>
              </select>
              <div className="flex items-center gap-1.5 text-sm">
                <span className="text-muted-foreground text-xs">Sent</span>
                <Input type="date" value={sentFrom} onChange={(e) => setParam("sentFrom", e.target.value)} className="h-9 w-36" />
                <span className="text-muted-foreground text-xs">to</span>
                <Input type="date" value={sentTo} onChange={(e) => setParam("sentTo", e.target.value)} className="h-9 w-36" />
              </div>
              <div className="flex items-center gap-1.5 text-sm">
                <span className="text-muted-foreground text-xs">Returned</span>
                <Input type="date" value={returnedFrom} onChange={(e) => setParam("returnedFrom", e.target.value)} className="h-9 w-36" />
                <span className="text-muted-foreground text-xs">to</span>
                <Input type="date" value={returnedTo} onChange={(e) => setParam("returnedTo", e.target.value)} className="h-9 w-36" />
              </div>
              {hasLifecycleFilters && (
                <Button variant="ghost" size="sm" onClick={clearLifecycleFilters} className="text-muted-foreground gap-1">
                  <X className="h-3 w-3" />Clear
                </Button>
              )}
            </div>
          </CardContent>
        </Card>
      </div>

      {/* Active filter pills */}
      {hasAnyFilter && (
        <div className="flex flex-wrap items-center gap-1.5">
          {searchParams.get("search") && (
            <span className="inline-flex items-center gap-1 rounded-full bg-muted px-2.5 py-1 text-xs font-medium text-foreground">
              Search: {searchParams.get("search")}
              <button onClick={() => { setSearch(""); setParam("search", ""); }} className="hover:text-destructive"><X className="h-3 w-3" /></button>
            </span>
          )}
          {status && (
            <span className="inline-flex items-center gap-1 rounded-full bg-muted px-2.5 py-1 text-xs font-medium text-foreground">
              Status: {status}
              <button onClick={() => setParam("status", "")} className="hover:text-destructive"><X className="h-3 w-3" /></button>
            </span>
          )}
          {lifecycle !== "all" && (
            <span className="inline-flex items-center gap-1 rounded-full bg-muted px-2.5 py-1 text-xs font-medium text-foreground">
              Lifecycle: {lifecycle}
              <button onClick={() => setParam("lifecycle", "all")} className="hover:text-destructive"><X className="h-3 w-3" /></button>
            </span>
          )}
        </div>
      )}

      {/* Group by company toggle */}
      {isSuperadmin && !urlCompanyId && (
        <label className="flex items-center gap-1.5 text-sm text-muted-foreground cursor-pointer select-none w-fit">
          <input
            type="checkbox"
            checked={groupByCompany}
            onChange={(e) => setGroupByCompany(e.target.checked)}
            className="rounded border-border"
          />
          Group by company
        </label>
      )}

      {/* Table */}
      <Card className="overflow-hidden">
        <div className="overflow-x-auto">
          <Table>
            <TableHeader>
              <TableRow className="bg-muted/30">
                {isSuperadmin && !groupByCompany && <TableHead className="font-semibold">Company</TableHead>}
                <TableHead className="font-semibold">Invoice #</TableHead>
                <TableHead className="font-semibold">Vendor</TableHead>
                <TableHead className="font-semibold hidden md:table-cell">Date</TableHead>
                <TableHead className="font-semibold text-right hidden sm:table-cell">Amount</TableHead>
                <TableHead className="font-semibold">Status</TableHead>
                <TableHead className="font-semibold hidden lg:table-cell">Source</TableHead>
                <TableHead className="font-semibold hidden xl:table-cell">Sent to OCR</TableHead>
                <TableHead className="font-semibold hidden xl:table-cell">Returned</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {isLoading ? (
                Array.from({ length: 5 }).map((_, i) => (
                  <TableRow key={i}>
                    {Array.from({ length: colCount }).map((__, j) => (
                      <TableCell key={j}><Skeleton className="h-4 w-full" /></TableCell>
                    ))}
                  </TableRow>
                ))
              ) : groupByCompany && groupedInvoices ? (
                groupedInvoices.map((group) => (
                  <>{/* Group header */}
                    <TableRow key={`group-${group.companyId}`} className="bg-muted/50 border-t-2 border-border">
                      <TableCell colSpan={8} className="py-2.5">
                        <div className="flex items-center gap-2">
                          <div className="flex h-6 w-6 shrink-0 items-center justify-center rounded bg-primary/10 text-[10px] font-bold text-primary">
                            {group.name[0].toUpperCase()}
                          </div>
                          <span className="font-semibold text-sm text-foreground">{group.name}</span>
                          {group.code && <span className="text-xs text-muted-foreground">{group.code}</span>}
                          <span className="text-xs text-muted-foreground">
                            ({group.invoices.length} invoice{group.invoices.length !== 1 ? "s" : ""})
                          </span>
                        </div>
                      </TableCell>
                    </TableRow>
                    {group.invoices.map((inv: any) => renderInvoiceRow(inv, false))}
                  </>
                ))
              ) : (
                invoices.map((inv: any) => renderInvoiceRow(inv, isSuperadmin))
              )}
              {!isLoading && invoices.length === 0 && (
                <TableRow>
                  <TableCell colSpan={colCount} className="py-16">
                    <div className="flex flex-col items-center justify-center text-center">
                      <div className="rounded-full bg-muted p-4 mb-3">
                        <FileText className="h-8 w-8 text-muted-foreground" />
                      </div>
                      <p className="text-sm font-medium text-foreground">No invoices found</p>
                      <p className="text-xs text-muted-foreground mt-0.5 max-w-sm">
                        {hasAnyFilter ? "Try adjusting your filters or search term" : "Upload your first invoice to get started"}
                      </p>
                      {!hasAnyFilter && (
                        <Link to="/upload">
                          <Button size="sm" className="mt-3 gap-1">
                            <Upload className="h-3.5 w-3.5" />
                            Upload Invoice
                          </Button>
                        </Link>
                      )}
                    </div>
                  </TableCell>
                </TableRow>
              )}
            </TableBody>
          </Table>
        </div>
      </Card>

      {/* Pagination */}
      {data && data.totalPages > 1 && (
        <div className="flex flex-col sm:flex-row items-center justify-between gap-3 pt-2">
          <p className="text-sm text-muted-foreground">
            Showing {(page - 1) * 20 + 1}\u2013{Math.min(page * 20, data.totalCount || invoices.length)} of {data.totalCount || invoices.length} results
          </p>
          <div className="flex items-center gap-1">
            <Button
              variant="outline"
              size="sm"
              disabled={page <= 1}
              onClick={() => setSearchParams((p) => { p.set("page", String(page - 1)); return p; })}
              className="h-8 w-8 p-0"
            >
              <ChevronLeft className="h-4 w-4" />
            </Button>
            {Array.from({ length: Math.min(data.totalPages, 5) }, (_, i) => {
              let pageNum: number;
              if (data.totalPages <= 5) {
                pageNum = i + 1;
              } else if (page <= 3) {
                pageNum = i + 1;
              } else if (page >= data.totalPages - 2) {
                pageNum = data.totalPages - 4 + i;
              } else {
                pageNum = page - 2 + i;
              }
              return (
                <Button
                  key={pageNum}
                  variant={pageNum === page ? "default" : "outline"}
                  size="sm"
                  onClick={() => setSearchParams((p) => { p.set("page", String(pageNum)); return p; })}
                  className="h-8 w-8 p-0 text-xs"
                >
                  {pageNum}
                </Button>
              );
            })}
            <Button
              variant="outline"
              size="sm"
              disabled={page >= data.totalPages}
              onClick={() => setSearchParams((p) => { p.set("page", String(page + 1)); return p; })}
              className="h-8 w-8 p-0"
            >
              <ChevronRight className="h-4 w-4" />
            </Button>
          </div>
        </div>
      )}
    </div>
  );
}
