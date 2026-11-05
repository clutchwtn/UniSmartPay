<?php

declare(strict_types=1);

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/includes/header.php';
?>
<div class="topbar">
  <div class="brand"><?php echo htmlspecialchars(APP_NAME); ?></div>
  <span class="badge badge-blue"><i class="fa-solid fa-wifi"></i> RFID + Code-barres</span>
</div>

<div class="container">

  <div style="max-width:580px; margin-bottom:32px;">
    <h1 class="section-title">Bienvenue</h1>
    <p class="section-sub">Système de paiement universitaire intelligent — restaurant & buvette.</p>
  </div>

  <div class="grid">

    <!-- Espaces -->
    <div class="card col-6">
      <div class="h2">Accès</div>
      <div style="display:flex; flex-direction:column; gap:10px;">
        <a class="btn btn-primary" href="<?php echo htmlspecialchars(BASE_URL); ?>/etudiant/login.php" style="justify-content:flex-start; padding:14px 18px;">
          <span style="width:36px;height:36px;background:rgba(255,255,255,0.1);border-radius:8px;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
            <i class="fa-solid fa-user-graduate"></i>
          </span>
          <div>
            <div style="font-weight:600;font-size:14px;">Espace étudiant</div>
            <div style="font-size:12px;opacity:0.75;margin-top:1px;">Solde, paiements, carte RFID</div>
          </div>
        </a>
        <a class="btn" href="<?php echo htmlspecialchars(BASE_URL); ?>/admin/login.php" style="justify-content:flex-start; padding:14px 18px;">
          <span style="width:36px;height:36px;background:rgba(255,255,255,0.08);border-radius:8px;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
            <i class="fa-solid fa-shield-halved"></i>
          </span>
          <div>
            <div style="font-weight:600;font-size:14px;">Espace admin</div>
            <div style="font-size:12px;color:var(--muted);margin-top:1px;">Gestion, terminaux, rapports</div>
          </div>
        </a>
      </div>
      <div class="demo-hint" style="margin-top:20px;">
        <div><b>Étudiant:</b> 2024/FST/0001 &nbsp;/&nbsp; Etudiant@1234</div>
        <div style="margin-top:3px;"><b>Admin:</b> admin@unismart.tn &nbsp;/&nbsp; Admin@1234</div>
      </div>
    </div>

    <!-- Modes terminal -->
    <div class="card col-6">
      <div class="h2">Terminaux</div>
      <div style="display:flex; flex-direction:column; gap:10px;">
        <a class="btn btn-success" href="<?php echo htmlspecialchars(BASE_URL); ?>/terminal/resto.php" style="justify-content:flex-start; padding:14px 18px;">
          <span style="width:36px;height:36px;background:rgba(255,255,255,0.1);border-radius:8px;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
            <i class="fa-solid fa-utensils"></i>
          </span>
          <div>
            <div style="font-weight:600;font-size:14px;">Mode RESTO</div>
            <div style="font-size:12px;opacity:0.75;margin-top:1px;">Débit ticket via carte RFID</div>
          </div>
        </a>
        <a class="btn btn-primary" href="<?php echo htmlspecialchars(BASE_URL); ?>/terminal/buvette.php" style="justify-content:flex-start; padding:14px 18px;">
          <span style="width:36px;height:36px;background:rgba(255,255,255,0.1);border-radius:8px;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
            <i class="fa-solid fa-barcode"></i>
          </span>
          <div>
            <div style="font-weight:600;font-size:14px;">Mode BUVETTE</div>
            <div style="font-size:12px;opacity:0.75;margin-top:1px;">Scan produits + paiement RFID</div>
          </div>
        </a>
      </div>
      <p class="text-muted" style="margin-top:16px;">
        Si la carte RFID n'est pas attribuée, l'admin doit l'assigner à l'étudiant.
      </p>
    </div>

  </div>
</div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
