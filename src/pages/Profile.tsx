import { useState, useEffect } from "react";
import { useMutation } from "@tanstack/react-query";
import { useNavigate } from "react-router-dom";
import api from "@/api/client";
import { useAuth } from "@/lib/auth";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { toast } from "sonner";
import { ArrowLeft, Save } from "lucide-react";

export default function Profile() {
  const navigate = useNavigate();
  const { user, refreshUser } = useAuth();
  const [form, setForm] = useState({ name: "", email: "", password: "" });

  useEffect(() => {
    if (user) {
      setForm({ name: user.name || "", email: user.email || "", password: "" });
    }
  }, [user]);

  const saveMutation = useMutation({
    mutationFn: (body: any) => api.patch(`/users/${user?.id}`, body).then((r) => r.data),
    onSuccess: () => {
      refreshUser();
      toast.success("Profile updated");
    },
    onError: (err: any) => toast.error(err.response?.data?.error || "Failed to save"),
  });

  const handleSave = () => {
    const body: any = { name: form.name, email: form.email };
    if (form.password) body.password = form.password;
    saveMutation.mutate(body);
  };

  return (
    <div className="space-y-4 max-w-xl">
      <div className="flex items-center gap-2">
        <button onClick={() => navigate(-1)} className="flex h-7 w-7 items-center justify-center rounded-md hover:bg-muted transition-colors">
          <ArrowLeft className="h-3.5 w-3.5 text-muted-foreground" />
        </button>
        <h2 className="text-2xl font-bold tracking-tight text-foreground">Profile</h2>
      </div>

      <Card>
        <CardHeader>
          <CardTitle>Account Information</CardTitle>
        </CardHeader>
        <CardContent className="space-y-4">
          <div className="space-y-1.5">
            <label className="text-sm font-medium text-foreground">Name</label>
            <Input value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} />
          </div>
          <div className="space-y-1.5">
            <label className="text-sm font-medium text-foreground">Email</label>
            <Input value={form.email} onChange={(e) => setForm({ ...form, email: e.target.value })} />
          </div>
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle>Change Password</CardTitle>
        </CardHeader>
        <CardContent className="space-y-4">
          <div className="space-y-1.5">
            <label className="text-sm font-medium text-foreground">New Password</label>
            <Input type="password" value={form.password} onChange={(e) => setForm({ ...form, password: e.target.value })} placeholder="Leave blank to keep current" />
          </div>
        </CardContent>
      </Card>

      <Button onClick={handleSave} disabled={saveMutation.isPending}>
        <Save className="h-3.5 w-3.5" /><span className="ml-1">Save Changes</span>
      </Button>
    </div>
  );
}
