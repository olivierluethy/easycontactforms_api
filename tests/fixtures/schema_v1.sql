-- EasyContactForm schema. Import via phpMyAdmin (MySQL 5.7+ / MariaDB).
-- Three tables: users (dashboard accounts), projects (one per landing page),
-- submissions (rows posted by the embeddable widget).

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
  `id`            INT          NOT NULL AUTO_INCREMENT,
  `user_id`       INT          NOT NULL,
  `project_name`  VARCHAR(150) NOT NULL,
  `project_token` VARCHAR(40)  NOT NULL,
  `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_projects_token` (`project_token`),
  KEY `idx_projects_token` (`project_token`),
  KEY `idx_projects_user` (`user_id`),
  CONSTRAINT `fk_projects_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `submissions` (
  `id`         INT          NOT NULL AUTO_INCREMENT,
  `project_id` INT          NOT NULL,
  `full_name`  VARCHAR(150) NOT NULL,
  `email`      VARCHAR(190) NOT NULL,
  `message`    TEXT         NOT NULL,
  `ip_address` VARCHAR(45)  NULL,
  `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_submissions_project` (`project_id`),
  KEY `idx_submissions_created` (`created_at`),
  CONSTRAINT `fk_submissions_project` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
