import { useMemo, useState } from "react";
import { Navigate, useNavigate } from "react-router-dom";
import { useQuery } from "@tanstack/react-query";
import api from "@/api/client";
import { useAuth } from "@/lib/auth";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Card, CardContent } from "@/components/ui/card";
import { Table, TableHeader, TableBody, TableRow, TableHead, TableCell } from "@/components/ui/table";
import { Skeleton } from "@/components/ui/skeleton";
import { getStatusClasses } from "@/lib/ui-utils";
import { CreditCard } from "lucide-react";

type JsonRecord = Record<string, unknown>;
type SubscriptionRow = JsonRecord;

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

function getOveragePer1kTokens(row: SubscriptionRow): number | null {
  return toNullableNumber(
    pickFirst(row, ["overagePer1kTokensUsd", "overage_per_1k_tokens_usd", "overageRateUsd", "overage_rate_usd"])
  );
}

function getOveragePerInvoice(row: SubscriptionRow): number | null {
  return toNullableNumber(
    pickFirst(row, ["overagePerInvoiceUsd", "overage_per_invoice_usd", "overageRatePerInvoiceUsd"])
  );
}

export default function Billing() {
  const { user } = useAuth();
  const navigate = useNavigate();
  const [search, setSearch] = useState("");

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

  if (!isSuperadmin) {
    return <Navigate to="/dashboard" replace />;
  }

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <div>
          <h2 className="text-2xl font-bold tracking-tight text-foreground">Billing</h2>
          <p className="text-sm text-muted-foreground mt-0.5">
            {isFetching && !isLoading ? "Refreshing..." : `${rows.length} clients`}
          </p>
        </div>
      </div>

      <Card className="overflow-hidden">
        <CardContent className="p-4 border-b border-border">
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
          <div className="overflow-x-auto">
            <Table>
              <TableHeader>
                <TableRow className="bg-muted/30">
                  <TableHead className="font-semibold">Client</TableHead>
                  <TableHead className="font-semibold">Plan</TableHead>
                  <TableHead className="font-semibold">Status</TableHead>
                  <TableHead className="text-right font-semibold">Invoice Limit</TableHead>
                  <TableHead className="text-right font-semibold">Storage Limit</TableHead>
                  <TableHead className="text-right font-semibold">Included Tokens</TableHead>
                  <TableHead className="text-right font-semibold">Used Tokens</TableHead>
                  <TableHead className="text-right font-semibold">Overage / 1k Tokens</TableHead>
                  <TableHead className="text-right font-semibold">Overage / Invoice</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {isLoading && (
                  <>
                    {[...Array(4)].map((_, i) => (
                      <TableRow key={i}>
                        <TableCell><Skeleton className="h-4 w-32" /><Skeleton className="h-3 w-16 mt-1" /></TableCell>
                        <TableCell><Skeleton className="h-4 w-16" /></TableCell>
                        <TableCell><Skeleton className="h-5 w-14 rounded-full" /></TableCell>
                        <TableCell className="text-right"><Skeleton className="h-4 w-12 ml-auto" /></TableCell>
                        <TableCell className="text-right"><Skeleton className="h-4 w-16 ml-auto" /></TableCell>
                        <TableCell className="text-right"><Skeleton className="h-4 w-16 ml-auto" /></TableCell>
                        <TableCell className="text-right"><Skeleton className="h-4 w-16 ml-auto" /></TableCell>
                        <TableCell className="text-right"><Skeleton className="h-4 w-12 ml-auto" /></TableCell>
                        <TableCell className="text-right"><Skeleton className="h-4 w-12 ml-auto" /></TableCell>
                        <TableCell><Skeleton className="h-8 w-14" /></TableCell>
                      </TableRow>
                    ))}
                  </>
                )}

                {!isLoading && isError && (
                  <TableRow>
                    <TableCell colSpan={10} className="py-6">
                      <div className="flex flex-wrap items-center justify-center gap-2 text-sm text-destructive">
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
                      <TableRow
                        key={companyId || `${getCompanyName(row)}-${index}`}
                        className="cursor-pointer hover:bg-primary/[0.03] transition-colors duration-150"
                        onClick={() => navigate(`/settings/billing/${companyId}`)}
                      >
                        <TableCell>
                          <div className="font-medium text-foreground">{getCompanyName(row)}</div>
                          {companyCode && <div className="text-xs text-muted-foreground">{companyCode}</div>}
                        </TableCell>
                        <TableCell className="text-muted-foreground">{getPlan(row)}</TableCell>
                        <TableCell>
                          <span className={`inline-flex items-center rounded-full border px-2 py-0.5 text-xs font-medium ${getStatusClasses(status)}`}>
                            {status}
                          </span>
                        </TableCell>
                        <TableCell className="text-right tabular-nums text-muted-foreground">{formatNumber(getInvoiceLimit(row))}</TableCell>
                        <TableCell className="text-right tabular-nums text-muted-foreground">{formatBytes(getStorageLimitBytes(row))}</TableCell>
                        <TableCell className="text-right tabular-nums text-muted-foreground">{formatNumber(getIncludedTokens(row))}</TableCell>
                        <TableCell className="text-right tabular-nums text-muted-foreground">{formatNumber(getTokenUsage(row))}</TableCell>
                        <TableCell className="text-right tabular-nums text-muted-foreground">{formatRate(getOveragePer1kTokens(row))}</TableCell>
                        <TableCell className="text-right tabular-nums text-muted-foreground">{formatRate(getOveragePerInvoice(row))}</TableCell>
                      </TableRow>
                    );
                  })}

                {!isLoading && !isError && filteredRows.length === 0 && (
                  <TableRow>
                    <TableCell colSpan={10} className="py-12">
                      <div className="flex flex-col items-center justify-center text-center">
                        <div className="rounded-full bg-muted p-3 mb-3">
                          <CreditCard className="h-5 w-5 text-muted-foreground" />
                        </div>
                        <p className="text-sm font-medium text-foreground">
                          {rows.length === 0 ? "No client billing data found" : "No clients match your search"}
                        </p>
                        <p className="text-sm text-muted-foreground mt-0.5">
                          {rows.length === 0 ? "Billing data will appear once companies are created" : "Try adjusting your search terms"}
                        </p>
                      </div>
                    </TableCell>
                  </TableRow>
                )}
              </TableBody>
            </Table>
          </div>
        </CardContent>
      </Card>

    </div>
  );
}
