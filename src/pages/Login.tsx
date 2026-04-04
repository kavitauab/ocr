import { useState } from "react";
import { useNavigate } from "react-router-dom";
import { useAuth } from "@/lib/auth";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Card, CardContent } from "@/components/ui/card";
import { Loader2, Mail, Lock } from "lucide-react";

export default function Login() {
  const navigate = useNavigate();
  const { login } = useAuth();
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [error, setError] = useState("");
  const [loading, setLoading] = useState(false);

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setError("");
    setLoading(true);
    try {
      await login(email, password);
      navigate("/dashboard");
    } catch (err: any) {
      setError(err.response?.data?.error || "Login failed");
    }
    setLoading(false);
  };

  return (
    <div className="flex min-h-screen">
      {/* Brand panel - desktop only */}
      <div className="hidden lg:flex lg:w-1/2 bg-[#1a1a1a] relative overflow-hidden">
        <div className="absolute inset-0 opacity-10">
          <div className="absolute top-20 left-20 h-64 w-64 rounded-full bg-[#b8965c]/30 blur-3xl" />
          <div className="absolute bottom-20 right-20 h-96 w-96 rounded-full bg-[#b8965c]/20 blur-3xl" />
        </div>
        <div className="relative z-10 flex flex-col justify-center px-16">
          <div className="flex items-center gap-3 mb-8">
            <div className="flex h-12 w-12 items-center justify-center rounded-xl bg-[#b8965c]/20 backdrop-blur-sm text-[#b8965c] font-bold text-lg shadow-lg" style={{fontFamily: "'Playfair Display', serif"}}>
              G
            </div>
            <span className="text-2xl font-bold text-white tracking-tight" style={{fontFamily: "'Playfair Display', serif"}}>Gentrula<span className="text-[#b8965c]">.</span></span>
          </div>
          <h2 className="text-4xl font-bold text-white leading-tight mb-4" style={{fontFamily: "'Playfair Display', serif"}}>
            AI-Powered<br />Invoice Processing
          </h2>
          <p className="text-[#b0b0b0] text-lg leading-relaxed max-w-md">
            Extract data from invoices automatically with high accuracy. Save time and reduce manual entry errors.
          </p>
          <div className="mt-10 flex items-center gap-6">
            <div className="text-center">
              <div className="text-2xl font-bold text-white">99%</div>
              <div className="text-xs text-[#b0b0b0] mt-0.5">Accuracy</div>
            </div>
            <div className="h-8 w-px bg-white/20" />
            <div className="text-center">
              <div className="text-2xl font-bold text-white">&lt;10s</div>
              <div className="text-xs text-[#b0b0b0] mt-0.5">Per Invoice</div>
            </div>
            <div className="h-8 w-px bg-white/20" />
            <div className="text-center">
              <div className="text-2xl font-bold text-white">PDF</div>
              <div className="text-xs text-[#b0b0b0] mt-0.5">& Images</div>
            </div>
          </div>
        </div>
      </div>

      {/* Login form */}
      <div className="flex-1 flex items-center justify-center p-6 bg-background">
        <div className="w-full max-w-sm">
          {/* Mobile logo */}
          <div className="lg:hidden flex flex-col items-center mb-8">
            <div className="flex h-12 w-12 items-center justify-center rounded-xl bg-[#1a1a1a] text-[#b8965c] font-bold text-lg shadow-lg mb-3" style={{fontFamily: "'Playfair Display', serif"}}>
              G
            </div>
            <span className="text-xl font-bold text-foreground tracking-tight" style={{fontFamily: "'Playfair Display', serif"}}>Gentrula<span className="text-[#b8965c]">.</span></span>
          </div>

          <Card className="shadow-xl border-border/50">
            <CardContent className="p-8">
              <div className="mb-6">
                <h1 className="text-xl font-bold text-foreground">Welcome back</h1>
                <p className="text-sm text-muted-foreground mt-1">Sign in to your account to continue</p>
              </div>

              <form onSubmit={handleSubmit} className="space-y-4">
                {error && (
                  <div className="flex items-center gap-2 text-sm text-red-600 bg-red-50 border border-red-200 px-3 py-2.5 rounded-lg animate-fade-in">
                    <div className="h-1.5 w-1.5 rounded-full bg-red-500 shrink-0" />
                    {error}
                  </div>
                )}

                <div className="space-y-1.5">
                  <label htmlFor="email" className="text-sm font-medium text-foreground">Email</label>
                  <div className="relative">
                    <Mail className="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-muted-foreground" />
                    <Input
                      id="email"
                      type="email"
                      value={email}
                      onChange={(e) => setEmail(e.target.value)}
                      placeholder="you@company.com"
                      className={`pl-9 ${error ? "border-red-300 focus:ring-red-200" : ""}`}
                      required
                    />
                  </div>
                </div>

                <div className="space-y-1.5">
                  <label htmlFor="password" className="text-sm font-medium text-foreground">Password</label>
                  <div className="relative">
                    <Lock className="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-muted-foreground" />
                    <Input
                      id="password"
                      type="password"
                      value={password}
                      onChange={(e) => setPassword(e.target.value)}
                      placeholder="\u2022\u2022\u2022\u2022\u2022\u2022\u2022\u2022"
                      className={`pl-9 ${error ? "border-red-300 focus:ring-red-200" : ""}`}
                      required
                    />
                  </div>
                </div>

                <Button type="submit" className="w-full h-10 mt-2" disabled={loading}>
                  {loading ? (
                    <span className="flex items-center gap-2">
                      <Loader2 className="h-4 w-4 animate-spin" />
                      Signing in...
                    </span>
                  ) : (
                    "Sign in"
                  )}
                </Button>
              </form>
            </CardContent>
          </Card>

          <p className="text-center text-xs text-muted-foreground mt-6">
            Powered by Claude AI &middot; Anthropic
          </p>
        </div>
      </div>
    </div>
  );
}
