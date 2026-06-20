-- Orders Page Enhancement: Add unified status column
-- Run this migration on your MySQL database

ALTER TABLE orders ADD COLUMN status VARCHAR(20) DEFAULT 'new' AFTER follow_up;

-- Migrate existing data to new status column
-- Priority: shipping > confirmed > followup > new

-- Orders sent to shipping company get 'shipping' status
UPDATE orders SET status = 'shipping' WHERE fiabilo_tracking_code IS NOT NULL AND fiabilo_tracking_code != '';

-- Confirmed orders (not shipped) get 'confirmed' status
UPDATE orders SET status = 'confirmed' WHERE confirmed = 1 AND (fiabilo_tracking_code IS NULL OR fiabilo_tracking_code = '');

-- Follow-up orders (not confirmed) get 'followup' status
UPDATE orders SET status = 'followup' WHERE follow_up = 1 AND confirmed = 0 AND (fiabilo_tracking_code IS NULL OR fiabilo_tracking_code = '');

-- Remaining orders stay as 'new' (default)

-- Add index for faster filtering
ALTER TABLE orders ADD INDEX idx_status (status);
