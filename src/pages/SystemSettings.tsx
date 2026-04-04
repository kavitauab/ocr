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
    classification_model: "",
  });

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
          <CardTitle>AI Models</CardTitle>
        </CardHeader>
        <CardContent className="space-y-4">
          <div className="space-y-1.5">
            <label className="text-sm font-medium text-foreground">Extraction Model</label>
            <select
              value={form.extraction_model || ""}
              onChange={(e) => setForm({ ...form, extraction_model: e.target.value })}
              className="w-full border border-border rounded-md px-3 py-1.5 text-sm bg-background text-foreground"
            >
              <option value="">Default (claude-sonnet-4-20250514)</option>
              {models.map((m: any) => (
                <option key={m.id} value={m.id}>{m.name} ({m.id})</option>
              ))}
            </select>
            <p className="text-xs text-muted-foreground">Model used for full invoice data extraction (higher accuracy, more tokens)</p>
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
            <p className="text-xs text-muted-foreground">Model used for document type classification (cheaper, faster)</p>
          </div>
        </CardContent>
      </Card>

      <Button onClick={() => saveMutation.mutate(form)} disabled={saveMutation.isPending}>
        <Save className="h-3.5 w-3.5" /><span className="ml-1">Save Settings</span>
      </Button>
    </div>
  );
}
