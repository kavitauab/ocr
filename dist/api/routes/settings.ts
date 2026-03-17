import { Router } from "express";
import { db } from "../db";
import { settings } from "../db/schema";
import { eq } from "drizzle-orm";

const router = Router();

router.get("/", async (req, res) => {
  if (req.user!.role !== "superadmin") return res.status(403).json({ error: "Access denied" });

  try {
    const items = await db.select().from(settings);
    const result: Record<string, string> = {};
    for (const item of items) {
      if (item.key.includes("api_key") && item.value) {
        result[item.key] = item.value.slice(0, 10) + "••••••••";
      } else {
        result[item.key] = item.value;
      }
    }
    return res.json({ settings: result });
  } catch (error) {
    return res.status(500).json({ error: "Failed to get settings" });
  }
});

router.patch("/", async (req, res) => {
  if (req.user!.role !== "superadmin") return res.status(403).json({ error: "Access denied" });

  try {
    for (const [key, value] of Object.entries(req.body)) {
      if (typeof value !== "string") continue;
      if (value.includes("••••••••")) continue;

      const [existing] = await db.select().from(settings).where(eq(settings.key, key));
      if (existing) {
        await db.update(settings).set({ value }).where(eq(settings.key, key));
      } else {
        await db.insert(settings).values({ key, value });
      }
    }

    return res.json({ success: true });
  } catch (error) {
    return res.status(500).json({ error: "Failed to update settings" });
  }
});

export default router;
