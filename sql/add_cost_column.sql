-- Run this if your products table already exists without cost column
ALTER TABLE `products` ADD COLUMN `cost` decimal(12,2) DEFAULT NULL COMMENT 'Cost per item for margin/facture' AFTER `image_src`;
