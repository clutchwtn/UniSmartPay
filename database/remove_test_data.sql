-- Removes the development test data seeded by unismart_pay_schema.sql:
-- the test student TEST/RFID/0001 (account and card) and the test product.
-- Run once on a new production database, before real use:
--   mysql unismart_pay < database/remove_test_data.sql

DELETE FROM cartes  WHERE id_etudiant IN (SELECT id_etudiant FROM etudiants WHERE matricule = 'TEST/RFID/0001');
DELETE FROM comptes WHERE id_etudiant IN (SELECT id_etudiant FROM etudiants WHERE matricule = 'TEST/RFID/0001');
DELETE FROM etudiants WHERE matricule = 'TEST/RFID/0001';
DELETE FROM produits  WHERE code_barre = '3700104455153';
