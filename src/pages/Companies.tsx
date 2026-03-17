import { useQuery } from "@tanstack/react-query";
import { Link, useNavigate } from "react-router-dom";
import api from "@/api/client";
import { useAuth } from "@/lib/auth";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { Table, TableHeader, TableBody, TableRow, TableHead, TableCell } from "@/components/ui/table";
import { Skeleton } from "@/components/ui/skeleton";
import { Plus, Building2 } from "lucide-react";

export default function Companies() {
  const { user } = useAuth();
  const navigate = useNavigate();
  const isSuperadmin = user?.role === "superadmin";

  const { data, isLoading } = useQuery({
    queryKey: ["companies"],
    queryFn: () => api.get("/companies").then((r) => r.data),
  });

  return (
    <div className="space-y-4">
      <div className="flex items-center justify-between">
        <div>
          <h2 className="text-2xl font-bold tracking-tight text-foreground">Companies</h2>
          <p className="text-sm text-muted-foreground mt-0.5">Manage your organization companies</p>
        </div>
        {isSuperadmin && (
          <Link to="/settings/companies/new"><Button size="sm"><Plus className="h-3.5 w-3.5" /><span className="ml-1">Add Company</span></Button></Link>
        )}
      </div>

      <Card className="overflow-hidden">
        <CardContent className="p-0">
          <div className="overflow-x-auto">
            <Table>
              <TableHeader>
                <TableRow className="bg-muted/30">
                  <TableHead className="font-semibold">Name</TableHead>
                  <TableHead className="font-semibold">Code</TableHead>
                  <TableHead className="font-semibold">Your Role</TableHead>
                  <TableHead className="font-semibold">Email</TableHead>
                  <TableHead className="font-semibold">Vecticum</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {data?.companies?.map((c: any) => (
                  <TableRow
                    key={c.id}
                    className="cursor-pointer hover:bg-primary/[0.03] transition-colors duration-150"
                    onClick={() => navigate(`/settings/companies/${c.id}`)}
                  >
                    <TableCell className="font-medium">{c.name}</TableCell>
                    <TableCell className="text-muted-foreground">{c.code}</TableCell>
                    <TableCell>
                      <span className="inline-flex items-center rounded-full border px-2 py-0.5 text-xs font-medium bg-muted/50 text-foreground capitalize">
                        {c.companyRole || c.company_role || "—"}
                      </span>
                    </TableCell>
                    <TableCell className="text-muted-foreground">{c.msFetchEnabled ? "Enabled" : "—"}</TableCell>
                    <TableCell className="text-muted-foreground">{c.vecticumEnabled ? "Enabled" : "—"}</TableCell>
                  </TableRow>
                ))}
                {isLoading && (
                  <>
                    {[...Array(3)].map((_, i) => (
                      <TableRow key={i}>
                        <TableCell><Skeleton className="h-4 w-32" /></TableCell>
                        <TableCell><Skeleton className="h-4 w-16" /></TableCell>
                        <TableCell><Skeleton className="h-5 w-16 rounded-full" /></TableCell>
                        <TableCell><Skeleton className="h-4 w-16" /></TableCell>
                        <TableCell><Skeleton className="h-4 w-16" /></TableCell>
                      </TableRow>
                    ))}
                  </>
                )}
                {!isLoading && (!data?.companies || data.companies.length === 0) && (
                  <TableRow>
                    <TableCell colSpan={5} className="py-12">
                      <div className="flex flex-col items-center justify-center text-center">
                        <div className="rounded-full bg-muted p-3 mb-3">
                          <Building2 className="h-5 w-5 text-muted-foreground" />
                        </div>
                        <p className="text-sm font-medium text-foreground">No companies yet</p>
                        <p className="text-sm text-muted-foreground mt-0.5">Get started by adding your first company</p>
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
