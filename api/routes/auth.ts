import { Router } from "express";
import { db } from "../db";
import { users } from "../db/schema";
import { eq } from "drizzle-orm";
import { verifyPassword, createSession } from "../lib/auth";
import { authMiddleware } from "../middleware/auth";

const router = Router();

router.post("/login", async (req, res) => {
  try {
    const { email, password } = req.body;

    if (!email || !password) {
      return res.status(400).json({ error: "Email and password required" });
    }

    const [user] = await db.select().from(users).where(eq(users.email, email));

    if (!user) {
      return res.status(401).json({ error: "Invalid credentials" });
    }

    const valid = await verifyPassword(password, user.passwordHash);
    if (!valid) {
      return res.status(401).json({ error: "Invalid credentials" });
    }

    const token = await createSession({
      id: user.id,
      name: user.name,
      email: user.email,
      role: user.role ?? "user",
    });

    return res.json({
      token,
      user: { id: user.id, name: user.name, email: user.email, role: user.role },
    });
  } catch (error) {
    console.error("Login error:", error);
    return res.status(500).json({ error: "Login failed" });
  }
});

router.get("/me", authMiddleware, (req, res) => {
  return res.json({ user: req.user });
});

router.post("/logout", (_req, res) => {
  return res.json({ success: true });
});

export default router;
