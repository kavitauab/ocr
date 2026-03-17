import { useQuery } from "@tanstack/react-query";
import { Link } from "react-router-dom";
import { useCompany } from "@/lib/company";
import api from "@/api/client";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Table, TableHeader, TableBody, TableRow, TableHead, TableCell } from "@/components/ui/table";
import { Badge } from "@/components/ui/badge";
import { FileText, CheckCircle, Loader2, AlertTriangle } from "lucide-react";

export default function Dashboard() {
  const { selectedCompany } = useCompany();
  const companyParam = selectedCompany ? `?companyId=${selectedCompany.id}` : "";

  const { data: stats } = useQuery({
    queryKey: ["stats", selectedCompany?.id],
    queryFn: () => api.get(`/invoices/stats${companyParam}`).then((r) => r.data),
  });

  const { data: invoicesData } = useQuery({
    queryKey: ["recent-invoices", selectedCompany?.id],
    queryFn: () => api.get(`/invoices${companyParam}&limit=5`).then((r) => r.data),
  });

  const statCards = [
    { label: "Total Invoices", value: stats?.totalInvoices || 0, icon: FileText, color: "text-blue-600" },
    { label: "Completed", value: stats?.completedCount || 0, icon: CheckCircle, color: "text-green-600" },
    { label: "Processing", value: stats?.processingCount || 0, icon: Loader2, color: "text-yellow-600" },
    { label: "Failed", value: stats?.failedCount || 0, icon: AlertTriangle, color: "text-red-600" },
  ];

  return (
    <div className="space-y-6">
      <h1 className="text-2xl font-bold">Dashboard</h1>
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

      {stats?.totalAmountSum > 0 && (
        <Card>
          <CardContent className="p-4">
            <p className="text-sm text-gray-500">Total Amount (Completed)</p>
            <p className="text-2xl font-bold">{Number(stats.totalAmountSum).toLocaleString("lt-LT", { style: "currency", currency: "EUR" })}</p>
          </CardContent>
        </Card>
      )}

      <Card>
        <CardHeader><CardTitle>Recent Invoices</CardTitle></CardHeader>
        <CardContent>
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
                </TableRow>
              ))}
              {(!invoicesData?.invoices || invoicesData.invoices.length === 0) && (
                <TableRow><TableCell colSpan={5} className="text-center text-gray-500">No invoices yet</TableCell></TableRow>
              )}
            </TableBody>
          </Table>
        </CardContent>
      </Card>
    </div>
  );
}
