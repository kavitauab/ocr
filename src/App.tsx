import { Routes, Route, Navigate } from "react-router-dom";
import { AuthProvider, ProtectedRoute } from "@/lib/auth";
import { CompanyProvider } from "@/lib/company";
import Layout from "@/components/Layout";
import Login from "@/pages/Login";
import Dashboard from "@/pages/Dashboard";
import Invoices from "@/pages/Invoices";
import InvoiceDetail from "@/pages/InvoiceDetail";
import Upload from "@/pages/Upload";
import Emails from "@/pages/Emails";
import Companies from "@/pages/Companies";
import CompanyEdit from "@/pages/CompanyEdit";
import Users from "@/pages/Users";
import Settings from "@/pages/Settings";

export default function App() {
  return (
    <AuthProvider>
      <Routes>
        <Route path="/login" element={<Login />} />
        <Route
          element={
            <ProtectedRoute>
              <CompanyProvider>
                <Layout />
              </CompanyProvider>
            </ProtectedRoute>
          }
        >
          <Route path="/" element={<Navigate to="/dashboard" replace />} />
          <Route path="/dashboard" element={<Dashboard />} />
          <Route path="/invoices" element={<Invoices />} />
          <Route path="/invoices/:id" element={<InvoiceDetail />} />
          <Route path="/upload" element={<Upload />} />
          <Route path="/emails" element={<Emails />} />
          <Route path="/settings" element={<Settings />} />
          <Route path="/settings/companies" element={<Companies />} />
          <Route path="/settings/companies/new" element={<CompanyEdit />} />
          <Route path="/settings/companies/:id" element={<CompanyEdit />} />
          <Route path="/settings/users" element={<Users />} />
        </Route>
      </Routes>
    </AuthProvider>
  );
}
