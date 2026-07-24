-- EasyContactForm schema v2. Import via phpMyAdmin (MySQL 5.7+ / MariaDB).
--
-- This file creates a *fresh* database. To upgrade an existing v1 install
-- (users / projects / submissions only), run `php migrations/migrate.php`
-- instead — it converts in place and preserves all data.
--
-- Shape:
--   users              dashboard accounts
--   projects           one per landing page; owns branding + forms
--   forms              one embeddable form; a project may have many
--   form_fields        the ordered field definitions of a form
--   submissions        one row per form submission
--   submission_values  the submitted values, one row per field
--   schema_migrations  which migrations have been applied
--
-- Identifier policy: every table whose rows are addressable from outside has a
-- `public_id` (random UUIDv4) and/or a random hex token. The AUTO_INCREMENT
-- `id` columns are internal only and must never appear in a URL, an API
-- payload, or an embed snippet.

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS `users` (
  `id`            INT          NOT NULL AUTO_INCREMENT,
  `email`         VARCHAR(190) NOT NULL,
  `password_hash` VARCHAR(255) NOT NULL,
  `api_token`     VARCHAR(64)  NOT NULL,
  `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_users_email` (`email`),
  UNIQUE KEY `uniq_users_api_token` (`api_token`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `projects` (
  `id`               INT          NOT NULL AUTO_INCREMENT,
  `public_id`        CHAR(36)     NOT NULL COMMENT 'UUIDv4 — the identifier used in dashboard URLs and API calls',
  `user_id`          INT          NOT NULL,
  `project_name`     VARCHAR(150) NOT NULL,
  `project_token`    VARCHAR(40)  NOT NULL COMMENT 'Public token used by embed snippets',
  `website_url`      VARCHAR(255) NULL,
  `logo_url`         TEXT         NULL COMMENT 'Resolved favicon URL, or a data: URI for an uploaded logo',
  `reply_from_email` VARCHAR(190) NULL COMMENT 'Sender used by quick-reply, and by the future automated reply service',
  `created_at`       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_projects_public_id` (`public_id`),
  UNIQUE KEY `uniq_projects_token` (`project_token`),
  KEY `idx_projects_user` (`user_id`),
  CONSTRAINT `fk_projects_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `forms` (
  `id`         INT          NOT NULL AUTO_INCREMENT,
  `public_id`  CHAR(36)     NOT NULL COMMENT 'UUIDv4 — used in dashboard URLs and API calls',
  `project_id` INT          NOT NULL,
  `form_name`  VARCHAR(150) NOT NULL,
  `form_token` VARCHAR(40)  NOT NULL COMMENT 'Public token used by this form''s embed snippet',
  `is_default` TINYINT(1)   NOT NULL DEFAULT 0 COMMENT 'The form legacy project_token submissions land on',
  `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_forms_public_id` (`public_id`),
  UNIQUE KEY `uniq_forms_token` (`form_token`),
  KEY `idx_forms_project` (`project_id`),
  CONSTRAINT `fk_forms_project` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `form_fields` (
  `id`          INT          NOT NULL AUTO_INCREMENT,
  `form_id`     INT          NOT NULL,
  `field_key`   VARCHAR(64)  NOT NULL COMMENT 'Stable machine key, ^[a-z][a-z0-9_]*$',
  `label`       VARCHAR(150) NOT NULL,
  `field_type`  VARCHAR(20)  NOT NULL DEFAULT 'text' COMMENT 'text | email | phone | textarea',
  `is_required` TINYINT(1)   NOT NULL DEFAULT 1,
  `sort_order`  INT          NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_form_fields_key` (`form_id`, `field_key`),
  KEY `idx_form_fields_order` (`form_id`, `sort_order`),
  CONSTRAINT `fk_form_fields_form` FOREIGN KEY (`form_id`) REFERENCES `forms` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `submissions` (
  `id`         INT          NOT NULL AUTO_INCREMENT,
  `public_id`  CHAR(36)     NOT NULL COMMENT 'UUIDv4 — used in dashboard URLs and API calls',
  `project_id` INT          NOT NULL,
  `form_id`    INT          NOT NULL,
  `is_read`    TINYINT(1)   NOT NULL DEFAULT 0,
  `read_at`    DATETIME     NULL,
  `ip_address` VARCHAR(45)  NULL,
  `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  -- Legacy columns, retained only so pre-v2 installs keep an untouched copy of
  -- their original data after migration. Current code never reads or writes
  -- them; every value lives in submission_values. Always NULL on fresh installs.
  `full_name`  VARCHAR(150) NULL,
  `email`      VARCHAR(190) NULL,
  `message`    TEXT         NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_submissions_public_id` (`public_id`),
  KEY `idx_submissions_project` (`project_id`),
  KEY `idx_submissions_form` (`form_id`, `created_at`),
  KEY `idx_submissions_unread` (`project_id`, `is_read`),
  KEY `idx_submissions_created` (`created_at`),
  CONSTRAINT `fk_submissions_project` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_submissions_form` FOREIGN KEY (`form_id`) REFERENCES `forms` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `submission_values` (
  `id`            INT          NOT NULL AUTO_INCREMENT,
  `submission_id` INT          NOT NULL,
  `field_key`     VARCHAR(64)  NOT NULL,
  -- Label and type are snapshotted at submit time, so editing or deleting a
  -- field later never rewrites the history of what was actually submitted.
  `field_label`   VARCHAR(150) NOT NULL,
  `field_type`    VARCHAR(20)  NOT NULL,
  `value`         TEXT         NOT NULL,
  `sort_order`    INT          NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_submission_values_key` (`submission_id`, `field_key`),
  KEY `idx_submission_values_order` (`submission_id`, `sort_order`),
  CONSTRAINT `fk_submission_values_submission` FOREIGN KEY (`submission_id`) REFERENCES `submissions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `schema_migrations` (
  `version`    VARCHAR(100) NOT NULL,
  `applied_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`version`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A fresh database is already at the latest version; record every migration as
-- applied so `php migrations/migrate.php` is a no-op here.
INSERT IGNORE INTO `schema_migrations` (`version`) VALUES ('001_forms_and_public_ids');

SET FOREIGN_KEY_CHECKS = 1;
