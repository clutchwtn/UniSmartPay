-- Idempotent payments: remember each payment's request id so a retried
-- request is answered from here instead of charging the student again.
-- Apply once to databases created before this change:
--   mysql -u <user> -p unismart_pay < database/migrations/003_payment_requests.sql
CREATE TABLE payment_requests (
	id_request     INT AUTO_INCREMENT PRIMARY KEY,
	scope          VARCHAR(40)  NOT NULL,         -- 'terminal:<id>' or 'etudiant:<id>'
	request_id     VARCHAR(64)  NOT NULL,
	id_transfert   INT,
	response_json  TEXT,
	created_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
	UNIQUE KEY uq_payment_request (scope, request_id),
	FOREIGN KEY (id_transfert) REFERENCES transferts(id_transfert) ON DELETE SET NULL,
	INDEX idx_payment_requests_created (created_at)
) ENGINE=InnoDB;
