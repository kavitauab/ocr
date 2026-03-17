import { db } from "../db";
import { companies, emailInbox, invoices } from "../db/schema";
import { eq } from "drizzle-orm";
import { fetchEmails, fetchAttachments, markAsRead } from "./microsoft-graph";
import { saveFile, getFilePath } from "./file-storage";
import { extractInvoiceData } from "./claude";
import { nanoid } from "nanoid";

const ALLOWED_TYPES = [
  "application/pdf",
  "image/png",
  "image/jpeg",
  "image/jpg",
];

export async function processCompanyEmails(companyId: string): Promise<{
  fetched: number;
  processed: number;
  errors: string[];
}> {
  const [company] = await db
    .select()
    .from(companies)
    .where(eq(companies.id, companyId));

  if (!company || !company.msFetchEnabled) {
    return { fetched: 0, processed: 0, errors: ["Email fetch not enabled"] };
  }

  const errors: string[] = [];
  let fetched = 0;
  let processed = 0;

  const messages = await fetchEmails(company);
  fetched = messages.length;

  for (const message of messages) {
    try {
      const existing = await db
        .select({ id: emailInbox.id })
        .from(emailInbox)
        .where(eq(emailInbox.messageId, message.id))
        .limit(1);

      if (existing.length > 0) continue;

      const emailId = nanoid();
      await db.insert(emailInbox).values({
        id: emailId,
        companyId,
        messageId: message.id,
        subject: message.subject,
        fromEmail: message.from?.emailAddress?.address,
        fromName: message.from?.emailAddress?.name,
        receivedDate: message.receivedDateTime,
        hasAttachments: message.hasAttachments,
        status: "processing",
      });

      if (!message.hasAttachments) {
        await db
          .update(emailInbox)
          .set({ status: "processed", attachmentCount: 0 })
          .where(eq(emailInbox.id, emailId));
        continue;
      }

      const attachments = await fetchAttachments(company, message.id);
      const invoiceAttachments = attachments.filter(
        (a) =>
          ALLOWED_TYPES.includes(a.contentType?.toLowerCase()) &&
          !a.isInline &&
          a.contentBytes
      );

      await db
        .update(emailInbox)
        .set({ attachmentCount: invoiceAttachments.length })
        .where(eq(emailInbox.id, emailId));

      for (const attachment of invoiceAttachments) {
        try {
          const buffer = Buffer.from(attachment.contentBytes, "base64");
          const { storedFilename, fileType } = await saveFile(
            buffer,
            attachment.name
          );
          const invoiceId = nanoid();

          await db.insert(invoices).values({
            id: invoiceId,
            companyId,
            emailInboxId: emailId,
            source: "email",
            originalFilename: attachment.name,
            storedFilename,
            fileType,
            fileSize: attachment.size || buffer.length,
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
              .where(eq(invoices.id, invoiceId));

            processed++;
          } catch (extractionError) {
            await db
              .update(invoices)
              .set({
                status: "failed",
                processingError:
                  extractionError instanceof Error
                    ? extractionError.message
                    : "Extraction failed",
              })
              .where(eq(invoices.id, invoiceId));
          }
        } catch (attError) {
          errors.push(
            `Attachment ${attachment.name}: ${attError instanceof Error ? attError.message : "Failed"}`
          );
        }
      }

      await db
        .update(emailInbox)
        .set({ status: "processed" })
        .where(eq(emailInbox.id, emailId));

      try {
        await markAsRead(company, message.id);
      } catch {
        // Non-critical
      }
    } catch (msgError) {
      errors.push(
        `Message ${message.subject}: ${msgError instanceof Error ? msgError.message : "Failed"}`
      );
    }
  }

  return { fetched, processed, errors };
}
