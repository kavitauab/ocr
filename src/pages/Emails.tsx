import { useQuery } from "@tanstack/react-query";
import { useCompany } from "@/lib/company";
import { Link } from "react-router-dom";
import api from "@/api/client";
import { Card, CardContent } from "@/components/ui/card";
import { Table, TableHeader, TableBody, TableRow, TableHead, TableCell } from "@/components/ui/table";
import { Button } from "@/components/ui/button";
import { Skeleton } from "@/components/ui/skeleton";
import { Input } from "@/components/ui/input";
import { getStatusClasses, formatDateTime } from "@/lib/ui-utils";
import { EmptyState } from "@/components/EmptyState";
import { toast } from "sonner";
import { useMemo, useState } from "react";
import { Mail, FileText, AlertCircle, ChevronDown, ChevronRight, Search } from "lucide-react";

function getPresetDates(period: "daily" | "weekly" | "monthly") {
  const end = new Date();
  end.setHours(0, 0, 0, 0);
  const start = new Date(end);
  if (period === "weekly") start.setDate(start.getDate() - 6);
  if (period === "monthly") start.setDate(start.getDate() - 29);
  const fmt = (d: Date) => d.toISOString().slice(0, 10);
  return { dateFrom: fmt(start), dateTo: fmt(end) };
}

export default function Emails() {
  const { selectedCompany } = useCompany();
  const [fetching, setFetching] = useState(false);
  const [expandedId, setExpandedId] = useState<string | null>(null);
  const [period, setPeriod] = useState<"daily" | "weekly" | "monthly" | "custom">("daily");
  const defaultDaily = getPresetDates("daily");
  const [customDateFrom, setCustomDateFrom] = useState(defaultDaily.dateFrom);
  const [customDateTo, setCustomDateTo] = useState(defaultDaily.dateTo);
  const [search, setSearch] = useState("");

  const queryParams = useMemo(() => {
    const params: Record<string, string> = {};
    if (selectedCompany) params.companyId = selectedCompany.id;
    params.period = period;
    if (period === "custom") {
      params.dateFrom = customDateFrom;
      params.dateTo = customDateTo;
    }
    if (search.trim()) {
      params.search = search.trim();
    }
    return params;
  }, [selectedCompany, period, customDateFrom, customDateTo, search]);

  const { data, isLoading, refetch } = useQuery({
    queryKey: ["emails", selectedCompany?.id || "__all__", period, customDateFrom, customDateTo, search],
    queryFn: () => api.get("/emails", { params: queryParams }).then((r) => r.data),
  });

  const activeRangeLabel = period === "custom"
    ? `${customDateFrom} to ${customDateTo}`
    : period === "daily"
      ? "Today"
      : period === "weekly"
        ? "Last 7 days"
        : "Last 30 days";

  const activatePreset = (next: "daily" | "weekly" | "monthly") => {
    setPeriod(next);
    const preset = getPresetDates(next);
    setCustomDateFrom(preset.dateFrom);
    setCustomDateTo(preset.dateTo);
  };

  const handleFetch = async () => {
    if (!selectedCompany) return;
    setFetching(true);
    try {
      const { data } = await api.post(`/companies/${selectedCompany.id}/fetch-emails`);
      toast.success(`Fetched ${data.fetched} emails, processed ${data.processed}`);
      refetch();
    } catch (err: any) {
      toast.error(err.response?.data?.error || "Failed to fetch emails");
    }
    setFetching(false);
  };

  return (
    <div className="space-y-4">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-bold tracking-tight text-foreground">Emails</h1>
          <p className="text-sm text-muted-foreground mt-0.5">Fetched email messages and their processing status</p>
        </div>
        <Button onClick={handleFetch} disabled={fetching || !selectedCompany}>
          {fetching ? "Fetching..." : "Fetch Emails"}
        </Button>
      </div>

      <Card>
        <CardContent className="p-4">
          <div className="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
            <div className="flex flex-col gap-3 sm:flex-row sm:items-center">
              <div className="flex items-center rounded-lg border border-border bg-card p-0.5">
                {(["daily", "weekly", "monthly", "custom"] as const).map((option) => (
                  <button
                    key={option}
                    onClick={() => option === "custom" ? setPeriod("custom") : activatePreset(option)}
                    className={`rounded-md px-3 py-1.5 text-sm font-medium transition-colors ${
                      period === option
                        ? "bg-primary text-primary-foreground"
                        : "text-muted-foreground hover:text-foreground"
                    }`}
                  >
                    {option === "daily" ? "Daily" : option === "weekly" ? "Weekly" : option === "monthly" ? "Monthly" : "Custom"}
                  </button>
                ))}
              </div>
              <div className="flex flex-col gap-2 sm:flex-row sm:items-center">
                <Input
                  type="date"
                  value={customDateFrom}
                  onChange={(e) => {
                    setPeriod("custom");
                    setCustomDateFrom(e.target.value);
                  }}
                  className="h-9 w-full sm:w-[160px]"
                />
                <Input
                  type="date"
                  value={customDateTo}
                  onChange={(e) => {
                    setPeriod("custom");
                    setCustomDateTo(e.target.value);
                  }}
                  className="h-9 w-full sm:w-[160px]"
                />
              </div>
            </div>
            <div className="relative w-full lg:w-80">
              <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
              <Input
                value={search}
                onChange={(e) => setSearch(e.target.value)}
                placeholder="Search subject or sender..."
                className="pl-9"
              />
            </div>
          </div>
          <div className="mt-3 flex flex-col gap-1 text-xs text-muted-foreground sm:flex-row sm:items-center sm:justify-between">
            <p>Showing emails for {activeRangeLabel}.</p>
            {!selectedCompany && <p>Select one company to use Fetch Emails.</p>}
          </div>
        </CardContent>
      </Card>

      <Card className="overflow-hidden">
        <CardContent className="p-0">
          <div className="overflow-x-auto">
            <Table>
              <TableHeader>
                <TableRow className="bg-muted/30">
                  <TableHead className="w-8"></TableHead>
                  <TableHead className="font-semibold">Subject</TableHead>
                  <TableHead className="font-semibold">From</TableHead>
                  <TableHead className="font-semibold">Received</TableHead>
                  <TableHead className="font-semibold">Attachments</TableHead>
                  <TableHead className="font-semibold">Status</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {data?.emails?.map((email: any) => {
                  const isExpanded = expandedId === email.id;
                  const attachments = email.attachments || [];
                  const hasAttachments = attachments.length > 0;
                  const skippedCount = attachments.filter((a: any) => a.status === "skipped").length;
                  const processedCount = attachments.filter((a: any) => a.status === "completed").length;

                  return (
                    <>
                      <TableRow
                        key={email.id}
                        className="hover:bg-primary/[0.03] transition-colors duration-150 cursor-pointer"
                        onClick={() => hasAttachments ? setExpandedId(isExpanded ? null : email.id) : null}
                      >
                        <TableCell className="w-8 px-2">
                          {hasAttachments && (
                            isExpanded ? <ChevronDown className="h-3.5 w-3.5 text-muted-foreground" /> : <ChevronRight className="h-3.5 w-3.5 text-muted-foreground" />
                          )}
                        </TableCell>
                        <TableCell className="max-w-xs truncate font-medium">{email.subject}</TableCell>
                        <TableCell>
                          <div>{email.fromName || "—"}</div>
                          {email.fromEmail && <div className="text-xs text-muted-foreground">{email.fromEmail}</div>}
                        </TableCell>
                        <TableCell className="text-muted-foreground">{formatDateTime(email.receivedDate)}</TableCell>
                        <TableCell>
                          <div className="flex items-center gap-1.5">
                            <span className="text-muted-foreground">{email.attachmentCount ?? 0}</span>
                            {processedCount > 0 && <span className="text-xs text-emerald-600">{processedCount} processed</span>}
                            {skippedCount > 0 && <span className="text-xs text-slate-500">{skippedCount} skipped</span>}
                          </div>
                        </TableCell>
                        <TableCell>
                          <span className={`inline-flex items-center rounded-full border px-2 py-0.5 text-xs font-medium ${getStatusClasses(email.status)}`}>
                            {email.status}
                          </span>
                        </TableCell>
                      </TableRow>
                      {isExpanded && attachments.map((att: any, idx: number) => (
                        <TableRow key={`${email.id}-att-${idx}`} className="bg-muted/10">
                          <TableCell></TableCell>
                          <TableCell colSpan={3} className="pl-8">
                            <div className="flex items-center gap-2">
                              <FileText className="h-3.5 w-3.5 text-muted-foreground shrink-0" />
                              {att.status === "skipped" ? (
                                <span className="text-sm text-muted-foreground">{att.filename}</span>
                              ) : (
                                <Link to={`/invoices/${att.invoiceId}`} className="text-sm text-primary hover:underline">{att.filename}</Link>
                              )}
                            </div>
                          </TableCell>
                          <TableCell>
                            {att.status === "skipped" && att.skipReason && (
                              <div className="flex items-center gap-1 text-xs text-slate-500">
                                <AlertCircle className="h-3 w-3" />
                                {att.skipReason}
                              </div>
                            )}
                            {att.documentType && att.status !== "skipped" && (
                              <span className="text-xs text-muted-foreground">{att.documentType}</span>
                            )}
                          </TableCell>
                          <TableCell>
                            <span className={`inline-flex items-center rounded-full border px-2 py-0.5 text-xs font-medium ${getStatusClasses(att.status)}`}>
                              {att.status}
                            </span>
                          </TableCell>
                        </TableRow>
                      ))}
                    </>
                  );
                })}
                {isLoading && (
                  <>
                    {[...Array(4)].map((_, i) => (
                      <TableRow key={i}>
                        <TableCell><Skeleton className="h-4 w-4" /></TableCell>
                        <TableCell><Skeleton className="h-4 w-48" /></TableCell>
                        <TableCell><Skeleton className="h-4 w-28" /></TableCell>
                        <TableCell><Skeleton className="h-4 w-20" /></TableCell>
                        <TableCell><Skeleton className="h-4 w-8" /></TableCell>
                        <TableCell><Skeleton className="h-5 w-16 rounded-full" /></TableCell>
                      </TableRow>
                    ))}
                  </>
                )}
                {!isLoading && (!data?.emails || data.emails.length === 0) && (
                  <TableRow>
                    <TableCell colSpan={6}>
                      <EmptyState
                        icon={Mail}
                        title="No emails"
                        description="Emails will appear here once fetched from your mailbox."
                      />
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
