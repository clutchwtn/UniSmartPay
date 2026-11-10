<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/functions.php';
require_admin_login();

$pdo = db();
$adminId = (int)$_SESSION['admin_id'];

$success = '';
$error = '';

$stmt = $pdo->prepare('SELECT id_admin, id_faculte, nom, prenom, email, role FROM admins WHERE id_admin = :id LIMIT 1');
$stmt->execute([':id' => $adminId]);
$admin = $stmt->fetch();

if (!$admin) {
    session_destroy();
    header('Location: ' . BASE_URL . '/admin/login.php');
    exit();
}

$adminRole = (string)$admin['role'];
$adminFaculteId = $admin['id_faculte'] !== null ? (int)$admin['id_faculte'] : null;

function admin_faculte_constraint_sql(string $tableAlias, ?int $adminFaculteId, string $adminRole): array {
    if ($adminRole === 'super_admin') {
        return ['', []];
    }
    if ($adminFaculteId === null) {
        return [' AND 1=0 ', []];
    }
    return [sprintf(' AND %s.id_faculte = :admin_faculte_id ', $tableAlias), [':admin_faculte_id' => $adminFaculteId]];
}

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = (string)($_POST['csrf_token'] ?? '');
    require_csrf_token($csrf);

    $action = (string)($_POST['action'] ?? '');

    try {
        if ($action === 'add_student') {
            $idFaculte = $adminRole === 'super_admin'
                ? (int)($_POST['id_faculte'] ?? 0)
                : (int)($adminFaculteId ?? 0);
            $matricule = trim((string)($_POST['matricule'] ?? ''));
            $nom = trim((string)($_POST['nom'] ?? ''));
            $prenom = trim((string)($_POST['prenom'] ?? ''));
            $email = trim((string)($_POST['email'] ?? ''));
            $password = (string)($_POST['password'] ?? '');

            if ($idFaculte <= 0 || $matricule === '' || $nom === '' || $prenom === '' || $email === '' || $password === '') {
                throw new UserFacingException('Veuillez remplir tous les champs (faculté incluse).');
            }
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new UserFacingException('Email invalide.');
            }

            $pdo->beginTransaction();

            $stmt = $pdo->prepare(
                'INSERT INTO etudiants (id_faculte, matricule, nom, prenom, email, password_hash) VALUES (:id_faculte, :matricule, :nom, :prenom, :email, :password_hash)'
            );
            $stmt->execute([
                ':id_faculte' => $idFaculte,
                ':matricule' => $matricule,
                ':nom' => $nom,
                ':prenom' => $prenom,
                ':email' => $email,
                ':password_hash' => password_hash($password, PASSWORD_DEFAULT),
            ]);
            $newStudentId = (int)$pdo->lastInsertId();

            $pdo->prepare('INSERT INTO comptes (id_etudiant, solde) VALUES (:id_etudiant, 0.00)')
                ->execute([':id_etudiant' => $newStudentId]);

            $pdo->commit();

            $success = 'Étudiant ajouté avec succès.';
            log_security($adminId, 'admin', 'CREATE_STUDENT', 'Ajout étudiant matricule=' . $matricule . ' email=' . $email, 'SUCCES');
        } elseif ($action === 'assign_card') {
            $matricule = trim((string)($_POST['matricule'] ?? ''));
            $uid = strtoupper(trim((string)($_POST['uid_rfid'] ?? '')));
            if ($matricule === '' || $uid === '') {
                throw new UserFacingException('Matricule et UID requis.');
            }

            [$constraintSql, $constraintParams] = admin_faculte_constraint_sql('e', $adminFaculteId, $adminRole);
            $stmt = $pdo->prepare(
                'SELECT e.id_etudiant FROM etudiants e WHERE e.matricule = :matricule ' . $constraintSql . ' LIMIT 1'
            );
            $stmt->execute(array_merge([':matricule' => $matricule], $constraintParams));
            $etu = $stmt->fetch();
            if (!$etu) {
                throw new UserFacingException('Étudiant introuvable (ou hors de votre faculté).');
            }
            $etudiantId = (int)$etu['id_etudiant'];

            // UID already used?
            $stmt = $pdo->prepare('SELECT id_etudiant FROM cartes WHERE uid_rfid = :uid AND id_etudiant <> :id_etudiant LIMIT 1');
            $stmt->execute([':uid' => $uid, ':id_etudiant' => $etudiantId]);
            if ($stmt->fetch()) {
                throw new UserFacingException('Cet UID est déjà assigné à un autre étudiant.');
            }

            // Upsert card for student
            $stmt = $pdo->prepare('SELECT id_carte FROM cartes WHERE id_etudiant = :id_etudiant LIMIT 1');
            $stmt->execute([':id_etudiant' => $etudiantId]);
            $card = $stmt->fetch();

            if ($card) {
                $pdo->prepare(
                    'UPDATE cartes
                     SET uid_rfid = :uid, statut = "active", motif_blocage = NULL, date_blocage = NULL, bloquee_par = NULL,
                         date_emission = CURDATE(), date_expiration = DATE_ADD(CURDATE(), INTERVAL 1 YEAR), created_by = :admin_id
                     WHERE id_etudiant = :id_etudiant'
                )->execute([':uid' => $uid, ':admin_id' => $adminId, ':id_etudiant' => $etudiantId]);
            } else {
                $pdo->prepare(
                    'INSERT INTO cartes (id_etudiant, uid_rfid, date_emission, date_expiration, created_by)
                     VALUES (:id_etudiant, :uid, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 1 YEAR), :admin_id)'
                )->execute([':id_etudiant' => $etudiantId, ':uid' => $uid, ':admin_id' => $adminId]);
            }

            $success = 'Carte assignée avec succès.';
            log_security($adminId, 'admin', 'ASSIGN_CARD', 'Assignation carte matricule=' . $matricule . ' uid=' . $uid, 'SUCCES');
        } elseif ($action === 'recharge') {
            $matricule = trim((string)($_POST['matricule'] ?? ''));
            $montant = money_parse($_POST['montant'] ?? null) ?? 0; // millimes
            $methode = (string)($_POST['methode'] ?? 'ESPECES');
            $note = trim((string)($_POST['note'] ?? ''));

            if ($matricule === '' || $montant <= 0) {
                throw new UserFacingException('Matricule et montant (> 0) requis.');
            }
            if (!in_array($methode, ['ESPECES', 'CARTE_BANCAIRE', 'VIREMENT', 'AUTRE'], true)) {
                $methode = 'ESPECES';
            }

            [$constraintSql, $constraintParams] = admin_faculte_constraint_sql('e', $adminFaculteId, $adminRole);
            $stmt = $pdo->prepare(
                'SELECT e.id_etudiant, c.id_compte
                 FROM etudiants e
                 JOIN comptes c ON c.id_etudiant = e.id_etudiant
                 WHERE e.matricule = :matricule ' . $constraintSql . '
                 LIMIT 1'
            );
            $stmt->execute(array_merge([':matricule' => $matricule], $constraintParams));
            $row = $stmt->fetch();
            if (!$row) {
                throw new UserFacingException('Étudiant introuvable (ou hors de votre faculté).');
            }
            $idCompte = (int)$row['id_compte'];

            $reference = generate_reference();

            $pdo->beginTransaction();

            $stmt = $pdo->prepare('SELECT solde FROM comptes WHERE id_compte = :id_compte FOR UPDATE');
            $stmt->execute([':id_compte' => $idCompte]);
            $acc = $stmt->fetch();
            if (!$acc) {
                throw new UserFacingException('Compte introuvable.');
            }
            $soldeAvant = money_to_millimes($acc['solde']);
            $soldeApres = $soldeAvant + $montant;

            $pdo->prepare('UPDATE comptes SET solde = solde + CAST(:credit AS DECIMAL(10,2)) WHERE id_compte = :id_compte')
                ->execute([':credit' => millimes_to_db($montant), ':id_compte' => $idCompte]);

            $pdo->prepare(
                'INSERT INTO transferts (id_compte, id_terminal, montant, type, statut, description, reference, solde_avant, solde_apres)
                 VALUES (:id_compte, NULL, :montant, "RECHARGE", "REUSSI", :description, :reference, :solde_avant, :solde_apres)'
            )->execute([
                ':id_compte' => $idCompte,
                ':montant' => millimes_to_db($montant),
                ':description' => 'Recharge par admin',
                ':reference' => $reference,
                ':solde_avant' => millimes_to_db($soldeAvant),
                ':solde_apres' => millimes_to_db($soldeApres),
            ]);

            $pdo->prepare(
                'INSERT INTO recharges (id_compte, id_admin, montant, methode, note, statut)
                 VALUES (:id_compte, :id_admin, :montant, :methode, :note, "REUSSI")'
            )->execute([
                ':id_compte' => $idCompte,
                ':id_admin' => $adminId,
                ':montant' => millimes_to_db($montant),
                ':methode' => $methode,
                ':note' => $note !== '' ? $note : null,
            ]);

            $pdo->commit();

            $success = 'Recharge réussie. Nouveau solde: TND ' . number_format(millimes_to_dt($soldeApres), 2, '.', ' ');
            log_security($adminId, 'admin', 'RECHARGE', 'Recharge matricule=' . $matricule . ' montant=' . millimes_to_db($montant) . ' ref=' . $reference, 'SUCCES');
        } elseif ($action === 'add_product') {
            $nom = trim((string)($_POST['nom'] ?? ''));
            $codeBarre = trim((string)($_POST['code_barre'] ?? ''));
            $prix = money_parse($_POST['prix'] ?? null) ?? 0; // millimes
            $categorie = trim((string)($_POST['categorie'] ?? ''));
            $stock = (int)($_POST['stock'] ?? 0);

          if ($nom === '' || $prix <= 0) {
            throw new UserFacingException('Nom et prix (> 0) requis.');
          }

          // For the scan flow, the barcode must exist in DB.
          if ($codeBarre === '') {
            throw new UserFacingException('Code-barres requis (pour le scan).');
          }

          // Normalize barcode (remove spaces/hyphens and keep alnum only)
          $codeBarre = strtoupper(preg_replace('/[^0-9A-Za-z]/', '', $codeBarre) ?? '');
          if ($codeBarre === '' || strlen($codeBarre) > 60) {
            throw new UserFacingException('Code-barres invalide.');
            }

          try {
            $pdo->prepare(
              'INSERT INTO produits (nom, code_barre, prix, categorie, stock, actif)
               VALUES (:nom, :code_barre, :prix, :categorie, :stock, 1)'
            )->execute([
              ':nom' => $nom,
              ':code_barre' => $codeBarre,
              ':prix' => millimes_to_db($prix),
              ':categorie' => $categorie !== '' ? $categorie : null,
              ':stock' => $stock,
            ]);
          } catch (PDOException $e) {
            // Duplicate barcode (unique index)
            if (($e->getCode() === '23000') || str_contains($e->getMessage(), 'Duplicate')) {
              throw new UserFacingException('Ce code-barres existe déjà.');
            }
            throw $e;
          }

            $success = 'Produit ajouté.';
            log_security($adminId, 'admin', 'ADD_PRODUCT', 'Ajout produit=' . $nom . ' code=' . $codeBarre, 'SUCCES');
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error = $e instanceof UserFacingException ? $e->getMessage() : 'Erreur serveur.';
        log_security($adminId, 'admin', 'ADMIN_ACTION', 'Erreur action=' . ($action ?: 'unknown') . ' msg=' . $e->getMessage(), 'ECHEC');
    }
}

// Load faculties (for super admin)
$facultes = $pdo->query('SELECT id_faculte, nom, code FROM facultes ORDER BY nom')->fetchAll();

// Stats
[$constraintSql, $constraintParams] = admin_faculte_constraint_sql('e', $adminFaculteId, $adminRole);
$stmt = $pdo->prepare('SELECT COUNT(*) AS cnt FROM etudiants e WHERE 1=1 ' . $constraintSql);
$stmt->execute($constraintParams);
$nbEtudiants = (int)($stmt->fetch()['cnt'] ?? 0);

$stmt = $pdo->prepare(
    'SELECT COALESCE(SUM(c.solde), 0) AS total
     FROM comptes c
     JOIN etudiants e ON e.id_etudiant = c.id_etudiant
     WHERE 1=1 ' . $constraintSql
);
$stmt->execute($constraintParams);
$totalSolde = (float)($stmt->fetch()['total'] ?? 0);

$nbProduits = (int)($pdo->query('SELECT COUNT(*) AS cnt FROM produits')->fetch()['cnt'] ?? 0);

// Latest students
$stmt = $pdo->prepare(
    'SELECT e.id_etudiant, e.matricule, e.nom, e.prenom, e.email, f.code AS fac_code, c.solde,
            ca.uid_rfid, ca.statut AS carte_statut
     FROM etudiants e
     JOIN facultes f ON f.id_faculte = e.id_faculte
     JOIN comptes c ON c.id_etudiant = e.id_etudiant
     LEFT JOIN cartes ca ON ca.id_etudiant = e.id_etudiant
     WHERE 1=1 ' . $constraintSql . '
     ORDER BY e.created_at DESC
     LIMIT 25'
);
$stmt->execute($constraintParams);
$etudiants = $stmt->fetchAll();

// Latest recharges
$stmt = $pdo->prepare(
    'SELECT r.montant, r.methode, r.date_recharge, e.matricule, e.nom, e.prenom
     FROM recharges r
     JOIN comptes c ON c.id_compte = r.id_compte
     JOIN etudiants e ON e.id_etudiant = c.id_etudiant
     WHERE 1=1 ' . $constraintSql . '
     ORDER BY r.date_recharge DESC
     LIMIT 15'
);
$stmt->execute($constraintParams);
$recharges = $stmt->fetchAll();

// Latest products
$produits = $pdo->query('SELECT nom, code_barre, prix, stock, actif FROM produits ORDER BY created_at DESC LIMIT 20')->fetchAll();

require_once __DIR__ . '/../includes/header.php';
?>
<div class="topbar">
  <div class="brand"><?php echo htmlspecialchars(APP_NAME); ?></div>
  <div style="display:flex;align-items:center;gap:12px;">
    <div class="badge"><i class="fa-solid fa-user-shield"></i> <?php echo htmlspecialchars((string)$admin['prenom'] . ' ' . (string)$admin['nom']); ?> • <?php echo htmlspecialchars((string)$admin['role']); ?></div>
    <a class="btn" href="<?php echo htmlspecialchars(BASE_URL); ?>/admin/logout.php"><i class="fa-solid fa-arrow-right-from-bracket"></i> Déconnexion</a>
  </div>
</div>

<div class="container">
  <h1 class="h1">Dashboard admin</h1>

  <?php if ($success): ?>
    <div class="alert alert-success" style="margin-bottom:12px;"><?php echo htmlspecialchars($success); ?></div>
  <?php endif; ?>
  <?php if ($error): ?>
    <div class="alert alert-danger" style="margin-bottom:12px;"><?php echo htmlspecialchars($error); ?></div>
  <?php endif; ?>

  <div class="grid" style="margin-top:16px;">
    <div class="card col-4">
      <div class="h2">Étudiants</div>
      <div style="font-size:28px;font-weight:800;"><?php echo (int)$nbEtudiants; ?></div>
    </div>
    <div class="card col-4">
      <div class="h2">Total soldes</div>
      <div style="font-size:28px;font-weight:800;">TND <?php echo number_format($totalSolde, 2, '.', ' '); ?></div>
    </div>
    <div class="card col-4">
      <div class="h2">Produits</div>
      <div style="font-size:28px;font-weight:800;"><?php echo (int)$nbProduits; ?></div>
    </div>

    <div class="card col-6">
      <div class="h2">Ajouter étudiant</div>
      <form method="post" action="">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">
        <input type="hidden" name="action" value="add_student">

        <?php if ($adminRole === 'super_admin'): ?>
          <div style="margin-bottom:10px;">
            <label class="h2" style="display:block;margin-bottom:6px;">Faculté</label>
            <select class="input" name="id_faculte" required>
              <option value="">-- choisir --</option>
              <?php foreach ($facultes as $f): ?>
                <option value="<?php echo (int)$f['id_faculte']; ?>"><?php echo htmlspecialchars((string)$f['nom'] . ' (' . (string)$f['code'] . ')'); ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        <?php else: ?>
          <div style="margin-bottom:10px;color:var(--muted);font-size:12px;">Faculté: <?php echo htmlspecialchars((string)$adminFaculteId); ?> (fixe)</div>
        <?php endif; ?>

        <div class="grid" style="gap:10px;">
          <div class="col-6">
            <label class="h2" style="display:block;margin-bottom:6px;">Matricule</label>
            <input class="input" name="matricule" placeholder="2024/FST/0003" required />
          </div>
          <div class="col-6">
            <label class="h2" style="display:block;margin-bottom:6px;">Email</label>
            <input class="input" name="email" placeholder="etu@example.com" required />
          </div>
          <div class="col-6">
            <label class="h2" style="display:block;margin-bottom:6px;">Nom</label>
            <input class="input" name="nom" required />
          </div>
          <div class="col-6">
            <label class="h2" style="display:block;margin-bottom:6px;">Prénom</label>
            <input class="input" name="prenom" required />
          </div>
          <div class="col-12">
            <label class="h2" style="display:block;margin-bottom:6px;">Mot de passe</label>
            <input class="input" name="password" type="password" placeholder="Mot de passe" required />
          </div>
        </div>

        <div style="margin-top:12px;">
          <button class="btn btn-primary" type="submit"><i class="fa-solid fa-user-plus"></i> Ajouter</button>
        </div>
      </form>
    </div>

    <div class="card col-6">
      <div class="h2">Carte RFID + Recharge</div>

      <div class="card" style="margin-bottom:12px;">
        <div class="h2" style="margin-bottom:8px;">Assigner une carte</div>
        <form method="post" action="">
          <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">
          <input type="hidden" name="action" value="assign_card">

          <div class="grid" style="gap:10px;">
            <div class="col-6">
              <label class="h2" style="display:block;margin-bottom:6px;">Matricule</label>
              <input class="input" name="matricule" placeholder="2024/FST/0001" required />
            </div>
            <div class="col-6">
              <label class="h2" style="display:block;margin-bottom:6px;">UID RFID</label>
              <input class="input" name="uid_rfid" placeholder="AA:BB:CC:DD" required />
            </div>
          </div>
          <div style="margin-top:12px;">
            <button class="btn btn-success" type="submit"><i class="fa-solid fa-id-card"></i> Assigner</button>
          </div>
        </form>
      </div>

      <div class="card">
        <div class="h2" style="margin-bottom:8px;">Recharger un compte</div>
        <form method="post" action="">
          <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">
          <input type="hidden" name="action" value="recharge">

          <div class="grid" style="gap:10px;">
            <div class="col-6">
              <label class="h2" style="display:block;margin-bottom:6px;">Matricule</label>
              <input class="input" name="matricule" placeholder="2024/FST/0001" required />
            </div>
            <div class="col-6">
              <label class="h2" style="display:block;margin-bottom:6px;">Montant (TND)</label>
              <input class="input" name="montant" type="number" min="0.01" step="0.01" placeholder="10.00" required />
            </div>
            <div class="col-6">
              <label class="h2" style="display:block;margin-bottom:6px;">Méthode</label>
              <select class="input" name="methode">
                <option value="ESPECES">Espèces</option>
                <option value="CARTE_BANCAIRE">Carte bancaire</option>
                <option value="VIREMENT">Virement</option>
                <option value="AUTRE">Autre</option>
              </select>
            </div>
            <div class="col-6">
              <label class="h2" style="display:block;margin-bottom:6px;">Note (optionnel)</label>
              <input class="input" name="note" placeholder="Ex: Recharge guichet" />
            </div>
          </div>
          <div style="margin-top:12px;">
            <button class="btn btn-primary" type="submit"><i class="fa-solid fa-wallet"></i> Recharger</button>
          </div>
        </form>
      </div>
    </div>

    <div class="card col-6">
      <div class="h2">Ajouter produit</div>
      <form method="post" action="">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">
        <input type="hidden" name="action" value="add_product">

        <div class="grid" style="gap:10px;">
          <div class="col-12">
            <label class="h2" style="display:block;margin-bottom:6px;">Nom</label>
            <input class="input" name="nom" placeholder="Sandwich" required />
          </div>
          <div class="col-6">
            <label class="h2" style="display:block;margin-bottom:6px;">Code-barres (obligatoire pour scan)</label>
            <input class="input" name="code_barre" placeholder="3017620422003" required />
          </div>
          <div class="col-6">
            <label class="h2" style="display:block;margin-bottom:6px;">Prix (TND)</label>
            <input class="input" name="prix" type="number" min="0.01" step="0.01" placeholder="2.50" required />
          </div>
          <div class="col-6">
            <label class="h2" style="display:block;margin-bottom:6px;">Catégorie</label>
            <input class="input" name="categorie" placeholder="Boissons" />
          </div>
          <div class="col-6">
            <label class="h2" style="display:block;margin-bottom:6px;">Stock</label>
            <input class="input" name="stock" type="number" min="0" step="1" placeholder="50" />
          </div>
        </div>
        <div style="margin-top:12px;">
          <button class="btn btn-success" type="submit"><i class="fa-solid fa-cart-plus"></i> Ajouter</button>
        </div>
      </form>
    </div>

    <div class="card col-6">
      <div class="h2">Dernières recharges</div>
      <?php if (empty($recharges)): ?>
        <div style="color:var(--muted);">Aucune recharge.</div>
      <?php else: ?>
        <table class="table">
          <thead>
            <tr>
              <th>Date</th>
              <th>Matricule</th>
              <th>Étudiant</th>
              <th>Montant</th>
              <th>Méthode</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($recharges as $r): ?>
              <tr>
                <td><?php echo htmlspecialchars((string)$r['date_recharge']); ?></td>
                <td><?php echo htmlspecialchars((string)$r['matricule']); ?></td>
                <td><?php echo htmlspecialchars((string)$r['prenom'] . ' ' . (string)$r['nom']); ?></td>
                <td><?php echo number_format((float)$r['montant'], 2, '.', ' '); ?></td>
                <td><?php echo htmlspecialchars((string)$r['methode']); ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>

    <div class="card col-12">
      <div class="h2">Étudiants (dernier ajout)</div>
      <?php if (empty($etudiants)): ?>
        <div style="color:var(--muted);">Aucun étudiant.</div>
      <?php else: ?>
        <table class="table">
          <thead>
            <tr>
              <th>Matricule</th>
              <th>Nom</th>
              <th>Email</th>
              <th>Faculté</th>
              <th>Solde</th>
              <th>Carte</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($etudiants as $e): ?>
              <tr>
                <td><?php echo htmlspecialchars((string)$e['matricule']); ?></td>
                <td><?php echo htmlspecialchars((string)$e['prenom'] . ' ' . (string)$e['nom']); ?></td>
                <td><?php echo htmlspecialchars((string)$e['email']); ?></td>
                <td><?php echo htmlspecialchars((string)$e['fac_code']); ?></td>
                <td><?php echo number_format((float)$e['solde'], 2, '.', ' '); ?></td>
                <td>
                  <?php if (!empty($e['uid_rfid'])): ?>
                    <?php echo htmlspecialchars(mask_uid((string)$e['uid_rfid'])); ?> (<?php echo htmlspecialchars((string)$e['carte_statut']); ?>)
                  <?php else: ?>
                    <span style="color:var(--muted);">non attribuée</span>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>

    <div class="card col-12">
      <div class="h2">Produits (dernier ajout)</div>
      <?php if (empty($produits)): ?>
        <div style="color:var(--muted);">Aucun produit.</div>
      <?php else: ?>
        <table class="table">
          <thead>
            <tr>
              <th>Nom</th>
              <th>Code-barres</th>
              <th>Prix</th>
              <th>Stock</th>
              <th>Actif</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($produits as $p): ?>
              <tr>
                <td><?php echo htmlspecialchars((string)$p['nom']); ?></td>
                <td><?php echo htmlspecialchars((string)($p['code_barre'] ?? '')); ?></td>
                <td><?php echo number_format((float)$p['prix'], 2, '.', ' '); ?></td>
                <td><?php echo (int)$p['stock']; ?></td>
                <td><?php echo ((int)$p['actif'] === 1) ? 'Oui' : 'Non'; ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
