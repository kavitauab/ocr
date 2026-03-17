import { NavLink, Outlet, Navigate } from "react-router-dom";
import { useAuth } from "@/lib/auth";

export default function Settings() {
  const { user } = useAuth();

  const links = [
    { to: "/settings/companies", label: "Companies" },
    ...(user?.role === "superadmin" ? [{ to: "/settings/users", label: "Users" }] : []),
  ];

  return (
    <div className="space-y-4">
      <h1 className="text-2xl font-bold">Settings</h1>
      <div className="flex gap-2 border-b pb-2">
        {links.map(({ to, label }) => (
          <NavLink
            key={to}
            to={to}
            className={({ isActive }) =>
              `px-3 py-1.5 text-sm rounded-md ${isActive ? "bg-blue-50 text-blue-700 font-medium" : "text-gray-600 hover:bg-gray-100"}`
            }
          >
            {label}
          </NavLink>
        ))}
      </div>
    </div>
  );
}
