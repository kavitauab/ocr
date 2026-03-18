import { useQuery, useMutation, useQueryClient } from "@tanstack/react-query";
import { useParams, useNavigate } from "react-router-dom";
import { useState, useEffect, useMemo } from "react";
import api from "@/api/client";
import { useAuth } from "@/lib/auth";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Select } from "@/components/ui/select";
import { Skeleton } from "@/components/ui/skeleton";
import { toast } from "sonner";
import { ArrowLeft, Save } from "lucide-react";
import { Navigate } from "react-router-dom";

const PLAN_OPTIONS = ["free", "starter", "professional", "enterprise"];
const STATUS_OPTIONS = ["active", "suspended", "cancelled"];

function pickFirst(record: Record<string, unknown>, keys: string[]): unknown {
  for (const key of keys) {
    const value = record[key];
    if (value !== null && value !== undefined) return value;
  }
  return null;
}

function toNullableString(value: unknown): string | null {
  if (value === null || value === undefined) return null;
  const text = String(value).trim();
  return text.length > 0 ? text : null;
}

function toInputValue(value: unknown): string {
  if (value === null || value === undefined) return "";
  return String(value);
}

function parseInputNumber(raw: string, label: string, integer = false): number | null {
  const trimmed = raw.trim();
  if (!trimmed) return null;
  const value = Number(trimmed);
  if (!Number.isFinite(value) || (integer && !Number.isInteger(value))) {
    throw new Error(`${label} must be ${integer ? "a whole number" : "a valid number"}`);
  }
  return value;
}

export default function BillingEdit() {
  const { id } = useParams<{ id: string }>();
  const navigate = useNavigate();
  const queryClient = useQueryClient();
  const { user } = useAuth();
  const isSuperadmin = user?.role === "superadmin";

  const [form, setForm] = useState({
    plan: "free",
    status: "active",
    invoiceLimit: "",
    storageLimitBytes: "",
    includedTokens: "",
    overagePer1kTokensUsd: "",
    overagePerInvoiceUsd: "",
    rateLimitPerHour: "",
    rateLimitPerDay: "",
  });

  const { data, isLoading } = useQuery({
    queryKey: ["subscription", id],
    queryFn: () => api.get(`/subscriptions/${id}`).then((r) => r.data),
    enabled: !!id,
  });

  useEffect(() => {
    if (data?.subscription) {
      const s = data.subscription;
      setForm({
        plan: toNullableString(pickFirst(s, ["plan", "subscriptionPlan", "subscription_plan"])) ?? "free",
        status: toNullableString(pickFirst(s, ["status", "billingStatus", "billing_status", "subscriptionStatus", "subscription_status"])) ?? "active",
        invoiceLimit: toInputValue(pickFirst(s, ["invoiceLimit", "invoice_limit"])),
        storageLimitBytes: toInputValue(pickFirst(s, ["storageLimitBytes", "storage_limit_bytes"])),
        includedTokens: toInputValue(pickFirst(s, ["includedTokens", "included_tokens", "tokenLimit", "token_limit"])),
        overagePer1kTokensUsd: toInputValue(pickFirst(s, ["overagePer1kTokensUsd", "overage_per_1k_tokens_usd", "overageRateUsd", "overage_rate_usd"])),
        overagePerInvoiceUsd: toInputValue(pickFirst(s, ["overagePerInvoiceUsd", "overage_per_invoice_usd", "overageRatePerInvoiceUsd"])),
        rateLimitPerHour: toInputValue(pickFirst(s, ["rateLimitPerHour", "rate_limit_per_hour"])),
        rateLimitPerDay: toInputValue(pickFirst(s, ["rateLimitPerDay", "rate_limit_per_day"])),
      });
    }
  }, [data]);

  const companyName = data?.subscription
    ? toNullableString(pickFirst(data.subscription, ["companyName", "company_name", "name"])) ?? "Unknown"
    : "";

  const planOptions = useMemo(() => {
    const current = form.plan.trim();
    if (current && !PLAN_OPTIONS.includes(current)) return [current, ...PLAN_OPTIONS];
    return PLAN_OPTIONS;
  }, [form.plan]);

  const statusOptions = useMemo(() => {
    const current = form.status.trim();
    if (current && !STATUS_OPTIONS.includes(current)) return [current, ...STATUS_OPTIONS];
    return STATUS_OPTIONS;
  }, [form.status]);

  const updateMutation = useMutation({
    mutationFn: (payload: Record<string, unknown>) =>
      api.patch(`/subscriptions/${id}`, payload).then((r) => r.data),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["subscriptions"] });
      queryClient.invalidateQueries({ queryKey: ["subscription", id] });
      toast.success("Billing settings saved");
    },
    onError: (err: any) => {
      toast.error(err?.response?.data?.error || err?.message || "Failed to save billing settings");
    },
  });

  if (!isSuperadmin) {
    return <Navigate to="/dashboard" replace />;
  }

  const save = () => {
    try {
      const payload: Record<string, unknown> = {
        plan: form.plan.trim(),
        status: form.status.trim(),
        invoice_limit: parseInputNumber(form.invoiceLimit, "Invoice limit", true),
        storage_limit_bytes: parseInputNumber(form.storageLimitBytes, "Storage limit", true),
        included_tokens: parseInputNumber(form.includedTokens, "Included tokens", true),
        overage_per_1k_tokens_usd: parseInputNumber(form.overagePer1kTokensUsd, "Overage per 1k tokens"),
        overage_per_invoice_usd: parseInputNumber(form.overagePerInvoiceUsd, "Overage per invoice"),
        rate_limit_per_hour: parseInputNumber(form.rateLimitPerHour, "Rate limit per hour", true),
        rate_limit_per_day: parseInputNumber(form.rateLimitPerDay, "Rate limit per day", true),
      };
      updateMutation.mutate(payload);
    } catch (err: any) {
      toast.error(err?.message || "Please check billing values");
    }
  };

  return (
    <div className="space-y-4">
      <div className="flex items-center gap-4">
        <Button variant="ghost" size="icon" onClick={() => navigate("/settings/billing")}>
          <ArrowLeft className="h-4 w-4" />
        </Button>
        <div>
          <h2 className="text-2xl font-bold tracking-tight text-foreground">Edit Billing</h2>
          {companyName && <p className="text-sm text-muted-foreground mt-0.5">{companyName}</p>}
        </div>
      </div>

      {isLoading ? (
        <Card>
          <CardHeader><Skeleton className="h-5 w-40" /></CardHeader>
          <CardContent className="space-y-4">
            {[...Array(4)].map((_, i) => (
              <div key={i} className="space-y-1.5">
                <Skeleton className="h-4 w-24" />
                <Skeleton className="h-10 w-full" />
              </div>
            ))}
          </CardContent>
        </Card>
      ) : (
        <>
          <Card>
            <CardHeader><CardTitle>Plan & Status</CardTitle></CardHeader>
            <CardContent>
              <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div className="space-y-1.5">
                  <label className="text-sm font-medium text-foreground">Plan</label>
                  <Select value={form.plan} onChange={(e) => setForm((prev) => ({ ...prev, plan: e.target.value }))}>
                    {planOptions.map((plan) => (
                      <option key={plan} value={plan}>{plan}</option>
                    ))}
                  </Select>
                </div>
                <div className="space-y-1.5">
                  <label className="text-sm font-medium text-foreground">Status</label>
                  <Select value={form.status} onChange={(e) => setForm((prev) => ({ ...prev, status: e.target.value }))}>
                    {statusOptions.map((status) => (
                      <option key={status} value={status}>{status}</option>
                    ))}
                  </Select>
                </div>
              </div>
            </CardContent>
          </Card>

          <Card>
            <CardHeader><CardTitle>Limits</CardTitle></CardHeader>
            <CardContent>
              <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div className="space-y-1.5">
                  <label className="text-sm font-medium text-foreground">Invoice Limit</label>
                  <Input type="number" value={form.invoiceLimit} onChange={(e) => setForm((prev) => ({ ...prev, invoiceLimit: e.target.value }))} placeholder="e.g. 1000" />
                </div>
                <div className="space-y-1.5">
                  <label className="text-sm font-medium text-foreground">Storage Limit (bytes)</label>
                  <Input type="number" value={form.storageLimitBytes} onChange={(e) => setForm((prev) => ({ ...prev, storageLimitBytes: e.target.value }))} placeholder="e.g. 1073741824" />
                </div>
                <div className="space-y-1.5">
                  <label className="text-sm font-medium text-foreground">Included Tokens</label>
                  <Input type="number" value={form.includedTokens} onChange={(e) => setForm((prev) => ({ ...prev, includedTokens: e.target.value }))} placeholder="e.g. 1000000" />
                </div>
              </div>
            </CardContent>
          </Card>

          <Card>
            <CardHeader><CardTitle>Rate Limits</CardTitle></CardHeader>
            <CardContent>
              <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div className="space-y-1.5">
                  <label className="text-sm font-medium text-foreground">Invoices per Hour</label>
                  <Input type="number" value={form.rateLimitPerHour} onChange={(e) => setForm((prev) => ({ ...prev, rateLimitPerHour: e.target.value }))} placeholder="Unlimited" />
                  <p className="text-xs text-muted-foreground">Leave empty for unlimited</p>
                </div>
                <div className="space-y-1.5">
                  <label className="text-sm font-medium text-foreground">Invoices per Day</label>
                  <Input type="number" value={form.rateLimitPerDay} onChange={(e) => setForm((prev) => ({ ...prev, rateLimitPerDay: e.target.value }))} placeholder="Unlimited" />
                  <p className="text-xs text-muted-foreground">Leave empty for unlimited</p>
                </div>
              </div>
            </CardContent>
          </Card>

          <Card>
            <CardHeader><CardTitle>Overage Pricing</CardTitle></CardHeader>
            <CardContent>
              <div className="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div className="space-y-1.5">
                  <label className="text-sm font-medium text-foreground">Overage per 1k Tokens (USD)</label>
                  <Input type="number" step="0.000001" value={form.overagePer1kTokensUsd} onChange={(e) => setForm((prev) => ({ ...prev, overagePer1kTokensUsd: e.target.value }))} placeholder="e.g. 0.005" />
                </div>
                <div className="space-y-1.5">
                  <label className="text-sm font-medium text-foreground">Overage per Invoice (USD)</label>
                  <Input type="number" step="0.000001" value={form.overagePerInvoiceUsd} onChange={(e) => setForm((prev) => ({ ...prev, overagePerInvoiceUsd: e.target.value }))} placeholder="e.g. 0.50" />
                </div>
              </div>
            </CardContent>
          </Card>

          <Button onClick={save} disabled={updateMutation.isPending}>
            <Save className="h-3.5 w-3.5" />
            <span className="ml-1">{updateMutation.isPending ? "Saving..." : "Save Changes"}</span>
          </Button>
        </>
      )}
    </div>
  );
}
