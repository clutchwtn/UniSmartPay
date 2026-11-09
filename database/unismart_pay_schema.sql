-- ============================================================
--  UniSmart Pay - Schéma Base de Données MySQL (XAMPP)
--  Système de paiement par carte RFID pour université
-- ============================================================

CREATE DATABASE IF NOT EXISTS unismart_pay CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE unismart_pay;

-- ============================================================
-- TABLE : facultes
-- ============================================================
CREATE TABLE facultes (
	id_faculte      INT AUTO_INCREMENT PRIMARY KEY,
	nom             VARCHAR(150) NOT NULL,
	code            VARCHAR(20)  NOT NULL UNIQUE,
	description     TEXT,
	actif           TINYINT(1)   NOT NULL DEFAULT 1,
	created_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ============================================================
-- TABLE : admins
-- ============================================================
CREATE TABLE admins (
	id_admin        INT AUTO_INCREMENT PRIMARY KEY,
	id_faculte      INT,                                          -- NULL = super admin
	nom             VARCHAR(100) NOT NULL,
	prenom          VARCHAR(100) NOT NULL,
	email           VARCHAR(150) NOT NULL UNIQUE,
	password_hash   VARCHAR(255) NOT NULL,                        -- password_hash() output
	role            ENUM('super_admin','admin_faculte') NOT NULL DEFAULT 'admin_faculte',
	actif           TINYINT(1)   NOT NULL DEFAULT 1,
	derniere_connexion TIMESTAMP NULL,
	created_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
	FOREIGN KEY (id_faculte) REFERENCES facultes(id_faculte) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ============================================================
-- TABLE : etudiants
-- ============================================================
CREATE TABLE etudiants (
	id_etudiant     INT AUTO_INCREMENT PRIMARY KEY,
	id_faculte      INT          NOT NULL,
	matricule       VARCHAR(30)  NOT NULL UNIQUE,
	nom             VARCHAR(100) NOT NULL,
	prenom          VARCHAR(100) NOT NULL,
	email           VARCHAR(150) NOT NULL UNIQUE,
	password_hash   VARCHAR(255) NOT NULL,
	date_naissance  DATE,
	telephone       VARCHAR(20),
	photo           VARCHAR(255),
	actif           TINYINT(1)   NOT NULL DEFAULT 1,
	derniere_connexion TIMESTAMP NULL,
	created_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
	FOREIGN KEY (id_faculte) REFERENCES facultes(id_faculte)
) ENGINE=InnoDB;

-- ============================================================
-- TABLE : comptes  (portefeuille de chaque étudiant)
-- ============================================================
CREATE TABLE comptes (
	id_compte       INT AUTO_INCREMENT PRIMARY KEY,
	id_etudiant     INT          NOT NULL UNIQUE,
	solde           DECIMAL(10,2) NOT NULL DEFAULT 0.00,
	solde_bloque    DECIMAL(10,2) NOT NULL DEFAULT 0.00,          -- solde gelé si carte bloquée
	updated_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
	FOREIGN KEY (id_etudiant) REFERENCES etudiants(id_etudiant)
) ENGINE=InnoDB;

-- ============================================================
-- TABLE : cartes  (carte RFID physique)
-- ============================================================
CREATE TABLE cartes (
	id_carte        INT AUTO_INCREMENT PRIMARY KEY,
	id_etudiant     INT          NOT NULL UNIQUE,
	uid_rfid        VARCHAR(50)  NOT NULL UNIQUE,
	date_emission   DATE         NOT NULL,
	date_expiration DATE,
	statut          ENUM('active','bloquee','perdue','expiree') NOT NULL DEFAULT 'active',
	motif_blocage   VARCHAR(255),
	bloquee_par     INT,                                          -- id_admin
	date_blocage    TIMESTAMP    NULL,
	created_by      INT,                                          -- id_admin créateur
	created_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
	FOREIGN KEY (id_etudiant)   REFERENCES etudiants(id_etudiant),
	FOREIGN KEY (bloquee_par)   REFERENCES admins(id_admin) ON DELETE SET NULL,
	FOREIGN KEY (created_by)    REFERENCES admins(id_admin) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ============================================================
-- TABLE : produits  (articles vendus à la buvette)
-- ============================================================
CREATE TABLE produits (
	id_produit      INT AUTO_INCREMENT PRIMARY KEY,
	nom             VARCHAR(150) NOT NULL,
	code_barre      VARCHAR(60)  UNIQUE,
	prix            DECIMAL(10,2) NOT NULL,
	categorie       VARCHAR(80),
	stock           INT          NOT NULL DEFAULT 0,
	image           VARCHAR(255),
	actif           TINYINT(1)   NOT NULL DEFAULT 1,
	created_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ============================================================
-- TABLE : config_resto  (prix du ticket au restaurant)
-- ============================================================
CREATE TABLE config_resto (
	id              INT AUTO_INCREMENT PRIMARY KEY,
	prix_ticket     DECIMAL(10,2) NOT NULL DEFAULT 3.50,
	description     VARCHAR(200),
	actif           TINYINT(1)   NOT NULL DEFAULT 1,
	updated_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
	updated_by      INT,
	FOREIGN KEY (updated_by) REFERENCES admins(id_admin) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ============================================================
-- TABLE : terminaux  (appareils Arduino / ESP32)
-- ============================================================
CREATE TABLE terminaux (
	id_terminal     INT AUTO_INCREMENT PRIMARY KEY,
	id_faculte      INT,
	nom             VARCHAR(100) NOT NULL,
	type            ENUM('RESTO','BUVETTE','RECHARGE') NOT NULL,
	localisation    VARCHAR(200),
	token_auth      VARCHAR(255) UNIQUE,                          -- SHA-256 hex of the terminal token (plaintext never stored)
	statut          ENUM('actif','inactif','erreur') NOT NULL DEFAULT 'actif',
	version_firmware VARCHAR(20),
	derniere_connexion TIMESTAMP NULL,
	created_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
	FOREIGN KEY (id_faculte) REFERENCES facultes(id_faculte) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ============================================================
-- TABLE : transferts  (toutes les transactions débit/crédit)
-- ============================================================
CREATE TABLE transferts (
	id_transfert    INT AUTO_INCREMENT PRIMARY KEY,
	id_compte       INT          NOT NULL,
	id_terminal     INT,
	montant         DECIMAL(10,2) NOT NULL,
	type            ENUM('PAIEMENT_RESTO','PAIEMENT_BUVETTE','RECHARGE','REMBOURSEMENT','BLOCAGE_DEBIT') NOT NULL,
	statut          ENUM('REUSSI','ECHOUE','EN_ATTENTE') NOT NULL DEFAULT 'REUSSI',
	description     TEXT,
	reference       VARCHAR(60)  UNIQUE,                          -- référence unique générée
	solde_avant     DECIMAL(10,2),
	solde_apres     DECIMAL(10,2),
	date_trans      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
	INDEX idx_compte (id_compte),
	INDEX idx_date   (date_trans),
	FOREIGN KEY (id_compte)   REFERENCES comptes(id_compte),
	FOREIGN KEY (id_terminal) REFERENCES terminaux(id_terminal) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ============================================================
-- TABLE : detail_transfert_buvette  (produits dans un achat buvette)
-- ============================================================
CREATE TABLE detail_transfert_buvette (
	id_detail       INT AUTO_INCREMENT PRIMARY KEY,
	id_transfert    INT          NOT NULL,
	id_produit      INT          NOT NULL,
	quantite        INT          NOT NULL DEFAULT 1,
	prix_unitaire   DECIMAL(10,2) NOT NULL,
	FOREIGN KEY (id_transfert) REFERENCES transferts(id_transfert),
	FOREIGN KEY (id_produit)   REFERENCES produits(id_produit)
) ENGINE=InnoDB;

-- ============================================================
-- TABLE : terminal_orders (commandes en attente pour paiement RFID)
-- Permet: scanner produits sur le web (buvette) puis payer via ESP32 (RFID)
-- ============================================================
CREATE TABLE terminal_orders (
	id_order       INT AUTO_INCREMENT PRIMARY KEY,
	id_terminal    INT          NOT NULL,
	mode           ENUM('RESTO','BUVETTE') NOT NULL,
	statut         ENUM('PENDING','IN_PROGRESS','PAYE','ECHEC','EXPIRE','ANNULE') NOT NULL DEFAULT 'PENDING',
	montant        DECIMAL(10,2) NOT NULL,
	items_json     TEXT,                          -- JSON: [{id_produit, quantite}]
	message_erreur VARCHAR(255),
	id_transfert   INT,
	reference      VARCHAR(60),
	created_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
	updated_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
	expires_at     TIMESTAMP    NULL,
	created_by_admin INT        NULL,             -- admin (caissier) qui a créé la commande
	FOREIGN KEY (id_terminal) REFERENCES terminaux(id_terminal) ON DELETE CASCADE,
	FOREIGN KEY (id_transfert) REFERENCES transferts(id_transfert) ON DELETE SET NULL,
	FOREIGN KEY (created_by_admin) REFERENCES admins(id_admin) ON DELETE SET NULL,
	INDEX idx_terminal_orders_pending (id_terminal, statut, created_at)
) ENGINE=InnoDB;

-- ============================================================
-- TABLE : recharges  (historique des rechargements)
-- ============================================================
CREATE TABLE recharges (
	id_recharge     INT AUTO_INCREMENT PRIMARY KEY,
	id_compte       INT          NOT NULL,
	id_admin        INT,                                          -- admin qui a effectué la recharge
	montant         DECIMAL(10,2) NOT NULL,
	methode         ENUM('ESPECES','CARTE_BANCAIRE','VIREMENT','AUTRE') NOT NULL DEFAULT 'ESPECES',
	reference_paiement VARCHAR(100),
	note            TEXT,
	statut          ENUM('REUSSI','ECHOUE') NOT NULL DEFAULT 'REUSSI',
	date_recharge   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
	FOREIGN KEY (id_compte) REFERENCES comptes(id_compte),
	FOREIGN KEY (id_admin)  REFERENCES admins(id_admin) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ============================================================
-- TABLE : code_appareil  (messages Arduino / ESP32 en temps réel)
-- ============================================================
CREATE TABLE code_appareil (
	id_message      INT AUTO_INCREMENT PRIMARY KEY,
	id_terminal     INT,
	uid_terminal    VARCHAR(50),                                  -- si terminal non enregistré
	type_message    ENUM(
		'INFO','SUCCESS','WARNING','ERROR','ERREUR',
		'SCAN','PANIER','RFID',
		'PAIEMENT','RESPONSE',
		'USER','RESTO','SOLDE',
		'SYSTEME','CONNEXION'
	) NOT NULL DEFAULT 'INFO',
	message         TEXT         NOT NULL,
	donnees_json    JSON,                                         -- données brutes de l'appareil
	uid_carte       VARCHAR(50),                                  -- carte scannée si pertinent
	date_message    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
	INDEX idx_terminal (id_terminal),
	INDEX idx_date     (date_message),
	FOREIGN KEY (id_terminal) REFERENCES terminaux(id_terminal) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ============================================================
-- TABLE : logs_securite  (audit de toutes les actions sensibles)
-- ============================================================
CREATE TABLE logs_securite (
	id_log          INT AUTO_INCREMENT PRIMARY KEY,
	id_user         INT,
	type_user       ENUM('admin','etudiant','terminal') NOT NULL DEFAULT 'admin',
	action          VARCHAR(100) NOT NULL,
	details         TEXT,
	ip_address      VARCHAR(45),
	statut          ENUM('SUCCES','ECHEC','ALERTE') NOT NULL DEFAULT 'SUCCES',
	date_action     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
	INDEX idx_user   (id_user),
	INDEX idx_date   (date_action)
) ENGINE=InnoDB;

-- ============================================================
-- DONNÉES INITIALES
-- ============================================================

-- Facultés de démonstration
INSERT INTO facultes (nom, code, description) VALUES
('Faculté des Sciences et Techniques',       'FST',  'Informatique, Mathématiques, Physique'),
('Faculté des Lettres et Sciences Humaines', 'FLSH', 'Lettres, Histoire, Philosophie'),
('Faculté des Sciences Économiques',         'FSE',  'Économie, Gestion, Commerce'),
('Institut Supérieur de Technologie',        'IST',  'Génie Civil, Électronique, Mécanique');

-- Admins: none seeded. Create them with: php scripts/create_admin.php

-- Config restaurant par défaut
INSERT INTO config_resto (prix_ticket, description) VALUES
(3.50, 'Ticket repas restaurant universitaire');

-- Terminals: none seeded. Create them with: php scripts/create_terminal.php

-- Produit buvette (TEST)
-- Code-barres fourni: 3700104455153
INSERT INTO produits (nom, code_barre, prix, categorie, stock) VALUES
('Produit Test', '3700104455153', 1.00, 'Test', 100);

-- Étudiant (TEST)  (mot de passe : Etudiant@1234)
INSERT INTO etudiants (id_faculte, matricule, nom, prenom, email, password_hash) VALUES
(1, 'TEST/RFID/0001', 'Test', 'RFID', 'test.rfid@etudiant.tn', SHA2('Etudiant@1234', 256));

-- Compte de l'étudiant (solde initial)
INSERT INTO comptes (id_etudiant, solde) VALUES (1, 20.00);

-- Carte RFID (TEST)
-- UID fourni:
--   hex:  9FF79DC2
--   :  :  9F:F7:9D:C2
INSERT INTO cartes (id_etudiant, uid_rfid, date_emission, date_expiration, created_by) VALUES
(1, '9F:F7:9D:C2', CURDATE(), DATE_ADD(CURDATE(), INTERVAL 1 YEAR), NULL);