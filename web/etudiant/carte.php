<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/functions.php';
require_student_login();

$pdo = db();
$etudiantId = (int)$_SESSION['etudiant_id'];

$success = '';
$error = '';

// "Block my card": a student can block their own card (lost or stolen) at any
// time. Only an admin can unblock it, so a thief with the student's password
// cannot turn the card back on.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  require_csrf_token((string)($_POST['csrf_token'] ?? ''));

  if ((string)($_POST['action'] ?? '') === 'block_card') {
    $stmt = $pdo->prepare(
      'UPDATE cartes
       SET statut = "bloquee", motif_blocage = :motif, date_blocage = NOW(), bloquee_par = NULL
       WHERE id_etudiant = :id AND statut = "active"'
    );
    $stmt->execute([':motif' => 'Bloquée par l\'étudiant', ':id' => $etudiantId]);

    if ($stmt->rowCount() === 1) {
      $success = 'Votre carte est bloquée. Aucun paiement ne sera accepté. Contactez l\'administration pour la débloquer.';
      log_security($etudiantId, 'etudiant', 'BLOCK_CARD', 'Carte bloquée par l\'étudiant', 'SUCCES');
    } else {
      $error = 'Aucune carte active à bloquer.';
      log_security($etudiantId, 'etudiant', 'BLOCK_CARD', 'Echec: aucune carte active', 'ECHEC');
    }
  }
}

$stmt = $pdo->prepare(
  'SELECT e.matricule, e.nom, e.prenom, e.email,
      ca.uid_rfid, ca.statut, ca.date_emission, ca.date_expiration, ca.motif_blocage
     FROM etudiants e
     LEFT JOIN cartes ca ON ca.id_etudiant = e.id_etudiant
     WHERE e.id_etudiant = :id
     LIMIT 1'
);
$stmt->execute([':id' => $etudiantId]);
$card = $stmt->fetch();

require_once __DIR__ . '/../includes/header.php';
?>

<div class="topbar">
  <div class="brand"><i class="fa-solid fa-bolt-lightning"></i><?php echo htmlspecialchars(APP_NAME); ?></div>
  <div class="topbar-right">
    <a class="btn btn-sm" href="<?php echo htmlspecialchars(BASE_URL); ?>/etudiant/dashboard.php">
      <i class="fa-solid fa-arrow-left"></i> Retour
    </a>
    <a class="btn btn-sm" href="<?php echo htmlspecialchars(BASE_URL); ?>/etudiant/logout.php">
      <i class="fa-solid fa-arrow-right-from-bracket"></i> Déconnexion
    </a>
  </div>
</div>

<div class="container">

  <div class="page-header">
    <h1 class="h1">Ma carte RFID</h1>
  </div>

  <?php if ($success): ?>
    <div class="alert alert-success" style="margin-bottom:12px;"><?php echo htmlspecialchars($success); ?></div>
  <?php endif; ?>
  <?php if ($error): ?>
    <div class="alert alert-danger" style="margin-bottom:12px;"><?php echo htmlspecialchars($error); ?></div>
  <?php endif; ?>

  <div class="grid">

    <!-- IDENTITÉ -->
    <div class="card col-6">
      <div class="card-title"><i class="fa-solid fa-user"></i> Identité</div>
      <div class="info-row">
        <span class="key">Nom complet</span>
        <span class="val"><?php echo htmlspecialchars((string)$card['prenom'] . ' ' . (string)$card['nom']); ?></span>
      </div>
      <div class="info-row">
        <span class="key">Matricule</span>
        <span class="val" style="font-family:monospace;"><?php echo htmlspecialchars((string)$card['matricule']); ?></span>
      </div>
      <?php if (!empty($card['email'])): ?>
      <div class="info-row">
        <span class="key">Email</span>
        <span class="val" style="font-size:13px;"><?php echo htmlspecialchars((string)$card['email']); ?></span>
      </div>
      <?php endif; ?>
    </div>

    <!-- CARTE -->
    <div class="card col-6">
      <div class="card-title"><i class="fa-solid fa-id-card"></i> Carte</div>

      <?php
      $uid    = (string)($card['uid_rfid'] ?? '');
      $statut = strtoupper((string)($card['statut'] ?? ''));
      ?>

      <div class="info-row">
        <span class="key">UID</span>
        <span class="val" style="font-family:monospace; letter-spacing:0.05em;">
          <?php echo htmlspecialchars($uid ? mask_uid($uid) : '—'); ?>
        </span>
      </div>

      <div class="info-row">
        <span class="key">Statut</span>
        <span class="val">
          <?php
          $badgeClass = match ($statut) {
              'ACTIVE', 'ACTIF' => 'badge-success',
              'BLOQUE', 'BLOQUEE', 'INACTIVE' => 'badge-danger',
              default => 'badge-muted',
          };
          echo '<span class="badge ' . $badgeClass . '">' . htmlspecialchars($statut ?: 'Non attribuée') . '</span>';
          ?>
        </span>
      </div>

      <div class="info-row">
        <span class="key">Date d'émission</span>
        <span class="val"><?php echo htmlspecialchars((string)($card['date_emission'] ?? '—')); ?></span>
      </div>

      <div class="info-row">
        <span class="key">Date d'expiration</span>
        <span class="val"><?php echo htmlspecialchars((string)($card['date_expiration'] ?? '—')); ?></span>
      </div>

      <?php if (!empty($card['motif_blocage'])): ?>
        <div class="alert alert-warning" style="margin-top:16px;">
          <i class="fa-solid fa-lock"></i>
          <div>
            <strong>Motif de blocage&nbsp;:</strong><br>
            <?php echo htmlspecialchars((string)$card['motif_blocage']); ?>
          </div>
        </div>
      <?php endif; ?>

      <?php if ($statut === 'ACTIVE'): ?>
        <form method="post" action="" style="margin-top:16px;"
              onsubmit="return confirm('Bloquer votre carte ? Seule l\'administration pourra la débloquer.');">
          <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">
          <input type="hidden" name="action" value="block_card">
          <button class="btn btn-danger" type="submit"><i class="fa-solid fa-lock"></i> Bloquer ma carte</button>
          <div class="text-muted" style="margin-top:6px;font-size:12px;">
            Carte perdue ou volée&nbsp;? Bloquez-la tout de suite. Seule l'administration peut la débloquer.
          </div>
        </form>
      <?php elseif ($statut === 'BLOQUEE'): ?>
        <div class="alert" style="margin-top:16px;">
          <i class="fa-solid fa-circle-info"></i>
          Carte bloquée&nbsp;: aucun paiement n'est accepté. Contactez l'administration pour la débloquer.
        </div>
      <?php endif; ?>

      <?php if (!$uid): ?>
        <div class="alert" style="margin-top:16px;">
          <i class="fa-solid fa-circle-info"></i>
          Aucune carte RFID attribuée. Contactez l'administration.
        </div>
      <?php endif; ?>
    </div>

  </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
