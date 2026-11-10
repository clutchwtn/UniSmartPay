<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/functions.php';
require_student_login();

$pdo = db();
$etudiantId = (int)$_SESSION['etudiant_id'];

$success = '';
$error = '';

$cfgStmt = $pdo->prepare('SELECT prix_ticket, description FROM config_resto WHERE actif = 1 ORDER BY updated_at DESC LIMIT 1');
$cfgStmt->execute();
$cfg = $cfgStmt->fetch();
$prixTicket = isset($cfg['prix_ticket']) ? money_to_millimes($cfg['prix_ticket']) : 0; // millimes
$descTicket = (string)($cfg['description'] ?? 'Ticket repas');

$accStmt = $pdo->prepare('SELECT id_compte, solde FROM comptes WHERE id_etudiant = :id LIMIT 1');
$accStmt->execute([':id' => $etudiantId]);
$acc = $accStmt->fetch();
$idCompte = (int)($acc['id_compte'] ?? 0);
$soldeActuel = isset($acc['solde']) ? money_to_millimes($acc['solde']) : 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $csrf = (string)($_POST['csrf_token'] ?? '');
  require_csrf_token($csrf);

  try {
    if ($prixTicket <= 0) {
      throw new UserFacingException('Configuration resto indisponible.');
    }
    if ($idCompte <= 0) {
      throw new UserFacingException('Compte introuvable.');
    }

    $reference = generate_reference();

    $pdo->beginTransaction();

    $lock = $pdo->prepare('SELECT solde FROM comptes WHERE id_compte = :id_compte FOR UPDATE');
    $lock->execute([':id_compte' => $idCompte]);
    $row = $lock->fetch();
    if (!$row) {
      throw new UserFacingException('Compte introuvable.');
    }
    $soldeAvant = money_to_millimes($row['solde']);
    if ($soldeAvant < $prixTicket) {
      throw new UserFacingException('Solde insuffisant.');
    }

    $soldeApres = $soldeAvant - $prixTicket;

    if (!account_debit($pdo, $idCompte, $prixTicket)) {
      throw new UserFacingException('Solde insuffisant.');
    }

    $pdo->prepare(
      'INSERT INTO transferts (id_compte, id_terminal, montant, type, statut, description, reference, solde_avant, solde_apres)
       VALUES (:id_compte, NULL, :montant, "PAIEMENT_RESTO", "REUSSI", :description, :reference, :solde_avant, :solde_apres)'
    )->execute([
      ':id_compte' => $idCompte,
      ':montant' => millimes_to_db($prixTicket),
      ':description' => $descTicket,
      ':reference' => $reference,
      ':solde_avant' => millimes_to_db($soldeAvant),
      ':solde_apres' => millimes_to_db($soldeApres),
    ]);

    $pdo->commit();

    $success = 'Paiement resto réussi. Référence: ' . $reference;
    $soldeActuel = $soldeApres;
    log_security($etudiantId, 'etudiant', 'PAIEMENT_RESTO', 'Ticket resto ref=' . $reference . ' montant=' . millimes_to_db($prixTicket), 'SUCCES');
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) {
      $pdo->rollBack();
    }
    $error = $e instanceof UserFacingException ? $e->getMessage() : 'Erreur serveur.';
    log_security($etudiantId, 'etudiant', 'PAIEMENT_RESTO', 'Echec ticket resto msg=' . $e->getMessage(), 'ECHEC');
  }
}

require_once __DIR__ . '/../includes/header.php';
?>
<div class="topbar">
  <div class="brand"><i class="fa-solid fa-bolt-lightning"></i><?php echo htmlspecialchars(APP_NAME); ?></div>
  <div class="topbar-right">
    <a class="btn btn-sm" href="<?php echo htmlspecialchars(BASE_URL); ?>/etudiant/dashboard.php"><i class="fa-solid fa-arrow-left"></i> Retour</a>
    <a class="btn btn-sm" href="<?php echo htmlspecialchars(BASE_URL); ?>/etudiant/logout.php"><i class="fa-solid fa-arrow-right-from-bracket"></i> Déconnexion</a>
  </div>
</div>

<div class="container">
  <div class="page-header">
    <h1 class="h1">Paiement restaurant</h1>
    <div class="text-muted" style="margin-top:6px;">Ticket repas fixe (mode RESTO)</div>
  </div>

  <?php if ($success): ?>
    <div class="alert alert-success" style="margin-bottom:12px;"><?php echo htmlspecialchars($success); ?></div>
  <?php endif; ?>
  <?php if ($error): ?>
    <div class="alert alert-danger" style="margin-bottom:12px;"><?php echo htmlspecialchars($error); ?></div>
  <?php endif; ?>

  <div class="grid">
    <div class="card col-6">
      <div class="card-title"><i class="fa-solid fa-receipt"></i> Ticket resto</div>
      <div style="font-size:32px;font-weight:800;margin-top:6px;">TND <?php echo number_format(millimes_to_dt($prixTicket), 2, '.', ' '); ?></div>
      <div style="margin-top:8px;color:var(--muted);"><?php echo htmlspecialchars($descTicket); ?></div>
      <div style="margin-top:12px;">
        <form method="post" action="">
          <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">
          <button class="btn btn-primary" type="submit"><i class="fa-solid fa-receipt"></i> Payer maintenant</button>
        </form>
      </div>
      <div style="margin-top:12px;color:var(--muted);font-size:12px;">Le paiement crée une ligne dans <b>transferts</b> (PAIEMENT_RESTO).</div>
    </div>

    <div class="card col-6">
      <div class="card-title"><i class="fa-solid fa-wallet"></i> Votre solde</div>
      <div style="font-size:32px;font-weight:800;margin-top:6px;">TND <?php echo number_format(millimes_to_dt($soldeActuel), 2, '.', ' '); ?></div>
      <div style="margin-top:12px;">
        <a class="btn" href="<?php echo htmlspecialchars(BASE_URL); ?>/etudiant/historique.php"><i class="fa-solid fa-clock-rotate-left"></i> Voir historique</a>
      </div>
    </div>
  </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
