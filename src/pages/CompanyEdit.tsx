import { useQuery, useMutation, useQueryClient } from "@tanstack/react-query";
import { useParams, useNavigate } from "react-router-dom";
import { useState, useEffect } from "react";
import api from "@/api/client";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Table, TableHeader, TableBody, TableRow, TableHead, TableCell } from "@/components/ui/table";
import { Dialog, DialogTitle } from "@/components/ui/dialog";
import { toast } from "sonner";
import { ArrowLeft, Save, Plus, Trash2 } from "lucide-react";

export default function CompanyEdit() {
  const { id } = useParams<{ id: string }>();
  const navigate = useNavigate();
  const queryClient = useQueryClient();
  const isNew = !id;

  const [showAddMember, setShowAddMember] = useState(false);
  const [memberForm, setMemberForm] = useState({ email: "", role: "viewer" });

  const [form, setForm] = useState({
    name: "", code: "", logoUrl: "",
    msClientId: "", msClientSecret: "", msTenantId: "", msSenderEmail: "",
    msFetchEnabled: false, msFetchFolder: "INBOX",
    vecticumEnabled: false, vecticumApiBaseUrl: "", vecticumClientId: "",
    vecticumClientSecret: "", vecticumCompanyId: "",
    vecticumAuthorId: "", vecticumAuthorName: "",
  });

  const { data } = useQuery({
    queryKey: ["company", id],
    queryFn: () => api.get(`/companies/${id}`).then((r) => r.data),
    enabled: !!id,
  });

  useEffect(() => {
    if (data?.company) {
      setForm((prev) => ({ ...prev, ...data.company }));
    }
  }, [data]);

  const { data: membersData, refetch: refetchMembers } = useQuery({
    queryKey: ["company-members", id],
    queryFn: () => api.get(`/companies/${id}/members`).then((r) => r.data),
    enabled: !!id,
  });

  const saveMutation = useMutation({
    mutationFn: (body: any) => isNew ? api.post("/companies", body).then((r) => r.data) : api.patch(`/companies/${id}`, body).then((r) => r.data),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ["companies"] });
      toast.success(isNew ? "Company created" : "Company updated");
      if (isNew) navigate("/settings/companies");
    },
    onError: (err: any) => toast.error(err.response?.data?.error || "Failed to save"),
  });

  const addMemberMutation = useMutation({
    mutationFn: (body: any) => api.post(`/companies/${id}/members`, body).then((r) => r.data),
    onSuccess: () => { refetchMembers(); setShowAddMember(false); setMemberForm({ email: "", role: "viewer" }); toast.success("Member added"); },
    onError: (err: any) => toast.error(err.response?.data?.error || "Failed to add member"),
  });

  const updateRoleMutation = useMutation({
    mutationFn: ({ userId, role }: { userId: string; role: string }) =>
      api.patch(`/companies/${id}/members/${userId}`, { role }).then((r) => r.data),
    onSuccess: () => { refetchMembers(); toast.success("Role updated"); },
    onError: (err: any) => toast.error(err.response?.data?.error || "Failed"),
  });

  const removeMemberMutation = useMutation({
    mutationFn: (userId: string) => api.delete(`/companies/${id}/members/${userId}`).then((r) => r.data),
    onSuccess: () => { refetchMembers(); toast.success("Member removed"); },
    onError: (err: any) => toast.error(err.response?.data?.error || "Failed"),
  });

  const set = (key: string, value: any) => setForm((prev) => ({ ...prev, [key]: value }));

  const testEmail = async () => {
    try {
      const { data } = await api.post(`/companies/${id}/test-email`);
      data.success ? toast.success(data.email || "Connected") : toast.error(data.error);
    } catch { toast.error("Test failed"); }
  };

  const testVecticum = async () => {
    try {
      const { data } = await api.post(`/companies/${id}/test-vecticum`);
      data.success ? toast.success(data.message || "Connected") : toast.error(data.error);
    } catch { toast.error("Test failed"); }
  };

  return (
    <div className="space-y-4 max-w-2xl">
      <div className="flex items-center gap-4">
        <Button variant="ghost" size="icon" onClick={() => navigate(-1)}><ArrowLeft className="h-4 w-4" /></Button>
        <h2 className="text-xl font-semibold">{isNew ? "New Company" : "Edit Company"}</h2>
      </div>

      <Card>
        <CardHeader><CardTitle>General</CardTitle></CardHeader>
        <CardContent className="space-y-3">
          <div><label className="text-sm font-medium">Name</label><Input value={form.name} onChange={(e) => set("name", e.target.value)} /></div>
          <div><label className="text-sm font-medium">Code</label><Input value={form.code} onChange={(e) => set("code", e.target.value)} /></div>
        </CardContent>
      </Card>

      {!isNew && (
        <>
          {/* Members */}
          <Card>
            <CardHeader className="flex flex-row items-center justify-between">
              <CardTitle>Members</CardTitle>
              <Button size="sm" variant="outline" onClick={() => setShowAddMember(true)}>
                <Plus className="h-3 w-3 mr-1" />Add Member
              </Button>
            </CardHeader>
            <CardContent className="p-0">
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead>Name</TableHead>
                    <TableHead>Email</TableHead>
                    <TableHead>Role</TableHead>
                    <TableHead></TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {(membersData?.members || []).map((m: any) => (
                    <TableRow key={m.userId || m.user_id}>
                      <TableCell>{m.name || m.userName}</TableCell>
                      <TableCell>{m.email || m.userEmail}</TableCell>
                      <TableCell>
                        <select value={m.role} onChange={(e) => updateRoleMutation.mutate({ userId: m.userId || m.user_id, role: e.target.value })} className="border rounded px-2 py-1 text-sm">
                          <option value="viewer">Viewer</option>
                          <option value="manager">Manager</option>
                          <option value="admin">Admin</option>
                          <option value="owner">Owner</option>
                        </select>
                      </TableCell>
                      <TableCell>
                        <Button variant="ghost" size="icon" onClick={() => { if (confirm("Remove this member?")) removeMemberMutation.mutate(m.userId || m.user_id); }}>
                          <Trash2 className="h-3 w-3" />
                        </Button>
                      </TableCell>
                    </TableRow>
                  ))}
                  {(membersData?.members || []).length === 0 && (
                    <TableRow><TableCell colSpan={4} className="text-center text-gray-500">No members yet</TableCell></TableRow>
                  )}
                </TableBody>
              </Table>
            </CardContent>
          </Card>

          <Card>
            <CardHeader className="flex flex-row items-center justify-between">
              <CardTitle>Microsoft 365 Email</CardTitle>
              <label className="flex items-center gap-2 text-sm">
                <input type="checkbox" checked={form.msFetchEnabled as boolean} onChange={(e) => set("msFetchEnabled", e.target.checked)} />
                Enabled
              </label>
            </CardHeader>
            <CardContent className="space-y-3">
              <div><label className="text-sm font-medium">Tenant ID</label><Input value={form.msTenantId} onChange={(e) => set("msTenantId", e.target.value)} /></div>
              <div><label className="text-sm font-medium">Client ID</label><Input value={form.msClientId} onChange={(e) => set("msClientId", e.target.value)} /></div>
              <div><label className="text-sm font-medium">Client Secret</label><Input type="password" value={form.msClientSecret} onChange={(e) => set("msClientSecret", e.target.value)} /></div>
              <div><label className="text-sm font-medium">Sender Email</label><Input value={form.msSenderEmail} onChange={(e) => set("msSenderEmail", e.target.value)} /></div>
              <div><label className="text-sm font-medium">Folder</label><Input value={form.msFetchFolder} onChange={(e) => set("msFetchFolder", e.target.value)} /></div>
              <Button variant="outline" size="sm" onClick={testEmail}>Test Connection</Button>
            </CardContent>
          </Card>

          <Card>
            <CardHeader className="flex flex-row items-center justify-between">
              <CardTitle>Vecticum</CardTitle>
              <label className="flex items-center gap-2 text-sm">
                <input type="checkbox" checked={form.vecticumEnabled as boolean} onChange={(e) => set("vecticumEnabled", e.target.checked)} />
                Enabled
              </label>
            </CardHeader>
            <CardContent className="space-y-3">
              <div><label className="text-sm font-medium">API Base URL</label><Input value={form.vecticumApiBaseUrl} onChange={(e) => set("vecticumApiBaseUrl", e.target.value)} /></div>
              <div><label className="text-sm font-medium">Client ID</label><Input value={form.vecticumClientId} onChange={(e) => set("vecticumClientId", e.target.value)} /></div>
              <div><label className="text-sm font-medium">Client Secret</label><Input type="password" value={form.vecticumClientSecret} onChange={(e) => set("vecticumClientSecret", e.target.value)} /></div>
              <div><label className="text-sm font-medium">Company ID (Endpoint)</label><Input value={form.vecticumCompanyId} onChange={(e) => set("vecticumCompanyId", e.target.value)} /></div>
              <div><label className="text-sm font-medium">Author ID</label><Input value={form.vecticumAuthorId} onChange={(e) => set("vecticumAuthorId", e.target.value)} /></div>
              <div><label className="text-sm font-medium">Author Name</label><Input value={form.vecticumAuthorName} onChange={(e) => set("vecticumAuthorName", e.target.value)} /></div>
              <Button variant="outline" size="sm" onClick={testVecticum}>Test Connection</Button>
            </CardContent>
          </Card>
        </>
      )}

      <Button onClick={() => saveMutation.mutate(form)} disabled={saveMutation.isPending}>
        <Save className="h-3 w-3 mr-1" />{isNew ? "Create" : "Save Changes"}
      </Button>

      <Dialog open={showAddMember} onClose={() => setShowAddMember(false)}>
        <DialogTitle>Add Member</DialogTitle>
        <div className="space-y-3 mt-4">
          <div><label className="text-sm font-medium">User Email</label><Input value={memberForm.email} onChange={(e) => setMemberForm({ ...memberForm, email: e.target.value })} placeholder="user@example.com" /></div>
          <div>
            <label className="text-sm font-medium">Role</label>
            <select value={memberForm.role} onChange={(e) => setMemberForm({ ...memberForm, role: e.target.value })} className="w-full border rounded px-3 py-1.5 text-sm">
              <option value="viewer">Viewer</option>
              <option value="manager">Manager</option>
              <option value="admin">Admin</option>
              <option value="owner">Owner</option>
            </select>
          </div>
          <div className="flex justify-end gap-2">
            <Button variant="outline" onClick={() => setShowAddMember(false)}>Cancel</Button>
            <Button onClick={() => addMemberMutation.mutate(memberForm)} disabled={addMemberMutation.isPending}>Add</Button>
          </div>
        </div>
      </Dialog>
    </div>
  );
}
