import { useQuery } from "@tanstack/react-query";
import { useCompany } from "@/lib/company";
import api from "@/api/client";
import { Card, CardContent } from "@/components/ui/card";
import { Table, TableHeader, TableBody, TableRow, TableHead, TableCell } from "@/components/ui/table";
import { Button } from "@/components/ui/button";
import { Skeleton } from "@/components/ui/skeleton";
import { getStatusClasses } from "@/lib/ui-utils";
import { toast } from "sonner";
import { useState } from "react";
import { Mail } from "lucide-react";

export default function Emails() {
  const { selectedCompany } = useCompany();
  const [fetching, setFetching] = useState(false);

  const { data, isLoading, refetch } = useQuery({
    queryKey: ["emails", selectedCompany?.id],
    queryFn: () => {
      const params: Record<string, string> = {};
      if (selectedCompany) params.companyId = selectedCompany.id;
      return api.get("/emails", { params }).then((r) => r.data);
    },
  });

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

      <Card className="overflow-hidden">
        <CardContent className="p-0">
          <div className="overflow-x-auto">
            <Table>
              <TableHeader>
                <TableRow className="bg-muted/30">
                  <TableHead className="font-semibold">Subject</TableHead>
                  <TableHead className="font-semibold">From</TableHead>
                  <TableHead className="font-semibold">Received</TableHead>
                  <TableHead className="font-semibold">Attachments</TableHead>
                  <TableHead className="font-semibold">Status</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {data?.emails?.map((email: any) => (
                  <TableRow key={email.id} className="hover:bg-primary/[0.03] transition-colors duration-150">
                    <TableCell className="max-w-xs truncate font-medium">{email.subject}</TableCell>
                    <TableCell className="text-muted-foreground">{email.fromName || email.fromEmail}</TableCell>
                    <TableCell className="text-muted-foreground">{email.receivedDate ? new Date(email.receivedDate).toLocaleDateString() : "—"}</TableCell>
                    <TableCell className="text-muted-foreground">{email.attachmentCount ?? 0}</TableCell>
                    <TableCell>
                      <span className={`inline-flex items-center rounded-full border px-2 py-0.5 text-xs font-medium ${getStatusClasses(email.status)}`}>
                        {email.status}
                      </span>
                    </TableCell>
                  </TableRow>
                ))}
                {isLoading && (
                  <>
                    {[...Array(4)].map((_, i) => (
                      <TableRow key={i}>
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
                    <TableCell colSpan={5} className="py-12">
                      <div className="flex flex-col items-center justify-center text-center">
                        <div className="rounded-full bg-muted p-3 mb-3">
                          <Mail className="h-5 w-5 text-muted-foreground" />
                        </div>
                        <p className="text-sm font-medium text-foreground">No emails</p>
                        <p className="text-sm text-muted-foreground mt-0.5">Emails will appear here once fetched from your mailbox</p>
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
