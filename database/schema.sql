-- ============================================================
-- PaymentModule - Complete MySQL 8 Schema
-- Run: mysql -u root -p paymentmodule < schema.sql
-- ============================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;
SET SQL_MODE = 'STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION';

-- ============================================================
-- Database
-- ============================================================
CREATE DATABASE IF NOT EXISTS `paymentmodule`
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE `paymentmodule`;

-- ============================================================
-- TABLE: settings
-- ============================================================
CREATE TABLE `settings` (
  `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `key`        VARCHAR(100) NOT NULL,
  `value`      TEXT,
  `type`       ENUM('string','integer','boolean','json') NOT NULL DEFAULT 'string',
  `group`      VARCHAR(50) NOT NULL DEFAULT 'general',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_settings_key` (`key`),
  KEY `idx_settings_group` (`group`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: users
-- ============================================================
CREATE TABLE `users` (
  `id`                    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `uuid`                  CHAR(36) NOT NULL,
  `name`                  VARCHAR(100) NOT NULL,
  `email`                 VARCHAR(191) NOT NULL,
  `password`              VARCHAR(255) NOT NULL,
  `role`                  ENUM('admin','user') NOT NULL DEFAULT 'user',
  `status`                ENUM('active','suspended','pending') NOT NULL DEFAULT 'pending',
  `email_verified_at`     TIMESTAMP NULL DEFAULT NULL,
  `email_verify_token`    VARCHAR(100) NULL DEFAULT NULL,
  `password_reset_token`  VARCHAR(100) NULL DEFAULT NULL,
  `password_reset_expires`TIMESTAMP NULL DEFAULT NULL,
  `stripe_customer_id`    VARCHAR(100) NULL DEFAULT NULL,
  `paypal_customer_id`    VARCHAR(100) NULL DEFAULT NULL,
  `avatar`                VARCHAR(255) NULL DEFAULT NULL,
  `timezone`              VARCHAR(50) NOT NULL DEFAULT 'UTC',
  `currency`              CHAR(3) NOT NULL DEFAULT 'USD',
  `last_login_at`         TIMESTAMP NULL DEFAULT NULL,
  `last_login_ip`         VARCHAR(45) NULL DEFAULT NULL,
  `two_factor_secret`     VARCHAR(255) NULL DEFAULT NULL,
  `two_factor_enabled`    TINYINT(1) NOT NULL DEFAULT 0,
  `created_at`            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_users_uuid`  (`uuid`),
  UNIQUE KEY `uq_users_email` (`email`),
  KEY `idx_users_role`   (`role`),
  KEY `idx_users_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: refresh_tokens
-- ============================================================
CREATE TABLE `refresh_tokens` (
  `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id`    INT UNSIGNED NOT NULL,
  `token`      VARCHAR(500) NOT NULL,
  `expires_at` TIMESTAMP NOT NULL,
  `revoked`    TINYINT(1) NOT NULL DEFAULT 0,
  `ip_address` VARCHAR(45) NULL DEFAULT NULL,
  `user_agent` VARCHAR(255) NULL DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_refresh_token` (`token`(191)),
  KEY `idx_rt_user_id` (`user_id`),
  CONSTRAINT `fk_rt_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: plans
-- ============================================================
CREATE TABLE `plans` (
  `id`             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `uuid`           CHAR(36) NOT NULL,
  `name`           VARCHAR(100) NOT NULL,
  `slug`           VARCHAR(100) NOT NULL,
  `description`    TEXT NULL,
  `type`           ENUM('one_time','subscription') NOT NULL DEFAULT 'subscription',
  `price_monthly`  DECIMAL(10,2) UNSIGNED NOT NULL DEFAULT 0.00,
  `price_yearly`   DECIMAL(10,2) UNSIGNED NOT NULL DEFAULT 0.00,
  `currency`       CHAR(3) NOT NULL DEFAULT 'USD',
  `trial_days`     SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `features`       JSON NULL,
  `limits`         JSON NULL,
  `is_featured`    TINYINT(1) NOT NULL DEFAULT 0,
  `is_active`      TINYINT(1) NOT NULL DEFAULT 1,
  `sort_order`     SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `stripe_monthly_price_id`  VARCHAR(100) NULL DEFAULT NULL,
  `stripe_yearly_price_id`   VARCHAR(100) NULL DEFAULT NULL,
  `paypal_monthly_plan_id`   VARCHAR(100) NULL DEFAULT NULL,
  `paypal_yearly_plan_id`    VARCHAR(100) NULL DEFAULT NULL,
  `metadata`       JSON NULL,
  `created_at`     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_plans_uuid` (`uuid`),
  UNIQUE KEY `uq_plans_slug` (`slug`),
  KEY `idx_plans_type`      (`type`),
  KEY `idx_plans_is_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: coupons
-- ============================================================
CREATE TABLE `coupons` (
  `id`              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `code`            VARCHAR(50) NOT NULL,
  `description`     VARCHAR(255) NULL DEFAULT NULL,
  `type`            ENUM('percent','fixed') NOT NULL DEFAULT 'percent',
  `value`           DECIMAL(10,2) UNSIGNED NOT NULL,
  `currency`        CHAR(3) NULL DEFAULT NULL,
  `min_amount`      DECIMAL(10,2) UNSIGNED NOT NULL DEFAULT 0.00,
  `max_uses`        INT UNSIGNED NULL DEFAULT NULL,
  `max_uses_per_user` INT UNSIGNED NOT NULL DEFAULT 1,
  `used_count`      INT UNSIGNED NOT NULL DEFAULT 0,
  `applies_to`      ENUM('all','one_time','subscription') NOT NULL DEFAULT 'all',
  `plan_ids`        JSON NULL,
  `valid_from`      TIMESTAMP NULL DEFAULT NULL,
  `valid_until`     TIMESTAMP NULL DEFAULT NULL,
  `is_active`       TINYINT(1) NOT NULL DEFAULT 1,
  `created_by`      INT UNSIGNED NOT NULL,
  `created_at`      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_coupons_code` (`code`),
  KEY `idx_coupons_is_active` (`is_active`),
  KEY `idx_coupons_valid_until` (`valid_until`),
  CONSTRAINT `fk_coupons_creator` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: subscriptions
-- ============================================================
CREATE TABLE `subscriptions` (
  `id`                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `uuid`              CHAR(36) NOT NULL,
  `user_id`           INT UNSIGNED NOT NULL,
  `plan_id`           INT UNSIGNED NOT NULL,
  `provider`          ENUM('stripe','paypal','paymob','cmi','checkout','manual') NOT NULL,
  `provider_sub_id`   VARCHAR(255) NULL DEFAULT NULL,
  `billing_cycle`     ENUM('monthly','yearly') NOT NULL DEFAULT 'monthly',
  `status`            ENUM('trialing','active','past_due','cancelled','expired','paused') NOT NULL DEFAULT 'active',
  `current_period_start` TIMESTAMP NOT NULL,
  `current_period_end`   TIMESTAMP NOT NULL,
  `trial_ends_at`     TIMESTAMP NULL DEFAULT NULL,
  `cancelled_at`      TIMESTAMP NULL DEFAULT NULL,
  `cancel_at_period_end` TINYINT(1) NOT NULL DEFAULT 0,
  `ended_at`          TIMESTAMP NULL DEFAULT NULL,
  `coupon_id`         INT UNSIGNED NULL DEFAULT NULL,
  `discount_amount`   DECIMAL(10,2) UNSIGNED NOT NULL DEFAULT 0.00,
  `metadata`          JSON NULL,
  `created_at`        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_subscriptions_uuid` (`uuid`),
  KEY `idx_subs_user_id` (`user_id`),
  KEY `idx_subs_plan_id` (`plan_id`),
  KEY `idx_subs_status`  (`status`),
  KEY `idx_subs_period_end` (`current_period_end`),
  CONSTRAINT `fk_subs_user`   FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_subs_plan`   FOREIGN KEY (`plan_id`) REFERENCES `plans` (`id`),
  CONSTRAINT `fk_subs_coupon` FOREIGN KEY (`coupon_id`) REFERENCES `coupons` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: transactions
-- ============================================================
CREATE TABLE `transactions` (
  `id`                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `uuid`                CHAR(36) NOT NULL,
  `user_id`             INT UNSIGNED NOT NULL,
  `subscription_id`     INT UNSIGNED NULL DEFAULT NULL,
  `plan_id`             INT UNSIGNED NULL DEFAULT NULL,
  `coupon_id`           INT UNSIGNED NULL DEFAULT NULL,
  `provider`            ENUM('stripe','paypal','paymob','cmi','checkout','manual') NOT NULL,
  `provider_txn_id`     VARCHAR(255) NULL DEFAULT NULL,
  `provider_payment_intent` VARCHAR(255) NULL DEFAULT NULL,
  `type`                ENUM('payment','refund','subscription','renewal','adjustment') NOT NULL DEFAULT 'payment',
  `status`              ENUM('pending','processing','completed','failed','refunded','partially_refunded','cancelled','disputed') NOT NULL DEFAULT 'pending',
  `amount`              DECIMAL(10,2) NOT NULL,
  `tax_amount`          DECIMAL(10,2) UNSIGNED NOT NULL DEFAULT 0.00,
  `discount_amount`     DECIMAL(10,2) UNSIGNED NOT NULL DEFAULT 0.00,
  `net_amount`          DECIMAL(10,2) NOT NULL,
  `currency`            CHAR(3) NOT NULL DEFAULT 'USD',
  `refunded_amount`     DECIMAL(10,2) UNSIGNED NOT NULL DEFAULT 0.00,
  `description`         VARCHAR(500) NULL DEFAULT NULL,
  `failure_code`        VARCHAR(100) NULL DEFAULT NULL,
  `failure_message`     TEXT NULL,
  `payment_method`      VARCHAR(100) NULL DEFAULT NULL,
  `payment_method_last4`CHAR(4) NULL DEFAULT NULL,
  `ip_address`          VARCHAR(45) NULL DEFAULT NULL,
  `user_agent`          VARCHAR(500) NULL DEFAULT NULL,
  `billing_name`        VARCHAR(150) NULL DEFAULT NULL,
  `billing_email`       VARCHAR(191) NULL DEFAULT NULL,
  `billing_address`     JSON NULL,
  `metadata`            JSON NULL,
  `created_at`          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_transactions_uuid`        (`uuid`),
  KEY `idx_txn_user_id`       (`user_id`),
  KEY `idx_txn_sub_id`        (`subscription_id`),
  KEY `idx_txn_provider_id`   (`provider_txn_id`),
  KEY `idx_txn_status`        (`status`),
  KEY `idx_txn_type`          (`type`),
  KEY `idx_txn_created_at`    (`created_at`),
  CONSTRAINT `fk_txn_user`   FOREIGN KEY (`user_id`)         REFERENCES `users` (`id`),
  CONSTRAINT `fk_txn_sub`    FOREIGN KEY (`subscription_id`) REFERENCES `subscriptions` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_txn_plan`   FOREIGN KEY (`plan_id`)         REFERENCES `plans` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_txn_coupon` FOREIGN KEY (`coupon_id`)       REFERENCES `coupons` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: invoices
-- ============================================================
CREATE TABLE `invoices` (
  `id`             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `uuid`           CHAR(36) NOT NULL,
  `number`         VARCHAR(30) NOT NULL,
  `user_id`        INT UNSIGNED NOT NULL,
  `transaction_id` INT UNSIGNED NOT NULL,
  `subscription_id`INT UNSIGNED NULL DEFAULT NULL,
  `status`         ENUM('draft','sent','paid','void','uncollectible') NOT NULL DEFAULT 'draft',
  `subtotal`       DECIMAL(10,2) NOT NULL,
  `tax_rate`       DECIMAL(5,2) UNSIGNED NOT NULL DEFAULT 0.00,
  `tax_amount`     DECIMAL(10,2) UNSIGNED NOT NULL DEFAULT 0.00,
  `discount_amount`DECIMAL(10,2) UNSIGNED NOT NULL DEFAULT 0.00,
  `total`          DECIMAL(10,2) NOT NULL,
  `currency`       CHAR(3) NOT NULL DEFAULT 'USD',
  `due_date`       DATE NULL DEFAULT NULL,
  `paid_at`        TIMESTAMP NULL DEFAULT NULL,
  `voided_at`      TIMESTAMP NULL DEFAULT NULL,
  `billing_details`JSON NULL,
  `line_items`     JSON NULL,
  `notes`          TEXT NULL,
  `pdf_path`       VARCHAR(500) NULL DEFAULT NULL,
  `created_at`     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_invoices_uuid`   (`uuid`),
  UNIQUE KEY `uq_invoices_number` (`number`),
  KEY `idx_inv_user_id` (`user_id`),
  KEY `idx_inv_txn_id`  (`transaction_id`),
  KEY `idx_inv_status`  (`status`),
  CONSTRAINT `fk_inv_user` FOREIGN KEY (`user_id`)        REFERENCES `users` (`id`),
  CONSTRAINT `fk_inv_txn`  FOREIGN KEY (`transaction_id`) REFERENCES `transactions` (`id`),
  CONSTRAINT `fk_inv_sub`  FOREIGN KEY (`subscription_id`)REFERENCES `subscriptions` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: refunds
-- ============================================================
CREATE TABLE `refunds` (
  `id`                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `uuid`              CHAR(36) NOT NULL,
  `transaction_id`    INT UNSIGNED NOT NULL,
  `user_id`           INT UNSIGNED NOT NULL,
  `processed_by`      INT UNSIGNED NULL DEFAULT NULL,
  `provider`          ENUM('stripe','paypal','paymob','cmi','checkout','manual') NOT NULL,
  `provider_refund_id`VARCHAR(255) NULL DEFAULT NULL,
  `amount`            DECIMAL(10,2) UNSIGNED NOT NULL,
  `currency`          CHAR(3) NOT NULL DEFAULT 'USD',
  `reason`            ENUM('duplicate','fraudulent','requested_by_customer','other') NOT NULL DEFAULT 'requested_by_customer',
  `reason_detail`     TEXT NULL,
  `status`            ENUM('pending','succeeded','failed','cancelled') NOT NULL DEFAULT 'pending',
  `failure_reason`    TEXT NULL,
  `metadata`          JSON NULL,
  `created_at`        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_refunds_uuid` (`uuid`),
  KEY `idx_ref_txn_id`  (`transaction_id`),
  KEY `idx_ref_user_id` (`user_id`),
  KEY `idx_ref_status`  (`status`),
  CONSTRAINT `fk_ref_txn`       FOREIGN KEY (`transaction_id`) REFERENCES `transactions` (`id`),
  CONSTRAINT `fk_ref_user`      FOREIGN KEY (`user_id`)        REFERENCES `users` (`id`),
  CONSTRAINT `fk_ref_processor` FOREIGN KEY (`processed_by`)   REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: webhooks
-- ============================================================
CREATE TABLE `webhooks` (
  `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `uuid`        CHAR(36) NOT NULL,
  `provider`    ENUM('stripe','paypal','paymob','cmi','checkout') NOT NULL,
  `event_type`  VARCHAR(100) NOT NULL,
  `event_id`    VARCHAR(255) NULL DEFAULT NULL,
  `payload`     LONGTEXT NOT NULL,
  `headers`     TEXT NULL,
  `signature`   VARCHAR(500) NULL DEFAULT NULL,
  `verified`    TINYINT(1) NOT NULL DEFAULT 0,
  `status`      ENUM('received','processing','processed','failed','ignored') NOT NULL DEFAULT 'received',
  `processed_at`TIMESTAMP NULL DEFAULT NULL,
  `attempts`    TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `error`       TEXT NULL,
  `ip_address`  VARCHAR(45) NULL DEFAULT NULL,
  `created_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_webhooks_uuid` (`uuid`),
  KEY `idx_wh_provider`   (`provider`),
  KEY `idx_wh_event_id`   (`event_id`),
  KEY `idx_wh_status`     (`status`),
  KEY `idx_wh_event_type` (`event_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: payment_logs
-- ============================================================
CREATE TABLE `payment_logs` (
  `id`             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id`        INT UNSIGNED NULL DEFAULT NULL,
  `transaction_id` INT UNSIGNED NULL DEFAULT NULL,
  `provider`       VARCHAR(50) NULL DEFAULT NULL,
  `level`          ENUM('debug','info','warning','error','critical') NOT NULL DEFAULT 'info',
  `action`         VARCHAR(100) NOT NULL,
  `message`        TEXT NOT NULL,
  `context`        JSON NULL,
  `ip_address`     VARCHAR(45) NULL DEFAULT NULL,
  `created_at`     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_pl_user_id`  (`user_id`),
  KEY `idx_pl_txn_id`   (`transaction_id`),
  KEY `idx_pl_level`    (`level`),
  KEY `idx_pl_created`  (`created_at`),
  CONSTRAINT `fk_pl_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_pl_txn`  FOREIGN KEY (`transaction_id`) REFERENCES `transactions` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: rate_limits
-- ============================================================
CREATE TABLE `rate_limits` (
  `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `key`        VARCHAR(191) NOT NULL,
  `attempts`   SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  `reset_at`   TIMESTAMP NOT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_rl_key` (`key`),
  KEY `idx_rl_reset_at` (`reset_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABLE: coupon_usages
-- ============================================================
CREATE TABLE `coupon_usages` (
  `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `coupon_id`  INT UNSIGNED NOT NULL,
  `user_id`    INT UNSIGNED NOT NULL,
  `transaction_id` INT UNSIGNED NOT NULL,
  `used_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_cu_coupon`  (`coupon_id`),
  KEY `idx_cu_user`    (`user_id`),
  CONSTRAINT `fk_cu_coupon` FOREIGN KEY (`coupon_id`) REFERENCES `coupons` (`id`),
  CONSTRAINT `fk_cu_user`   FOREIGN KEY (`user_id`)   REFERENCES `users` (`id`),
  CONSTRAINT `fk_cu_txn`    FOREIGN KEY (`transaction_id`) REFERENCES `transactions` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- Default Data
-- ============================================================

-- Admin user (password: Admin@1234 - CHANGE IN PRODUCTION)
INSERT INTO `users` (`uuid`,`name`,`email`,`password`,`role`,`status`,`email_verified_at`) VALUES
  (UUID(),'Administrator','admin@example.com','$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi','admin','active',NOW());

-- Default Plans
INSERT INTO `plans` (`uuid`,`name`,`slug`,`description`,`type`,`price_monthly`,`price_yearly`,`currency`,`trial_days`,`features`,`is_featured`,`is_active`,`sort_order`) VALUES
  (UUID(),'Starter','starter','Perfect for individuals and small projects','subscription',9.99,99.99,'USD',14,'["5 Projects","10GB Storage","Email Support","API Access"]',0,1,1),
  (UUID(),'Professional','professional','For growing teams and businesses','subscription',29.99,299.99,'USD',14,'["Unlimited Projects","100GB Storage","Priority Support","API Access","Analytics","Custom Domain"]',1,1,2),
  (UUID(),'Enterprise','enterprise','For large organizations','subscription',99.99,999.99,'USD',0,'["Unlimited Projects","1TB Storage","24/7 Phone Support","API Access","Advanced Analytics","Custom Domain","SSO","SLA"]',0,1,3),
  (UUID(),'One-Time License','one-time-license','Lifetime access - pay once','one_time',299.00,299.00,'USD',0,'["Unlimited Projects","Lifetime Access","100GB Storage","Email Support","API Access"]',0,1,4);

-- Default Settings
INSERT INTO `settings` (`key`,`value`,`type`,`group`) VALUES
  ('site_name','PaymentModule','string','general'),
  ('site_url','https://yourdomain.com','string','general'),
  ('support_email','support@example.com','string','general'),
  ('default_currency','USD','string','payment'),
  ('tax_enabled','1','boolean','payment'),
  ('tax_rate','20','integer','payment'),
  ('tax_name','VAT','string','payment'),
  ('invoice_prefix','INV','string','invoice'),
  ('invoice_next_number','1000','integer','invoice'),
  ('webhook_retry_attempts','3','integer','webhook');

SET FOREIGN_KEY_CHECKS = 1;
