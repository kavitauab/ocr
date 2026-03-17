import { useMemo, useState, type FormEvent } from "react";
import { useQuery } from "@tanstack/react-query";
import { Link, useSearchParams } from "react-router-dom";
import { useCompany } from "@/lib/company";
import { useAuth } from "@/lib/auth";
import api from "@/api/client";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Table, TableHeader, TableBody, TableRow, TableHead, TableCell } from "@/components/ui/table";
import { Badge } from "@/components/ui/badge";
import { Download, X } from "lucide-react";

function getSentToOcrAt(invoice: any): string | null {
  return invoice?.ocrSentAt ?? invoice?.ocrStartedAt ?? invoice?.sentToOcrAt ?? invoice?.sentAt ?? invoice?.lastSentAt ?? null;
}

function getReturnedAt(invoice: any): string | null {
  return invoice?.ocrReturnedAt ?? invoice?.returnedAt ?? invoice?.lastReturnedAt ?? invoice?.completedAt ?? null;
}

function formatDateTime(value: string | null | undefined): string {
  if (!value) return "—";
  const parsed = new Date(value);
  if (Number.isNaN(parsed.getTime())) return "—";
  return parsed.toLocaleString("lt-LT", { dateStyle: "short", timeStyle: "short" });
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

  // URL companyId takes priority over sidebar selection
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
    setSearchParams((prev) => {
      prev.delete("companyId");
      prev.set("page", "1");
      return prev;
    });
  };

  const setParam = (key: string, value: string) => {
    setSearchParams((prev) => {
      if (value) prev.set(key, value);
      else prev.delete(key);
      prev.set("page", "1");
      return prev;
    });
  };

  const clearLifecycleFilters = () => {
    setSearchParams((prev) => {
      prev.delete("lifecycle");
      prev.delete("sentFrom");
      prev.delete("sentTo");
      prev.delete("returnedFrom");
      prev.delete("returnedTo");
      prev.set("page", "1");
      return prev;
    });
  };

  const hasLifecycleFilters = lifecycle !== "all" || !!sentFrom || !!sentTo || !!returnedFrom || !!returnedTo;

  const [groupByCompany, setGroupByCompany] = useState(false);
  const invoices = Array.isArray(data?.invoices) ? data.invoices : [];

  // Group invoices by company for superadmin grouped view
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

  const handleExportCsv = () => {
    const token = localStorage.getItem("token");
    const exportParams = new URLSearchParams(searchParams);

    if (!exportParams.get("companyId") && effectiveCompanyId) {
      exportParams.set("companyId", effectiveCompanyId);
    }
    if (token) {
      exportParams.set("access_token", token);
    }

    const query = exportParams.toString();
    const url = query ? `/api/invoices/export?${query}` : "/api/invoices/export";
    window.location.href = url;
  };

  return (
    <div className="space-y-4">
      <div className="flex items-center justify-between">
        <h1 className="text-2xl font-bold">Invoices</h1>
        <div className="flex items-center gap-2">
          <Button variant="outline" onClick={handleExportCsv}>
            <Download className="h-4 w-4 mr-1" />
            Export CSV
          </Button>
          <Link to="/upload"><Button>Upload Invoice</Button></Link>
        </div>
      </div>

      {filterCompany && (
        <div className="flex items-center gap-2 bg-blue-50 border border-blue-200 rounded-lg px-4 py-2">
          <span className="text-sm text-blue-700">
            Showing invoices for <strong>{filterCompany.name}</strong>
          </span>
          <Button variant="ghost" size="sm" onClick={clearCompanyFilter} className="h-6 px-2 text-blue-600 hover:text-blue-800">
            <X className="h-3 w-3 mr-1" />Clear filter
          </Button>
        </div>
      )}

      <div className="flex gap-2">
        <form onSubmit={handleSearch} className="flex gap-2 flex-1">
          <Input
            placeholder="Search invoices, vendors, status, sent/returned timestamps..."
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            className="max-w-md"
          />
          <Button type="submit" variant="outline">Search</Button>
        </form>
        <select
          value={status}
          onChange={(e) => setParam("status", e.target.value)}
          className="border rounded px-3 py-1 text-sm"
        >
          <option value="">All statuses</option>
          <option value="completed">Completed</option>
          <option value="processing">Processing</option>
          <option value="failed">Failed</option>
        </select>
      </div>

      <div className="flex flex-wrap items-center gap-2">
        <select
          value={lifecycle}
          onChange={(e) => setParam("lifecycle", e.target.value)}
          className="h-10 border rounded px-3 py-1 text-sm"
        >
          <option value="all">All lifecycle states</option>
          <option value="sent">Sent to OCR</option>
          <option value="not-sent">Not sent to OCR</option>
          <option value="returned">Returned from OCR</option>
          <option value="pending-return">Sent, awaiting return</option>
        </select>

        <div className="flex items-center gap-1 text-sm">
          <span className="text-gray-500">Sent</span>
          <Input type="date" value={sentFrom} onChange={(e) => setParam("sentFrom", e.target.value)} className="h-10 w-40" />
          <span className="text-gray-500">to</span>
          <Input type="date" value={sentTo} onChange={(e) => setParam("sentTo", e.target.value)} className="h-10 w-40" />
        </div>

        <div className="flex items-center gap-1 text-sm">
          <span className="text-gray-500">Returned</span>
          <Input type="date" value={returnedFrom} onChange={(e) => setParam("returnedFrom", e.target.value)} className="h-10 w-40" />
          <span className="text-gray-500">to</span>
          <Input type="date" value={returnedTo} onChange={(e) => setParam("returnedTo", e.target.value)} className="h-10 w-40" />
        </div>

        {hasLifecycleFilters && (
          <Button type="button" variant="ghost" size="sm" onClick={clearLifecycleFilters}>
            <X className="h-3 w-3 mr-1" />Clear lifecycle filters
          </Button>
        )}
      </div>

      {isSuperadmin && !urlCompanyId && (
        <div className="flex items-center gap-2">
          <label className="flex items-center gap-1.5 text-sm text-gray-600 cursor-pointer select-none">
            <input
              type="checkbox"
              checked={groupByCompany}
              onChange={(e) => setGroupByCompany(e.target.checked)}
              className="rounded border-gray-300"
            />
            Group by company
          </label>
        </div>
      )}

      <Table>
        <TableHeader>
          <TableRow>
            {isSuperadmin && !groupByCompany && <TableHead>Company</TableHead>}
            <TableHead>Invoice #</TableHead>
            <TableHead>Vendor</TableHead>
            <TableHead>Date</TableHead>
            <TableHead>Amount</TableHead>
            <TableHead>Status</TableHead>
            <TableHead>Source</TableHead>
            <TableHead>Sent to OCR</TableHead>
            <TableHead>Returned</TableHead>
          </TableRow>
        </TableHeader>
        <TableBody>
          {groupByCompany && groupedInvoices ? (
            groupedInvoices.map((group) => (
              <>
                <TableRow key={`group-${group.companyId}`} className="bg-gray-50 border-t-2">
                  <TableCell colSpan={8} className="py-2">
                    <span className="font-semibold text-sm">{group.name}</span>
                    {group.code && <span className="text-xs text-gray-400 ml-2">{group.code}</span>}
                    <span className="text-xs text-gray-400 ml-2">({group.invoices.length} invoice{group.invoices.length !== 1 ? "s" : ""})</span>
                  </TableCell>
                </TableRow>
                {group.invoices.map((inv: any) => (
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
                    <TableCell><Badge variant="outline">{inv.source}</Badge></TableCell>
                    <TableCell className="text-sm text-gray-600">{formatDateTime(getSentToOcrAt(inv))}</TableCell>
                    <TableCell className="text-sm text-gray-600">{formatDateTime(getReturnedAt(inv))}</TableCell>
                  </TableRow>
                ))}
              </>
            ))
          ) : (
            invoices.map((inv: any) => (
              <TableRow key={inv.id}>
                {isSuperadmin && (
                  <TableCell>
                    <span className="text-sm">{inv.companyName || "—"}</span>
                    {inv.companyCode && <span className="text-xs text-gray-400 ml-1">({inv.companyCode})</span>}
                  </TableCell>
                )}
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
                <TableCell><Badge variant="outline">{inv.source}</Badge></TableCell>
                <TableCell className="text-sm text-gray-600">{formatDateTime(getSentToOcrAt(inv))}</TableCell>
                <TableCell className="text-sm text-gray-600">{formatDateTime(getReturnedAt(inv))}</TableCell>
              </TableRow>
            ))
          )}
          {isLoading && <TableRow><TableCell colSpan={isSuperadmin && !groupByCompany ? 9 : 8} className="text-center">Loading...</TableCell></TableRow>}
          {!isLoading && invoices.length === 0 && (
            <TableRow><TableCell colSpan={isSuperadmin && !groupByCompany ? 9 : 8} className="text-center text-gray-500">No invoices found</TableCell></TableRow>
          )}
        </TableBody>
      </Table>

      {data && data.totalPages > 1 && (
        <div className="flex justify-center gap-2">
          <Button variant="outline" size="sm" disabled={page <= 1} onClick={() => setSearchParams((p) => { p.set("page", String(page - 1)); return p; })}>Previous</Button>
          <span className="text-sm py-1">Page {page} of {data.totalPages}</span>
          <Button variant="outline" size="sm" disabled={page >= data.totalPages} onClick={() => setSearchParams((p) => { p.set("page", String(page + 1)); return p; })}>Next</Button>
        </div>
      )}
    </div>
  );
}
