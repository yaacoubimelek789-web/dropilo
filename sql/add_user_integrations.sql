-- Run this if your DB already exists without user_integrations
CREATE TABLE IF NOT EXISTS `user_integrations` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int unsigned NOT NULL,
  `provider` varchar(50) NOT NULL DEFAULT 'fiabilo',
  `add_token_encrypted` text COMMENT 'Add Token for creating shipments',
  `tracking_token_encrypted` text COMMENT 'Tracking Token for status',
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `user_provider` (`user_id`, `provider`),
  CONSTRAINT `user_integrations_user_fk` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
