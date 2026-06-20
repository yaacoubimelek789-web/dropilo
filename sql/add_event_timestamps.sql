-- Migration: Add event-based timestamps to orders table
ALTER TABLE `orders` 
ADD COLUMN `confirmed_at` DATETIME DEFAULT NULL AFTER `confirmed`,
ADD COLUMN `followup_at` DATETIME DEFAULT NULL AFTER `follow_up`,
ADD COLUMN `shipped_at` DATETIME DEFAULT NULL,
ADD COLUMN `delivered_at` DATETIME DEFAULT NULL,
ADD COLUMN `returned_at` DATETIME DEFAULT NULL;

-- Backfill: For existing records, use reasonable defaults where possible
-- (e.g., if already confirmed, set confirmed_at to order_created_at or created_at)
UPDATE orders SET confirmed_at = COALESCE(order_created_at, created_at) WHERE confirmed = 1 AND confirmed_at IS NULL;
UPDATE orders SET followup_at = created_at WHERE follow_up = 1 AND followup_at IS NULL;
UPDATE orders SET shipped_at = created_at WHERE fiabilo_tracking_code IS NOT NULL AND shipped_at IS NULL;
UPDATE orders SET delivered_at = created_at WHERE (LOWER(fiabilo_status) IN ('livré', 'livrés', 'livrer', 'delivered', 'reçu')) AND delivered_at IS NULL;
UPDATE orders SET returned_at = created_at WHERE (LOWER(fiabilo_status) IN ('retourné', 'annulé', 'retour', 'refusé', 'returned', 'cancelled')) AND returned_at IS NULL;
