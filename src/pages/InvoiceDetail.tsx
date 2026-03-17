import { useQuery, useMutation, useQueryClient } from "@tanstack/react-query";
import { useParams, useNavigate } from "react-router-dom";
import { useState } from "react";
import api from "@/api/client";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Badge } from "@/components/ui/badge";
import { toast } from "sonner";
import { ArrowLeft, Save, Send, Trash2, CheckCircle, AlertTriangle, AlertCircle } from "lucide-react";

function ConfidenceDot({ score }: { score?: number }) {
  if (score == null) return null;
  if (score >= 0.8) return <CheckCircle className="h-3.5 w-3.5 text-green-500 shrink-0" title={`Confidence: ${(score * 100).toFixed(0)}%`} />;
  if (score >= 0.5) return <AlertTriangle className="h-3.5 w-3.5 text-yellow-500 shrink-0" title={`Confidence: ${(score * 100).toFixed(0)}%`} />;
  return <AlertCircle className="h-3.5 w-3.5 text-red-500 shrink-0" title={`Confidence: ${(score * 100).toFixed(0)}%`} />;
}

export default function InvoiceDetail() {
  const { id } = useParams<{ id: string }>();
  const navigate = useNavigate();
  const queryClient = useQueryClient();
  const [editing, setEditing] = useState(false);
  const [form, setForm] = useState<Record<string, any>>({});

  const token = localStorage.getItem("token");
  const fileUrl = `/api/invoices/${id}/file?access_token=${encodeURIComponent(token || "")}`;

  const { data, isLoading } = useQuery({
    queryKey: ["invoice", id],
    queryFn: () => api.get(`/invoices/${id}`).then((r) => r.data),
    enabled: !!id,
  });

  const invoice = data?.invoice;

  const updateMutation = useMutation({
    mutationFn: (updates: Record<string, any>) => api.patch(`/invoices/${id}`, updates).then((r) => r.data),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["invoice", id] });
      setEditing(false);
      toast.success("Invoice updated");
    },
    onError: () => toast.error("Failed to update"),
  });

  const deleteMutation = useMutation({
    mutationFn: () => api.delete(`/invoices/${id}`).then((r) => r.data),
    onSuccess: () => {
      toast.success("Invoice deleted");
      navigate("/invoices");
    },
  });

  const vecticumMutation = useMutation({
    mutationFn: () => api.post(`/invoices/${id}/vecticum`).then((r) => r.data),
    onSuccess: (data) => toast.success(data.message || "Sent to Vecticum"),
    onError: (err: any) => toast.error(err.response?.data?.error || "Failed"),
  });

  if (isLoading) return <div className="p-8 text-center text-gray-500">Loading...</div>;
  if (!invoice) return <div className="p-8 text-center text-gray-500">Invoice not found</div>;

  const startEdit = () => {
    setForm({
      invoiceNumber: invoice.invoiceNumber || "",
      invoiceDate: invoice.invoiceDate || "",
      dueDate: invoice.dueDate || "",
      vendorName: invoice.vendorName || "",
      vendorAddress: invoice.vendorAddress || "",
      vendorVatId: invoice.vendorVatId || "",
      buyerName: invoice.buyerName || "",
      buyerAddress: invoice.buyerAddress || "",
      buyerVatId: invoice.buyerVatId || "",
      totalAmount: invoice.totalAmount || "",
      taxAmount: invoice.taxAmount || "",
      subtotalAmount: invoice.subtotalAmount || "",
      currency: invoice.currency || "",
      poNumber: invoice.poNumber || "",
    });
    setEditing(true);
  };

  const confidence = invoice.confidenceScores || {};

  const fields: [string, string][] = [
    ["invoiceNumber", "Invoice Number"],
    ["invoiceDate", "Invoice Date"],
    ["dueDate", "Due Date"],
    ["vendorName", "Vendor"],
    ["vendorAddress", "Vendor Address"],
    ["vendorVatId", "Vendor VAT ID"],
    ["buyerName", "Buyer"],
    ["buyerAddress", "Buyer Address"],
    ["buyerVatId", "Buyer VAT ID"],
    ["totalAmount", "Total Amount"],
    ["taxAmount", "Tax Amount"],
    ["subtotalAmount", "Subtotal"],
    ["currency", "Currency"],
    ["poNumber", "PO Number"],
  ];

  const statusColor = invoice.status === "completed" ? "default"
    : invoice.status === "failed" ? "destructive"
    : "secondary";

  return (
    <div className="space-y-4">
      <div className="flex items-center gap-4">
        <Button variant="ghost" size="icon" onClick={() => navigate(-1)}>
          <ArrowLeft className="h-4 w-4" />
        </Button>
        <h1 className="text-2xl font-bold flex-1">{invoice.invoiceNumber || invoice.originalFilename}</h1>
        <Badge variant={statusColor}>{invoice.status}</Badge>
      </div>

      {invoice.status === "failed" && invoice.processingError && (
        <Card className="border-red-200 bg-red-50">
          <CardContent className="py-3">
            <p className="text-sm text-red-700"><strong>Error:</strong> {invoice.processingError}</p>
          </CardContent>
        </Card>
      )}

      <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
        {/* Invoice Details */}
        <Card>
          <CardHeader className="flex flex-row items-center justify-between">
            <CardTitle>Invoice Details</CardTitle>
            <div className="flex gap-2">
              {!editing && <Button variant="outline" size="sm" onClick={startEdit}>Edit</Button>}
              {editing && (
                <>
                  <Button size="sm" onClick={() => updateMutation.mutate(form)} disabled={updateMutation.isPending}>
                    <Save className="h-3 w-3 mr-1" />Save
                  </Button>
                  <Button variant="outline" size="sm" onClick={() => setEditing(false)}>Cancel</Button>
                </>
              )}
            </div>
          </CardHeader>
          <CardContent className="space-y-2">
            {fields.map(([key, label]) => {
              const value = (invoice as any)[key];
              const conf = confidence[key];
              return (
                <div key={key} className="grid grid-cols-[140px_1fr] gap-2 items-center">
                  <span className="text-sm text-gray-500 flex items-center gap-1.5">
                    {!editing && <ConfidenceDot score={conf} />}
                    {label}
                  </span>
                  {editing ? (
                    <Input value={form[key] || ""} onChange={(e) => setForm({ ...form, [key]: e.target.value })} />
                  ) : (
                    <span className="text-sm font-medium">{value || "—"}</span>
                  )}
                </div>
              );
            })}
            {invoice.bankDetails && (
              <div className="grid grid-cols-[140px_1fr] gap-2 items-start pt-2 border-t">
                <span className="text-sm text-gray-500 flex items-center gap-1.5">
                  <ConfidenceDot score={confidence.bankDetails} />
                  Bank Details
                </span>
                <span className="text-sm font-medium whitespace-pre-wrap">{invoice.bankDetails}</span>
              </div>
            )}
            {invoice.paymentTerms && (
              <div className="grid grid-cols-[140px_1fr] gap-2 items-start">
                <span className="text-sm text-gray-500 flex items-center gap-1.5">
                  <ConfidenceDot score={confidence.paymentTerms} />
                  Payment Terms
                </span>
                <span className="text-sm font-medium">{invoice.paymentTerms}</span>
              </div>
            )}
          </CardContent>
        </Card>

        {/* Right column */}
        <div className="space-y-4">
          {/* File Preview */}
          <Card>
            <CardHeader><CardTitle>File Preview</CardTitle></CardHeader>
            <CardContent>
              {invoice.fileType === "pdf" ? (
                <iframe src={fileUrl} className="w-full h-[500px] border rounded" />
              ) : (
                <img src={fileUrl} alt="Invoice" className="max-w-full rounded" />
              )}
            </CardContent>
          </Card>

          {/* Actions */}
          <Card>
            <CardHeader><CardTitle>Actions</CardTitle></CardHeader>
            <CardContent className="flex gap-2">
              <Button variant="outline" onClick={() => vecticumMutation.mutate()} disabled={vecticumMutation.isPending}>
                <Send className="h-3 w-3 mr-1" />Send to Vecticum
              </Button>
              <Button
                variant="destructive"
                onClick={() => { if (confirm("Delete this invoice?")) deleteMutation.mutate(); }}
                disabled={deleteMutation.isPending}
              >
                <Trash2 className="h-3 w-3 mr-1" />Delete
              </Button>
            </CardContent>
          </Card>

          {/* Metadata */}
          <Card>
            <CardHeader><CardTitle>Metadata</CardTitle></CardHeader>
            <CardContent className="text-sm space-y-1 text-gray-600">
              <div className="flex justify-between"><span>Source</span><span className="font-medium">{invoice.source}</span></div>
              <div className="flex justify-between"><span>File</span><span className="font-medium">{invoice.originalFilename}</span></div>
              <div className="flex justify-between"><span>Size</span><span className="font-medium">{invoice.fileSize ? `${(invoice.fileSize / 1024).toFixed(1)} KB` : "—"}</span></div>
              <div className="flex justify-between"><span>Uploaded</span><span className="font-medium">{invoice.createdAt}</span></div>
            </CardContent>
          </Card>
        </div>
      </div>
    </div>
  );
}
