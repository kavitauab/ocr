import { useQuery } from "@tanstack/react-query";
import { useCompany } from "@/lib/company";
import api from "@/api/client";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Table, TableHeader, TableBody, TableRow, TableHead, TableCell } from "@/components/ui/table";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { toast } from "sonner";
import { useState } from "react";

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
        <h1 className="text-2xl font-bold">Emails</h1>
        <Button onClick={handleFetch} disabled={fetching || !selectedCompany}>
          {fetching ? "Fetching..." : "Fetch Emails"}
        </Button>
      </div>

      <Card>
        <CardContent className="p-0">
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>Subject</TableHead>
                <TableHead>From</TableHead>
                <TableHead>Received</TableHead>
                <TableHead>Attachments</TableHead>
                <TableHead>Status</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {data?.emails?.map((email: any) => (
                <TableRow key={email.id}>
                  <TableCell className="max-w-xs truncate">{email.subject}</TableCell>
                  <TableCell>{email.fromName || email.fromEmail}</TableCell>
                  <TableCell>{email.receivedDate ? new Date(email.receivedDate).toLocaleDateString() : "—"}</TableCell>
                  <TableCell>{email.attachmentCount ?? 0}</TableCell>
                  <TableCell>
                    <Badge variant={email.status === "processed" ? "default" : email.status === "failed" ? "destructive" : "secondary"}>
                      {email.status}
                    </Badge>
                  </TableCell>
                </TableRow>
              ))}
              {isLoading && <TableRow><TableCell colSpan={5} className="text-center">Loading...</TableCell></TableRow>}
              {!isLoading && (!data?.emails || data.emails.length === 0) && (
                <TableRow><TableCell colSpan={5} className="text-center text-gray-500">No emails</TableCell></TableRow>
              )}
            </TableBody>
          </Table>
        </CardContent>
      </Card>
    </div>
  );
}
