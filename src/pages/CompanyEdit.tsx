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
    ["documentType", "Document Type"],
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
    name: "", code: "", vatNumber: "", buyerKeywords: "", logoUrl: "",
    msClientId: "", msClientSecret: "", msTenantId: "", msSenderEmail: "",
    msFetchEnabled: false, msFetchFolder: "INBOX",
    vecticumEnabled: false, vecticumAutoSend: false,
    vecticumApiBaseUrl: "https://app.vecticum.com/api/v1", vecticumClientId: "",
    vecticumClientSecret: "", vecticumCompanyId: "Rsk9Jv9bV7bGBFupWlE3",
    vecticumPartnerEndpoint: "gnzSuOSBmKbBdytb1OGc", vecticumInboxSetupId: "",
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
    onSuccess: (responseData) => {
      // Update form with the response data to prevent stale state
      if (responseData?.company) {
        setForm((prev) => ({ ...prev, ...responseData.company }));
      }
      queryClient.invalidateQueries({ queryKey: ["companies"] });
      queryClient.invalidateQueries({ queryKey: ["company", id] });
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
  // Helper: safely get string value for Input (null → "")
  const v = (key: string): string => form[key] ?? "";

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
    <div className="space-y-4">
      <div className="flex items-center gap-4">
        <Button variant="ghost" size="icon" onClick={() => navigate(-1)}><ArrowLeft className="h-4 w-4" /></Button>
        <div>
          <h2 className="text-2xl font-bold tracking-tight text-foreground">{pageTitle}</h2>
          {!isNew && !canEdit && (
            <span className="inline-flex items-center gap-1 rounded-full border px-2 py-0.5 text-xs font-medium bg-muted text-muted-foreground mt-1">
              <Eye className="h-3 w-3" />Read-only
            </span>
          )}
        </div>
      </div>

      {/* General */}
      <Card>
        <CardHeader><CardTitle>General</CardTitle></CardHeader>
        <CardContent className="space-y-4">
          <div className="space-y-1.5">
            <label className="text-sm font-medium text-foreground">Name</label>
            <Input value={v("name")} onChange={(e) => set("name", e.target.value)} disabled={!isNew && !canEdit} />
          </div>
          <div className="space-y-1.5">
            <label className="text-sm font-medium text-foreground">Code</label>
            <Input value={v("code")} onChange={(e) => set("code", e.target.value)} disabled={!isNew && !canEdit} />
          </div>
          <div className="space-y-1.5">
            <label className="text-sm font-medium text-foreground">VAT Number</label>
            <Input value={v("vatNumber")} onChange={(e) => set("vatNumber", e.target.value)} disabled={!isNew && !canEdit} placeholder="e.g. LT100007165618" />
          </div>
          <div className="space-y-1.5">
            <label className="text-sm font-medium text-foreground">Buyer Keywords</label>
            <Input value={v("buyerKeywords")} onChange={(e) => set("buyerKeywords", e.target.value)} disabled={!isNew && !canEdit} placeholder="e.g. Desmita Solutions, Desmita" />
            <p className="text-xs text-muted-foreground">Comma-separated keywords to match invoice buyer name. If buyer doesn't contain any keyword, it's flagged as mismatch.</p>
          </div>
        </CardContent>
      </Card>

      {!isNew && (
        <>
          {/* Extraction Fields - admin+ only */}
          {canEdit && (
            <Card>
              <CardHeader className="flex flex-row items-center justify-between">
                <CardTitle>Extraction Fields</CardTitle>
                <label className="flex items-center gap-2 text-sm text-muted-foreground">
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
                <p className="text-xs text-muted-foreground mb-3">Select which fields to extract from invoices for this company. Unchecked fields will be skipped during AI extraction.</p>
                <div className="grid grid-cols-2 gap-2">
                  {allExtractionFields.map(([key, label]) => {
                    const isAllMode = form.extractionFields === null || form.extractionFields === undefined;
                    const isChecked = isAllMode || (Array.isArray(form.extractionFields) && form.extractionFields.includes(key));
                    return (
                      <label key={key} className="flex items-center gap-2 text-sm py-1 text-foreground">
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
            <Card className="overflow-hidden">
              <CardHeader className="flex flex-row items-center justify-between">
                <CardTitle>Members</CardTitle>
                {canManageMembers && (
                  <Button size="sm" variant="outline" onClick={() => setShowAddMember(true)}>
                    <Plus className="h-3.5 w-3.5" /><span className="ml-1">Add Member</span>
                  </Button>
                )}
              </CardHeader>
              <CardContent className="p-0">
                <div className="overflow-x-auto">
                  <Table>
                    <TableHeader>
                      <TableRow className="bg-muted/30">
                        <TableHead className="font-semibold">Name</TableHead>
                        <TableHead className="font-semibold">Email</TableHead>
                        <TableHead className="font-semibold">Role</TableHead>
                        {canManageMembers && <TableHead></TableHead>}
                      </TableRow>
                    </TableHeader>
                    <TableBody>
                      {(membersData?.members || []).map((m: any) => (
                        <TableRow key={m.userId || m.user_id} className="hover:bg-primary/[0.03] transition-colors duration-150">
                          <TableCell className="font-medium">{m.name || m.userName}</TableCell>
                          <TableCell className="text-muted-foreground">{m.email || m.userEmail}</TableCell>
                          <TableCell>
                            {canManageMembers ? (
                              <select value={m.role} onChange={(e) => updateRoleMutation.mutate({ userId: m.userId || m.user_id, role: e.target.value })} className="border border-border rounded-md px-2 py-1 text-sm bg-background text-foreground">
                                <option value="viewer">Viewer</option>
                                <option value="manager">Manager</option>
                                <option value="admin">Admin</option>
                                <option value="owner">Owner</option>
                              </select>
                            ) : (
                              <span className="inline-flex items-center rounded-full border px-2 py-0.5 text-xs font-medium bg-muted/50 text-foreground capitalize">{m.role}</span>
                            )}
                          </TableCell>
                          {canManageMembers && (
                            <TableCell>
                              <Button variant="ghost" size="icon" onClick={() => { if (confirm("Remove this member?")) removeMemberMutation.mutate(m.userId || m.user_id); }}>
                                <Trash2 className="h-3.5 w-3.5" />
                              </Button>
                            </TableCell>
                          )}
                        </TableRow>
                      ))}
                      {(membersData?.members || []).length === 0 && (
                        <TableRow><TableCell colSpan={canManageMembers ? 4 : 3} className="text-center text-muted-foreground py-8">No members yet</TableCell></TableRow>
                      )}
                    </TableBody>
                  </Table>
                </div>
              </CardContent>
            </Card>
          )}

          {/* Microsoft 365 Email - manager can view, admin+ can edit */}
          {canViewIntegrations && (
            <Card>
              <CardHeader className="flex flex-row items-center justify-between">
                <CardTitle>Microsoft 365 Email</CardTitle>
                {canEditIntegrations ? (
                  <label className="flex items-center gap-2 text-sm text-foreground">
                    <input type="checkbox" checked={form.msFetchEnabled as boolean} onChange={(e) => set("msFetchEnabled", e.target.checked)} />
                    Enabled
                  </label>
                ) : (
                  <span className={`inline-flex items-center rounded-full border px-2 py-0.5 text-xs font-medium ${form.msFetchEnabled ? "bg-emerald-50 text-emerald-700 border-emerald-200" : "bg-muted text-muted-foreground border-border"}`}>
                    {form.msFetchEnabled ? "Enabled" : "Disabled"}
                  </span>
                )}
              </CardHeader>
              {form.msFetchEnabled && (
                <CardContent className="space-y-4">
                  <div className="space-y-1.5"><label className="text-sm font-medium text-foreground">Tenant ID</label><Input value={v("msTenantId")} onChange={(e) => set("msTenantId", e.target.value)} disabled={!canEditIntegrations} /></div>
                  <div className="space-y-1.5"><label className="text-sm font-medium text-foreground">Client ID</label><Input value={v("msClientId")} onChange={(e) => set("msClientId", e.target.value)} disabled={!canEditIntegrations} /></div>
                  <div className="space-y-1.5"><label className="text-sm font-medium text-foreground">Client Secret</label><Input type="password" value={v("msClientSecret")} onChange={(e) => set("msClientSecret", e.target.value)} disabled={!canEditIntegrations} /></div>
                  <div className="space-y-1.5">
                    <label className="text-sm font-medium text-foreground">Default Mailbox / Sender Email</label>
                    <Input value={v("msSenderEmail")} onChange={(e) => set("msSenderEmail", e.target.value)} disabled={!canEditIntegrations} />
                    <p className="text-xs text-muted-foreground">This mailbox is used both to fetch incoming invoices and to send issue replies back to suppliers/customers.</p>
                  </div>
                  <div className="space-y-1.5"><label className="text-sm font-medium text-foreground">Folder</label><Input value={v("msFetchFolder")} onChange={(e) => set("msFetchFolder", e.target.value)} disabled={!canEditIntegrations} /></div>
                  {canEditIntegrations && <Button variant="outline" size="sm" onClick={testEmail}>Test Connection</Button>}
                </CardContent>
              )}
            </Card>
          )}

          {/* Vecticum - manager can view, admin+ can edit */}
          {canViewIntegrations && (
            <Card>
              <CardHeader className="flex flex-row items-center justify-between">
                <CardTitle>Vecticum</CardTitle>
                {canEditIntegrations ? (
                  <label className="flex items-center gap-2 text-sm text-foreground">
                    <input type="checkbox" checked={form.vecticumEnabled as boolean} onChange={(e) => set("vecticumEnabled", e.target.checked)} />
                    Enabled
                  </label>
                ) : (
                  <span className={`inline-flex items-center rounded-full border px-2 py-0.5 text-xs font-medium ${form.vecticumEnabled ? "bg-emerald-50 text-emerald-700 border-emerald-200" : "bg-muted text-muted-foreground border-border"}`}>
                    {form.vecticumEnabled ? "Enabled" : "Disabled"}
                  </span>
                )}
              </CardHeader>
              {form.vecticumEnabled && (
                <CardContent className="space-y-4">
                  <div className="space-y-1.5"><label className="text-sm font-medium text-foreground">API Base URL</label><Input value={v("vecticumApiBaseUrl")} onChange={(e) => set("vecticumApiBaseUrl", e.target.value)} disabled={!canEditIntegrations} placeholder="https://app.vecticum.com/api/v1" /></div>
                  <div className="space-y-1.5"><label className="text-sm font-medium text-foreground">Client ID</label><Input value={v("vecticumClientId")} onChange={(e) => set("vecticumClientId", e.target.value)} disabled={!canEditIntegrations} /></div>
                  <div className="space-y-1.5"><label className="text-sm font-medium text-foreground">Client Secret</label><Input type="password" value={v("vecticumClientSecret")} onChange={(e) => set("vecticumClientSecret", e.target.value)} disabled={!canEditIntegrations} /></div>
                  <div className="space-y-1.5"><label className="text-sm font-medium text-foreground">Invoice Class ID</label><Input value={v("vecticumCompanyId")} onChange={(e) => set("vecticumCompanyId", e.target.value)} disabled={!canEditIntegrations} placeholder="Rsk9Jv9bV7bGBFupWlE3" /></div>
                  <div className="space-y-1.5"><label className="text-sm font-medium text-foreground">Partner Endpoint</label><Input value={v("vecticumPartnerEndpoint")} onChange={(e) => set("vecticumPartnerEndpoint", e.target.value)} disabled={!canEditIntegrations} placeholder="gnzSuOSBmKbBdytb1OGc" /></div>
                  <div className="space-y-1.5">
                    <label className="text-sm font-medium text-foreground">Inbox Setup ID</label>
                    <Input
                      value={v("vecticumInboxSetupId")}
                      onChange={(e) => set("vecticumInboxSetupId", e.target.value)}
                      disabled={!canEditIntegrations}
                      placeholder="e.g. CtZXWGwUHlwbFFFdLtdg"
                    />
                    <p className="text-xs text-muted-foreground">
                      Exact Vecticum `_inboxSetup` record ID used to resolve the correct default author for this company mailbox.
                    </p>
                  </div>
                  <label className="flex items-center gap-2 text-sm text-foreground pt-2">
                    <input type="checkbox" checked={form.vecticumAutoSend as boolean} onChange={(e) => set("vecticumAutoSend", e.target.checked)} disabled={!canEditIntegrations} />
                    Auto-send to Vecticum after OCR
                    <span className="text-xs text-muted-foreground">(only if buyer matches and no errors)</span>
                  </label>
                  {canEditIntegrations && <Button variant="outline" size="sm" onClick={testVecticum}>Test Connection</Button>}
                </CardContent>
              )}
            </Card>
          )}
        </>
      )}

      {/* Save button - only for admin+ or new company */}
      {(isNew || canEdit) && (
        <Button onClick={() => saveMutation.mutate(form)} disabled={saveMutation.isPending}>
          <Save className="h-3.5 w-3.5" /><span className="ml-1">{isNew ? "Create" : "Save Changes"}</span>
        </Button>
      )}

      {/* Add Member Dialog */}
      <Dialog open={showAddMember} onClose={resetMemberDialog}>
        <DialogTitle>Add Member</DialogTitle>
        <div className="space-y-4 mt-4">
          {/* Mode tabs */}
          <div className="flex gap-1 bg-muted p-1 rounded-lg">
            <button
              onClick={() => { setMemberMode("search"); setSelectedUser(null); }}
              className={`flex-1 flex items-center justify-center gap-1.5 py-1.5 px-3 rounded text-sm font-medium transition-colors ${memberMode === "search" ? "bg-background shadow-sm text-foreground" : "text-muted-foreground hover:text-foreground"}`}
            >
              <Search className="h-3.5 w-3.5" />Existing User
            </button>
            <button
              onClick={() => setMemberMode("create")}
              className={`flex-1 flex items-center justify-center gap-1.5 py-1.5 px-3 rounded text-sm font-medium transition-colors ${memberMode === "create" ? "bg-background shadow-sm text-foreground" : "text-muted-foreground hover:text-foreground"}`}
            >
              <UserPlus className="h-3.5 w-3.5" />New User
            </button>
          </div>

          {memberMode === "search" ? (
            <div className="relative">
              <div className="space-y-1.5">
                <label className="text-sm font-medium text-foreground">Search by name or email</label>
                <Input
                  value={searchQuery}
                  onChange={(e) => handleSearch(e.target.value)}
                  onFocus={() => searchResults.length > 0 && setShowResults(true)}
                  placeholder="Type to search..."
                  autoFocus
                />
              </div>
              {showResults && searchResults.length > 0 && (
                <div className="absolute z-10 w-full mt-1 bg-card border border-border rounded-lg shadow-lg max-h-48 overflow-y-auto">
                  {searchResults.map((u) => (
                    <button
                      key={u.id}
                      onClick={() => selectUser(u)}
                      className="w-full text-left px-3 py-2 hover:bg-primary/[0.05] border-b border-border last:border-b-0 transition-colors"
                    >
                      <div className="text-sm font-medium text-foreground">{u.name}</div>
                      <div className="text-xs text-muted-foreground">{u.email}</div>
                    </button>
                  ))}
                </div>
              )}
              {showResults && searchResults.length === 0 && searchQuery.length >= 2 && (
                <div className="absolute z-10 w-full mt-1 bg-card border border-border rounded-lg shadow-lg p-3">
                  <p className="text-sm text-muted-foreground">No users found.</p>
                  <button onClick={() => { setMemberMode("create"); setMemberForm((f) => ({ ...f, email: searchQuery })); }} className="text-sm text-primary hover:underline mt-1">
                    Create new user instead
                  </button>
                </div>
              )}
              {selectedUser && (
                <div className="mt-2 p-2 bg-primary/[0.05] rounded-lg flex items-center gap-2 border border-primary/20">
                  <div className="flex-1">
                    <span className="text-sm font-medium text-foreground">{selectedUser.name}</span>
                    <span className="text-xs text-muted-foreground ml-2">{selectedUser.email}</span>
                  </div>
                  <button onClick={() => { setSelectedUser(null); setSearchQuery(""); }} className="text-xs text-muted-foreground hover:text-foreground transition-colors">clear</button>
                </div>
              )}
            </div>
          ) : (
            <>
              <div className="space-y-1.5">
                <label className="text-sm font-medium text-foreground">Name</label>
                <Input value={memberForm.name} onChange={(e) => setMemberForm({ ...memberForm, name: e.target.value })} placeholder="John Doe" autoFocus />
              </div>
              <div className="space-y-1.5">
                <label className="text-sm font-medium text-foreground">Email</label>
                <Input value={memberForm.email} onChange={(e) => setMemberForm({ ...memberForm, email: e.target.value })} placeholder="user@example.com" />
              </div>
              <div className="space-y-1.5">
                <label className="text-sm font-medium text-foreground">Password</label>
                <Input type="password" value={memberForm.password} onChange={(e) => setMemberForm({ ...memberForm, password: e.target.value })} placeholder="Initial password" />
              </div>
            </>
          )}

          <div className="space-y-1.5">
            <label className="text-sm font-medium text-foreground">Role</label>
            <select value={memberForm.role} onChange={(e) => setMemberForm({ ...memberForm, role: e.target.value })} className="w-full border border-border rounded-md px-3 py-1.5 text-sm bg-background text-foreground">
              <option value="viewer">Viewer</option>
              <option value="manager">Manager</option>
              <option value="admin">Admin</option>
              <option value="owner">Owner</option>
            </select>
          </div>

          <div className="flex justify-end gap-2 pt-2">
            <Button variant="outline" onClick={resetMemberDialog}>Cancel</Button>
            <Button onClick={handleAddMember} disabled={addMemberMutation.isPending || !canAdd}>
              {memberMode === "create" ? <><UserPlus className="h-3.5 w-3.5" /><span className="ml-1">Create & Add</span></> : "Add"}
            </Button>
          </div>
        </div>
      </Dialog>
    </div>
  );
}
