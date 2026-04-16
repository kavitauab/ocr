import { useQuery, useMutation, useQueryClient } from "@tanstack/react-query";
import { useState, useEffect } from "react";
import api from "@/api/client";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Button } from "@/components/ui/button";
import { toast } from "sonner";
import { Save } from "lucide-react";

export default function SystemSettings() {
  const queryClient = useQueryClient();
  const [form, setForm] = useState<Record<string, string>>({
    anthropic_api_key: "",
    cron_secret: "",
    extraction_model: "",
    extraction_model_fast: "",
    classification_model: "",
    smart_extraction: "1",
    extraction_confidence_threshold: "0.9",
    critical_fields: "invoiceNumber,vendorName,totalAmount,currency",
    issue_reply_subject: "Re: {emailSubject}",
    issue_reply_body: "Hello {senderName},\n\nWe could not complete processing for \"{reference}\".\n\nIssue:\n{issue}\n\nPlease review the document and resend a corrected version if needed.\n\nRegards,\n{companyName}",
    auto_issue_reply_on_vecticum_failure: "1",
    auto_issue_reply_on_buyer_mismatch: "1",
  });

  const allCriticalFieldOptions: [string, string][] = [
    ["documentType", "Document Type"],
    ["invoiceNumber", "Invoice Number"],
    ["invoiceDate", "Invoice Date"],
    ["dueDate", "Due Date"],
    ["vendorName", "Vendor Name"],
    ["vendorAddress", "Vendor Address"],
    ["vendorVatId", "Vendor VAT ID"],
    ["buyerName", "Buyer Name"],
    ["buyerAddress", "Buyer Address"],
    ["buyerVatId", "Buyer VAT ID"],
    ["subtotalAmount", "Subtotal"],
    ["taxAmount", "Tax Amount"],
    ["totalAmount", "Total Amount"],
    ["currency", "Currency"],
    ["poNumber", "PO Number"],
    ["paymentTerms", "Payment Terms"],
    ["bankDetails", "Bank Details"],
  ];

  const criticalFieldsArr = (form.critical_fields || "").split(",").map(s => s.trim()).filter(Boolean);
  const toggleCriticalField = (key: string) => {
    const next = criticalFieldsArr.includes(key)
      ? criticalFieldsArr.filter(k => k !== key)
      : [...criticalFieldsArr, key];
    setForm({ ...form, critical_fields: next.join(",") });
  };

  const { data } = useQuery({
    queryKey: ["settings"],
    queryFn: () => api.get("/settings").then((r) => r.data),
  });

  const { data: modelsData } = useQuery({
    queryKey: ["models"],
    queryFn: () => api.get("/settings/models").then((r) => r.data).catch(() => ({ models: [] })),
  });

  useEffect(() => {
    if (data?.settings) {
      setForm((prev) => ({ ...prev, ...data.settings }));
    }
  }, [data]);

  const saveMutation = useMutation({
    mutationFn: (body: Record<string, string>) =>
      api.patch("/settings", body).then((r) => r.data),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["settings"] });
      toast.success("Settings saved");
    },
    onError: (err: any) => toast.error(err.response?.data?.error || "Failed to save"),
  });

  const models = modelsData?.models || [];

  return (
    <div className="space-y-4">
      <div>
        <h2 className="text-2xl font-bold tracking-tight text-foreground">System Settings</h2>
        <p className="text-sm text-muted-foreground mt-0.5">Global configuration for the OCR system</p>
      </div>

      <Card>
        <CardHeader>
          <CardTitle>API Configuration</CardTitle>
        </CardHeader>
        <CardContent className="space-y-4">
          <div className="space-y-1.5">
            <label className="text-sm font-medium text-foreground">Anthropic API Key</label>
            <Input
              type="password"
              value={form.anthropic_api_key}
              onChange={(e) => setForm({ ...form, anthropic_api_key: e.target.value })}
              placeholder="sk-ant-..."
            />
            <p className="text-xs text-muted-foreground mt-1">Used for Claude invoice extraction</p>
          </div>
          <div className="space-y-1.5">
            <label className="text-sm font-medium text-foreground">Cron Secret</label>
            <Input
              type="password"
              value={form.cron_secret}
              onChange={(e) => setForm({ ...form, cron_secret: e.target.value })}
              placeholder="Secret key for cron endpoints"
            />
            <p className="text-xs text-muted-foreground mt-1">Required for automated email fetch</p>
          </div>
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle>Issue Reply Email</CardTitle>
        </CardHeader>
        <CardContent className="space-y-4">
          <label className="flex items-center gap-2 text-sm font-medium text-foreground">
            <input
              type="checkbox"
              checked={form.auto_issue_reply_on_vecticum_failure !== "0"}
              onChange={(e) => setForm({ ...form, auto_issue_reply_on_vecticum_failure: e.target.checked ? "1" : "0" })}
            />
            Auto-send reply when Vecticum upload fails
          </label>
          <label className="flex items-center gap-2 text-sm font-medium text-foreground">
            <input
              type="checkbox"
              checked={form.auto_issue_reply_on_buyer_mismatch !== "0"}
              onChange={(e) => setForm({ ...form, auto_issue_reply_on_buyer_mismatch: e.target.checked ? "1" : "0" })}
            />
            Auto-send reply when invoice is for the wrong company
          </label>
          <div className="space-y-1.5">
            <label className="text-sm font-medium text-foreground">Default Subject</label>
            <Input
              value={form.issue_reply_subject || ""}
              onChange={(e) => setForm({ ...form, issue_reply_subject: e.target.value })}
              placeholder="Re: {emailSubject}"
            />
            <p className="text-xs text-muted-foreground">Used when replying to invoice senders about processing or Vecticum issues.</p>
          </div>
          <div className="space-y-1.5">
            <label className="text-sm font-medium text-foreground">Default Body</label>
            <textarea
              value={form.issue_reply_body || ""}
              onChange={(e) => setForm({ ...form, issue_reply_body: e.target.value })}
              className="min-h-56 w-full rounded-md border border-border bg-background px-3 py-2 text-sm text-foreground"
            />
            <p className="text-xs text-muted-foreground">
              Available placeholders: {"{senderName}"}, {"{senderEmail}"}, {"{reference}"}, {"{invoiceNumber}"}, {"{fileName}"}, {"{emailSubject}"}, {"{companyName}"}, {"{issue}"}, {"{vecticumError}"}, {"{processingError}"}.
            </p>
            <p className="text-xs text-muted-foreground">
              Use blank lines to create paragraphs. Replies are now sent as formatted HTML email so Outlook keeps the spacing.
            </p>
          </div>
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle>AI Models</CardTitle>
        </CardHeader>
        <CardContent className="space-y-4">
          <label className="flex items-center gap-2 text-sm font-medium text-foreground">
            <input type="checkbox" checked={form.smart_extraction !== "0"} onChange={(e) => setForm({ ...form, smart_extraction: e.target.checked ? "1" : "0" })} />
            Smart Extraction (try cheap model first, escalate if low confidence)
          </label>
          <p className="text-xs text-muted-foreground -mt-2">Saves ~80% on well-formatted invoices by using the fast model first</p>

          <div className="space-y-1.5">
            <label className="text-sm font-medium text-foreground">Primary Extraction Model</label>
            <select
              value={form.extraction_model || ""}
              onChange={(e) => setForm({ ...form, extraction_model: e.target.value })}
              className="w-full border border-border rounded-md px-3 py-1.5 text-sm bg-background text-foreground"
            >
              <option value="">Default (claude-sonnet-4-6)</option>
              {models.map((m: any) => (
                <option key={m.id} value={m.id}>{m.name} ({m.id})</option>
              ))}
            </select>
            <p className="text-xs text-muted-foreground">High-accuracy model used for extraction (or as fallback when smart extraction escalates)</p>
          </div>
          <div className="space-y-1.5">
            <label className="text-sm font-medium text-foreground">Fast Extraction Model</label>
            <select
              value={form.extraction_model_fast || ""}
              onChange={(e) => setForm({ ...form, extraction_model_fast: e.target.value })}
              className="w-full border border-border rounded-md px-3 py-1.5 text-sm bg-background text-foreground"
            >
              <option value="">Default (claude-haiku-4-5-20251001)</option>
              {models.map((m: any) => (
                <option key={m.id} value={m.id}>{m.name} ({m.id})</option>
              ))}
            </select>
            <p className="text-xs text-muted-foreground">Cheap model tried first when smart extraction is enabled (~3x cheaper)</p>
          </div>
          <div className="space-y-1.5">
            <label className="text-sm font-medium text-foreground">Confidence Threshold</label>
            <Input
              type="number"
              step="0.05"
              min="0"
              max="1"
              value={form.extraction_confidence_threshold || "0.9"}
              onChange={(e) => setForm({ ...form, extraction_confidence_threshold: e.target.value })}
              className="w-32"
            />
            <p className="text-xs text-muted-foreground">Minimum confidence score on critical fields to accept cheap model result. Below this → escalate to primary model. (0.0-1.0)</p>
          </div>
          <div className="space-y-1.5">
            <label className="text-sm font-medium text-foreground">Critical Fields for Escalation</label>
            <p className="text-xs text-muted-foreground">Only these fields trigger escalation to the primary model when confidence is below the threshold. Non-critical fields are accepted as-is from the fast model to save costs.</p>
            <div className="grid grid-cols-2 gap-2 pt-1">
              {allCriticalFieldOptions.map(([key, label]) => (
                <label key={key} className="flex items-center gap-2 text-sm py-1 text-foreground">
                  <input
                    type="checkbox"
                    checked={criticalFieldsArr.includes(key)}
                    onChange={() => toggleCriticalField(key)}
                  />
                  {label}
                </label>
              ))}
            </div>
          </div>
          <div className="space-y-1.5">
            <label className="text-sm font-medium text-foreground">Classification Model</label>
            <select
              value={form.classification_model || ""}
              onChange={(e) => setForm({ ...form, classification_model: e.target.value })}
              className="w-full border border-border rounded-md px-3 py-1.5 text-sm bg-background text-foreground"
            >
              <option value="">Default (claude-haiku-4-5-20251001)</option>
              {models.map((m: any) => (
                <option key={m.id} value={m.id}>{m.name} ({m.id})</option>
              ))}
            </select>
            <p className="text-xs text-muted-foreground">Model used for document type classification (invoice vs act vs other)</p>
          </div>
        </CardContent>
      </Card>

      <Button onClick={() => saveMutation.mutate(form)} disabled={saveMutation.isPending}>
        <Save className="h-3.5 w-3.5" /><span className="ml-1">Save Settings</span>
      </Button>
    </div>
  );
}
