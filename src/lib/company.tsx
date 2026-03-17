import { createContext, useContext, useState, useEffect, type ReactNode } from "react";
import { useAuth } from "./auth";
import api from "@/api/client";

interface Company {
  id: string;
  name: string;
  code: string;
  role: string;
}

const ROLE_HIERARCHY: Record<string, number> = {
  viewer: 0,
  manager: 1,
  admin: 2,
  owner: 3,
  superadmin: 4,
};

interface CompanyContextType {
  companies: Company[];
  selectedCompany: Company | null;
  switchCompany: (companyId: string) => void;
  loading: boolean;
  refetch: () => Promise<void>;
  hasCompanyRole: (minRole: string) => boolean;
  isSuperadmin: boolean;
}

const CompanyContext = createContext<CompanyContextType | null>(null);

export function CompanyProvider({ children }: { children: ReactNode }) {
  const { user } = useAuth();
  const [companies, setCompanies] = useState<Company[]>([]);
  const [selectedCompany, setSelectedCompany] = useState<Company | null>(null);
  const [loading, setLoading] = useState(true);

  const fetchCompanies = async () => {
    try {
      const { data } = await api.get("/user/companies");
      const mapped = (data.companies || []).map((c: any) => ({
        ...c,
        role: c.company_role || c.companyRole || c.role || "viewer",
      }));
      setCompanies(mapped);

      const savedId = localStorage.getItem("selectedCompanyId");
      const saved = mapped.find((c: Company) => c.id === savedId);
      setSelectedCompany(saved || mapped[0] || null);
    } catch {
      setCompanies([]);
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    if (user) {
      fetchCompanies();
    } else {
      setCompanies([]);
      setSelectedCompany(null);
      setLoading(false);
    }
  }, [user]);

  const switchCompany = (companyId: string) => {
    const company = companies.find((c) => c.id === companyId);
    if (company) {
      setSelectedCompany(company);
      localStorage.setItem("selectedCompanyId", companyId);
    }
  };

  const isSuperadmin = user?.role === "superadmin";

  const hasCompanyRole = (minRole: string): boolean => {
    if (isSuperadmin) return true;
    const userRole = selectedCompany?.role;
    if (!userRole) return false;
    return (ROLE_HIERARCHY[userRole] ?? -1) >= (ROLE_HIERARCHY[minRole] ?? 0);
  };

  return (
    <CompanyContext.Provider value={{ companies, selectedCompany, switchCompany, loading, refetch: fetchCompanies, hasCompanyRole, isSuperadmin }}>
      {children}
    </CompanyContext.Provider>
  );
}

export function useCompany() {
  const context = useContext(CompanyContext);
  if (!context) throw new Error("useCompany must be used within CompanyProvider");
  return context;
}
