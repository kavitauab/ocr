import { Router } from "express";
import { db } from "../db";
import { companies, userCompanies, users, usageLogs, auditLog } from "../db/schema";
import { nanoid } from "nanoid";
import { desc, eq, and, inArray } from "drizzle-orm";
import { getUserCompanies, requireCompanyAccess } from "../lib/auth";
import { logAction } from "../lib/audit";
import { testConnection } from "../lib/microsoft-graph";
import { testVecticumConnection } from "../lib/vecticum";
import { processCompanyEmails } from "../lib/email-processor";
import { sql } from "drizzle-orm";

const router = Router();

// GET /api/companies
router.get("/", async (req, res) => {
  try {
    let items;
    if (req.user!.role === "superadmin") {
      items = await db.select().from(companies).orderBy(desc(companies.createdAt));
    } else {
      const userComps = await getUserCompanies(req.user!);
      const companyIds = userComps.map((c) => c.id);
      if (companyIds.length === 0) return res.json({ companies: [] });
      items = await db.select().from(companies).where(inArray(companies.id, companyIds)).orderBy(desc(companies.createdAt));
    }

    const masked = items.map((c) => ({
      ...c,
      msClientSecret: c.msClientSecret ? "••••••••" : null,
      msAccessToken: undefined,
      msTokenExpires: undefined,
      vecticumClientSecret: c.vecticumClientSecret ? "••••••••" : null,
      vecticumAccessToken: undefined,
      vecticumTokenExpires: undefined,
    }));

    return res.json({ companies: masked });
  } catch (error) {
    return res.status(500).json({ error: "Failed to list companies" });
  }
});

// POST /api/companies
router.post("/", async (req, res) => {
  try {
    const { name, code, logoUrl } = req.body;
    if (!name || !code) return res.status(400).json({ error: "Name and code are required" });

    const id = nanoid();
    await db.insert(companies).values({ id, name, code, logoUrl: logoUrl || null });
    await db.insert(userCompanies).values({ id: nanoid(), userId: req.user!.id, companyId: id, role: "owner" });

    await logAction({ userId: req.user!.id, companyId: id, action: "create", resourceType: "company", resourceId: id });

    const [company] = await db.select().from(companies).where(eq(companies.id, id));
    return res.status(201).json({ company });
  } catch (error) {
    return res.status(500).json({ error: "Failed to create company" });
  }
});

// GET /api/companies/:id
router.get("/:id", async (req, res) => {
  try {
    const { id } = req.params;
    try { await requireCompanyAccess(req.user!, id); } catch { return res.status(403).json({ error: "Access denied" }); }

    const [company] = await db.select().from(companies).where(eq(companies.id, id));
    if (!company) return res.status(404).json({ error: "Company not found" });

    return res.json({
      company: {
        ...company,
        msClientSecret: company.msClientSecret ? "••••••••" : null,
        msAccessToken: undefined,
        msTokenExpires: undefined,
        vecticumClientSecret: company.vecticumClientSecret ? "••••••••" : null,
        vecticumAccessToken: undefined,
        vecticumTokenExpires: undefined,
      },
    });
  } catch (error) {
    return res.status(500).json({ error: "Failed to get company" });
  }
});

// PATCH /api/companies/:id
router.patch("/:id", async (req, res) => {
  try {
    const { id } = req.params;
    try { await requireCompanyAccess(req.user!, id, "admin"); } catch { return res.status(403).json({ error: "Access denied" }); }

    const [existing] = await db.select().from(companies).where(eq(companies.id, id));
    if (!existing) return res.status(404).json({ error: "Company not found" });

    const updates: Record<string, unknown> = { updatedAt: new Date() };
    const allowedFields = [
      "name", "code", "logoUrl",
      "msClientId", "msClientSecret", "msTenantId", "msSenderEmail",
      "msFetchEnabled", "msFetchFolder", "msFetchIntervalMinutes",
      "vecticumEnabled", "vecticumApiBaseUrl", "vecticumClientId",
      "vecticumClientSecret", "vecticumCompanyId",
      "vecticumAuthorId", "vecticumAuthorName",
    ];

    for (const field of allowedFields) {
      if (field in req.body) {
        if (req.body[field] === "••••••••") continue;
        updates[field] = req.body[field];
      }
    }

    await db.update(companies).set(updates).where(eq(companies.id, id));
    await logAction({ userId: req.user!.id, companyId: id, action: "update", resourceType: "company", resourceId: id });

    const [updated] = await db.select().from(companies).where(eq(companies.id, id));
    return res.json({
      company: {
        ...updated,
        msClientSecret: updated.msClientSecret ? "••••••••" : null,
        msAccessToken: undefined,
        vecticumClientSecret: updated.vecticumClientSecret ? "••••••••" : null,
        vecticumAccessToken: undefined,
      },
    });
  } catch (error) {
    return res.status(500).json({ error: "Failed to update company" });
  }
});

// DELETE /api/companies/:id
router.delete("/:id", async (req, res) => {
  try {
    const { id } = req.params;
    try { await requireCompanyAccess(req.user!, id, "owner"); } catch { return res.status(403).json({ error: "Access denied" }); }

    await db.delete(companies).where(eq(companies.id, id));
    await logAction({ userId: req.user!.id, companyId: id, action: "delete", resourceType: "company", resourceId: id });
    return res.json({ success: true });
  } catch (error) {
    return res.status(500).json({ error: "Failed to delete company" });
  }
});

// GET /api/companies/:id/members
router.get("/:id/members", async (req, res) => {
  try {
    const { id: companyId } = req.params;
    try { await requireCompanyAccess(req.user!, companyId); } catch { return res.status(403).json({ error: "Access denied" }); }

    const members = await db
      .select({
        id: users.id,
        name: users.name,
        email: users.email,
        companyRole: userCompanies.role,
        joinedAt: userCompanies.createdAt,
      })
      .from(userCompanies)
      .innerJoin(users, eq(users.id, userCompanies.userId))
      .where(eq(userCompanies.companyId, companyId));

    return res.json({ members });
  } catch (error) {
    return res.status(500).json({ error: "Failed to list members" });
  }
});

// POST /api/companies/:id/members
router.post("/:id/members", async (req, res) => {
  try {
    const { id: companyId } = req.params;
    try { await requireCompanyAccess(req.user!, companyId, "admin"); } catch { return res.status(403).json({ error: "Access denied" }); }

    const { email, role = "viewer" } = req.body;
    if (!email) return res.status(400).json({ error: "Email is required" });

    const [user] = await db.select().from(users).where(eq(users.email, email));
    if (!user) return res.status(404).json({ error: "User not found. They must have an account first." });

    const [existing] = await db.select().from(userCompanies).where(and(eq(userCompanies.userId, user.id), eq(userCompanies.companyId, companyId)));
    if (existing) return res.status(409).json({ error: "User is already a member of this company" });

    await db.insert(userCompanies).values({ id: nanoid(), userId: user.id, companyId, role: role as "owner" | "admin" | "manager" | "viewer" });
    await logAction({ userId: req.user!.id, companyId, action: "add_member", resourceType: "user", resourceId: user.id, metadata: { role, email } });

    return res.status(201).json({ success: true });
  } catch (error) {
    return res.status(500).json({ error: "Failed to add member" });
  }
});

// PATCH /api/companies/:id/members/:userId
router.patch("/:id/members/:userId", async (req, res) => {
  try {
    const { id: companyId, userId } = req.params;
    try { await requireCompanyAccess(req.user!, companyId, "admin"); } catch { return res.status(403).json({ error: "Access denied" }); }

    const { role } = req.body;
    if (!role || !["owner", "admin", "manager", "viewer"].includes(role)) {
      return res.status(400).json({ error: "Invalid role" });
    }

    await db.update(userCompanies).set({ role: role as "owner" | "admin" | "manager" | "viewer" }).where(and(eq(userCompanies.userId, userId), eq(userCompanies.companyId, companyId)));
    await logAction({ userId: req.user!.id, companyId, action: "change_role", resourceType: "user", resourceId: userId, metadata: { role } });

    return res.json({ success: true });
  } catch (error) {
    return res.status(500).json({ error: "Failed to update member role" });
  }
});

// DELETE /api/companies/:id/members/:userId
router.delete("/:id/members/:userId", async (req, res) => {
  try {
    const { id: companyId, userId } = req.params;
    try { await requireCompanyAccess(req.user!, companyId, "admin"); } catch { return res.status(403).json({ error: "Access denied" }); }

    if (userId === req.user!.id) return res.status(400).json({ error: "Cannot remove yourself from the company" });

    await db.delete(userCompanies).where(and(eq(userCompanies.userId, userId), eq(userCompanies.companyId, companyId)));
    await logAction({ userId: req.user!.id, companyId, action: "remove_member", resourceType: "user", resourceId: userId });

    return res.json({ success: true });
  } catch (error) {
    return res.status(500).json({ error: "Failed to remove member" });
  }
});

// GET /api/companies/:id/usage
router.get("/:id/usage", async (req, res) => {
  try {
    const { id: companyId } = req.params;
    try { await requireCompanyAccess(req.user!, companyId); } catch { return res.status(403).json({ error: "Access denied" }); }

    const logs = await db.select().from(usageLogs).where(eq(usageLogs.companyId, companyId)).orderBy(desc(usageLogs.month)).limit(12);
    return res.json({ usage: logs });
  } catch (error) {
    return res.status(500).json({ error: "Failed to get usage" });
  }
});

// GET /api/companies/:id/audit-log
router.get("/:id/audit-log", async (req, res) => {
  try {
    const { id: companyId } = req.params;
    try { await requireCompanyAccess(req.user!, companyId, "admin"); } catch { return res.status(403).json({ error: "Access denied" }); }

    const limit = parseInt((req.query.limit as string) || "50");

    const logs = await db
      .select({
        id: auditLog.id,
        action: auditLog.action,
        resourceType: auditLog.resourceType,
        resourceId: auditLog.resourceId,
        metadata: auditLog.metadata,
        createdAt: auditLog.createdAt,
        userName: users.name,
        userEmail: users.email,
      })
      .from(auditLog)
      .leftJoin(users, eq(users.id, auditLog.userId))
      .where(eq(auditLog.companyId, companyId))
      .orderBy(desc(auditLog.createdAt))
      .limit(limit);

    return res.json({ logs });
  } catch (error) {
    return res.status(500).json({ error: "Failed to get audit log" });
  }
});

// POST /api/companies/:id/test-email
router.post("/:id/test-email", async (req, res) => {
  try {
    const { id } = req.params;
    try { await requireCompanyAccess(req.user!, id, "admin"); } catch { return res.status(403).json({ error: "Access denied" }); }

    const [company] = await db.select().from(companies).where(eq(companies.id, id));
    if (!company) return res.status(404).json({ error: "Company not found" });

    const result = await testConnection(company);
    return res.json(result);
  } catch (error) {
    return res.status(500).json({ error: "Test failed" });
  }
});

// POST /api/companies/:id/test-vecticum
router.post("/:id/test-vecticum", async (req, res) => {
  try {
    const { id } = req.params;
    try { await requireCompanyAccess(req.user!, id, "admin"); } catch { return res.status(403).json({ error: "Access denied" }); }

    const [company] = await db.select().from(companies).where(eq(companies.id, id));
    if (!company) return res.status(404).json({ error: "Company not found" });

    const result = await testVecticumConnection(company);
    return res.json(result);
  } catch (error) {
    return res.status(500).json({ error: "Test failed" });
  }
});

// POST /api/companies/:id/fetch-emails
router.post("/:id/fetch-emails", async (req, res) => {
  try {
    const { id } = req.params;
    try { await requireCompanyAccess(req.user!, id, "manager"); } catch { return res.status(403).json({ error: "Access denied" }); }

    const result = await processCompanyEmails(id);
    return res.json(result);
  } catch (err) {
    return res.status(500).json({ error: err instanceof Error ? err.message : "Failed to fetch emails" });
  }
});

export default router;
