import { useMemo, useState } from "react";
import { Navigate } from "react-router-dom";
import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";
import api from "@/api/client";
import { useAuth } from "@/lib/auth";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Card, CardContent } from "@/components/ui/card";
import { Table, TableHeader, TableBody, TableRow, TableHead, TableCell } from "@/components/ui/table";
import { Badge } from "@/components/ui/badge";
import { Dialog, DialogTitle } from "@/components/ui/dialog";
import { Select } from "@/components/ui/select";
import { toast } from "sonner";
import { Pencil } from "lucide-react";

type JsonRecord = Record<string, unknown>;
type SubscriptionRow = JsonRecord;

interface BillingFormState {
  plan: string;
  status: string;
  invoiceLimit: string;
  storageLimitBytes: string;
  includedTokens: string;
  overageRateInputUsd: string;
  overageRateOutputUsd: string;
}

const PLAN_OPTIONS = ["free", "starter", "professional", "enterprise"];
const STATUS_OPTIONS = ["active", "suspended", "cancelled"];

function isRecord(value: unknown): value is JsonRecord {
  return typeof value === "object" && value !== null && !Array.isArray(value);
}

function getErrorMessage(error: unknown, fallback: string): string {
  if (isRecord(error)) {
    const response = error.response;
    if (isRecord(response)) {
      const data = response.data;
      if (isRecord(data)) {
        const message = data.error ?? data.message;
        if (typeof message === "string" && message.trim()) return message;
      }
    }
  }
  if (error instanceof Error && error.message.trim()) return error.message;
  return fallback;
}

function pickFirst(record: JsonRecord, keys: string[]): unknown {
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

function toNullableNumber(value: unknown): number | null {
  if (value === null || value === undefined || value === "") return null;
  const num = typeof value === "number" ? value : Number(value);
  return Number.isFinite(num) ? num : null;
}

function toInputValue(value: number | null): string {
  return value === null ? "" : String(value);
}

function formatNumber(value: number | null): string {
  if (value === null) return "—";
  return new Intl.NumberFormat("en-US").format(value);
}

function formatRate(value: number | null): string {
  if (value === null) return "—";
  return new Intl.NumberFormat("en-US", { maximumFractionDigits: 6 }).format(value);
}

function formatBytes(value: number | null): string {
  if (value === null) return "—";
  if (value === 0) return "0 B";
  const units = ["B", "KB", "MB", "GB", "TB"];
  let nextValue = Math.abs(value);
  let unitIndex = 0;
  while (nextValue >= 1024 && unitIndex < units.length - 1) {
    nextValue /= 1024;
    unitIndex += 1;
  }
  const decimals = nextValue >= 100 ? 0 : nextValue >= 10 ? 1 : 2;
  const signedValue = value < 0 ? -nextValue : nextValue;
  return `${signedValue.toFixed(decimals)} ${units[unitIndex]}`;
}

function extractRows(payload: unknown): SubscriptionRow[] {
  if (Array.isArray(payload)) return payload.filter(isRecord);
  if (!isRecord(payload)) return [];
  const listCandidate = payload.subscriptions ?? payload.items ?? payload.data;
  return Array.isArray(listCandidate) ? listCandidate.filter(isRecord) : [];
}

function getCompanyRecord(row: SubscriptionRow): JsonRecord | null {
  return isRecord(row.company) ? row.company : null;
}

function getCompanyId(row: SubscriptionRow): string {
  const company = getCompanyRecord(row);
  const nestedId = company ? toNullableString(pickFirst(company, ["id", "companyId", "company_id"])) : null;
  if (nestedId) return nestedId;
  return toNullableString(pickFirst(row, ["companyId", "company_id", "id"])) ?? "";
}

function getCompanyName(row: SubscriptionRow): string {
  const company = getCompanyRecord(row);
  const nestedName = company ? toNullableString(pickFirst(company, ["name", "companyName", "company_name"])) : null;
  if (nestedName) return nestedName;
  return toNullableString(pickFirst(row, ["companyName", "company_name", "name"])) ?? "Unknown company";
}

function getCompanyCode(row: SubscriptionRow): string | null {
  const company = getCompanyRecord(row);
  const nestedCode = company ? toNullableString(pickFirst(company, ["code", "companyCode", "company_code"])) : null;
  if (nestedCode) return nestedCode;
  return toNullableString(pickFirst(row, ["companyCode", "company_code", "code"]));
}

function getPlan(row: SubscriptionRow): string {
  return toNullableString(pickFirst(row, ["plan", "subscriptionPlan", "subscription_plan"])) ?? "—";
}

function getStatus(row: SubscriptionRow): string {
  return toNullableString(
    pickFirst(row, ["status", "billingStatus", "billing_status", "subscriptionStatus", "subscription_status"])
  ) ?? "—";
}

function getInvoiceLimit(row: SubscriptionRow): number | null {
  return toNullableNumber(pickFirst(row, ["invoiceLimit", "invoice_limit"]));
}

function getStorageLimitBytes(row: SubscriptionRow): number | null {
  return toNullableNumber(pickFirst(row, ["storageLimitBytes", "storage_limit_bytes"]));
}

function getIncludedTokens(row: SubscriptionRow): number | null {
  return toNullableNumber(pickFirst(row, ["includedTokens", "included_tokens", "tokenLimit", "token_limit"]));
}

function getTokenUsage(row: SubscriptionRow): number | null {
  return toNullableNumber(pickFirst(row, ["tokenUsage", "totalTokens", "tokensUsed", "tokens_used"]));
}

function getOverageRateInput(row: SubscriptionRow): number | null {
  return (
    toNullableNumber(pickFirst(row, ["overageRateInputUsd", "overage_rate_input_usd"])) ??
    toNullableNumber(pickFirst(row, ["overageRateUsd", "overage_rate_usd", "overageRate", "overage_rate"]))
  );
}

function getOverageRateOutput(row: SubscriptionRow): number | null {
  return (
    toNullableNumber(pickFirst(row, ["overageRateOutputUsd", "overage_rate_output_usd"])) ??
    toNullableNumber(pickFirst(row, ["overageRateUsd", "overage_rate_usd", "overageRate", "overage_rate"]))
  );
}

function getStatusBadge(status: string): "default" | "secondary" | "destructive" | "outline" {
  const normalized = status.toLowerCase();
  if (normalized === "active") return "default";
  if (normalized === "suspended" || normalized === "cancelled") return "destructive";
  if (normalized === "—") return "outline";
  return "secondary";
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

const EMPTY_FORM: BillingFormState = {
  plan: "free",
  status: "active",
  invoiceLimit: "",
  storageLimitBytes: "",
  includedTokens: "",
  overageRateInputUsd: "",
  overageRateOutputUsd: "",
};

export default function Billing() {
  const { user } = useAuth();
  const queryClient = useQueryClient();
  const [search, setSearch] = useState("");
  const [editingRow, setEditingRow] = useState<SubscriptionRow | null>(null);
  const [form, setForm] = useState<BillingFormState>(EMPTY_FORM);

  const isSuperadmin = user?.role === "superadmin";

  const { data, isLoading, isError, error, refetch, isFetching } = useQuery({
    queryKey: ["subscriptions"],
    queryFn: () => api.get("/subscriptions").then((r) => r.data as unknown),
  });

  const rows = useMemo(() => extractRows(data), [data]);

  const filteredRows = useMemo(() => {
    const term = search.trim().toLowerCase();
    if (!term) return rows;
    return rows.filter((row) => {
      const searchable = [
        getCompanyName(row),
        getCompanyCode(row) ?? "",
        getCompanyId(row),
        getPlan(row),
        getStatus(row),
      ]
        .join(" ")
        .toLowerCase();
      return searchable.includes(term);
    });
  }, [rows, search]);

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
    mutationFn: ({ companyId, payload }: { companyId: string; payload: Record<string, unknown> }) =>
      api.patch(`/subscriptions/${companyId}`, payload).then((r) => r.data),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["subscriptions"] });
      toast.success("Billing settings saved");
      setEditingRow(null);
    },
    onError: (mutationError: unknown) => {
      toast.error(getErrorMessage(mutationError, "Failed to save billing settings"));
    },
  });

  if (!isSuperadmin) {
    return <Navigate to="/dashboard" replace />;
  }

  const openEditDialog = (row: SubscriptionRow) => {
    setEditingRow(row);
    setForm({
      plan: toNullableString(pickFirst(row, ["plan", "subscriptionPlan", "subscription_plan"])) ?? "free",
      status:
        toNullableString(
          pickFirst(row, ["status", "billingStatus", "billing_status", "subscriptionStatus", "subscription_status"])
        ) ?? "active",
      invoiceLimit: toInputValue(getInvoiceLimit(row)),
      storageLimitBytes: toInputValue(getStorageLimitBytes(row)),
      includedTokens: toInputValue(getIncludedTokens(row)),
      overageRateInputUsd: toInputValue(getOverageRateInput(row)),
      overageRateOutputUsd: toInputValue(getOverageRateOutput(row)),
    });
  };

  const closeEditDialog = () => {
    if (updateMutation.isPending) return;
    setEditingRow(null);
    setForm(EMPTY_FORM);
  };

  const save = () => {
    if (!editingRow) return;
    const companyId = getCompanyId(editingRow);
    if (!companyId) {
      toast.error("Selected client is missing company ID");
      return;
    }

    try {
      const payload: Record<string, unknown> = {
        plan: form.plan.trim(),
        status: form.status.trim(),
        invoice_limit: parseInputNumber(form.invoiceLimit, "Invoice limit", true),
        storage_limit_bytes: parseInputNumber(form.storageLimitBytes, "Storage limit", true),
        included_tokens: parseInputNumber(form.includedTokens, "Included tokens", true),
        overage_rate_input_usd: parseInputNumber(form.overageRateInputUsd, "Overage input rate"),
        overage_rate_output_usd: parseInputNumber(form.overageRateOutputUsd, "Overage output rate"),
      };
      updateMutation.mutate({ companyId, payload });
    } catch (saveError) {
      toast.error(getErrorMessage(saveError, "Please check billing values"));
    }
  };

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <h2 className="text-xl font-semibold">Billing</h2>
        <div className="text-xs text-gray-500">
          {isFetching && !isLoading ? "Refreshing..." : `${rows.length} clients`}
        </div>
      </div>

      <Card>
        <CardContent className="p-4 border-b">
          <div className="flex flex-wrap items-center gap-2">
            <Input
              value={search}
              onChange={(event) => setSearch(event.target.value)}
              placeholder="Search by company, code, plan, status..."
              className="w-full sm:w-80"
            />
            {search && (
              <Button variant="outline" size="sm" onClick={() => setSearch("")}>
                Clear
              </Button>
            )}
          </div>
        </CardContent>
        <CardContent className="p-0">
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>Client</TableHead>
                <TableHead>Plan</TableHead>
                <TableHead>Status</TableHead>
                <TableHead className="text-right">Invoice Limit</TableHead>
                <TableHead className="text-right">Storage Limit</TableHead>
                <TableHead className="text-right">Included Tokens</TableHead>
                <TableHead className="text-right">Used Tokens</TableHead>
                <TableHead className="text-right">Overage In</TableHead>
                <TableHead className="text-right">Overage Out</TableHead>
                <TableHead />
              </TableRow>
            </TableHeader>
            <TableBody>
              {isLoading && (
                <TableRow>
                  <TableCell colSpan={10} className="text-center py-8 text-gray-500">
                    Loading billing settings...
                  </TableCell>
                </TableRow>
              )}

              {!isLoading && isError && (
                <TableRow>
                  <TableCell colSpan={10} className="py-6">
                    <div className="flex flex-wrap items-center justify-center gap-2 text-sm text-red-600">
                      <span>{getErrorMessage(error, "Failed to load billing settings")}</span>
                      <Button variant="outline" size="sm" onClick={() => refetch()}>
                        Retry
                      </Button>
                    </div>
                  </TableCell>
                </TableRow>
              )}

              {!isLoading &&
                !isError &&
                filteredRows.map((row, index) => {
                  const companyId = getCompanyId(row);
                  const companyCode = getCompanyCode(row);
                  const status = getStatus(row);
                  return (
                    <TableRow key={companyId || `${getCompanyName(row)}-${index}`}>
                      <TableCell>
                        <div className="font-medium">{getCompanyName(row)}</div>
                        {companyCode && <div className="text-xs text-gray-500">{companyCode}</div>}
                      </TableCell>
                      <TableCell>{getPlan(row)}</TableCell>
                      <TableCell>
                        <Badge variant={getStatusBadge(status)}>{status}</Badge>
                      </TableCell>
                      <TableCell className="text-right tabular-nums">{formatNumber(getInvoiceLimit(row))}</TableCell>
                      <TableCell className="text-right tabular-nums">{formatBytes(getStorageLimitBytes(row))}</TableCell>
                      <TableCell className="text-right tabular-nums">{formatNumber(getIncludedTokens(row))}</TableCell>
                      <TableCell className="text-right tabular-nums">{formatNumber(getTokenUsage(row))}</TableCell>
                      <TableCell className="text-right tabular-nums">{formatRate(getOverageRateInput(row))}</TableCell>
                      <TableCell className="text-right tabular-nums">{formatRate(getOverageRateOutput(row))}</TableCell>
                      <TableCell className="text-right">
                        <Button variant="outline" size="sm" onClick={() => openEditDialog(row)}>
                          <Pencil className="h-3.5 w-3.5 mr-1" />
                          Edit
                        </Button>
                      </TableCell>
                    </TableRow>
                  );
                })}

              {!isLoading && !isError && filteredRows.length === 0 && (
                <TableRow>
                  <TableCell colSpan={10} className="text-center py-8 text-gray-500">
                    {rows.length === 0
                      ? "No client billing data found."
                      : "No clients match your search."}
                  </TableCell>
                </TableRow>
              )}
            </TableBody>
          </Table>
        </CardContent>
      </Card>

      <Dialog open={!!editingRow} onClose={closeEditDialog}>
        <DialogTitle>Edit Billing</DialogTitle>
        <p className="text-sm text-gray-500 mt-1">
          {editingRow ? getCompanyName(editingRow) : "Client"}
        </p>

        <div className="grid grid-cols-1 sm:grid-cols-2 gap-3 mt-4">
          <div>
            <label className="text-sm font-medium">Plan</label>
            <Select value={form.plan} onChange={(event) => setForm((prev) => ({ ...prev, plan: event.target.value }))}>
              {planOptions.map((plan) => (
                <option key={plan} value={plan}>
                  {plan}
                </option>
              ))}
            </Select>
          </div>

          <div>
            <label className="text-sm font-medium">Status</label>
            <Select
              value={form.status}
              onChange={(event) => setForm((prev) => ({ ...prev, status: event.target.value }))}
            >
              {statusOptions.map((status) => (
                <option key={status} value={status}>
                  {status}
                </option>
              ))}
            </Select>
          </div>

          <div>
            <label className="text-sm font-medium">Invoice Limit</label>
            <Input
              type="number"
              value={form.invoiceLimit}
              onChange={(event) => setForm((prev) => ({ ...prev, invoiceLimit: event.target.value }))}
              placeholder="e.g. 1000"
            />
          </div>

          <div>
            <label className="text-sm font-medium">Storage Limit (bytes)</label>
            <Input
              type="number"
              value={form.storageLimitBytes}
              onChange={(event) => setForm((prev) => ({ ...prev, storageLimitBytes: event.target.value }))}
              placeholder="e.g. 1073741824"
            />
          </div>

          <div>
            <label className="text-sm font-medium">Included Tokens</label>
            <Input
              type="number"
              value={form.includedTokens}
              onChange={(event) => setForm((prev) => ({ ...prev, includedTokens: event.target.value }))}
              placeholder="e.g. 1000000"
            />
          </div>

          <div>
            <label className="text-sm font-medium">Overage In Rate (USD)</label>
            <Input
              type="number"
              step="0.000001"
              value={form.overageRateInputUsd}
              onChange={(event) => setForm((prev) => ({ ...prev, overageRateInputUsd: event.target.value }))}
              placeholder="e.g. 0.005"
            />
          </div>

          <div>
            <label className="text-sm font-medium">Overage Out Rate (USD)</label>
            <Input
              type="number"
              step="0.000001"
              value={form.overageRateOutputUsd}
              onChange={(event) => setForm((prev) => ({ ...prev, overageRateOutputUsd: event.target.value }))}
              placeholder="e.g. 0.015"
            />
          </div>
        </div>

        <div className="flex justify-end gap-2 mt-5">
          <Button variant="outline" onClick={closeEditDialog} disabled={updateMutation.isPending}>
            Cancel
          </Button>
          <Button onClick={save} disabled={updateMutation.isPending}>
            {updateMutation.isPending ? "Saving..." : "Save"}
          </Button>
        </div>
      </Dialog>
    </div>
  );
}
