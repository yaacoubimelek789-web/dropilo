-- Shopify Made Easy - Orders Confirmation
-- Run this once on Hostinger MySQL (u755103422_gloras)

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- users
CREATE TABLE IF NOT EXISTS `users` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `email` varchar(255) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `name` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- shops (one user, many shops)
CREATE TABLE IF NOT EXISTS `shops` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int unsigned NOT NULL,
  `name` varchar(255) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `shops_user_fk` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- products (per shop, from Shopify products CSV)
CREATE TABLE IF NOT EXISTS `products` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `shop_id` int unsigned NOT NULL,
  `handle` varchar(255) DEFAULT NULL,
  `title` varchar(500) NOT NULL,
  `body_html` text,
  `vendor` varchar(255) DEFAULT NULL,
  `type` varchar(255) DEFAULT NULL,
  `variant_sku` varchar(255) DEFAULT NULL,
  `variant_price` decimal(12,2) DEFAULT NULL,
  `image_src` varchar(1000) DEFAULT NULL,
  `cost` decimal(12,2) DEFAULT NULL COMMENT 'Cost per item for margin/facture',
  `status` varchar(50) DEFAULT 'active',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `shop_id` (`shop_id`),
  KEY `shop_title` (`shop_id`, `title`(191)),
  KEY `shop_sku` (`shop_id`, `variant_sku`(100)),
  CONSTRAINT `products_shop_fk` FOREIGN KEY (`shop_id`) REFERENCES `shops` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- orders (per shop, from Shopify orders CSV)
CREATE TABLE IF NOT EXISTS `orders` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `shop_id` int unsigned NOT NULL,
  `name` varchar(100) NOT NULL COMMENT 'Order # e.g. #1081',
  `order_created_at` datetime DEFAULT NULL COMMENT 'Created at from CSV',
  `financial_status` varchar(50) DEFAULT NULL,
  `fulfillment_status` varchar(50) DEFAULT NULL,
  `total` decimal(12,2) DEFAULT NULL,
  `currency` varchar(10) DEFAULT NULL,
  `shipping_method` varchar(255) DEFAULT NULL,
  `billing_name` varchar(255) DEFAULT NULL,
  `billing_phone` varchar(100) DEFAULT NULL,
  `billing_address` varchar(500) DEFAULT NULL,
  `billing_city` varchar(255) DEFAULT NULL,
  `billing_zip` varchar(50) DEFAULT NULL,
  `billing_country` varchar(100) DEFAULT NULL,
  `shipping_name` varchar(255) DEFAULT NULL,
  `shipping_address` varchar(500) DEFAULT NULL,
  `shipping_city` varchar(255) DEFAULT NULL,
  `shipping_zip` varchar(50) DEFAULT NULL,
  `notes` text,
  `phone` varchar(100) DEFAULT NULL,
  `confirmed` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'User confirmed by phone',
  `follow_up` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Needs follow-up',
  `fiabilo_tracking_code` varchar(100) DEFAULT NULL,
  `fiabilo_status` varchar(50) DEFAULT NULL COMMENT 'e.g. En attente, Livré',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `shop_id` (`shop_id`),
  KEY `shop_name` (`shop_id`, `name`),
  KEY `confirmed` (`confirmed`),
  KEY `follow_up` (`follow_up`),
  CONSTRAINT `orders_shop_fk` FOREIGN KEY (`shop_id`) REFERENCES `shops` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- order line items (with optional product match)
CREATE TABLE IF NOT EXISTS `order_line_items` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `order_id` int unsigned NOT NULL,
  `lineitem_name` varchar(500) NOT NULL,
  `lineitem_sku` varchar(255) DEFAULT NULL,
  `lineitem_price` decimal(12,2) DEFAULT NULL,
  `lineitem_quantity` int unsigned NOT NULL DEFAULT 1,
  `vendor` varchar(255) DEFAULT NULL,
  `fulfillment_status` varchar(50) DEFAULT NULL,
  `product_id` int unsigned DEFAULT NULL COMMENT 'Matched product when in catalog',
  PRIMARY KEY (`id`),
  KEY `order_id` (`order_id`),
  KEY `product_id` (`product_id`),
  CONSTRAINT `line_items_order_fk` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE,
  CONSTRAINT `line_items_product_fk` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- user integrations (Fiabilo tokens etc.), encrypted
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

SET FOREIGN_KEY_CHECKS = 1;
