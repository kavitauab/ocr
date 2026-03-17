import { useQuery } from "@tanstack/react-query";
import { Link } from "react-router-dom";
import api from "@/api/client";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { Table, TableHeader, TableBody, TableRow, TableHead, TableCell } from "@/components/ui/table";
import { Plus } from "lucide-react";

export default function Companies() {
  const { data, isLoading } = useQuery({
    queryKey: ["companies"],
    queryFn: () => api.get("/companies").then((r) => r.data),
  });

  return (
    <div className="space-y-4">
      <div className="flex items-center justify-between">
        <h2 className="text-xl font-semibold">Companies</h2>
        <Link to="/settings/companies/new"><Button size="sm"><Plus className="h-3 w-3 mr-1" />Add Company</Button></Link>
      </div>

      <Card>
        <CardContent className="p-0">
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>Name</TableHead>
                <TableHead>Code</TableHead>
                <TableHead>Email</TableHead>
                <TableHead>Vecticum</TableHead>
                <TableHead></TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {data?.companies?.map((c: any) => (
                <TableRow key={c.id}>
                  <TableCell className="font-medium">{c.name}</TableCell>
                  <TableCell>{c.code}</TableCell>
                  <TableCell>{c.msFetchEnabled ? "Enabled" : "—"}</TableCell>
                  <TableCell>{c.vecticumEnabled ? "Enabled" : "—"}</TableCell>
                  <TableCell>
                    <Link to={`/settings/companies/${c.id}`}>
                      <Button variant="outline" size="sm">Edit</Button>
                    </Link>
                  </TableCell>
                </TableRow>
              ))}
              {isLoading && <TableRow><TableCell colSpan={5} className="text-center">Loading...</TableCell></TableRow>}
            </TableBody>
          </Table>
        </CardContent>
      </Card>
    </div>
  );
}
