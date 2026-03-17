import { SignJWT, jwtVerify } from "jose";
import bcrypt from "bcryptjs";
import { db } from "../db";
import { userCompanies, companies } from "../db/schema";
import { eq, and } from "drizzle-orm";
import type { Request } from "express";

const JWT_SECRET = new TextEncoder().encode(
  process.env.JWT_SECRET || "default-dev-secret-change-in-production"
);

export interface SessionUser {
  id: string;
  name: string;
  email: string;
  role: "superadmin" | "user";
}

export type CompanyRole = "owner" | "admin" | "manager" | "viewer";

const ROLE_HIERARCHY: Record<CompanyRole, number> = {
  owner: 4,
  admin: 3,
  manager: 2,
  viewer: 1,
};

export async function hashPassword(password: string): Promise<string> {
  return bcrypt.hash(password, 12);
}

export async function verifyPassword(password: string, hash: string): Promise<boolean> {
  return bcrypt.compare(password, hash);
}

export async function createSession(user: SessionUser): Promise<string> {
  return new SignJWT({ ...user })
    .setProtectedHeader({ alg: "HS256" })
    .setExpirationTime("7d")
    .sign(JWT_SECRET);
}

export async function verifyToken(token: string): Promise<SessionUser | null> {
  try {
    const { payload } = await jwtVerify(token, JWT_SECRET);
    return payload as unknown as SessionUser;
  } catch {
    return null;
  }
}

export async function getSessionFromRequest(req: Request): Promise<SessionUser | null> {
  const authHeader = req.headers.authorization;
  if (!authHeader?.startsWith("Bearer ")) return null;
  const token = authHeader.slice(7);
  return verifyToken(token);
}

export async function getUserCompanyRole(userId: string, companyId: string): Promise<CompanyRole | null> {
  const [row] = await db
    .select({ role: userCompanies.role })
    .from(userCompanies)
    .where(and(eq(userCompanies.userId, userId), eq(userCompanies.companyId, companyId)));
  return (row?.role as CompanyRole) ?? null;
}

export async function requireCompanyAccess(
  user: SessionUser,
  companyId: string,
  minRole: CompanyRole = "viewer"
): Promise<CompanyRole> {
  if (user.role === "superadmin") return "owner";
  const role = await getUserCompanyRole(user.id, companyId);
  if (!role) throw new Error("No access to this company");
  if (ROLE_HIERARCHY[role] < ROLE_HIERARCHY[minRole]) {
    throw new Error("Insufficient permissions");
  }
  return role;
}

export async function getUserCompanies(user: SessionUser) {
  if (user.role === "superadmin") {
    const allCompanies = await db
      .select({ id: companies.id, name: companies.name, code: companies.code, role: companies.id })
      .from(companies);
    return allCompanies.map((c) => ({ ...c, role: "owner" as CompanyRole }));
  }
  return db
    .select({ id: companies.id, name: companies.name, code: companies.code, role: userCompanies.role })
    .from(userCompanies)
    .innerJoin(companies, eq(companies.id, userCompanies.companyId))
    .where(eq(userCompanies.userId, user.id));
}
