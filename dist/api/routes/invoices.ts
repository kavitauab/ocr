import { Router } from "express";
import multer from "multer";
import { db } from "../db";
import { invoices } from "../db/schema";
import { saveFile, getFilePath, readStoredFile } from "../lib/file-storage";
import { extractInvoiceData } from "../lib/claude";
import { uploadToVecticum } from "../lib/vecticum";
import { trackInvoiceProcessed } from "../lib/usage";
import { logAction } from "../lib/audit";
import { requireCompanyAccess, getUserCompanies } from "../lib/auth";
import { companies } from "../db/schema";
import { nanoid } from "nanoid";
import { desc, like, eq, or, sql, and, inArray } from "drizzle-orm";

const router = Router();
const upload = multer({ storage: multer.memoryStorage(), limits: { fileSize: 20 * 1024 * 1024 } });

const ALLOWED_TYPES = ["application/pdf", "image/png", "image/jpeg"];

const CONTENT_TYPES: Record<string, string> = {
  pdf: "application/pdf",
  png: "image/png",
  jpg: "image/jpeg",
  jpeg: "image/jpeg",
};

// POST /api/invoices - upload
router.post("/", upload.single("file"), async (req, res) => {
  try {
    const file = req.file;
    const companyId = req.body.companyId;

    if (!file) return res.status(400).json({ error: "No file provided" });
    if (!companyId) return res.status(400).json({ error: "Company is required" });

    try {
      await requireCompanyAccess(req.user!, companyId, "manager");
    } catch {
      return res.status(403).json({ error: "Access denied" });
    }

    if (!ALLOWED_TYPES.includes(file.mimetype)) {
      return res.status(400).json({ error: "Invalid file type. Accepted: PDF, PNG, JPG" });
    }

    const { storedFilename, fileType } = await saveFile(file.buffer, file.originalname, companyId);
    const id = nanoid();

    await db.insert(invoices).values({
      id,
      companyId,
      source: "upload",
      originalFilename: file.originalname,
      storedFilename,
      fileType,
      fileSize: file.size,
      status: "processing",
    });

    try {
      const filePath = getFilePath(storedFilename);
      const extracted = await extractInvoiceData(filePath, fileType);

      await db
        .update(invoices)
        .set({
          status: "completed",
          invoiceNumber: extracted.invoiceNumber,
          invoiceDate: extracted.invoiceDate,
          dueDate: extracted.dueDate,
          vendorName: extracted.vendorName,
          vendorAddress: extracted.vendorAddress,
          vendorVatId: extracted.vendorVatId,
          buyerName: extracted.buyerName,
          buyerAddress: extracted.buyerAddress,
          buyerVatId: extracted.buyerVatId,
          totalAmount: extracted.totalAmount?.toString() ?? null,
          currency: extracted.currency,
          taxAmount: extracted.taxAmount?.toString() ?? null,
          subtotalAmount: extracted.subtotalAmount?.toString() ?? null,
          poNumber: extracted.poNumber,
          paymentTerms: extracted.paymentTerms,
          bankDetails: extracted.bankDetails,
          confidenceScores: extracted.confidence,
          rawExtraction: extracted,
          updatedAt: new Date(),
        })
        .where(eq(invoices.id, id));

      await trackInvoiceProcessed(companyId, file.size);
    } catch (extractionError) {
      await db
        .update(invoices)
        .set({
          status: "failed",
          processingError: extractionError instanceof Error ? extractionError.message : "Extraction failed",
          updatedAt: new Date(),
        })
        .where(eq(invoices.id, id));
    }

    await logAction({
      userId: req.user!.id,
      companyId,
      action: "upload",
      resourceType: "invoice",
      resourceId: id,
    });

    const [invoice] = await db.select().from(invoices).where(eq(invoices.id, id));
    return res.status(201).json({ invoice });
  } catch (error) {
    console.error("Upload error:", error);
    return res.status(500).json({ error: "Failed to process upload" });
  }
});

// GET /api/invoices - list
router.get("/", async (req, res) => {
  try {
    const search = (req.query.search as string) || "";
    const status = (req.query.status as string) || "";
    const companyId = (req.query.companyId as string) || "";
    const page = parseInt((req.query.page as string) || "1");
    const limit = parseInt((req.query.limit as string) || "20");
    const offset = (page - 1) * limit;

    const conditions = [];

    if (companyId) {
      try {
        await requireCompanyAccess(req.user!, companyId);
      } catch {
        return res.status(403).json({ error: "Access denied" });
      }
      conditions.push(eq(invoices.companyId, companyId));
    } else if (req.user!.role !== "superadmin") {
      const userComps = await getUserCompanies(req.user!);
      const companyIds = userComps.map((c) => c.id);
      if (companyIds.length === 0) {
        return res.json({ invoices: [], total: 0, page, totalPages: 0 });
      }
      conditions.push(inArray(invoices.companyId, companyIds));
    }

    if (search) {
      conditions.push(
        or(
          like(invoices.invoiceNumber, `%${search}%`),
          like(invoices.vendorName, `%${search}%`),
          like(invoices.buyerName, `%${search}%`)
        )
      );
    }

    if (status) {
      conditions.push(eq(invoices.status, status as "uploaded" | "processing" | "completed" | "failed"));
    }

    const whereClause = conditions.length > 0 ? and(...conditions) : undefined;

    const items = await db
      .select()
      .from(invoices)
      .where(whereClause)
      .orderBy(desc(invoices.createdAt))
      .limit(limit)
      .offset(offset);

    const [{ count }] = await db
      .select({ count: sql<number>`count(*)` })
      .from(invoices)
      .where(whereClause);

    return res.json({
      invoices: items,
      total: count,
      page,
      totalPages: Math.ceil(count / limit),
    });
  } catch (error) {
    console.error("List invoices error:", error);
    return res.status(500).json({ error: "Failed to list invoices" });
  }
});

// GET /api/invoices/stats
router.get("/stats", async (req, res) => {
  try {
    const companyId = req.query.companyId as string | undefined;

    let companyFilter;
    if (companyId) {
      try {
        await requireCompanyAccess(req.user!, companyId);
      } catch {
        return res.status(403).json({ error: "Access denied" });
      }
      companyFilter = eq(invoices.companyId, companyId);
    } else if (req.user!.role !== "superadmin") {
      const userCompanyList = await getUserCompanies(req.user!);
      const companyIds = userCompanyList.map((c) => c.id);
      companyFilter = companyIds.length > 0 ? inArray(invoices.companyId, companyIds) : sql`1 = 0`;
    }

    const [totals] = await db
      .select({
        total: sql<number>`count(*)`,
        completed: sql<number>`sum(case when status = 'completed' then 1 else 0 end)`,
        processing: sql<number>`sum(case when status = 'processing' then 1 else 0 end)`,
        failed: sql<number>`sum(case when status = 'failed' then 1 else 0 end)`,
        totalAmount: sql<number>`sum(case when status = 'completed' then total_amount else 0 end)`,
      })
      .from(invoices)
      .where(companyFilter);

    return res.json({
      totalInvoices: totals.total || 0,
      completedCount: totals.completed || 0,
      processingCount: totals.processing || 0,
      failedCount: totals.failed || 0,
      totalAmountSum: totals.totalAmount || 0,
    });
  } catch (error) {
    console.error("Stats error:", error);
    return res.status(500).json({ error: "Failed to get stats" });
  }
});

// GET /api/invoices/:id
router.get("/:id", async (req, res) => {
  try {
    const { id } = req.params;
    const [invoice] = await db.select().from(invoices).where(eq(invoices.id, id));

    if (!invoice) return res.status(404).json({ error: "Invoice not found" });

    if (invoice.companyId) {
      try {
        await requireCompanyAccess(req.user!, invoice.companyId);
      } catch {
        return res.status(403).json({ error: "Access denied" });
      }
    }

    return res.json({ invoice });
  } catch (error) {
    return res.status(500).json({ error: "Failed to get invoice" });
  }
});

// PATCH /api/invoices/:id
router.patch("/:id", async (req, res) => {
  try {
    const { id } = req.params;
    const [existing] = await db.select().from(invoices).where(eq(invoices.id, id));

    if (!existing) return res.status(404).json({ error: "Invoice not found" });

    if (existing.companyId) {
      try {
        await requireCompanyAccess(req.user!, existing.companyId, "manager");
      } catch {
        return res.status(403).json({ error: "Access denied" });
      }
    }

    await db.update(invoices).set({ ...req.body, updatedAt: new Date() }).where(eq(invoices.id, id));
    const [updated] = await db.select().from(invoices).where(eq(invoices.id, id));
    return res.json({ invoice: updated });
  } catch (error) {
    return res.status(500).json({ error: "Failed to update invoice" });
  }
});

// DELETE /api/invoices/:id
router.delete("/:id", async (req, res) => {
  try {
    const { id } = req.params;
    const [existing] = await db.select().from(invoices).where(eq(invoices.id, id));

    if (!existing) return res.status(404).json({ error: "Invoice not found" });

    if (existing.companyId) {
      try {
        await requireCompanyAccess(req.user!, existing.companyId, "admin");
      } catch {
        return res.status(403).json({ error: "Access denied" });
      }
    }

    await db.delete(invoices).where(eq(invoices.id, id));

    await logAction({
      userId: req.user!.id,
      companyId: existing.companyId ?? undefined,
      action: "delete",
      resourceType: "invoice",
      resourceId: id,
    });

    return res.json({ success: true });
  } catch (error) {
    return res.status(500).json({ error: "Failed to delete invoice" });
  }
});

// GET /api/invoices/:id/file
router.get("/:id/file", async (req, res) => {
  try {
    const { id } = req.params;
    const [invoice] = await db.select().from(invoices).where(eq(invoices.id, id));

    if (!invoice) return res.status(404).json({ error: "Invoice not found" });

    if (invoice.companyId) {
      try {
        await requireCompanyAccess(req.user!, invoice.companyId, "viewer");
      } catch {
        return res.status(403).json({ error: "Access denied" });
      }
    }

    const buffer = await readStoredFile(invoice.storedFilename);
    const contentType = CONTENT_TYPES[invoice.fileType] || "application/octet-stream";

    res.setHeader("Content-Type", contentType);
    res.setHeader("Content-Disposition", `inline; filename="${invoice.originalFilename}"`);
    return res.send(buffer);
  } catch {
    return res.status(404).json({ error: "File not found" });
  }
});

// POST /api/invoices/:id/vecticum
router.post("/:id/vecticum", async (req, res) => {
  try {
    const { id } = req.params;
    const [invoice] = await db.select().from(invoices).where(eq(invoices.id, id));

    if (!invoice) return res.status(404).json({ error: "Invoice not found" });
    if (!invoice.companyId) return res.status(400).json({ error: "Invoice has no company assigned" });

    try {
      await requireCompanyAccess(req.user!, invoice.companyId, "manager");
    } catch {
      return res.status(403).json({ error: "Access denied" });
    }

    const [company] = await db.select().from(companies).where(eq(companies.id, invoice.companyId));
    if (!company) return res.status(404).json({ error: "Company not found" });
    if (!company.vecticumEnabled) return res.status(400).json({ error: "Vecticum is not enabled for this company" });

    const result = await uploadToVecticum(company, {
      invoiceNumber: invoice.invoiceNumber,
      invoiceDate: invoice.invoiceDate,
      dueDate: invoice.dueDate,
      vendorName: invoice.vendorName,
      vendorVatId: invoice.vendorVatId,
      subtotalAmount: invoice.subtotalAmount,
      taxAmount: invoice.taxAmount,
      totalAmount: invoice.totalAmount,
      currency: invoice.currency,
    });

    if (result.success) {
      return res.json({
        success: true,
        externalId: result.externalId,
        message: `Invoice sent to Vecticum (ID: ${result.externalId})`,
      });
    }

    return res.status(500).json({ error: result.error || "Failed to upload to Vecticum" });
  } catch (error) {
    return res.status(500).json({ error: "Failed to send to Vecticum" });
  }
});

export default router;
