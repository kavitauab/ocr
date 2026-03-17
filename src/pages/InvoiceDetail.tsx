import { useQuery, useMutation, useQueryClient } from "@tanstack/react-query";
import { useParams, useNavigate } from "react-router-dom";
import { useState } from "react";
import api from "@/api/client";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Badge } from "@/components/ui/badge";
import { toast } from "sonner";
import { ArrowLeft, Save, Send, Trash2 } from "lucide-react";

export default function InvoiceDetail() {
  const { id } = useParams<{ id: string }>();
  const navigate = useNavigate();
  const queryClient = useQueryClient();
  const [editing, setEditing] = useState(false);
  const [form, setForm] = useState<Record<string, any>>({});

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

  if (isLoading) return <div>Loading...</div>;
  if (!invoice) return <div>Invoice not found</div>;

  const startEdit = () => {
    setForm({
      invoiceNumber: invoice.invoiceNumber || "",
      invoiceDate: invoice.invoiceDate || "",
      dueDate: invoice.dueDate || "",
      vendorName: invoice.vendorName || "",
      vendorVatId: invoice.vendorVatId || "",
      buyerName: invoice.buyerName || "",
      buyerVatId: invoice.buyerVatId || "",
      totalAmount: invoice.totalAmount || "",
      taxAmount: invoice.taxAmount || "",
      subtotalAmount: invoice.subtotalAmount || "",
      currency: invoice.currency || "",
      poNumber: invoice.poNumber || "",
    });
    setEditing(true);
  };

  const fields = [
    ["invoiceNumber", "Invoice Number"],
    ["invoiceDate", "Invoice Date"],
    ["dueDate", "Due Date"],
    ["vendorName", "Vendor"],
    ["vendorVatId", "Vendor VAT ID"],
    ["buyerName", "Buyer"],
    ["buyerVatId", "Buyer VAT ID"],
    ["totalAmount", "Total Amount"],
    ["taxAmount", "Tax Amount"],
    ["subtotalAmount", "Subtotal"],
    ["currency", "Currency"],
    ["poNumber", "PO Number"],
  ] as const;

  return (
    <div className="space-y-4">
      <div className="flex items-center gap-4">
        <Button variant="ghost" size="icon" onClick={() => navigate(-1)}><ArrowLeft className="h-4 w-4" /></Button>
        <h1 className="text-2xl font-bold flex-1">{invoice.invoiceNumber || invoice.originalFilename}</h1>
        <Badge variant={invoice.status === "completed" ? "default" : invoice.status === "failed" ? "destructive" : "secondary"}>
          {invoice.status}
        </Badge>
      </div>

      <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
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
          <CardContent className="space-y-3">
            {fields.map(([key, label]) => (
              <div key={key} className="grid grid-cols-3 gap-2 items-center">
                <span className="text-sm text-gray-500">{label}</span>
                {editing ? (
                  <Input className="col-span-2" value={form[key] || ""} onChange={(e) => setForm({ ...form, [key]: e.target.value })} />
                ) : (
                  <span className="col-span-2 text-sm">{(invoice as any)[key] || "—"}</span>
                )}
              </div>
            ))}
          </CardContent>
        </Card>

        <div className="space-y-4">
          <Card>
            <CardHeader><CardTitle>File Preview</CardTitle></CardHeader>
            <CardContent>
              {invoice.fileType === "pdf" ? (
                <iframe src={`/api/invoices/${id}/file`} className="w-full h-96 border rounded" />
              ) : (
                <img src={`/api/invoices/${id}/file`} alt="Invoice" className="max-w-full rounded" />
              )}
            </CardContent>
          </Card>

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
        </div>
      </div>
    </div>
  );
}
