import { db } from "../db";
import { auditLog } from "../db/schema";
import { nanoid } from "nanoid";

export async function logAction(params: {
  userId?: string;
  companyId?: string;
  action: string;
  resourceType: string;
  resourceId?: string;
  metadata?: Record<string, unknown>;
}) {
  await db.insert(auditLog).values({
    id: nanoid(),
    userId: params.userId ?? null,
    companyId: params.companyId ?? null,
    action: params.action,
    resourceType: params.resourceType,
    resourceId: params.resourceId ?? null,
    metadata: params.metadata ?? null,
  });
}
