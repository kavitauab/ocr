import {
  mysqlTable,
  varchar,
  text,
  boolean,
  int,
  bigint,
  decimal,
  datetime,
  mysqlEnum,
  json,
  uniqueIndex,
  index,
} from "drizzle-orm/mysql-core";
import { sql } from "drizzle-orm";

export const companies = mysqlTable("companies", {
  id: varchar("id", { length: 21 }).primaryKey(),
  name: varchar("name", { length: 255 }).notNull(),
  code: varchar("code", { length: 50 }).notNull().unique(),
  logoUrl: varchar("logo_url", { length: 2048 }),
  msClientId: varchar("ms_client_id", { length: 255 }),
  msClientSecret: varchar("ms_client_secret", { length: 255 }),
  msTenantId: varchar("ms_tenant_id", { length: 255 }),
  msSenderEmail: varchar("ms_sender_email", { length: 255 }),
  msFetchEnabled: boolean("ms_fetch_enabled").default(false),
  msFetchFolder: varchar("ms_fetch_folder", { length: 255 }).default("INBOX"),
  msFetchIntervalMinutes: int("ms_fetch_interval_minutes").default(15),
  msAccessToken: text("ms_access_token"),
  msTokenExpires: varchar("ms_token_expires", { length: 50 }),
  vecticumEnabled: boolean("vecticum_enabled").default(false),
  vecticumApiBaseUrl: varchar("vecticum_api_base_url", { length: 2048 }),
  vecticumClientId: varchar("vecticum_client_id", { length: 255 }),
  vecticumClientSecret: varchar("vecticum_client_secret", { length: 255 }),
  vecticumCompanyId: varchar("vecticum_company_id", { length: 255 }),
  vecticumAuthorId: varchar("vecticum_author_id", { length: 255 }),
  vecticumAuthorName: varchar("vecticum_author_name", { length: 255 }),
  vecticumAccessToken: text("vecticum_access_token"),
  vecticumTokenExpires: varchar("vecticum_token_expires", { length: 50 }),
  createdAt: datetime("created_at").notNull().default(sql`NOW()`),
  updatedAt: datetime("updated_at").notNull().default(sql`NOW()`),
});

export const users = mysqlTable("users", {
  id: varchar("id", { length: 21 }).primaryKey(),
  name: varchar("name", { length: 255 }).notNull(),
  email: varchar("email", { length: 255 }).notNull().unique(),
  passwordHash: varchar("password_hash", { length: 255 }).notNull(),
  role: mysqlEnum("role", ["superadmin", "user"]).notNull().default("user"),
  createdAt: datetime("created_at").notNull().default(sql`NOW()`),
  updatedAt: datetime("updated_at").notNull().default(sql`NOW()`),
});

export const userCompanies = mysqlTable(
  "user_companies",
  {
    id: varchar("id", { length: 21 }).primaryKey(),
    userId: varchar("user_id", { length: 21 }).notNull().references(() => users.id, { onDelete: "cascade" }),
    companyId: varchar("company_id", { length: 21 }).notNull().references(() => companies.id, { onDelete: "cascade" }),
    role: mysqlEnum("role", ["owner", "admin", "manager", "viewer"]).notNull().default("viewer"),
    createdAt: datetime("created_at").notNull().default(sql`NOW()`),
  },
  (table) => [uniqueIndex("user_company_unique").on(table.userId, table.companyId)]
);

export const invoices = mysqlTable(
  "invoices",
  {
    id: varchar("id", { length: 21 }).primaryKey(),
    companyId: varchar("company_id", { length: 21 }).references(() => companies.id),
    emailInboxId: varchar("email_inbox_id", { length: 21 }),
    source: mysqlEnum("source", ["upload", "email"]).default("upload"),
    originalFilename: varchar("original_filename", { length: 500 }).notNull(),
    storedFilename: varchar("stored_filename", { length: 500 }).notNull(),
    fileType: varchar("file_type", { length: 50 }).notNull(),
    fileSize: int("file_size").notNull(),
    pageCount: int("page_count").default(1),
    status: mysqlEnum("status", ["uploaded", "processing", "completed", "failed"]).notNull().default("uploaded"),
    processingError: text("processing_error"),
    invoiceNumber: varchar("invoice_number", { length: 255 }),
    invoiceDate: varchar("invoice_date", { length: 20 }),
    dueDate: varchar("due_date", { length: 20 }),
    vendorName: varchar("vendor_name", { length: 500 }),
    vendorAddress: text("vendor_address"),
    vendorVatId: varchar("vendor_vat_id", { length: 100 }),
    buyerName: varchar("buyer_name", { length: 500 }),
    buyerAddress: text("buyer_address"),
    buyerVatId: varchar("buyer_vat_id", { length: 100 }),
    totalAmount: decimal("total_amount", { precision: 12, scale: 2 }),
    currency: varchar("currency", { length: 10 }),
    taxAmount: decimal("tax_amount", { precision: 12, scale: 2 }),
    subtotalAmount: decimal("subtotal_amount", { precision: 12, scale: 2 }),
    poNumber: varchar("po_number", { length: 255 }),
    paymentTerms: text("payment_terms"),
    bankDetails: text("bank_details"),
    confidenceScores: json("confidence_scores"),
    rawExtraction: json("raw_extraction"),
    createdAt: datetime("created_at").notNull().default(sql`NOW()`),
    updatedAt: datetime("updated_at").notNull().default(sql`NOW()`),
  },
  (table) => [index("invoices_company_idx").on(table.companyId)]
);

export const lineItems = mysqlTable("line_items", {
  id: varchar("id", { length: 21 }).primaryKey(),
  invoiceId: varchar("invoice_id", { length: 21 }).notNull().references(() => invoices.id, { onDelete: "cascade" }),
  lineNumber: int("line_number").notNull(),
  description: text("description"),
  quantity: decimal("quantity", { precision: 12, scale: 4 }),
  unit: varchar("unit", { length: 50 }),
  unitPrice: decimal("unit_price", { precision: 12, scale: 4 }),
  totalPrice: decimal("total_price", { precision: 12, scale: 2 }),
  vatRate: decimal("vat_rate", { precision: 5, scale: 2 }),
  confidence: decimal("confidence", { precision: 3, scale: 2 }),
});

export const emailInbox = mysqlTable(
  "email_inbox",
  {
    id: varchar("id", { length: 21 }).primaryKey(),
    companyId: varchar("company_id", { length: 21 }).notNull().references(() => companies.id),
    messageId: varchar("message_id", { length: 500 }).notNull(),
    subject: text("subject"),
    fromEmail: varchar("from_email", { length: 255 }),
    fromName: varchar("from_name", { length: 255 }),
    receivedDate: varchar("received_date", { length: 50 }),
    hasAttachments: boolean("has_attachments").default(false),
    attachmentCount: int("attachment_count").default(0),
    status: mysqlEnum("status", ["new", "processing", "processed", "failed"]).notNull().default("new"),
    processingError: text("processing_error"),
    createdAt: datetime("created_at").notNull().default(sql`NOW()`),
  },
  (table) => [uniqueIndex("email_inbox_company_message").on(table.companyId, table.messageId)]
);

export const settings = mysqlTable("settings", {
  key: varchar("key", { length: 255 }).primaryKey(),
  value: text("value").notNull(),
});

export const usageLogs = mysqlTable(
  "usage_logs",
  {
    id: varchar("id", { length: 21 }).primaryKey(),
    companyId: varchar("company_id", { length: 21 }).notNull().references(() => companies.id, { onDelete: "cascade" }),
    month: varchar("month", { length: 7 }).notNull(),
    invoicesProcessed: int("invoices_processed").notNull().default(0),
    storageUsedBytes: bigint("storage_used_bytes", { mode: "number" }).notNull().default(0),
    apiCallsCount: int("api_calls_count").notNull().default(0),
    updatedAt: datetime("updated_at").notNull().default(sql`NOW()`),
  },
  (table) => [uniqueIndex("usage_company_month").on(table.companyId, table.month)]
);

export const auditLog = mysqlTable(
  "audit_log",
  {
    id: varchar("id", { length: 21 }).primaryKey(),
    userId: varchar("user_id", { length: 21 }),
    companyId: varchar("company_id", { length: 21 }),
    action: varchar("action", { length: 50 }).notNull(),
    resourceType: varchar("resource_type", { length: 50 }).notNull(),
    resourceId: varchar("resource_id", { length: 21 }),
    metadata: json("metadata"),
    createdAt: datetime("created_at").notNull().default(sql`NOW()`),
  },
  (table) => [index("audit_company_created").on(table.companyId, table.createdAt)]
);

export const subscriptions = mysqlTable("subscriptions", {
  id: varchar("id", { length: 21 }).primaryKey(),
  companyId: varchar("company_id", { length: 21 }).notNull().references(() => companies.id, { onDelete: "cascade" }).unique(),
  plan: mysqlEnum("plan", ["free", "starter", "professional", "enterprise"]).notNull().default("free"),
  status: mysqlEnum("status", ["active", "suspended", "cancelled"]).notNull().default("active"),
  invoiceLimit: int("invoice_limit"),
  storageLimitBytes: bigint("storage_limit_bytes", { mode: "number" }),
  createdAt: datetime("created_at").notNull().default(sql`NOW()`),
  updatedAt: datetime("updated_at").notNull().default(sql`NOW()`),
});
