import { useQuery } from "@tanstack/react-query";
import { Link, useSearchParams } from "react-router-dom";
import { useCompany } from "@/lib/company";
import api from "@/api/client";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Table, TableHeader, TableBody, TableRow, TableHead, TableCell } from "@/components/ui/table";
import { Badge } from "@/components/ui/badge";
import { useState } from "react";

export default function Invoices() {
  const { selectedCompany } = useCompany();
  const [searchParams, setSearchParams] = useSearchParams();
  const page = parseInt(searchParams.get("page") || "1");
  const status = searchParams.get("status") || "";
  const [search, setSearch] = useState(searchParams.get("search") || "");

  const { data, isLoading } = useQuery({
    queryKey: ["invoices", selectedCompany?.id, page, status, search],
    queryFn: () => {
      const params: Record<string, string> = { page: String(page), limit: "20" };
      if (selectedCompany) params.companyId = selectedCompany.id;
      if (status) params.status = status;
      if (search) params.search = search;
      return api.get("/invoices", { params }).then((r) => r.data);
    },
  });

  const handleSearch = (e: React.FormEvent) => {
    e.preventDefault();
    setSearchParams((prev) => { prev.set("search", search); prev.set("page", "1"); return prev; });
  };

  return (
    <div className="space-y-4">
      <div className="flex items-center justify-between">
        <h1 className="text-2xl font-bold">Invoices</h1>
        <Link to="/upload"><Button>Upload Invoice</Button></Link>
      </div>

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
                <Link to={`/invoices/${inv.id}`} className="text-blue-600 hover:underline">
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
