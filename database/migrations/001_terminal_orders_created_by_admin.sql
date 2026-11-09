-- Fix #3: record which admin created each terminal order.
-- Apply once to databases created before this change:
--   mysql -u <user> -p unismart_pay < database/migrations/001_terminal_orders_created_by_admin.sql
ALTER TABLE terminal_orders
	ADD COLUMN created_by_admin INT NULL AFTER expires_at,
	ADD CONSTRAINT fk_terminal_orders_created_by_admin
		FOREIGN KEY (created_by_admin) REFERENCES admins(id_admin) ON DELETE SET NULL;
