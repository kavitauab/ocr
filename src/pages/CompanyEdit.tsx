import { useQuery, useMutation, useQueryClient } from "@tanstack/react-query";
import { useParams, useNavigate } from "react-router-dom";
import { useState, useEffect, useRef } from "react";
import api from "@/api/client";
import { useAuth } from "@/lib/auth";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Table, TableHeader, TableBody, TableRow, TableHead, TableCell } from "@/components/ui/table";
import { Dialog, DialogTitle } from "@/components/ui/dialog";
import { toast } from "sonner";
import { ArrowLeft, Save, Plus, Trash2, Search, UserPlus, Eye } from "lucide-react";

const ROLE_HIERARCHY: Record<string, number> = { viewer: 0, manager: 1, admin: 2, owner: 3, superadmin: 4 };

function meetsRole(userRole: string | undefined, minRole: string): boolean {
  if (!userRole) return false;
  return (ROLE_HIERARCHY[userRole] ?? -1) >= (ROLE_HIERARCHY[minRole] ?? 0);
}

interface SearchUser {
  id: string;
  name: string;
  email: string;
}

export default function CompanyEdit() {
  const { id } = useParams<{ id: string }>();
  const navigate = useNavigate();
  const queryClient = useQueryClient();
  const { user } = useAuth();
  const isNew = !id;

  const [showAddMember, setShowAddMember] = useState(false);
  const [memberMode, setMemberMode] = useState<"search" | "create">("search");
  const [memberForm, setMemberForm] = useState({ email: "", name: "", password: "", role: "manager" });
  const [searchQuery, setSearchQuery] = useState("");
  const [searchResults, setSearchResults] = useState<SearchUser[]>([]);
  const [selectedUser, setSelectedUser] = useState<SearchUser | null>(null);
  const [showResults, setShowResults] = useState(false);
  const searchTimeout = useRef<ReturnType<typeof setTimeout>>(undefined);

  const allExtractionFields: [string, string][] = [
    ["invoiceNumber", "Invoice Number"],
    ["invoiceDate", "Invoice Date"],
    ["dueDate", "Due Date"],
    ["vendorName", "Vendor Name"],
    ["vendorAddress", "Vendor Address"],
    ["vendorVatId", "Vendor VAT ID"],
    ["buyerName", "Buyer Name"],
    ["buyerAddress", "Buyer Address"],
    ["buyerVatId", "Buyer VAT ID"],
    ["subtotalAmount", "Subtotal"],
    ["taxAmount", "Tax Amount"],
    ["totalAmount", "Total Amount"],
    ["currency", "Currency"],
    ["poNumber", "PO Number"],
    ["paymentTerms", "Payment Terms"],
    ["bankDetails", "Bank Details"],
  ];

  const [form, setForm] = useState<Record<string, any>>({
    name: "", code: "", logoUrl: "",
    msClientId: "", msClientSecret: "", msTenantId: "", msSenderEmail: "",
    msFetchEnabled: false, msFetchFolder: "INBOX",
    vecticumEnabled: false, vecticumApiBaseUrl: "", vecticumClientId: "",
    vecticumClientSecret: "", vecticumCompanyId: "",
    vecticumAuthorId: "", vecticumAuthorName: "",
    extractionFields: null,
  });

  const { data } = useQuery({
    queryKey: ["company", id],
    queryFn: () => api.get(`/companies/${id}`).then((r) => r.data),
    enabled: !!id,
  });

  // Determine user's role in this company
  const userRole: string = data?.company?.userRole
    || (user?.role === "superadmin" ? "superadmin" : "viewer");
  const canEdit = meetsRole(userRole, "admin");
  const canManageMembers = meetsRole(userRole, "admin");
  const canViewIntegrations = meetsRole(userRole, "manager");
  const canEditIntegrations = meetsRole(userRole, "admin");

  useEffect(() => {
    if (data?.company) {
      setForm((prev) => ({ ...prev, ...data.company }));
    }
  }, [data]);

  const { data: membersData, refetch: refetchMembers } = useQuery({
    queryKey: ["company-members", id],
    queryFn: () => api.get(`/companies/${id}/members`).then((r) => r.data),
    enabled: !!id && meetsRole(userRole, "manager"),
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
    onSuccess: () => {
      refetchMembers();
      resetMemberDialog();
      toast.success("Member added");
    },
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

  const resetMemberDialog = () => {
    setShowAddMember(false);
    setMemberMode("search");
    setMemberForm({ email: "", name: "", password: "", role: "manager" });
    setSearchQuery("");
    setSearchResults([]);
    setSelectedUser(null);
    setShowResults(false);
  };

  const handleSearch = (q: string) => {
    setSearchQuery(q);
    setSelectedUser(null);
    if (searchTimeout.current) clearTimeout(searchTimeout.current);
    if (q.length < 2) { setSearchResults([]); setShowResults(false); return; }
    searchTimeout.current = setTimeout(async () => {
      try {
        const { data } = await api.get(`/users/search?q=${encodeURIComponent(q)}`);
        setSearchResults(data.users || []);
        setShowResults(true);
      } catch {
        setSearchResults([]);
      }
    }, 300);
  };

  const selectUser = (u: SearchUser) => {
    setSelectedUser(u);
    setSearchQuery(u.email);
    setMemberForm((prev) => ({ ...prev, email: u.email, name: u.name }));
    setShowResults(false);
  };

  const handleAddMember = () => {
    if (memberMode === "search" && selectedUser) {
      addMemberMutation.mutate({ email: selectedUser.email, role: memberForm.role });
    } else if (memberMode === "create") {
      addMemberMutation.mutate({
        email: memberForm.email,
        name: memberForm.name,
        password: memberForm.password,
        role: memberForm.role,
      });
    }
  };

  const canAdd = memberMode === "search" ? !!selectedUser : (!!memberForm.email && !!memberForm.name && !!memberForm.password);

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

  const pageTitle = isNew ? "New Company" : canEdit ? "Edit Company" : "Company Details";

  return (
    <div className="space-y-4 max-w-2xl">
      <div className="flex items-center gap-4">
        <Button variant="ghost" size="icon" onClick={() => navigate(-1)}><ArrowLeft className="h-4 w-4" /></Button>
        <h2 className="text-xl font-semibold">{pageTitle}</h2>
        {!isNew && !canEdit && (
          <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded text-xs font-medium bg-gray-100 text-gray-600">
            <Eye className="h-3 w-3" />Read-only
          </span>
        )}
      </div>

      {/* General */}
      <Card>
        <CardHeader><CardTitle>General</CardTitle></CardHeader>
        <CardContent className="space-y-3">
          <div><label className="text-sm font-medium">Name</label><Input value={form.name} onChange={(e) => set("name", e.target.value)} disabled={!isNew && !canEdit} /></div>
          <div><label className="text-sm font-medium">Code</label><Input value={form.code} onChange={(e) => set("code", e.target.value)} disabled={!isNew && !canEdit} /></div>
        </CardContent>
      </Card>

      {!isNew && (
        <>
          {/* Extraction Fields - admin+ only */}
          {canEdit && (
            <Card>
              <CardHeader className="flex flex-row items-center justify-between">
                <CardTitle>Extraction Fields</CardTitle>
                <label className="flex items-center gap-2 text-sm text-gray-500">
                  <input
                    type="checkbox"
                    checked={form.extractionFields === null || form.extractionFields === undefined}
                    onChange={(e) => {
                      if (e.target.checked) {
                        set("extractionFields", null);
                      } else {
                        set("extractionFields", allExtractionFields.map(([k]) => k));
                      }
                    }}
                  />
                  All fields
                </label>
              </CardHeader>
              <CardContent>
                <p className="text-xs text-gray-500 mb-3">Select which fields to extract from invoices for this company. Unchecked fields will be skipped during AI extraction.</p>
                <div className="grid grid-cols-2 gap-2">
                  {allExtractionFields.map(([key, label]) => {
                    const isAllMode = form.extractionFields === null || form.extractionFields === undefined;
                    const isChecked = isAllMode || (Array.isArray(form.extractionFields) && form.extractionFields.includes(key));
                    return (
                      <label key={key} className="flex items-center gap-2 text-sm py-1">
                        <input
                          type="checkbox"
                          checked={isChecked}
                          disabled={isAllMode}
                          onChange={(e) => {
                            const current: string[] = Array.isArray(form.extractionFields) ? [...form.extractionFields] : allExtractionFields.map(([k]) => k);
                            if (e.target.checked) {
                              if (!current.includes(key)) current.push(key);
                            } else {
                              const idx = current.indexOf(key);
                              if (idx > -1) current.splice(idx, 1);
                            }
                            set("extractionFields", current);
                          }}
                        />
                        {label}
                      </label>
                    );
                  })}
                </div>
              </CardContent>
            </Card>
          )}

          {/* Members - manager can view, admin+ can manage */}
          {canViewIntegrations && (
            <Card>
              <CardHeader className="flex flex-row items-center justify-between">
                <CardTitle>Members</CardTitle>
                {canManageMembers && (
                  <Button size="sm" variant="outline" onClick={() => setShowAddMember(true)}>
                    <Plus className="h-3 w-3 mr-1" />Add Member
                  </Button>
                )}
              </CardHeader>
              <CardContent className="p-0">
                <Table>
                  <TableHeader>
                    <TableRow>
                      <TableHead>Name</TableHead>
                      <TableHead>Email</TableHead>
                      <TableHead>Role</TableHead>
                      {canManageMembers && <TableHead></TableHead>}
                    </TableRow>
                  </TableHeader>
                  <TableBody>
                    {(membersData?.members || []).map((m: any) => (
                      <TableRow key={m.userId || m.user_id}>
                        <TableCell>{m.name || m.userName}</TableCell>
                        <TableCell>{m.email || m.userEmail}</TableCell>
                        <TableCell>
                          {canManageMembers ? (
                            <select value={m.role} onChange={(e) => updateRoleMutation.mutate({ userId: m.userId || m.user_id, role: e.target.value })} className="border rounded px-2 py-1 text-sm">
                              <option value="viewer">Viewer</option>
                              <option value="manager">Manager</option>
                              <option value="admin">Admin</option>
                              <option value="owner">Owner</option>
                            </select>
                          ) : (
                            <span className="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-gray-100 text-gray-700 capitalize">{m.role}</span>
                          )}
                        </TableCell>
                        {canManageMembers && (
                          <TableCell>
                            <Button variant="ghost" size="icon" onClick={() => { if (confirm("Remove this member?")) removeMemberMutation.mutate(m.userId || m.user_id); }}>
                              <Trash2 className="h-3 w-3" />
                            </Button>
                          </TableCell>
                        )}
                      </TableRow>
                    ))}
                    {(membersData?.members || []).length === 0 && (
                      <TableRow><TableCell colSpan={canManageMembers ? 4 : 3} className="text-center text-gray-500">No members yet</TableCell></TableRow>
                    )}
                  </TableBody>
                </Table>
              </CardContent>
            </Card>
          )}

          {/* Microsoft 365 Email - manager can view, admin+ can edit */}
          {canViewIntegrations && (
            <Card>
              <CardHeader className="flex flex-row items-center justify-between">
                <CardTitle>Microsoft 365 Email</CardTitle>
                {canEditIntegrations ? (
                  <label className="flex items-center gap-2 text-sm">
                    <input type="checkbox" checked={form.msFetchEnabled as boolean} onChange={(e) => set("msFetchEnabled", e.target.checked)} />
                    Enabled
                  </label>
                ) : (
                  <span className={`inline-flex items-center px-2 py-0.5 rounded text-xs font-medium ${form.msFetchEnabled ? "bg-green-100 text-green-700" : "bg-gray-100 text-gray-500"}`}>
                    {form.msFetchEnabled ? "Enabled" : "Disabled"}
                  </span>
                )}
              </CardHeader>
              <CardContent className="space-y-3">
                <div><label className="text-sm font-medium">Tenant ID</label><Input value={form.msTenantId} onChange={(e) => set("msTenantId", e.target.value)} disabled={!canEditIntegrations} /></div>
                <div><label className="text-sm font-medium">Client ID</label><Input value={form.msClientId} onChange={(e) => set("msClientId", e.target.value)} disabled={!canEditIntegrations} /></div>
                <div><label className="text-sm font-medium">Client Secret</label><Input type="password" value={form.msClientSecret} onChange={(e) => set("msClientSecret", e.target.value)} disabled={!canEditIntegrations} /></div>
                <div><label className="text-sm font-medium">Sender Email</label><Input value={form.msSenderEmail} onChange={(e) => set("msSenderEmail", e.target.value)} disabled={!canEditIntegrations} /></div>
                <div><label className="text-sm font-medium">Folder</label><Input value={form.msFetchFolder} onChange={(e) => set("msFetchFolder", e.target.value)} disabled={!canEditIntegrations} /></div>
                {canEditIntegrations && <Button variant="outline" size="sm" onClick={testEmail}>Test Connection</Button>}
              </CardContent>
            </Card>
          )}

          {/* Vecticum - manager can view, admin+ can edit */}
          {canViewIntegrations && (
            <Card>
              <CardHeader className="flex flex-row items-center justify-between">
                <CardTitle>Vecticum</CardTitle>
                {canEditIntegrations ? (
                  <label className="flex items-center gap-2 text-sm">
                    <input type="checkbox" checked={form.vecticumEnabled as boolean} onChange={(e) => set("vecticumEnabled", e.target.checked)} />
                    Enabled
                  </label>
                ) : (
                  <span className={`inline-flex items-center px-2 py-0.5 rounded text-xs font-medium ${form.vecticumEnabled ? "bg-green-100 text-green-700" : "bg-gray-100 text-gray-500"}`}>
                    {form.vecticumEnabled ? "Enabled" : "Disabled"}
                  </span>
                )}
              </CardHeader>
              <CardContent className="space-y-3">
                <div><label className="text-sm font-medium">API Base URL</label><Input value={form.vecticumApiBaseUrl} onChange={(e) => set("vecticumApiBaseUrl", e.target.value)} disabled={!canEditIntegrations} /></div>
                <div><label className="text-sm font-medium">Client ID</label><Input value={form.vecticumClientId} onChange={(e) => set("vecticumClientId", e.target.value)} disabled={!canEditIntegrations} /></div>
                <div><label className="text-sm font-medium">Client Secret</label><Input type="password" value={form.vecticumClientSecret} onChange={(e) => set("vecticumClientSecret", e.target.value)} disabled={!canEditIntegrations} /></div>
                <div><label className="text-sm font-medium">Company ID (Endpoint)</label><Input value={form.vecticumCompanyId} onChange={(e) => set("vecticumCompanyId", e.target.value)} disabled={!canEditIntegrations} /></div>
                <div><label className="text-sm font-medium">Author ID</label><Input value={form.vecticumAuthorId} onChange={(e) => set("vecticumAuthorId", e.target.value)} disabled={!canEditIntegrations} /></div>
                <div><label className="text-sm font-medium">Author Name</label><Input value={form.vecticumAuthorName} onChange={(e) => set("vecticumAuthorName", e.target.value)} disabled={!canEditIntegrations} /></div>
                {canEditIntegrations && <Button variant="outline" size="sm" onClick={testVecticum}>Test Connection</Button>}
              </CardContent>
            </Card>
          )}
        </>
      )}

      {/* Save button - only for admin+ or new company */}
      {(isNew || canEdit) && (
        <Button onClick={() => saveMutation.mutate(form)} disabled={saveMutation.isPending}>
          <Save className="h-3 w-3 mr-1" />{isNew ? "Create" : "Save Changes"}
        </Button>
      )}

      {/* Add Member Dialog */}
      <Dialog open={showAddMember} onClose={resetMemberDialog}>
        <DialogTitle>Add Member</DialogTitle>
        <div className="space-y-4 mt-4">
          {/* Mode tabs */}
          <div className="flex gap-1 bg-gray-100 p-1 rounded-lg">
            <button
              onClick={() => { setMemberMode("search"); setSelectedUser(null); }}
              className={`flex-1 flex items-center justify-center gap-1.5 py-1.5 px-3 rounded text-sm font-medium transition-colors ${memberMode === "search" ? "bg-white shadow-sm" : "text-gray-500 hover:text-gray-700"}`}
            >
              <Search className="h-3.5 w-3.5" />Existing User
            </button>
            <button
              onClick={() => setMemberMode("create")}
              className={`flex-1 flex items-center justify-center gap-1.5 py-1.5 px-3 rounded text-sm font-medium transition-colors ${memberMode === "create" ? "bg-white shadow-sm" : "text-gray-500 hover:text-gray-700"}`}
            >
              <UserPlus className="h-3.5 w-3.5" />New User
            </button>
          </div>

          {memberMode === "search" ? (
            <div className="relative">
              <label className="text-sm font-medium">Search by name or email</label>
              <Input
                value={searchQuery}
                onChange={(e) => handleSearch(e.target.value)}
                onFocus={() => searchResults.length > 0 && setShowResults(true)}
                placeholder="Type to search..."
                autoFocus
              />
              {showResults && searchResults.length > 0 && (
                <div className="absolute z-10 w-full mt-1 bg-white border rounded-lg shadow-lg max-h-48 overflow-y-auto">
                  {searchResults.map((u) => (
                    <button
                      key={u.id}
                      onClick={() => selectUser(u)}
                      className="w-full text-left px-3 py-2 hover:bg-blue-50 border-b last:border-b-0"
                    >
                      <div className="text-sm font-medium">{u.name}</div>
                      <div className="text-xs text-gray-500">{u.email}</div>
                    </button>
                  ))}
                </div>
              )}
              {showResults && searchResults.length === 0 && searchQuery.length >= 2 && (
                <div className="absolute z-10 w-full mt-1 bg-white border rounded-lg shadow-lg p-3">
                  <p className="text-sm text-gray-500">No users found.</p>
                  <button onClick={() => { setMemberMode("create"); setMemberForm((f) => ({ ...f, email: searchQuery })); }} className="text-sm text-blue-600 hover:underline mt-1">
                    Create new user instead
                  </button>
                </div>
              )}
              {selectedUser && (
                <div className="mt-2 p-2 bg-blue-50 rounded-lg flex items-center gap-2">
                  <div className="flex-1">
                    <span className="text-sm font-medium">{selectedUser.name}</span>
                    <span className="text-xs text-gray-500 ml-2">{selectedUser.email}</span>
                  </div>
                  <button onClick={() => { setSelectedUser(null); setSearchQuery(""); }} className="text-xs text-gray-400 hover:text-gray-600">clear</button>
                </div>
              )}
            </div>
          ) : (
            <>
              <div>
                <label className="text-sm font-medium">Name</label>
                <Input value={memberForm.name} onChange={(e) => setMemberForm({ ...memberForm, name: e.target.value })} placeholder="John Doe" autoFocus />
              </div>
              <div>
                <label className="text-sm font-medium">Email</label>
                <Input value={memberForm.email} onChange={(e) => setMemberForm({ ...memberForm, email: e.target.value })} placeholder="user@example.com" />
              </div>
              <div>
                <label className="text-sm font-medium">Password</label>
                <Input type="password" value={memberForm.password} onChange={(e) => setMemberForm({ ...memberForm, password: e.target.value })} placeholder="Initial password" />
              </div>
            </>
          )}

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
            <Button variant="outline" onClick={resetMemberDialog}>Cancel</Button>
            <Button onClick={handleAddMember} disabled={addMemberMutation.isPending || !canAdd}>
              {memberMode === "create" ? <><UserPlus className="h-3 w-3 mr-1" />Create & Add</> : "Add"}
            </Button>
          </div>
        </div>
      </Dialog>
    </div>
  );
}
