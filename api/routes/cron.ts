import { Router } from "express";
import { db } from "../db";
import { companies } from "../db/schema";
import { eq } from "drizzle-orm";
import { processCompanyEmails } from "../lib/email-processor";

const router = Router();

router.get("/fetch-emails", async (req, res) => {
  const authHeader = req.headers.authorization;
  const cronSecret = process.env.CRON_SECRET;
  if (cronSecret && authHeader !== `Bearer ${cronSecret}`) {
    return res.status(401).json({ error: "Unauthorized" });
  }

  try {
    const enabledCompanies = await db.select().from(companies).where(eq(companies.msFetchEnabled, true));
    const results: Record<string, unknown> = {};

    for (const company of enabledCompanies) {
      try {
        results[company.code] = await processCompanyEmails(company.id);
      } catch (err) {
        results[company.code] = { error: err instanceof Error ? err.message : "Failed" };
      }
    }

    return res.json({ companiesProcessed: enabledCompanies.length, results });
  } catch (error) {
    return res.status(500).json({ error: "Cron job failed" });
  }
});

export default router;
