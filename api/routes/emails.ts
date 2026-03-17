import { Router } from "express";
import { db } from "../db";
import { emailInbox, companies } from "../db/schema";
import { eq, desc, sql, inArray, and } from "drizzle-orm";
import { getUserCompanies } from "../lib/auth";

const router = Router();

router.get("/", async (req, res) => {
  try {
    const companyId = req.query.companyId as string | undefined;
    const status = req.query.status as string | undefined;
    const page = parseInt((req.query.page as string) || "1");
    const limit = parseInt((req.query.limit as string) || "50");
    const offset = (page - 1) * limit;

    const userCompanyList = await getUserCompanies(req.user!);
    const allowedCompanyIds = userCompanyList.map((c) => c.id);

    const conditions = [];

    if (companyId) {
      if (!allowedCompanyIds.includes(companyId)) {
        return res.status(403).json({ error: "Access denied" });
      }
      conditions.push(eq(emailInbox.companyId, companyId));
    } else if (allowedCompanyIds.length > 0) {
      conditions.push(inArray(emailInbox.companyId, allowedCompanyIds));
    } else {
      return res.json({ emails: [], total: 0, page, totalPages: 0 });
    }

    if (status) {
      conditions.push(eq(emailInbox.status, status as "new" | "processing" | "processed" | "failed"));
    }

    const whereClause = conditions.length > 1 ? and(...conditions) : conditions[0];

    const items = await db
      .select({
        id: emailInbox.id,
        companyId: emailInbox.companyId,
        companyName: companies.name,
        messageId: emailInbox.messageId,
        subject: emailInbox.subject,
        fromEmail: emailInbox.fromEmail,
        fromName: emailInbox.fromName,
        receivedDate: emailInbox.receivedDate,
        hasAttachments: emailInbox.hasAttachments,
        attachmentCount: emailInbox.attachmentCount,
        status: emailInbox.status,
        processingError: emailInbox.processingError,
        createdAt: emailInbox.createdAt,
      })
      .from(emailInbox)
      .leftJoin(companies, eq(emailInbox.companyId, companies.id))
      .where(whereClause)
      .orderBy(desc(emailInbox.createdAt))
      .limit(limit)
      .offset(offset);

    const [{ count }] = await db
      .select({ count: sql<number>`count(*)` })
      .from(emailInbox)
      .where(whereClause);

    return res.json({ emails: items, total: count, page, totalPages: Math.ceil(count / limit) });
  } catch (error) {
    return res.status(500).json({ error: "Failed to list emails" });
  }
});

export default router;
