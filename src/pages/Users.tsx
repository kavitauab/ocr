import { useQuery, useMutation, useQueryClient } from "@tanstack/react-query";
import { useState } from "react";
import api from "@/api/client";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Card, CardContent } from "@/components/ui/card";
import { Table, TableHeader, TableBody, TableRow, TableHead, TableCell } from "@/components/ui/table";
import { Skeleton } from "@/components/ui/skeleton";
import { Dialog, DialogTitle } from "@/components/ui/dialog";
import { getStatusClasses } from "@/lib/ui-utils";
import { useAuth } from "@/lib/auth";
import { toast } from "sonner";
import { Plus, Trash2, Users as UsersIcon } from "lucide-react";

const emptyForm = { name: "", email: "", password: "", role: "user" };

export default function Users() {
  const queryClient = useQueryClient();
  const { user: currentUser, refreshUser } = useAuth();
  const [showCreate, setShowCreate] = useState(false);
  const [editingUser, setEditingUser] = useState<any>(null);
  const [form, setForm] = useState(emptyForm);
  const [editForm, setEditForm] = useState({ name: "", email: "", password: "", role: "user" });

  const { data, isLoading } = useQuery({
    queryKey: ["users"],
    queryFn: () => api.get("/users").then((r) => r.data),
  });

  const createMutation = useMutation({
    mutationFn: (body: any) => api.post("/users", body).then((r) => r.data),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["users"] });
      setShowCreate(false);
      setForm(emptyForm);
      toast.success("User created");
    },
    onError: (err: any) => toast.error(err.response?.data?.error || "Failed"),
  });

  const updateMutation = useMutation({
    mutationFn: ({ id, body }: { id: string; body: any }) => api.patch(`/users/${id}`, body).then((r) => r.data),
    onSuccess: (_data, variables) => {
      queryClient.invalidateQueries({ queryKey: ["users"] });
      if (variables.id === currentUser?.id) refreshUser();
      setEditingUser(null);
      toast.success("User updated");
    },
    onError: (err: any) => toast.error(err.response?.data?.error || "Failed"),
  });

  const deleteMutation = useMutation({
    mutationFn: (id: string) => api.delete(`/users/${id}`).then((r) => r.data),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["users"] });
      setEditingUser(null);
      toast.success("User deleted");
    },
  });

  const openEdit = (u: any) => {
    setEditingUser(u);
    setEditForm({ name: u.name, email: u.email, password: "", role: u.role });
  };

  const saveEdit = () => {
    const body: any = { name: editForm.name, email: editForm.email, role: editForm.role };
    if (editForm.password) body.password = editForm.password;
    updateMutation.mutate({ id: editingUser.id, body });
  };

  return (
    <div className="space-y-4">
      <div className="flex items-center justify-between">
        <div>
          <h2 className="text-2xl font-bold tracking-tight text-foreground">Users</h2>
          <p className="text-sm text-muted-foreground mt-0.5">Manage system users and their roles</p>
        </div>
        <Button size="sm" onClick={() => setShowCreate(true)}><Plus className="h-3.5 w-3.5" /><span className="ml-1">Add User</span></Button>
      </div>

      <Card className="overflow-hidden">
        <CardContent className="p-0">
          <div className="overflow-x-auto">
            <Table>
              <TableHeader>
                <TableRow className="bg-muted/30">
                  <TableHead className="font-semibold">Name</TableHead>
                  <TableHead className="font-semibold">Email</TableHead>
                  <TableHead className="font-semibold">Role</TableHead>
                </TableRow>
              </TableHeader>
              <TableBody>
                {data?.users?.map((u: any) => (
                  <TableRow key={u.id} className="hover:bg-primary/[0.03] transition-colors duration-150 cursor-pointer" onClick={() => openEdit(u)}>
                    <TableCell className="font-medium">{u.name}</TableCell>
                    <TableCell className="text-muted-foreground">{u.email}</TableCell>
                    <TableCell>
                      <span className={`inline-flex items-center rounded-full border px-2 py-0.5 text-xs font-medium ${getStatusClasses(u.role === "superadmin" ? "active" : "")}`}>
                        {u.role}
                      </span>
                    </TableCell>
                  </TableRow>
                ))}
                {isLoading && (
                  <>
                    {[...Array(3)].map((_, i) => (
                      <TableRow key={i}>
                        <TableCell><Skeleton className="h-4 w-28" /></TableCell>
                        <TableCell><Skeleton className="h-4 w-40" /></TableCell>
                        <TableCell><Skeleton className="h-5 w-16 rounded-full" /></TableCell>
                      </TableRow>
                    ))}
                  </>
                )}
                {!isLoading && (!data?.users || data.users.length === 0) && (
                  <TableRow>
                    <TableCell colSpan={3} className="py-12">
                      <div className="flex flex-col items-center justify-center text-center">
                        <div className="rounded-full bg-muted p-3 mb-3">
                          <UsersIcon className="h-5 w-5 text-muted-foreground" />
                        </div>
                        <p className="text-sm font-medium text-foreground">No users yet</p>
                        <p className="text-sm text-muted-foreground mt-0.5">Add your first user to get started</p>
                      </div>
                    </TableCell>
                  </TableRow>
                )}
              </TableBody>
            </Table>
          </div>
        </CardContent>
      </Card>

      {/* Create dialog */}
      <Dialog open={showCreate} onClose={() => setShowCreate(false)}>
        <DialogTitle>Create User</DialogTitle>
        <div className="space-y-4 mt-4">
          <div className="space-y-1.5"><label className="text-sm font-medium text-foreground">Name</label><Input value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} /></div>
          <div className="space-y-1.5"><label className="text-sm font-medium text-foreground">Email</label><Input value={form.email} onChange={(e) => setForm({ ...form, email: e.target.value })} /></div>
          <div className="space-y-1.5"><label className="text-sm font-medium text-foreground">Password</label><Input type="password" value={form.password} onChange={(e) => setForm({ ...form, password: e.target.value })} /></div>
          <div className="space-y-1.5">
            <label className="text-sm font-medium text-foreground">Role</label>
            <select value={form.role} onChange={(e) => setForm({ ...form, role: e.target.value })} className="w-full border border-border rounded-md px-3 py-1.5 text-sm bg-background text-foreground">
              <option value="user">User</option>
              <option value="superadmin">Superadmin</option>
            </select>
          </div>
          <div className="flex justify-end gap-2 pt-2">
            <Button variant="outline" onClick={() => setShowCreate(false)}>Cancel</Button>
            <Button onClick={() => createMutation.mutate(form)} disabled={createMutation.isPending}>Create</Button>
          </div>
        </div>
      </Dialog>

      {/* Edit dialog */}
      <Dialog open={!!editingUser} onClose={() => setEditingUser(null)}>
        <DialogTitle>Edit User</DialogTitle>
        <div className="space-y-4 mt-4">
          <div className="space-y-1.5"><label className="text-sm font-medium text-foreground">Name</label><Input value={editForm.name} onChange={(e) => setEditForm({ ...editForm, name: e.target.value })} /></div>
          <div className="space-y-1.5"><label className="text-sm font-medium text-foreground">Email</label><Input value={editForm.email} onChange={(e) => setEditForm({ ...editForm, email: e.target.value })} /></div>
          <div className="space-y-1.5">
            <label className="text-sm font-medium text-foreground">New Password</label>
            <Input type="password" value={editForm.password} onChange={(e) => setEditForm({ ...editForm, password: e.target.value })} placeholder="Leave blank to keep current" />
          </div>
          <div className="space-y-1.5">
            <label className="text-sm font-medium text-foreground">Role</label>
            <select value={editForm.role} onChange={(e) => setEditForm({ ...editForm, role: e.target.value })} className="w-full border border-border rounded-md px-3 py-1.5 text-sm bg-background text-foreground">
              <option value="user">User</option>
              <option value="superadmin">Superadmin</option>
            </select>
          </div>
          <div className="flex justify-between pt-2">
            <Button variant="outline" className="text-red-600 hover:text-red-700 hover:bg-red-50" onClick={() => { if (confirm(`Delete user ${editingUser?.name}?`)) deleteMutation.mutate(editingUser.id); }}>
              <Trash2 className="h-3.5 w-3.5 mr-1" />Delete
            </Button>
            <div className="flex gap-2">
              <Button variant="outline" onClick={() => setEditingUser(null)}>Cancel</Button>
              <Button onClick={saveEdit} disabled={updateMutation.isPending}>Save</Button>
            </div>
          </div>
        </div>
      </Dialog>
    </div>
  );
}
