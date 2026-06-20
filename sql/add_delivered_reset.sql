-- Add last_delivered_reset column to user_integrations to track real-time dashboard resets
ALTER TABLE `user_integrations` ADD COLUMN `last_delivered_reset` DATETIME DEFAULT NULL;
