import { useQuery, useMutation, useQueryClient } from "@tanstack/react-query";
import { useParams, useNavigate, Link } from "react-router-dom";
import { useState } from "react";
import api from "@/api/client";
import { useCompany } from "@/lib/company";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Skeleton } from "@/components/ui/skeleton";
import { getStatusClasses, formatRelativeTime } from "@/lib/ui-utils";
import { toast } from "sonner";
import {
  ArrowLeft,
  Save,
  Send,
  Trash2,
  CheckCircle,
  AlertTriangle,
  AlertCircle,
  Download,
  Pencil,
  X,
  Clock,
  FileText,
  ExternalLink,
  RotateCcw,
} from "lucide-react";

function ConfidenceDot({ score }: { score?: number }) {
  if (score == null) return null;
  const pct = (score * 100).toFixed(0);
  if (score >= 0.8) return (
    <span title={`${pct}% confidence`} className="flex h-5 w-5 items-center justify-center rounded-full bg-emerald-50">
      <CheckCircle className="h-3 w-3 text-emerald-500" />
    </span>
  );
  if (score >= 0.5) return (
    <span title={`${pct}% confidence`} className="flex h-5 w-5 items-center justify-center rounded-full bg-amber-50">
      <AlertTriangle className="h-3 w-3 text-amber-500" />
    </span>
  );
  return (
    <span title={`${pct}% confidence`} className="flex h-5 w-5 items-center justify-center rounded-full bg-red-50">
      <AlertCircle className="h-3 w-3 text-red-500" />
    </span>
  );
}

function getUploadedAt(invoice: any): string | null {
  return invoice?.uploadedAt ?? invoice?.createdAt ?? null;
}
function getSentToOcrAt(invoice: any): string | null {
  return invoice?.ocrSentAt ?? invoice?.ocrStartedAt ?? invoice?.sentToOcrAt ?? invoice?.sentAt ?? null;
}
function getReturnedAt(invoice: any): string | null {
  return invoice?.ocrReturnedAt ?? invoice?.returnedAt ?? invoice?.lastReturnedAt ?? invoice?.completedAt ?? null;
}
function parseDateTime(value: string | null | undefined): Date | null {
  if (!value) return null;
  const parsed = new Date(value);
  return Number.isNaN(parsed.getTime()) ? null : parsed;
}
function fmtDateTime(value: string | null | undefined): string {
  const p = parseDateTime(value);
  if (!p) return "\u2014";
  return p.toLocaleString("lt-LT", { dateStyle: "short", timeStyle: "short" });
}
function formatDuration(ms: number | null): string {
  if (ms === null || !Number.isFinite(ms) || ms < 0) return "\u2014";
  const s = Math.round(ms / 1000);
  const h = Math.floor(s / 3600);
  const m = Math.floor((s % 3600) / 60);
  const sec = s % 60;
  if (h > 0) return `${h}h ${m}m ${sec}s`;
  if (m > 0) return `${m}m ${sec}s`;
  return `${sec}s`;
}
function getProcessingDurationMs(invoice: any): number | null {
  for (const c of [invoice?.processingDurationMs, invoice?.ocrProcessingDurationMs, invoice?.durationMs]) {
    const v = Number(c); if (Number.isFinite(v) && v >= 0) return v;
  }
  for (const c of [invoice?.processingDurationSeconds, invoice?.ocrProcessingDurationSeconds, invoice?.durationSeconds]) {
    const v = Number(c); if (Number.isFinite(v) && v >= 0) return v * 1000;
  }
  const sent = parseDateTime(getSentToOcrAt(invoice));
  const ret = parseDateTime(getReturnedAt(invoice));
  if (sent && ret) { const d = ret.getTime() - sent.getTime(); if (d >= 0) return d; }
  return null;
}

const documentTypeLabels: Record<string, string> = { invoice: "Invoice", proforma: "Proforma", credit_note: "Credit Note" };
const documentTypeColors: Record<string, string> = {
  invoice: "bg-blue-50 text-blue-700 border-blue-200",
  proforma: "bg-amber-50 text-amber-700 border-amber-200",
  credit_note: "bg-red-50 text-red-700 border-red-200",
};

const fieldSections: { title: string; fields: [string, string][] }[] = [
  { title: "Document Info", fields: [["documentType", "Type"], ["invoiceNumber", "Invoice #"], ["invoiceDate", "Date"], ["dueDate", "Due Date"], ["poNumber", "PO Number"]] },
  { title: "Vendor", fields: [["vendorName", "Name"], ["vendorAddress", "Address"], ["vendorVatId", "VAT ID"]] },
  { title: "Buyer", fields: [["buyerName", "Name"], ["buyerAddress", "Address"], ["buyerVatId", "VAT ID"]] },
  { title: "Financial", fields: [["subtotalAmount", "Subtotal"], ["taxAmount", "Tax"], ["totalAmount", "Total"], ["currency", "Currency"]] },
];

export default function InvoiceDetail() {
  const { id } = useParams<{ id: string }>();
  const navigate = useNavigate();
  const queryClient = useQueryClient();
  const { hasCompanyRole } = useCompany();
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
    onSuccess: () => { queryClient.invalidateQueries({ queryKey: ["invoice", id] }); setEditing(false); toast.success("Invoice updated"); },
    onError: () => toast.error("Failed to update"),
  });
  const deleteMutation = useMutation({
    mutationFn: () => api.delete(`/invoices/${id}`).then((r) => r.data),
    onSuccess: () => { toast.success("Invoice deleted"); navigate("/invoices"); },
  });
  const vecticumMutation = useMutation({
    mutationFn: () => api.post(`/invoices/${id}/vecticum`).then((r) => r.data),
    onSuccess: (d) => toast.success(d.message || "Sent to Vecticum"),
    onError: (err: any) => toast.error(err.response?.data?.error || "Failed"),
  });
  const retryMutation = useMutation({
    mutationFn: () => api.post(`/invoices/${id}/retry`).then((r) => r.data),
    onSuccess: () => { toast.success("Invoice queued for retry"); queryClient.invalidateQueries({ queryKey: ["invoice", id] }); },
    onError: (err: any) => toast.error(err.response?.data?.error || "Retry failed"),
  });

  if (isLoading) return (
    <div className="space-y-2">
      <Skeleton className="h-8 w-64" />
      <div className="grid grid-cols-1 lg:grid-cols-12 gap-3">
        <div className="lg:col-span-5"><Skeleton className="h-96 w-full" /></div>
        <div className="lg:col-span-7"><Skeleton className="h-96 w-full" /></div>
      </div>
    </div>
  );
  if (!invoice) return (
    <div className="flex flex-col items-center justify-center py-20">
      <div className="rounded-full bg-muted p-4 mb-3"><FileText className="h-8 w-8 text-muted-foreground" /></div>
      <p className="text-sm font-medium">Invoice not found</p>
      <Link to="/invoices"><Button variant="outline" size="sm" className="mt-3">Back to Invoices</Button></Link>
    </div>
  );

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
  const uploadedAt = getUploadedAt(invoice);
  const sentToOcrAt = getSentToOcrAt(invoice);
  const returnedAt = getReturnedAt(invoice);
  const sentToOcrDate = parseDateTime(sentToOcrAt);
  const returnedDate = parseDateTime(returnedAt);
  const processingDurationMs = getProcessingDurationMs(invoice);
  const processingDuration = processingDurationMs !== null ? formatDuration(processingDurationMs) : sentToOcrDate && !returnedDate ? "In progress" : "\u2014";

  const timelineSteps = [
    { label: "Uploaded", time: uploadedAt, color: "bg-slate-500", active: !!uploadedAt },
    { label: "Sent to OCR", time: sentToOcrAt, color: "bg-blue-500", active: !!sentToOcrAt },
    { label: "OCR Returned", time: returnedAt, color: "bg-emerald-500", active: !!returnedAt },
    ...(invoice.vecticumId ? [{ label: "Sent to Vecticum", time: null, color: "bg-purple-500", active: true }] : []),
  ];

  return (
    <div className="space-y-2">
      {/* Header: back + title + badges + action buttons */}
      <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2">
        <div className="flex items-center gap-2">
          <button onClick={() => navigate("/invoices")} className="flex h-7 w-7 items-center justify-center rounded-md hover:bg-muted transition-colors shrink-0">
            <ArrowLeft className="h-3.5 w-3.5 text-muted-foreground" />
          </button>
          <h1 className="text-lg font-bold tracking-tight text-foreground">
            {invoice.invoiceNumber || invoice.originalFilename}
          </h1>
          <span className={`inline-flex items-center rounded-full border px-2 py-0.5 text-[11px] font-medium ${getStatusClasses(invoice.status)}`}>
            {invoice.status}
          </span>
          {invoice.documentType && (
            <span className={`inline-flex items-center rounded-full border px-1.5 py-0 text-[11px] font-medium ${documentTypeColors[invoice.documentType] || "bg-slate-50 text-slate-600 border-slate-200"}`}>
              {documentTypeLabels[invoice.documentType] || invoice.documentType}
            </span>
          )}
          {invoice.source && (
            <span className="text-[11px] text-muted-foreground">&middot; {invoice.source}{invoice.senderEmail ? ` from ${invoice.senderName || invoice.senderEmail}` : ""}</span>
          )}
        </div>

        <div className="flex items-center gap-1.5">
          <Button variant="outline" size="sm" className="gap-1 h-7 text-xs px-2.5" onClick={() => { window.open(`/api/invoices/${id}/metadata?access_token=${encodeURIComponent(token || "")}`, "_blank"); }}>
            <Download className="h-3 w-3" />JSON
          </Button>
          <Button variant="outline" size="sm" className="gap-1 h-7 text-xs px-2.5" onClick={() => vecticumMutation.mutate()} disabled={vecticumMutation.isPending || invoice.buyerMismatch} title={invoice.buyerMismatch ? "Buyer does not match company" : undefined}>
            <Send className="h-3 w-3" />Vecticum
          </Button>
          {(invoice.status === "failed" || invoice.status === "retrying") && (
            <Button variant="outline" size="sm" className="gap-1 h-7 text-xs px-2.5 text-amber-600 hover:text-amber-700 hover:bg-amber-50" onClick={() => retryMutation.mutate()} disabled={retryMutation.isPending}>
              <RotateCcw className="h-3 w-3" />Retry
            </Button>
          )}
          {hasCompanyRole("admin") && (
            <Button variant="outline" size="sm" className="gap-1 h-7 text-xs px-2.5 text-red-600 hover:text-red-700 hover:bg-red-50" onClick={() => { if (confirm("Delete this invoice?")) deleteMutation.mutate(); }}>
              <Trash2 className="h-3 w-3" />Delete
            </Button>
          )}
        </div>
      </div>

      {/* Error banner */}
      {invoice.status === "failed" && invoice.processingError && (
        <div className="flex items-center gap-2 rounded-lg border border-red-200 bg-red-50 px-3 py-2">
          <AlertCircle className="h-3.5 w-3.5 text-red-500 shrink-0" />
          <p className="text-xs text-red-700">{invoice.processingError}</p>
        </div>
      )}

      {/* Buyer mismatch warning */}
      {invoice.buyerMismatch && (
        <div className="flex items-center gap-2 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2">
          <AlertCircle className="h-3.5 w-3.5 text-amber-500 shrink-0" />
          <p className="text-xs text-amber-700">
            Buyer mismatch: invoice is addressed to <strong>{invoice.buyerName}</strong>{invoice.buyerVatId ? ` (${invoice.buyerVatId})` : ""} which doesn't match this company. This invoice may have been uploaded to the wrong company.
          </p>
        </div>
      )}

      <div className="grid grid-cols-1 lg:grid-cols-12 gap-3">
        {/* Left: Details */}
        <div className="lg:col-span-5 space-y-3">
          <Card className="overflow-hidden">
            <CardContent className="p-0">
              {/* Edit button row inside the card */}
              <div className="flex items-center justify-end px-4 pt-2.5 pb-0">
                {!editing ? (
                  <Button variant="outline" size="sm" onClick={startEdit} className="gap-1 h-7 text-xs px-2.5">
                    <Pencil className="h-3 w-3" />Edit
                  </Button>
                ) : (
                  <div className="flex items-center gap-1.5">
                    <Button size="sm" onClick={() => updateMutation.mutate(form)} disabled={updateMutation.isPending} className="gap-1 h-7 text-xs px-2.5">
                      <Save className="h-3 w-3" />Save
                    </Button>
                    <Button variant="outline" size="sm" onClick={() => setEditing(false)} className="gap-1 h-7 text-xs px-2.5">
                      <X className="h-3 w-3" />Cancel
                    </Button>
                  </div>
                )}
              </div>

              {fieldSections.map((section, si) => (
                <div key={section.title}>
                  {si > 0 && <div className="border-t border-border/50" />}
                  <div className="px-4 pt-3 pb-0.5">
                    <h3 className="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">{section.title}</h3>
                  </div>
                  <div className="px-4 pb-3 space-y-1.5">
                    {section.fields.map(([key, label]) => {
                      const value = (invoice as any)[key];
                      const conf = confidence[key];
                      return (
                        <div key={key} className="flex items-center gap-2">
                          <div className="flex items-center gap-1 w-24 shrink-0">
                            {!editing && <ConfidenceDot score={conf} />}
                            <span className="text-[11px] text-muted-foreground">{label}</span>
                          </div>
                          <div className="flex-1 min-w-0">
                            {editing ? (
                              key === "documentType" ? (
                                <select
                                  value={form[key] || ""}
                                  onChange={(e) => setForm({ ...form, [key]: e.target.value })}
                                  className="w-full h-7 rounded-md border border-border bg-card px-2 text-xs"
                                >
                                  <option value="">{"\u2014"}</option>
                                  <option value="invoice">Invoice</option>
                                  <option value="proforma">Proforma</option>
                                  <option value="credit_note">Credit Note</option>
                                </select>
                              ) : (
                                <Input value={form[key] || ""} onChange={(e) => setForm({ ...form, [key]: e.target.value })} className="h-7 text-xs" />
                              )
                            ) : key === "documentType" && value ? (
                              <span className={`inline-flex items-center rounded-full border px-1.5 py-0 text-[11px] font-medium ${documentTypeColors[value] || "bg-slate-50 text-slate-600"}`}>
                                {documentTypeLabels[value] || value}
                              </span>
                            ) : (
                              <span className="text-xs font-medium text-foreground truncate block">{value || "\u2014"}</span>
                            )}
                          </div>
                        </div>
                      );
                    })}
                  </div>
                </div>
              ))}

              {/* Bank details & payment terms */}
              {(invoice.bankDetails || invoice.paymentTerms) && (
                <>
                  <div className="border-t border-border/50" />
                  <div className="px-4 pt-3 pb-0.5">
                    <h3 className="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">Payment</h3>
                  </div>
                  <div className="px-4 pb-3 space-y-1.5">
                    {invoice.bankDetails && (
                      <div className="flex items-start gap-2">
                        <div className="flex items-center gap-1 w-24 shrink-0 pt-0.5">
                          <ConfidenceDot score={confidence.bankDetails} />
                          <span className="text-[11px] text-muted-foreground">Bank</span>
                        </div>
                        <span className="text-xs font-medium text-foreground whitespace-pre-wrap">{invoice.bankDetails}</span>
                      </div>
                    )}
                    {invoice.paymentTerms && (
                      <div className="flex items-start gap-2">
                        <div className="flex items-center gap-1 w-24 shrink-0 pt-0.5">
                          <ConfidenceDot score={confidence.paymentTerms} />
                          <span className="text-[11px] text-muted-foreground">Terms</span>
                        </div>
                        <span className="text-xs font-medium text-foreground">{invoice.paymentTerms}</span>
                      </div>
                    )}
                  </div>
                </>
              )}
            </CardContent>
          </Card>

          {/* Timeline */}
          <Card>
            <CardHeader className="pb-1 px-4 pt-3">
              <CardTitle className="text-xs font-semibold flex items-center gap-1.5">
                <Clock className="h-3 w-3 text-muted-foreground" />
                Timeline
              </CardTitle>
            </CardHeader>
            <CardContent className="px-4 pb-3">
              <div className="relative ml-2 space-y-3 border-l-2 border-border/60 pl-4">
                {timelineSteps.map((step, i) => (
                  <div key={i} className="relative flex items-start justify-between gap-2">
                    <span className={`absolute -left-[21px] top-0.5 h-2.5 w-2.5 rounded-full ring-3 ring-card ${step.active ? step.color : "bg-muted"}`} />
                    <div>
                      <span className="text-xs text-foreground">{step.label}</span>
                      {step.time && (
                        <span className="block text-[10px] text-muted-foreground">{formatRelativeTime(step.time)}</span>
                      )}
                    </div>
                    <span className="text-[10px] text-muted-foreground whitespace-nowrap">{fmtDateTime(step.time)}</span>
                  </div>
                ))}
                <div className="relative flex items-start justify-between gap-2">
                  <span className={`absolute -left-[21px] top-0.5 h-2.5 w-2.5 rounded-full ring-3 ring-card ${processingDurationMs !== null ? "bg-amber-500" : "bg-muted"}`} />
                  <span className="text-xs text-foreground">Duration</span>
                  <span className="text-[10px] font-medium text-foreground">{processingDuration}</span>
                </div>
              </div>
            </CardContent>
          </Card>

          {/* Metadata */}
          <Card>
            <CardContent className="py-3 px-4">
              <div className="space-y-1.5 text-[11px]">
                <div className="flex justify-between"><span className="text-muted-foreground">File</span><span className="font-medium text-foreground truncate ml-3">{invoice.originalFilename}</span></div>
                <div className="flex justify-between"><span className="text-muted-foreground">Size</span><span className="font-medium text-foreground">{invoice.fileSize ? `${(invoice.fileSize / 1024).toFixed(1)} KB` : "\u2014"}</span></div>
                <div className="flex justify-between"><span className="text-muted-foreground">ID</span><span className="font-mono text-muted-foreground">{invoice.id}</span></div>
                {invoice.vecticumId && (
                  <div className="flex justify-between"><span className="text-muted-foreground">Vecticum</span><span className="font-mono text-purple-600">{invoice.vecticumId}</span></div>
                )}
                {invoice.senderEmail && (
                  <div className="flex justify-between"><span className="text-muted-foreground">Sender</span><span className="text-foreground">{invoice.senderEmail}</span></div>
                )}
              </div>
            </CardContent>
          </Card>
        </div>

        {/* Right: Preview */}
        <div className="lg:col-span-7">
          <Card className="overflow-hidden sticky top-2">
            <CardHeader className="pb-1 px-4 pt-2">
              <div className="flex items-center justify-between">
                <CardTitle className="text-xs font-semibold">Preview</CardTitle>
                <a href={fileUrl} target="_blank" rel="noreferrer" className="text-[11px] text-muted-foreground hover:text-foreground transition-colors flex items-center gap-1">
                  <ExternalLink className="h-3 w-3" />Open
                </a>
              </div>
            </CardHeader>
            <CardContent className="p-2">
              {invoice.fileType === "pdf" ? (
                <iframe src={fileUrl} className="w-full h-[calc(100vh-8rem)] rounded-lg border border-border/50" />
              ) : (
                <img src={fileUrl} alt="Invoice" className="max-w-full rounded-lg" />
              )}
            </CardContent>
          </Card>
        </div>
      </div>
    </div>
  );
}
