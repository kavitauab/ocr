import { useQuery } from "@tanstack/react-query";
import { Link, useSearchParams, useNavigate } from "react-router-dom";
import { useCompany } from "@/lib/company";
import api from "@/api/client";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Table, TableHeader, TableBody, TableRow, TableHead, TableCell } from "@/components/ui/table";
import { Badge } from "@/components/ui/badge";
import { useState } from "react";
import { X } from "lucide-react";

export default function Invoices() {
  const { selectedCompany, companies } = useCompany();
  const navigate = useNavigate();
  const [searchParams, setSearchParams] = useSearchParams();
  const page = parseInt(searchParams.get("page") || "1");
  const status = searchParams.get("status") || "";
  const urlCompanyId = searchParams.get("companyId") || "";
  const [search, setSearch] = useState(searchParams.get("search") || "");

  // URL companyId takes priority over sidebar selection
  const effectiveCompanyId = urlCompanyId || selectedCompany?.id || "";
  const filterCompany = urlCompanyId ? companies.find((c) => c.id === urlCompanyId) : null;

  const { data, isLoading } = useQuery({
    queryKey: ["invoices", effectiveCompanyId, page, status, search],
    queryFn: () => {
      const params: Record<string, string> = { page: String(page), limit: "20" };
      if (effectiveCompanyId) params.companyId = effectiveCompanyId;
      if (status) params.status = status;
      if (search) params.search = search;
      return api.get("/invoices", { params }).then((r) => r.data);
    },
  });

  const handleSearch = (e: React.FormEvent) => {
    e.preventDefault();
    setSearchParams((prev) => { prev.set("search", search); prev.set("page", "1"); return prev; });
  };

  const clearCompanyFilter = () => {
    setSearchParams((prev) => { prev.delete("companyId"); prev.set("page", "1"); return prev; });
  };

  return (
    <div className="space-y-4">
      <div className="flex items-center justify-between">
        <h1 className="text-2xl font-bold">Invoices</h1>
        <Link to="/upload"><Button>Upload Invoice</Button></Link>
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
          <Input placeholder="Search invoices..." value={search} onChange={(e) => setSearch(e.target.value)} className="max-w-sm" />
          <Button type="submit" variant="outline">Search</Button>
        </form>
        <select
          value={status}
          onChange={(e) => setSearchParams((prev) => { prev.set("status", e.target.value); prev.set("page", "1"); return prev; })}
          className="border rounded px-3 py-1 text-sm"
        >
          <option value="">All statuses</option>
          <option value="completed">Completed</option>
          <option value="processing">Processing</option>
          <option value="failed">Failed</option>
        </select>
      </div>

      <Table>
        <TableHeader>
          <TableRow>
            <TableHead>Invoice #</TableHead>
            <TableHead>Vendor</TableHead>
            <TableHead>Date</TableHead>
            <TableHead>Amount</TableHead>
            <TableHead>Status</TableHead>
            <TableHead>Source</TableHead>
          </TableRow>
        </TableHeader>
        <TableBody>
          {data?.invoices?.map((inv: any) => (
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
            </TableRow>
          ))}
          {isLoading && <TableRow><TableCell colSpan={6} className="text-center">Loading...</TableCell></TableRow>}
          {!isLoading && (!data?.invoices || data.invoices.length === 0) && (
            <TableRow><TableCell colSpan={6} className="text-center text-gray-500">No invoices found</TableCell></TableRow>
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
