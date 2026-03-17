-- OCR Invoice System - Database Schema
-- Database: admin_ocr

CREATE TABLE IF NOT EXISTS `companies` (
  `id` VARCHAR(30) NOT NULL PRIMARY KEY,
  `name` VARCHAR(255) NOT NULL,
  `code` VARCHAR(50) NOT NULL UNIQUE,
  `logo_url` VARCHAR(2048),
  `ms_client_id` VARCHAR(255),
  `ms_client_secret` VARCHAR(255),
  `ms_tenant_id` VARCHAR(255),
  `ms_sender_email` VARCHAR(255),
  `ms_fetch_enabled` TINYINT(1) DEFAULT 0,
  `ms_fetch_folder` VARCHAR(255) DEFAULT 'INBOX',
  `ms_fetch_interval_minutes` INT DEFAULT 15,
  `ms_access_token` TEXT,
  `ms_token_expires` VARCHAR(50),
  `vecticum_enabled` TINYINT(1) DEFAULT 0,
  `vecticum_api_base_url` VARCHAR(2048),
  `vecticum_client_id` VARCHAR(255),
  `vecticum_client_secret` VARCHAR(255),
  `vecticum_company_id` VARCHAR(255),
  `vecticum_author_id` VARCHAR(255),
  `vecticum_author_name` VARCHAR(255),
  `vecticum_access_token` TEXT,
  `vecticum_token_expires` VARCHAR(50),
  `extraction_fields` JSON DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `users` (
  `id` VARCHAR(30) NOT NULL PRIMARY KEY,
  `name` VARCHAR(255) NOT NULL,
  `email` VARCHAR(255) NOT NULL UNIQUE,
  `password_hash` VARCHAR(255) NOT NULL,
  `role` ENUM('superadmin','user') NOT NULL DEFAULT 'user',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `user_companies` (
  `id` VARCHAR(30) NOT NULL PRIMARY KEY,
  `user_id` VARCHAR(30) NOT NULL,
  `company_id` VARCHAR(30) NOT NULL,
  `role` ENUM('owner','admin','manager','viewer') NOT NULL DEFAULT 'viewer',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uk_user_company` (`user_id`, `company_id`),
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`company_id`) REFERENCES `companies`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `invoices` (
  `id` VARCHAR(30) NOT NULL PRIMARY KEY,
  `company_id` VARCHAR(30),
  `email_inbox_id` VARCHAR(30),
  `source` ENUM('upload','email') DEFAULT 'upload',
  `original_filename` VARCHAR(500) NOT NULL,
  `stored_filename` VARCHAR(500) NOT NULL,
  `file_type` VARCHAR(50) NOT NULL,
  `file_size` INT NOT NULL,
  `page_count` INT DEFAULT 1,
  `status` ENUM('uploaded','processing','completed','failed') NOT NULL DEFAULT 'uploaded',
  `processing_error` TEXT,
  `invoice_number` VARCHAR(255),
  `invoice_date` VARCHAR(20),
  `due_date` VARCHAR(20),
  `vendor_name` VARCHAR(500),
  `vendor_address` TEXT,
  `vendor_vat_id` VARCHAR(100),
  `buyer_name` VARCHAR(500),
  `buyer_address` TEXT,
  `buyer_vat_id` VARCHAR(100),
  `total_amount` DECIMAL(12,2),
  `currency` VARCHAR(10),
  `tax_amount` DECIMAL(12,2),
  `subtotal_amount` DECIMAL(12,2),
  `po_number` VARCHAR(255),
  `payment_terms` TEXT,
  `bank_details` TEXT,
  `confidence_scores` JSON,
  `raw_extraction` JSON,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_company` (`company_id`),
  FOREIGN KEY (`company_id`) REFERENCES `companies`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `line_items` (
  `id` VARCHAR(30) NOT NULL PRIMARY KEY,
  `invoice_id` VARCHAR(30) NOT NULL,
  `line_number` INT NOT NULL,
  `description` TEXT,
  `quantity` DECIMAL(12,4),
  `unit` VARCHAR(50),
  `unit_price` DECIMAL(12,4),
  `total_price` DECIMAL(12,2),
  `vat_rate` DECIMAL(5,2),
  `confidence` DECIMAL(3,2),
  FOREIGN KEY (`invoice_id`) REFERENCES `invoices`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `email_inbox` (
  `id` VARCHAR(30) NOT NULL PRIMARY KEY,
  `company_id` VARCHAR(30) NOT NULL,
  `message_id` VARCHAR(500) NOT NULL,
  `subject` TEXT,
  `from_email` VARCHAR(255),
  `from_name` VARCHAR(255),
  `received_date` VARCHAR(50),
  `has_attachments` TINYINT(1) DEFAULT 0,
  `attachment_count` INT DEFAULT 0,
  `status` ENUM('new','processing','processed','failed') NOT NULL DEFAULT 'new',
  `processing_error` TEXT,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uk_company_message` (`company_id`, `message_id`(191)),
  FOREIGN KEY (`company_id`) REFERENCES `companies`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `settings` (
  `key` VARCHAR(255) NOT NULL PRIMARY KEY,
  `value` TEXT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `usage_logs` (
  `id` VARCHAR(30) NOT NULL PRIMARY KEY,
  `company_id` VARCHAR(30) NOT NULL,
  `month` VARCHAR(7) NOT NULL,
  `invoices_processed` INT NOT NULL DEFAULT 0,
  `storage_used_bytes` BIGINT NOT NULL DEFAULT 0,
  `api_calls_count` INT NOT NULL DEFAULT 0,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uk_company_month` (`company_id`, `month`),
  FOREIGN KEY (`company_id`) REFERENCES `companies`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `audit_log` (
  `id` VARCHAR(30) NOT NULL PRIMARY KEY,
  `user_id` VARCHAR(30),
  `company_id` VARCHAR(30),
  `action` VARCHAR(50) NOT NULL,
  `resource_type` VARCHAR(50) NOT NULL,
  `resource_id` VARCHAR(30),
  `metadata` JSON,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_company_created` (`company_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `subscriptions` (
  `id` VARCHAR(30) NOT NULL PRIMARY KEY,
  `company_id` VARCHAR(30) NOT NULL UNIQUE,
  `plan` ENUM('free','starter','professional','enterprise') NOT NULL DEFAULT 'free',
  `status` ENUM('active','suspended','cancelled') NOT NULL DEFAULT 'active',
  `invoice_limit` INT,
  `storage_limit_bytes` BIGINT,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`company_id`) REFERENCES `companies`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
