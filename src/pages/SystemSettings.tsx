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
  });

  const { data } = useQuery({
    queryKey: ["settings"],
    queryFn: () => api.get("/settings").then((r) => r.data),
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

  return (
    <div className="space-y-4 max-w-2xl">
      <h2 className="text-xl font-semibold">System Settings</h2>

      <Card>
        <CardHeader>
          <CardTitle>API Configuration</CardTitle>
        </CardHeader>
        <CardContent className="space-y-3">
          <div>
            <label className="text-sm font-medium">Anthropic API Key</label>
            <Input
              type="password"
              value={form.anthropic_api_key}
              onChange={(e) => setForm({ ...form, anthropic_api_key: e.target.value })}
              placeholder="sk-ant-..."
            />
            <p className="text-xs text-gray-500 mt-1">Used for Claude invoice extraction</p>
          </div>
          <div>
            <label className="text-sm font-medium">Cron Secret</label>
            <Input
              type="password"
              value={form.cron_secret}
              onChange={(e) => setForm({ ...form, cron_secret: e.target.value })}
              placeholder="Secret key for cron endpoints"
            />
            <p className="text-xs text-gray-500 mt-1">Required for automated email fetch</p>
          </div>
        </CardContent>
      </Card>

      <Button onClick={() => saveMutation.mutate(form)} disabled={saveMutation.isPending}>
        <Save className="h-3 w-3 mr-1" />Save Settings
      </Button>
    </div>
  );
}
