import { useQuery, useMutation, useQueryClient } from "@tanstack/react-query";
import { useParams, useNavigate } from "react-router-dom";
import { useState } from "react";
import api from "@/api/client";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Badge } from "@/components/ui/badge";
import { toast } from "sonner";
import { ArrowLeft, Save, Send, Trash2, CheckCircle, AlertTriangle, AlertCircle, Download } from "lucide-react";

function ConfidenceDot({ score }: { score?: number }) {
  if (score == null) return null;
  const title = `Confidence: ${(score * 100).toFixed(0)}%`;
  if (score >= 0.8) return <span title={title}><CheckCircle className="h-3.5 w-3.5 text-green-500 shrink-0" /></span>;
  if (score >= 0.5) return <span title={title}><AlertTriangle className="h-3.5 w-3.5 text-yellow-500 shrink-0" /></span>;
  return <span title={title}><AlertCircle className="h-3.5 w-3.5 text-red-500 shrink-0" /></span>;
}

function getUploadedAt(invoice: any): string | null {
  return invoice?.uploadedAt ?? invoice?.createdAt ?? null;
}

function getSentToOcrAt(invoice: any): string | null {
  return invoice?.ocrSentAt ?? invoice?.ocrStartedAt ?? invoice?.sentToOcrAt ?? invoice?.sentAt ?? invoice?.lastSentAt ?? null;
}

function getReturnedAt(invoice: any): string | null {
  return invoice?.ocrReturnedAt ?? invoice?.returnedAt ?? invoice?.lastReturnedAt ?? invoice?.completedAt ?? null;
}

function parseDateTime(value: string | null | undefined): Date | null {
  if (!value) return null;
  const parsed = new Date(value);
  if (Number.isNaN(parsed.getTime())) return null;
  return parsed;
}

function formatDateTime(value: string | null | undefined): string {
  const parsed = parseDateTime(value);
  if (!parsed) return "—";
  return parsed.toLocaleString("lt-LT", { dateStyle: "short", timeStyle: "short" });
}

function formatDuration(ms: number | null): string {
  if (ms === null || !Number.isFinite(ms) || ms < 0) return "—";
  const totalSeconds = Math.round(ms / 1000);
  const hours = Math.floor(totalSeconds / 3600);
  const minutes = Math.floor((totalSeconds % 3600) / 60);
  const seconds = totalSeconds % 60;
  if (hours > 0) return `${hours}h ${minutes}m ${seconds}s`;
  if (minutes > 0) return `${minutes}m ${seconds}s`;
  return `${seconds}s`;
}

function getProcessingDurationMs(invoice: any): number | null {
  const msCandidates = [invoice?.processingDurationMs, invoice?.ocrProcessingDurationMs, invoice?.durationMs];
  for (const candidate of msCandidates) {
    const value = Number(candidate);
    if (Number.isFinite(value) && value >= 0) return value;
  }

  const secondCandidates = [invoice?.processingDurationSeconds, invoice?.ocrProcessingDurationSeconds, invoice?.durationSeconds];
  for (const candidate of secondCandidates) {
    const value = Number(candidate);
    if (Number.isFinite(value) && value >= 0) return value * 1000;
  }

  const sentAt = parseDateTime(getSentToOcrAt(invoice));
  const returnedAt = parseDateTime(getReturnedAt(invoice));
  if (sentAt && returnedAt) {
    const diff = returnedAt.getTime() - sentAt.getTime();
    if (diff >= 0) return diff;
  }

  return null;
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

  const documentTypeLabels: Record<string, string> = {
    invoice: "Invoice",
    proforma: "Proforma",
    credit_note: "Credit Note",
  };

  const startEdit = () => {
    setForm({
      documentType: invoice.documentType || "",
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
    ["documentType", "Document Type"],
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
  const uploadedAt = getUploadedAt(invoice);
  const sentToOcrAt = getSentToOcrAt(invoice);
  const returnedAt = getReturnedAt(invoice);
  const uploadedDate = parseDateTime(uploadedAt);
  const sentToOcrDate = parseDateTime(sentToOcrAt);
  const returnedDate = parseDateTime(returnedAt);
  const processingDurationMs = getProcessingDurationMs(invoice);
  const processingDuration = processingDurationMs !== null
    ? formatDuration(processingDurationMs)
    : sentToOcrDate && !returnedDate
      ? "In progress"
      : "—";

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
                    key === "documentType" ? (
                      <select
                        value={form[key] || ""}
                        onChange={(e) => setForm({ ...form, [key]: e.target.value })}
                        className="border rounded px-3 py-1.5 text-sm"
                      >
                        <option value="">—</option>
                        <option value="invoice">Invoice</option>
                        <option value="proforma">Proforma</option>
                        <option value="credit_note">Credit Note</option>
                      </select>
                    ) : (
                      <Input value={form[key] || ""} onChange={(e) => setForm({ ...form, [key]: e.target.value })} />
                    )
                  ) : (
                    key === "documentType" && value ? (
                      <span className={`inline-flex items-center px-2 py-0.5 rounded text-xs font-medium ${
                        value === "credit_note" ? "bg-red-100 text-red-700" :
                        value === "proforma" ? "bg-yellow-100 text-yellow-700" :
                        "bg-blue-100 text-blue-700"
                      }`}>
                        {documentTypeLabels[value] || value}
                      </span>
                    ) : (
                      <span className="text-sm font-medium">{value || "—"}</span>
                    )
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

          {/* Lifecycle Timeline */}
          <Card>
            <CardHeader><CardTitle>Lifecycle Timeline</CardTitle></CardHeader>
            <CardContent>
              <div className="ml-1 space-y-4 border-l border-gray-200 pl-4">
                <div className="relative flex items-start justify-between gap-4">
                  <span className={`absolute -left-[21px] top-1.5 h-2.5 w-2.5 rounded-full ${uploadedDate ? "bg-gray-500" : "bg-gray-300"}`} />
                  <span className="text-sm text-gray-500">Uploaded / Created</span>
                  <span className="text-sm font-medium text-right">{formatDateTime(uploadedAt)}</span>
                </div>
                <div className="relative flex items-start justify-between gap-4">
                  <span className={`absolute -left-[21px] top-1.5 h-2.5 w-2.5 rounded-full ${sentToOcrDate ? "bg-blue-500" : "bg-gray-300"}`} />
                  <span className="text-sm text-gray-500">OCR Sent</span>
                  <span className="text-sm font-medium text-right">{formatDateTime(sentToOcrAt)}</span>
                </div>
                <div className="relative flex items-start justify-between gap-4">
                  <span className={`absolute -left-[21px] top-1.5 h-2.5 w-2.5 rounded-full ${returnedDate ? "bg-green-500" : "bg-gray-300"}`} />
                  <span className="text-sm text-gray-500">OCR Returned</span>
                  <span className="text-sm font-medium text-right">{formatDateTime(returnedAt)}</span>
                </div>
                <div className="relative flex items-start justify-between gap-4">
                  <span className={`absolute -left-[21px] top-1.5 h-2.5 w-2.5 rounded-full ${processingDurationMs !== null ? "bg-amber-500" : "bg-gray-300"}`} />
                  <span className="text-sm text-gray-500">Processing Duration</span>
                  <span className="text-sm font-medium text-right">{processingDuration}</span>
                </div>
              </div>
            </CardContent>
          </Card>

          {/* Actions */}
          <Card>
            <CardHeader><CardTitle>Actions</CardTitle></CardHeader>
            <CardContent className="flex gap-2 flex-wrap">
              <a href={`/api/invoices/${id}/metadata?access_token=${encodeURIComponent(token || "")}`} download>
                <Button variant="outline">
                  <Download className="h-3 w-3 mr-1" />Download JSON
                </Button>
              </a>
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
              <div className="flex justify-between"><span>Uploaded</span><span className="font-medium">{formatDateTime(uploadedAt)}</span></div>
            </CardContent>
          </Card>
        </div>
      </div>
    </div>
  );
}
