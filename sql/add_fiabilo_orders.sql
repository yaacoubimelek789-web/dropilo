ALTER TABLE `orders` ADD COLUMN `fiabilo_tracking_code` varchar(100) DEFAULT NULL AFTER `follow_up`;
ALTER TABLE `orders` ADD COLUMN `fiabilo_status` varchar(50) DEFAULT NULL COMMENT 'e.g. En attente, Livré' AFTER `fiabilo_tracking_code`;
