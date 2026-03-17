import { db } from "../db";
import { companies } from "../db/schema";
import { eq } from "drizzle-orm";

type CompanyRow = typeof companies.$inferSelect;

interface VecticumTokenResponse {
  success: boolean;
  token?: string;
  message?: string;
}

export async function getVecticumToken(company: CompanyRow): Promise<string> {
  if (company.vecticumAccessToken && company.vecticumTokenExpires) {
    const expires = new Date(company.vecticumTokenExpires);
    if (expires > new Date(Date.now() + 5 * 60 * 1000)) {
      return company.vecticumAccessToken;
    }
  }

  if (!company.vecticumApiBaseUrl || !company.vecticumClientId || !company.vecticumClientSecret) {
    throw new Error("Vecticum credentials not configured");
  }

  const response = await fetch(`${company.vecticumApiBaseUrl}/oauth/token`, {
    method: "POST",
    headers: {
      Accept: "application/json",
      "Content-Type": "application/json",
      Authorization: JSON.stringify({ client_id: company.vecticumClientId, client_secret: company.vecticumClientSecret }),
    },
  });

  const data: VecticumTokenResponse = await response.json();
  if (!data.success || !data.token) {
    throw new Error(`Vecticum auth failed: ${data.message || "No token returned"}`);
  }

  const expiresAt = new Date(Date.now() + 23 * 60 * 60 * 1000).toISOString();
  await db.update(companies).set({ vecticumAccessToken: data.token, vecticumTokenExpires: expiresAt }).where(eq(companies.id, company.id));

  return data.token;
}

export async function testVecticumConnection(company: CompanyRow): Promise<{ success: boolean; message?: string; error?: string }> {
  try {
    const token = await getVecticumToken(company);
    if (company.vecticumCompanyId) {
      const res = await fetch(`${company.vecticumApiBaseUrl}/${company.vecticumCompanyId}`, {
        headers: { Accept: "application/json", Authorization: `Bearer ${token}` },
      });
      if (!res.ok) return { success: false, error: `Endpoint returned ${res.status}` };
      const data = await res.json();
      const count = Array.isArray(data) ? data.length : 0;
      return { success: true, message: `Connected. Found ${count} records.` };
    }
    return { success: true, message: "Authentication successful" };
  } catch (err) {
    return { success: false, error: err instanceof Error ? err.message : "Connection failed" };
  }
}

function toNum(v: number | string | null | undefined): number {
  if (v == null) return 0;
  const n = typeof v === "string" ? parseFloat(v) : v;
  return isNaN(n) ? 0 : n;
}

interface InvoiceMetadata {
  invoiceNumber?: string | null;
  invoiceDate?: string | null;
  dueDate?: string | null;
  vendorName?: string | null;
  vendorVatId?: string | null;
  subtotalAmount?: number | string | null;
  taxAmount?: number | string | null;
  totalAmount?: number | string | null;
  currency?: string | null;
  description?: string | null;
}

const VECTICUM_EUR_CURRENCY = { id: "O18j5zeck1yHYb5W4H86", name: "EUR" };

export async function uploadToVecticum(
  company: CompanyRow,
  metadata: InvoiceMetadata
): Promise<{ success: boolean; externalId?: string; error?: string }> {
  if (!company.vecticumCompanyId) return { success: false, error: "Vecticum endpoint ID not configured" };

  try {
    const token = await getVecticumToken(company);
    const total = toNum(metadata.totalAmount);
    const tax = toNum(metadata.taxAmount);
    const subtotal = toNum(metadata.subtotalAmount);
    const totalInclVat = (total && tax ? total + tax : total).toFixed(2);

    const body: Record<string, unknown> = {
      invoiceNo: metadata.invoiceNumber || undefined,
      invoiceDate: metadata.invoiceDate || undefined,
      paymentDate: metadata.dueDate || undefined,
      invoiceAmount: subtotal || total,
      vatAmount: tax,
      totalAmount: subtotal || total,
      totalInclVat,
      description: metadata.vendorName
        ? `${metadata.vendorName}${metadata.description ? " - " + metadata.description : ""}`
        : metadata.description || undefined,
    };

    if (metadata.vendorVatId) body.counterpartyCode = metadata.vendorVatId;
    if (!metadata.currency || metadata.currency === "EUR") body.currency = VECTICUM_EUR_CURRENCY;
    if (company.vecticumAuthorId) body.author = { id: company.vecticumAuthorId, name: company.vecticumAuthorName || "" };

    for (const key of Object.keys(body)) {
      if (body[key] === undefined) delete body[key];
    }

    const res = await fetch(`${company.vecticumApiBaseUrl}/${company.vecticumCompanyId}`, {
      method: "POST",
      headers: { Accept: "application/json", "Content-Type": "application/json", Authorization: `Bearer ${token}` },
      body: JSON.stringify(body),
    });

    if (!res.ok) {
      const text = await res.text();
      let errorMsg = `Vecticum API error: ${res.status}`;
      try { const errData = JSON.parse(text); errorMsg = errData.message || errorMsg; } catch { /* use default */ }
      return { success: false, error: errorMsg };
    }

    const data = await res.json();
    return { success: true, externalId: data.id };
  } catch (err) {
    return { success: false, error: err instanceof Error ? err.message : "Upload failed" };
  }
}
