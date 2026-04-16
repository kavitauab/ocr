import { Outlet, NavLink, useNavigate, useLocation } from "react-router-dom";
import { useAuth } from "@/lib/auth";
import { useCompany } from "@/lib/company";
import { useState, useEffect } from "react";
import { Sheet } from "@/components/ui/sheet";
import { Tooltip } from "@/components/ui/tooltip";
import { Avatar } from "@/components/ui/avatar";
import { DropdownMenu, DropdownItem, DropdownSeparator } from "@/components/ui/dropdown-menu";
import {
  LayoutDashboard,
  FileText,
  Upload,
  Mail,
  Settings,
  LogOut,
  Building2,
  Users,
  ChevronDown,
  Wrench,
  CreditCard,
  Menu,
  X,
  Activity,
} from "lucide-react";

const ALL_COMPANIES_VALUE = "__all__";

export default function Layout() {
  const { user, logout } = useAuth();
  const { companies, selectedCompany, switchCompany, hasCompanyRole, isSuperadmin } = useCompany();
  const navigate = useNavigate();
  const location = useLocation();

  const isSettingsPath = location.pathname.startsWith("/settings");
  const [settingsOpen, setSettingsOpen] = useState(isSettingsPath);
  const [mobileOpen, setMobileOpen] = useState(false);

  useEffect(() => {
    if (isSettingsPath) setSettingsOpen(true);
  }, [isSettingsPath]);

  // Close mobile drawer on route change
  useEffect(() => {
    setMobileOpen(false);
  }, [location.pathname]);

  // Set browser tab title based on route
  useEffect(() => {
    const titles: Record<string, string> = {
      "/dashboard": "Dashboard",
      "/invoices": "Invoices",
      "/upload": "Upload",
      "/emails": "Emails",
      "/profile": "Profile",
      "/settings/companies": "Companies",
      "/settings/billing": "Billing",
      "/settings/users": "Users",
      "/settings/system": "System",
      "/settings/health": "Health",
    };
    const path = location.pathname;
    const match = titles[path]
      || (path.startsWith("/invoices/") ? "Invoice Detail" : null)
      || (path.startsWith("/settings/companies/") ? "Company Settings" : null)
      || (path.startsWith("/settings/billing/") ? "Billing" : null);
    document.title = match ? `${match} — Gentrula` : "Gentrula";
  }, [location.pathname]);

  const handleLogout = () => {
    logout();
    navigate("/login");
  };

  const mainNavItems = [
    { to: "/dashboard", label: "Dashboard", icon: LayoutDashboard },
    { to: "/invoices", label: "Invoices", icon: FileText },
    ...(hasCompanyRole("manager")
      ? [
          { to: "/upload", label: "Upload", icon: Upload },
          { to: "/emails", label: "Emails", icon: Mail },
        ]
      : []),
  ];

  const settingsSubItems = [
    ...(isSuperadmin
      ? [{ to: "/settings/companies", label: "Companies", icon: Building2 }]
      : hasCompanyRole("admin") && selectedCompany
        ? [{ to: `/settings/companies/${selectedCompany.id}`, label: "Company", icon: Building2 }]
        : []),
    ...(isSuperadmin
      ? [
          { to: "/settings/billing", label: "Billing", icon: CreditCard },
          { to: "/settings/users", label: "Users", icon: Users },
          { to: "/settings/system", label: "System", icon: Wrench },
          { to: "/settings/health", label: "Health", icon: Activity },
        ]
      : []),
  ];

  const navContent = (isCollapsed: boolean) => (
    <>
      {/* Logo */}
      <div className={`flex items-center border-b border-border/60 ${isCollapsed ? "justify-center p-3" : "px-4 py-4"}`}>
        <span className={`font-semibold tracking-tight text-foreground ${isCollapsed ? "text-base" : "text-lg"}`} style={{fontFamily: "'Playfair Display', serif"}}>
          {isCollapsed ? "G" : "Gentrula"}<span className="text-[#b8965c]">.</span>
        </span>
      </div>

      {/* Company switcher */}
      {companies.length > 1 && (
        <div className={`border-b border-border/60 ${isCollapsed ? "p-2" : "px-3 py-2.5"}`}>
          {isCollapsed ? (
            <Tooltip content={selectedCompany?.name || (isSuperadmin ? "All companies" : "Select company")} side="right">
              <div className="flex h-8 w-8 mx-auto items-center justify-center rounded-md bg-muted text-xs font-semibold text-muted-foreground cursor-default">
                {(selectedCompany?.name || (isSuperadmin ? "All companies" : "?"))[0].toUpperCase()}
              </div>
            </Tooltip>
          ) : (
            <select
              value={selectedCompany?.id || (isSuperadmin ? ALL_COMPANIES_VALUE : "")}
              onChange={(e) => switchCompany(e.target.value)}
              className="w-full text-sm border border-border rounded-md px-2.5 py-1.5 bg-card text-foreground focus:ring-2 focus:ring-ring/20 focus:border-primary-light transition-colors"
            >
              {isSuperadmin && <option value={ALL_COMPANIES_VALUE}>All companies</option>}
              {companies.map((c) => (
                <option key={c.id} value={c.id}>{c.name}</option>
              ))}
            </select>
          )}
        </div>
      )}

      {/* Navigation */}
      <nav className="flex-1 overflow-y-auto custom-scrollbar px-2 py-2 space-y-0.5">
        {mainNavItems.map(({ to, label, icon: Icon }) =>
          isCollapsed ? (
            <Tooltip key={to} content={label} side="right">
              <NavLink
                to={to}
                className={({ isActive }) =>
                  `flex items-center justify-center h-9 w-9 mx-auto rounded-lg transition-all duration-150 ${
                    isActive
                      ? "bg-primary/10 text-primary shadow-sm"
                      : "text-muted-foreground hover:bg-muted hover:text-foreground"
                  }`
                }
              >
                <Icon className="h-[18px] w-[18px]" />
              </NavLink>
            </Tooltip>
          ) : (
            <NavLink
              key={to}
              to={to}
              className={({ isActive }) =>
                `flex items-center gap-2.5 px-3 py-2 rounded-lg text-sm transition-all duration-150 ${
                  isActive
                    ? "bg-primary/10 text-primary font-medium shadow-sm"
                    : "text-muted-foreground hover:bg-muted hover:text-foreground"
                }`
              }
            >
              <Icon className="h-[18px] w-[18px] shrink-0" />
              {label}
            </NavLink>
          )
        )}

        {/* Settings */}
        {settingsSubItems.length > 0 && (
          <>
            <div className="pt-2 pb-1">
              {!isCollapsed && (
                <div className="px-3 text-[10px] font-semibold uppercase tracking-widest text-muted-foreground/60">
                  Settings
                </div>
              )}
              {isCollapsed && <div className="mx-auto w-6 border-t border-border/60" />}
            </div>

            {isCollapsed ? (
              settingsSubItems.map(({ to, label, icon: Icon }) => (
                <Tooltip key={to} content={label} side="right">
                  <NavLink
                    to={to}
                    className={({ isActive }) =>
                      `flex items-center justify-center h-9 w-9 mx-auto rounded-lg transition-all duration-150 ${
                        isActive
                          ? "bg-primary/10 text-primary shadow-sm"
                          : "text-muted-foreground hover:bg-muted hover:text-foreground"
                      }`
                    }
                  >
                    <Icon className="h-[18px] w-[18px]" />
                  </NavLink>
                </Tooltip>
              ))
            ) : (
              <>
                <button
                  onClick={() => setSettingsOpen(!settingsOpen)}
                  className={`flex items-center justify-between w-full px-3 py-2 rounded-lg text-sm transition-all duration-150 ${
                    isSettingsPath
                      ? "bg-primary/10 text-primary font-medium"
                      : "text-muted-foreground hover:bg-muted hover:text-foreground"
                  }`}
                >
                  <span className="flex items-center gap-2.5">
                    <Settings className="h-[18px] w-[18px]" />
                    Settings
                  </span>
                  <ChevronDown
                    className={`h-3.5 w-3.5 transition-transform duration-200 ${settingsOpen ? "rotate-180" : ""}`}
                  />
                </button>
                <div
                  className={`overflow-hidden transition-all duration-200 ${
                    settingsOpen ? "max-h-60 opacity-100" : "max-h-0 opacity-0"
                  }`}
                >
                  <div className="ml-3 pl-3 border-l border-border/60 space-y-0.5 py-0.5">
                    {settingsSubItems.map(({ to, label, icon: Icon }) => (
                      <NavLink
                        key={to}
                        to={to}
                        className={({ isActive }) =>
                          `flex items-center gap-2 px-2.5 py-1.5 rounded-md text-sm transition-all duration-150 ${
                            isActive
                              ? "bg-primary/8 text-primary font-medium"
                              : "text-muted-foreground hover:bg-muted hover:text-foreground"
                          }`
                        }
                      >
                        <Icon className="h-3.5 w-3.5" />
                        {label}
                      </NavLink>
                    ))}
                  </div>
                </div>
              </>
            )}
          </>
        )}
      </nav>

      {/* User section */}
      <div className={`border-t border-border/60 ${isCollapsed ? "p-2" : "px-3 py-3"}`}>
        {isCollapsed ? (
          <Tooltip content={user?.name || user?.email || "User"} side="right">
            <button onClick={() => navigate("/profile")} className="flex items-center justify-center mx-auto text-xs font-medium text-muted-foreground hover:text-foreground transition-colors">
              {(user?.name || "U")[0].toUpperCase()}
            </button>
          </Tooltip>
        ) : (
          <DropdownMenu
            position="top"
            trigger={
              <button className="flex items-center gap-2.5 w-full px-2 py-1.5 rounded-lg hover:bg-muted transition-colors text-left">
                <div className="flex-1 min-w-0">
                  <div className="text-xs font-medium text-foreground truncate">{user?.name || user?.email}</div>
                  <div className="text-[10px] text-muted-foreground capitalize">{user?.role || "user"}</div>
                </div>
                <ChevronDown className="h-3 w-3 text-muted-foreground shrink-0" />
              </button>
            }
            align="left"
          >
            <DropdownItem onClick={() => navigate("/profile")}>
              <Users className="h-3.5 w-3.5" />
              Profile
            </DropdownItem>
            <DropdownSeparator />
            <DropdownItem onClick={handleLogout} variant="destructive">
              <LogOut className="h-3.5 w-3.5" />
              Sign out
            </DropdownItem>
          </DropdownMenu>
        )}
      </div>
    </>
  );

  return (
    <div className="flex min-h-screen bg-background">
      {/* Desktop sidebar */}
      <aside
        className="hidden lg:flex lg:flex-col fixed inset-y-0 left-0 z-30 w-52 bg-card border-r border-border/60 sidebar-transition"
      >
        {navContent(false)}
      </aside>

      {/* Mobile header */}
      <header className="lg:hidden fixed top-0 inset-x-0 z-40 h-14 bg-card/95 backdrop-blur-sm border-b border-border/60 flex items-center px-4 gap-3">
        <button
          onClick={() => setMobileOpen(true)}
          className="flex items-center justify-center h-9 w-9 rounded-lg hover:bg-muted transition-colors"
        >
          <Menu className="h-5 w-5 text-foreground" />
        </button>
        <span className="font-semibold text-lg text-foreground" style={{fontFamily: "'Playfair Display', serif"}}>Gentrula<span className="text-[#b8965c]">.</span></span>
        <div className="flex-1" />
      </header>

      {/* Mobile drawer */}
      <Sheet open={mobileOpen} onClose={() => setMobileOpen(false)} side="left">
        <div className="flex items-center justify-between px-4 py-3 border-b border-border/60">
          <span className="font-semibold text-lg" style={{fontFamily: "'Playfair Display', serif"}}>Gentrula<span className="text-[#b8965c]">.</span></span>
          <button
            onClick={() => setMobileOpen(false)}
            className="flex items-center justify-center h-8 w-8 rounded-lg hover:bg-muted transition-colors"
          >
            <X className="h-4 w-4" />
          </button>
        </div>

        {/* Company switcher for mobile */}
        {companies.length > 1 && (
          <div className="px-3 py-2.5 border-b border-border/60">
            <select
              value={selectedCompany?.id || (isSuperadmin ? ALL_COMPANIES_VALUE : "")}
              onChange={(e) => switchCompany(e.target.value)}
              className="w-full text-sm border border-border rounded-md px-2.5 py-1.5 bg-card text-foreground"
            >
              {isSuperadmin && <option value={ALL_COMPANIES_VALUE}>All companies</option>}
              {companies.map((c) => (
                <option key={c.id} value={c.id}>{c.name}</option>
              ))}
            </select>
          </div>
        )}

        <nav className="flex-1 overflow-y-auto px-2 py-2 space-y-0.5">
          {mainNavItems.map(({ to, label, icon: Icon }) => (
            <NavLink
              key={to}
              to={to}
              className={({ isActive }) =>
                `flex items-center gap-2.5 px-3 py-2.5 rounded-lg text-sm transition-colors ${
                  isActive
                    ? "bg-primary/10 text-primary font-medium"
                    : "text-muted-foreground hover:bg-muted hover:text-foreground"
                }`
              }
            >
              <Icon className="h-[18px] w-[18px]" />
              {label}
            </NavLink>
          ))}

          {settingsSubItems.length > 0 && (
            <>
              <div className="px-3 pt-3 pb-1 text-[10px] font-semibold uppercase tracking-widest text-muted-foreground/60">
                Settings
              </div>
              {settingsSubItems.map(({ to, label, icon: Icon }) => (
                <NavLink
                  key={to}
                  to={to}
                  className={({ isActive }) =>
                    `flex items-center gap-2.5 px-3 py-2.5 rounded-lg text-sm transition-colors ${
                      isActive
                        ? "bg-primary/10 text-primary font-medium"
                        : "text-muted-foreground hover:bg-muted hover:text-foreground"
                    }`
                  }
                >
                  <Icon className="h-[18px] w-[18px]" />
                  {label}
                </NavLink>
              ))}
            </>
          )}
        </nav>

        <div className="border-t border-border/60 px-3 py-3">
          <button
            onClick={handleLogout}
            className="flex items-center gap-2.5 w-full px-3 py-2 rounded-lg text-sm text-red-600 hover:bg-red-50 transition-colors"
          >
            <LogOut className="h-[18px] w-[18px]" />
            Sign out
          </button>
        </div>
      </Sheet>

      {/* Main content */}
      <main className="flex-1 min-h-screen sidebar-transition pt-14 lg:pt-0 lg:ml-52">
        <div className="p-4 md:p-5 lg:p-6 animate-fade-in">
          <Outlet />
        </div>
      </main>
    </div>
  );
}
