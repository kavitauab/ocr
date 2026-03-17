import { useQuery } from "@tanstack/react-query";
import { Link, useNavigate } from "react-router-dom";
import { useCompany } from "@/lib/company";
import { useAuth } from "@/lib/auth";
import api from "@/api/client";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Table, TableHeader, TableBody, TableRow, TableHead, TableCell } from "@/components/ui/table";
import { Badge } from "@/components/ui/badge";
import { FileText, CheckCircle, Loader2, AlertTriangle, Building2 } from "lucide-react";

function formatBytes(bytes: number): string {
  if (bytes === 0) return "0 B";
  const k = 1024;
  const sizes = ["B", "KB", "MB", "GB"];
  const i = Math.floor(Math.log(bytes) / Math.log(k));
  return parseFloat((bytes / Math.pow(k, i)).toFixed(1)) + " " + sizes[i];
}

function formatDate(d: string | null): string {
  if (!d) return "—";
  const date = new Date(d);
  return date.toLocaleDateString("lt-LT");
}

export default function Dashboard() {
  const { user } = useAuth();
  const { selectedCompany } = useCompany();
  const navigate = useNavigate();
  const isSuperadmin = user?.role === "superadmin";
  const companyParam = selectedCompany ? `?companyId=${selectedCompany.id}` : "";

  const { data: stats } = useQuery({
    queryKey: ["stats", selectedCompany?.id],
    queryFn: () => api.get(`/invoices/stats${companyParam}`).then((r) => r.data),
  });

  const { data: invoicesData } = useQuery({
    queryKey: ["recent-invoices", selectedCompany?.id],
    queryFn: () => api.get(`/invoices${companyParam}&limit=5`).then((r) => r.data),
    enabled: !!selectedCompany,
  });

  const statCards = [
    { label: "Total Invoices", value: stats?.totalInvoices || 0, icon: FileText, color: "text-blue-600" },
    { label: "Completed", value: stats?.completedCount || 0, icon: CheckCircle, color: "text-green-600" },
    { label: "Processing", value: stats?.processingCount || 0, icon: Loader2, color: "text-yellow-600" },
    { label: "Failed", value: stats?.failedCount || 0, icon: AlertTriangle, color: "text-red-600" },
  ];

  const showCompanyOverview = isSuperadmin && !selectedCompany;
  const companies: any[] = stats?.companies || [];

  return (
    <div className="space-y-6">
      <h1 className="text-2xl font-bold">Dashboard</h1>

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
              Customers Overview
            </CardTitle>
          </CardHeader>
          <CardContent className="p-0">
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead>Company</TableHead>
                  <TableHead className="text-center">Scanned</TableHead>
                  <TableHead className="text-center">Completed</TableHead>
                  <TableHead className="text-center">Failed</TableHead>
                  <TableHead className="text-center">API Calls</TableHead>
                  <TableHead className="text-right">Storage</TableHead>
                  <TableHead className="text-right">Last Activity</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {companies.map((c: any) => (
                  <TableRow
                    key={c.companyId}
                    className="cursor-pointer hover:bg-gray-50"
                    onClick={() => navigate(`/invoices?companyId=${c.companyId}`)}
                  >
                    <TableCell>
                      <div>
                        <span className="font-medium">{c.companyName}</span>
                        <span className="text-xs text-gray-400 ml-2">{c.companyCode}</span>
                      </div>
                    </TableCell>
                    <TableCell className="text-center font-mono">{c.totalInvoices}</TableCell>
                    <TableCell className="text-center">
                      <span className="text-green-600 font-mono">{c.completedCount}</span>
                    </TableCell>
                    <TableCell className="text-center">
                      {c.failedCount > 0 ? (
                        <span className="text-red-600 font-mono">{c.failedCount}</span>
                      ) : (
                        <span className="text-gray-400 font-mono">0</span>
                      )}
                    </TableCell>
                    <TableCell className="text-center font-mono">{c.apiCalls}</TableCell>
                    <TableCell className="text-right text-sm">{formatBytes(c.storageUsedBytes)}</TableCell>
                    <TableCell className="text-right text-sm text-gray-500">{formatDate(c.lastActivity)}</TableCell>
                  </TableRow>
                ))}
                {companies.length === 0 && (
                  <TableRow>
                    <TableCell colSpan={7} className="text-center text-gray-500 py-8">No companies yet</TableCell>
                  </TableRow>
                )}
              </TableBody>
            </Table>
          </CardContent>
        </Card>
      )}

      {/* Company-specific view: Recent Invoices */}
      {selectedCompany && (
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
