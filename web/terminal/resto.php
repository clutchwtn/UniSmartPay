<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_admin_login();
require_once __DIR__ . '/../includes/header.php';

$terminalId = (int)($_GET['terminal_id'] ?? 1);
if ($terminalId <= 0) {
  $terminalId = 1;
}
?>
<div class="topbar">
  <div class="brand"><i class="fa-solid fa-bolt-lightning"></i><?php echo htmlspecialchars(APP_NAME); ?></div>
  <div class="topbar-right">
    <a class="btn btn-sm" href="<?php echo htmlspecialchars(BASE_URL); ?>/"><i class="fa-solid fa-house"></i> Accueil</a>
  </div>
</div>

<div class="container">
  <div class="page-header">
    <h1 class="h1">Mode RESTO (Terminal)</h1>
    <div class="section-sub">Passez la carte RFID sur le terminal pour débiter le ticket</div>
  </div>

  <div class="card">
    <div class="card-title"><i class="fa-solid fa-utensils"></i> Instructions</div>

    <div style="margin-top:16px;" class="alert alert-warning">
      Terminal ID: <b><?php echo (int)$terminalId; ?></b> • Les logs ci-dessous se mettent à jour automatiquement.
    </div>

    <div style="margin-top:8px;color:var(--muted);font-size:12px;">
      Ce mode est synchronisé automatiquement: en ouvrant cette page, le terminal passe en <b>RESTO</b>.
    </div>

    <div style="margin-top:12px;color:var(--muted);font-size:12px;">
      Si la carte est introuvable ou non active, l'admin doit l'assigner à l'étudiant.
    </div>
  </div>

  <div class="card" style="margin-top:16px;">
    <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;">
      <div class="card-title" style="margin:0;"><i class="fa-solid fa-terminal"></i> Serial Monitor (USB)</div>
      <div style="display:flex;gap:10px;flex-wrap:wrap;">
        <button id="btn_serial_connect" class="btn btn-primary" type="button"><i class="fa-solid fa-plug"></i> Connecter</button>
        <button id="btn_serial_disconnect" class="btn" type="button"><i class="fa-solid fa-ban"></i> Déconnecter</button>
      </div>
    </div>
    <div style="margin-top:8px;color:var(--muted);font-size:12px;">Lit le port série USB (COM) comme Arduino IDE. Nécessite Chrome/Edge desktop + HTTPS/localhost. Important: fermez Arduino IDE (Serial Monitor/Plotter) avant de connecter.</div>
    <pre id="serial_monitor" style="margin-top:10px;background:var(--bg);border:1px solid var(--border);border-radius:12px;padding:12px;max-height:260px;overflow:auto;white-space:pre-wrap;"></pre>
    <div id="serial_status" style="margin-top:8px;color:var(--muted);font-size:12px;"></div>
  </div>

  
</div>

<!-- Logs du terminal (BD) supprimés sur demande -->

<script src="/UniSmartPay/assets/js/web_serial_monitor.js"></script>
<script>
  const csrfToken = <?php echo json_encode(csrf_token(), JSON_UNESCAPED_SLASHES); ?>;
  const terminalId = <?php echo (int)$terminalId; ?>;

  // Set terminal mode dynamically (RESTO)
  (async () => {
    try {
      await fetch('<?php echo htmlspecialchars(BASE_URL); ?>/api/terminal_mode_set.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        credentials: 'same-origin',
        body: JSON.stringify({ csrf_token: csrfToken, terminal_id: terminalId, mode: 'RESTO' })
      });
    } catch (e) {
      // ignore
    }
  })();

  // Arduino-like Serial Monitor in the browser (USB)
  try {
    const sm = UniSmartWebSerialMonitor({
      baudRate: 115200,
      preEl: document.getElementById('serial_monitor'),
      statusEl: document.getElementById('serial_status'),
      connectBtn: document.getElementById('btn_serial_connect'),
      disconnectBtn: document.getElementById('btn_serial_disconnect'),
      maxLines: 500,
    });
    sm.init();
  } catch (e) {
    const st = document.getElementById('serial_status');
    if (st) st.textContent = 'Serial: indisponible';
  }
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
