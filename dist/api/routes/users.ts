import { Router } from "express";
import { db } from "../db";
import { users } from "../db/schema";
import { nanoid } from "nanoid";
import { desc, eq } from "drizzle-orm";
import { hashPassword, getUserCompanies } from "../lib/auth";

const router = Router();

// GET /api/user/companies
router.get("/companies", async (req, res) => {
  try {
    const companies = await getUserCompanies(req.user!);
    return res.json({ companies });
  } catch (error) {
    return res.status(500).json({ error: "Failed to get companies" });
  }
});

// GET /api/users
router.get("/", async (req, res) => {
  if (req.user!.role !== "superadmin") return res.status(403).json({ error: "Access denied" });

  try {
    const items = await db
      .select({ id: users.id, name: users.name, email: users.email, role: users.role, createdAt: users.createdAt })
      .from(users)
      .orderBy(desc(users.createdAt));

    return res.json({ users: items });
  } catch (error) {
    return res.status(500).json({ error: "Failed to list users" });
  }
});

// POST /api/users
router.post("/", async (req, res) => {
  if (req.user!.role !== "superadmin") return res.status(403).json({ error: "Access denied" });

  try {
    const { name, email, password, role } = req.body;
    if (!name || !email || !password) return res.status(400).json({ error: "Name, email, and password are required" });

    const passwordHash = await hashPassword(password);
    const id = nanoid();

    await db.insert(users).values({
      id,
      name,
      email,
      passwordHash,
      role: role === "superadmin" ? "superadmin" : "user",
    });

    return res.status(201).json({ user: { id, name, email, role: role === "superadmin" ? "superadmin" : "user" } });
  } catch (error) {
    return res.status(500).json({ error: "Failed to create user" });
  }
});

// PATCH /api/users/:id
router.patch("/:id", async (req, res) => {
  if (req.user!.role !== "superadmin") return res.status(403).json({ error: "Access denied" });

  try {
    const { id } = req.params;
    const updates: Record<string, unknown> = { updatedAt: new Date() };

    if (req.body.name) updates.name = req.body.name;
    if (req.body.email) updates.email = req.body.email;
    if (req.body.role !== undefined) updates.role = req.body.role;
    if (req.body.password) updates.passwordHash = await hashPassword(req.body.password);

    await db.update(users).set(updates).where(eq(users.id, id));

    const [updated] = await db
      .select({ id: users.id, name: users.name, email: users.email, role: users.role })
      .from(users)
      .where(eq(users.id, id));

    return res.json({ user: updated });
  } catch (error) {
    return res.status(500).json({ error: "Failed to update user" });
  }
});

// DELETE /api/users/:id
router.delete("/:id", async (req, res) => {
  if (req.user!.role !== "superadmin") return res.status(403).json({ error: "Access denied" });

  try {
    const { id } = req.params;
    await db.delete(users).where(eq(users.id, id));
    return res.json({ success: true });
  } catch (error) {
    return res.status(500).json({ error: "Failed to delete user" });
  }
});

export default router;
