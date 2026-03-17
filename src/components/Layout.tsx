import { Outlet, NavLink, useNavigate, useLocation } from "react-router-dom";
import { useAuth } from "@/lib/auth";
import { useCompany } from "@/lib/company";
import { useState, useEffect } from "react";
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
} from "lucide-react";

export default function Layout() {
  const { user, logout } = useAuth();
  const { companies, selectedCompany, switchCompany, hasCompanyRole, isSuperadmin } = useCompany();
  const navigate = useNavigate();
  const location = useLocation();

  const isSettingsPath = location.pathname.startsWith("/settings");
  const [settingsOpen, setSettingsOpen] = useState(isSettingsPath);

  useEffect(() => {
    if (isSettingsPath) setSettingsOpen(true);
  }, [isSettingsPath]);

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
          { to: "/settings/users", label: "Users", icon: Users },
          { to: "/settings/system", label: "System", icon: Wrench },
        ]
      : []),
  ];

  return (
    <div className="flex min-h-screen bg-gray-50">
      <aside className="w-56 border-r bg-white flex flex-col">
        <div className="p-4 border-b">
          <div className="flex items-center gap-2">
            <div className="flex h-8 w-8 items-center justify-center rounded-md bg-blue-600 text-white font-bold text-sm">
              S
            </div>
            <span className="font-semibold text-sm">ScanInvoice</span>
          </div>
        </div>

        {companies.length > 1 && (
          <div className="p-3 border-b">
            <select
              value={selectedCompany?.id || ""}
              onChange={(e) => switchCompany(e.target.value)}
              className="w-full text-sm border rounded px-2 py-1.5"
            >
              {companies.map((c) => (
                <option key={c.id} value={c.id}>
                  {c.name}
                </option>
              ))}
            </select>
          </div>
        )}

        <nav className="flex-1 p-2">
          {mainNavItems.map(({ to, label, icon: Icon }) => (
            <NavLink
              key={to}
              to={to}
              className={({ isActive }) =>
                `flex items-center gap-2 px-3 py-2 rounded-md text-sm ${
                  isActive
                    ? "bg-blue-50 text-blue-700 font-medium"
                    : "text-gray-600 hover:bg-gray-100"
                }`
              }
            >
              <Icon className="h-4 w-4" />
              {label}
            </NavLink>
          ))}

          {/* Settings expandable - only show if there are sub-items */}
          {settingsSubItems.length > 0 && (
            <>
              <button
                onClick={() => setSettingsOpen(!settingsOpen)}
                className={`flex items-center justify-between w-full px-3 py-2 rounded-md text-sm mt-1 ${
                  isSettingsPath
                    ? "bg-blue-50 text-blue-700 font-medium"
                    : "text-gray-600 hover:bg-gray-100"
                }`}
              >
                <span className="flex items-center gap-2">
                  <Settings className="h-4 w-4" />
                  Settings
                </span>
                <ChevronDown
                  className={`h-3 w-3 transition-transform ${settingsOpen ? "rotate-180" : ""}`}
                />
              </button>
              {settingsOpen && (
                <div className="ml-4 mt-0.5 space-y-0.5">
                  {settingsSubItems.map(({ to, label, icon: Icon }) => (
                    <NavLink
                      key={to}
                      to={to}
                      className={({ isActive }) =>
                        `flex items-center gap-2 px-3 py-1.5 rounded-md text-sm ${
                          isActive
                            ? "bg-blue-50 text-blue-700 font-medium"
                            : "text-gray-500 hover:bg-gray-100"
                        }`
                      }
                    >
                      <Icon className="h-3.5 w-3.5" />
                      {label}
                    </NavLink>
                  ))}
                </div>
              )}
            </>
          )}
        </nav>

        <div className="p-3 border-t">
          <div className="flex items-center justify-between">
            <div className="text-xs text-gray-500 truncate">
              {user?.email}
            </div>
            <button onClick={handleLogout} className="p-1 text-gray-400 hover:text-gray-600">
              <LogOut className="h-4 w-4" />
            </button>
          </div>
        </div>
      </aside>

      <main className="flex-1 p-6 overflow-auto">
        <Outlet />
      </main>
    </div>
  );
}
