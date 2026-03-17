import { db } from "../db";
import { usageLogs } from "../db/schema";
import { eq, and, sql } from "drizzle-orm";
import { nanoid } from "nanoid";

function currentMonth(): string {
  const d = new Date();
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}`;
}

export async function trackInvoiceProcessed(
  companyId: string,
  fileSize: number
) {
  const month = currentMonth();

  const result = await db
    .update(usageLogs)
    .set({
      invoicesProcessed: sql`${usageLogs.invoicesProcessed} + 1`,
      storageUsedBytes: sql`${usageLogs.storageUsedBytes} + ${fileSize}`,
      apiCallsCount: sql`${usageLogs.apiCallsCount} + 1`,
      updatedAt: new Date(),
    })
    .where(
      and(eq(usageLogs.companyId, companyId), eq(usageLogs.month, month))
    );

  if (result[0].affectedRows === 0) {
    await db.insert(usageLogs).values({
      id: nanoid(),
      companyId,
      month,
      invoicesProcessed: 1,
      storageUsedBytes: fileSize,
      apiCallsCount: 1,
    });
  }
}

export async function trackApiCall(companyId: string) {
  const month = currentMonth();

  const result = await db
    .update(usageLogs)
    .set({
      apiCallsCount: sql`${usageLogs.apiCallsCount} + 1`,
      updatedAt: new Date(),
    })
    .where(
      and(eq(usageLogs.companyId, companyId), eq(usageLogs.month, month))
    );

  if (result[0].affectedRows === 0) {
    await db.insert(usageLogs).values({
      id: nanoid(),
      companyId,
      month,
      invoicesProcessed: 0,
      storageUsedBytes: 0,
      apiCallsCount: 1,
    });
  }
}
