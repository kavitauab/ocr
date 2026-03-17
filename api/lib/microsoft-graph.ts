import { db } from "../db";
import { companies } from "../db/schema";
import { eq } from "drizzle-orm";

type CompanyRow = typeof companies.$inferSelect;

interface M365TokenResponse {
  access_token: string;
  expires_in: number;
  error?: string;
  error_description?: string;
}

interface M365Message {
  id: string;
  subject: string;
  from: { emailAddress: { name: string; address: string } };
  receivedDateTime: string;
  hasAttachments: boolean;
  isRead: boolean;
}

interface M365Attachment {
  id: string;
  name: string;
  contentType: string;
  size: number;
  contentBytes: string;
  isInline: boolean;
}

export async function getM365Token(company: CompanyRow): Promise<string> {
  if (company.msAccessToken && company.msTokenExpires) {
    const expires = new Date(company.msTokenExpires);
    if (expires > new Date(Date.now() + 5 * 60 * 1000)) {
      return company.msAccessToken;
    }
  }

  if (!company.msTenantId || !company.msClientId || !company.msClientSecret) {
    throw new Error("M365 credentials not configured");
  }

  const response = await fetch(
    `https://login.microsoftonline.com/${company.msTenantId}/oauth2/v2.0/token`,
    {
      method: "POST",
      headers: { "Content-Type": "application/x-www-form-urlencoded" },
      body: new URLSearchParams({
        grant_type: "client_credentials",
        client_id: company.msClientId,
        client_secret: company.msClientSecret,
        scope: "https://graph.microsoft.com/.default",
      }),
    }
  );

  const data: M365TokenResponse = await response.json();
  if (data.error) {
    throw new Error(`M365 auth failed: ${data.error_description || data.error}`);
  }

  const expiresAt = new Date(Date.now() + data.expires_in * 1000).toISOString();
  await db.update(companies).set({ msAccessToken: data.access_token, msTokenExpires: expiresAt }).where(eq(companies.id, company.id));

  return data.access_token;
}

export async function fetchEmails(company: CompanyRow, sinceHours = 5): Promise<M365Message[]> {
  const token = await getM365Token(company);
  const folder = company.msFetchFolder || "INBOX";
  const email = company.msSenderEmail;
  const since = new Date(Date.now() - sinceHours * 60 * 60 * 1000).toISOString();

  const url = `https://graph.microsoft.com/v1.0/users/${email}/mailFolders/${folder}/messages?$filter=receivedDateTime ge ${since}&$orderby=receivedDateTime desc&$top=50&$select=id,subject,from,receivedDateTime,hasAttachments,isRead`;

  const res = await fetch(url, { headers: { Authorization: `Bearer ${token}` } });
  if (!res.ok) {
    const error = await res.text();
    throw new Error(`Failed to fetch emails: ${res.status} ${error}`);
  }

  const data = await res.json();
  return data.value || [];
}

export async function fetchAttachments(company: CompanyRow, messageId: string): Promise<M365Attachment[]> {
  const token = await getM365Token(company);
  const email = company.msSenderEmail;

  const res = await fetch(
    `https://graph.microsoft.com/v1.0/users/${email}/messages/${messageId}/attachments`,
    { headers: { Authorization: `Bearer ${token}` } }
  );
  if (!res.ok) throw new Error(`Failed to fetch attachments: ${res.status}`);

  const data = await res.json();
  return data.value || [];
}

export async function markAsRead(company: CompanyRow, messageId: string): Promise<void> {
  const token = await getM365Token(company);
  const email = company.msSenderEmail;

  await fetch(`https://graph.microsoft.com/v1.0/users/${email}/messages/${messageId}`, {
    method: "PATCH",
    headers: { Authorization: `Bearer ${token}`, "Content-Type": "application/json" },
    body: JSON.stringify({ isRead: true }),
  });
}

export async function testConnection(company: CompanyRow): Promise<{ success: boolean; email?: string; error?: string }> {
  try {
    const token = await getM365Token(company);
    const email = company.msSenderEmail;

    const res = await fetch(
      `https://graph.microsoft.com/v1.0/users/${email}/mailFolders/INBOX?$select=displayName,totalItemCount`,
      { headers: { Authorization: `Bearer ${token}` } }
    );

    if (!res.ok) {
      const error = await res.text();
      return { success: false, error: `Graph API error: ${res.status} ${error}` };
    }

    const data = await res.json();
    return { success: true, email: `Connected to ${email} (${data.totalItemCount} messages in Inbox)` };
  } catch (err) {
    return { success: false, error: err instanceof Error ? err.message : "Connection failed" };
  }
}
