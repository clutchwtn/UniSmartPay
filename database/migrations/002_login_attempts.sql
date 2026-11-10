-- Fix #9: failed-login tracking for rate limiting.
-- Apply once to databases created before this change:
--   mysql -u <user> -p unismart_pay < database/migrations/002_login_attempts.sql
CREATE TABLE login_attempts (
	id_attempt      INT AUTO_INCREMENT PRIMARY KEY,
	type_user       ENUM('admin','etudiant') NOT NULL,
	identifier      VARCHAR(190) NOT NULL,
	ip_address      VARCHAR(45)  NOT NULL DEFAULT '',
	attempted_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
	INDEX idx_account (type_user, identifier, attempted_at),
	INDEX idx_ip      (ip_address, attempted_at)
) ENGINE=InnoDB;
