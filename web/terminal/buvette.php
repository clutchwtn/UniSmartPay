<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/functions.php';
require_admin_login();
require_once __DIR__ . '/../includes/header.php';

// Default to a single terminal id (same ESP32 can switch modes dynamically)
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
    <h1 class="h1">Mode BUVETTE (Terminal)</h1>
    <div class="section-sub">Scanner les produits, puis payer avec la carte RFID sur le terminal</div>
  </div>

  <style>
    /* Ensure barcode isn't cropped in the preview (Quagga injects its own <video>) */
    #scanner video { width: 100%; height: 100%; object-fit: contain; background: var(--bg); }
    #scanner canvas { width: 100%; height: 100%; object-fit: contain; }
  </style>

  <div class="grid">
    <div class="card col-6">
      <div class="card-title"><i class="fa-solid fa-camera"></i> Scanner</div>
      <div id="scanner" style="width:100%;height:280px;border:1px dashed var(--border);border-radius:12px;overflow:hidden;"></div>
      <div style="margin-top:10px;color:var(--muted);font-size:12px;">Autorisez la caméra. Scannez un code-barres (ex: 3700104455153). Note: la caméra est bloquée sur <b>http://10.x.x.x</b> (non-HTTPS) — utilisez <b>http://localhost</b> sur le PC, ou activez HTTPS.</div>
      <div style="margin-top:12px;display:flex;gap:10px;flex-wrap:wrap;">
        <button id="btn_camera" class="btn btn-primary" type="button"><i class="fa-solid fa-camera"></i> Activer caméra</button>
        <button id="btn_camera_stop" class="btn" type="button"><i class="fa-solid fa-camera-slash"></i> Stop</button>
      </div>
      <div id="scan_status" style="margin-top:10px;color:var(--muted);"></div>
      <div id="debug_scan" style="margin-top:10px;color:var(--muted);font-size:12px;"></div>
    </div>

    <div class="card col-6">
      <div class="card-title"><i class="fa-solid fa-basket-shopping"></i> Panier</div>
      <div id="cart" style="color:var(--muted);">Aucun produit scanné.</div>
      <div style="margin-top:14px;display:flex;gap:10px;flex-wrap:wrap;">
        <button id="btn_clear" class="btn btn-danger" type="button"><i class="fa-solid fa-trash"></i> Vider</button>
        <button id="btn_pay" class="btn btn-success" type="button"><i class="fa-solid fa-id-card"></i> Payer (Carte RFID)</button>
      </div>
      <div id="pay_status" style="margin-top:12px;"></div>
      <div id="debug_pay" style="margin-top:10px;color:var(--muted);font-size:12px;"></div>
      <div style="margin-top:10px;color:var(--muted);font-size:12px;">Terminal ID: <b><?php echo (int)$terminalId; ?></b></div>
      <div style="margin-top:8px;color:var(--muted);font-size:12px;">
        Ce mode est synchronisé automatiquement: en ouvrant cette page, le terminal passe en <b>BUVETTE</b>.
        S'il n'y a aucune commande en attente, l'ESP32 affichera <b>Panier vide / aucune commande</b> et ne débitera rien.
      </div>
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

<script src="/UniSmartPay/assets/vendor/zxing.min.js"></script>
<script src="/UniSmartPay/assets/vendor/quagga.min.js"></script>
<script src="/UniSmartPay/assets/js/barcode_scanner.js"></script>
<script src="/UniSmartPay/assets/js/web_serial_monitor.js"></script>
<script>
  const csrfToken = <?php echo json_encode(csrf_token(), JSON_UNESCAPED_SLASHES); ?>;
  const terminalId = <?php echo (int)$terminalId; ?>;
  const cart = new Map(); // id_produit -> {id_produit, nom, prix, quantite}

  // Set terminal mode dynamically (BUVETTE)
  (async () => {
    try {
      await fetch('<?php echo htmlspecialchars(BASE_URL); ?>/api/terminal_mode_set.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        credentials: 'same-origin',
        body: JSON.stringify({ csrf_token: csrfToken, terminal_id: terminalId, mode: 'BUVETTE' })
      });
    } catch (e) {
      // ignore
    }
  })();

  async function postTerminalLog(type, message, data = null, uid = null) {
    try {
      await fetch('<?php echo htmlspecialchars(BASE_URL); ?>/api/admin_terminal_log.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        credentials: 'same-origin',
        body: JSON.stringify({
          csrf_token: csrfToken,
          id_terminal: terminalId,
          type_message: String(type || 'INFO').toUpperCase(),
          message: String(message || ''),
          donnees_json: data,
          uid_carte: uid
        })
      });
    } catch (e) {
      // ignore logging failures
    }
  }

  function renderCart() {
    const el = document.getElementById('cart');
    if (cart.size === 0) {
      el.innerHTML = '<div style="color:var(--muted);">Aucun produit scanné.</div>';
      return;
    }

    let total = 0;
    let html = '<table class="table"><thead><tr><th>Produit</th><th>PU</th><th>Qté</th><th>Total</th></tr></thead><tbody>';

    for (const item of cart.values()) {
      const line = item.prix * item.quantite;
      total += line;
      html += `<tr><td>${escapeHtml(item.nom)}</td><td>${item.prix.toFixed(2)}</td><td>${item.quantite}</td><td>${line.toFixed(2)}</td></tr>`;
    }

    html += `</tbody></table><div style="margin-top:10px;font-weight:800;font-size:18px;">Total: TND ${total.toFixed(2)}</div>`;
    el.innerHTML = html;

    // Log cart total (throttled by cooldown in scanner and user actions)
    postTerminalLog('PANIER', 'Total actuel : ' + total.toFixed(2) + ' DT', { total: total }).catch(() => {});
  }

  function escapeHtml(s){
    return String(s).replace(/[&<>'"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','\'':'&#39;','"':'&quot;'}[c]));
  }

  async function lookupBarcode(code) {
    const status = document.getElementById('scan_status');
    const dbg = document.getElementById('debug_scan');
    status.textContent = 'Scan: ' + code + ' …';
    dbg.textContent = '';

    const res = await fetch('<?php echo htmlspecialchars(BASE_URL); ?>/api/barcode_lookup.php?code=' + encodeURIComponent(code), { credentials: 'same-origin' });
    const data = await res.json();
    dbg.textContent = 'HTTP ' + res.status + ' • ' + JSON.stringify(data);

    if (!data.ok) {
      const baseMsg = 'Je ne connais pas ce produit. Ajoutez-le à la base de données (Admin → Ajouter produit) s\'il vous plaît.';
      const detail = (data && data.error) ? String(data.error) : 'Produit introuvable';
      status.textContent = baseMsg + ' (Code: ' + code + ') • ' + detail;
      return;
    }

    const p = data.produit;

    // Log scan event
    postTerminalLog('SCAN', 'Produit détecté : ' + p.nom + ' (' + parseFloat(p.prix).toFixed(2) + ' DT) • code=' + code, {
      code_barre: code,
      id_produit: p.id_produit,
      nom: p.nom,
      prix: p.prix
    }).catch(() => {});
    const existing = cart.get(p.id_produit);
    if (existing) {
      existing.quantite += 1;
    } else {
      cart.set(p.id_produit, { id_produit: p.id_produit, nom: p.nom, prix: parseFloat(p.prix), quantite: 1 });
    }

    status.textContent = 'Ajouté: ' + p.nom;
    renderCart();
  }

  document.getElementById('btn_clear').addEventListener('click', () => {
    cart.clear();
    renderCart();
    document.getElementById('pay_status').innerHTML = '';

    postTerminalLog('PANIER', 'Panier vidé', { total: 0 }).catch(() => {});
  });

  const scanner = UniSmartBarcodeScanner({
    cooldownMs: 900,
    preferNative: true,
    preferZXing: true,
    allowQuagga: true,
    // FAST SCAN (PC/webcam): restrict formats + reduce decoder work
    allowedFormats: ['EAN_13', 'EAN_8', 'CODE_128', 'UPC_A', 'UPC_E'],
    zxingTryHarder: false,
    zxingAlsoInverted: false,
    quaggaArea: { top: '25%', right: '15%', left: '15%', bottom: '25%' },
    onStatus: (msg) => {
      document.getElementById('scan_status').textContent = msg;
    },
    onError: (err) => {
      console.error(err);
      const msg = (err && err.message) ? err.message : String(err);
      const stack = (err && err.stack) ? String(err.stack) : '';
      document.getElementById('scan_status').textContent = 'Caméra: ' + msg;
      document.getElementById('debug_scan').textContent = stack;
    },
    onDetected: (code) => lookupBarcode(String(code).trim()).catch(err => {
      console.error(err);
      document.getElementById('scan_status').textContent = 'Erreur scan';
    })
  });

  renderCart();

  async function startCamera() {
    const statusEl = document.getElementById('scan_status');
    const dbgEl = document.getElementById('debug_scan');

    const host = window.location.hostname;
    const isLocal = host === 'localhost' || host === '127.0.0.1';
    if (!window.isSecureContext && !isLocal) {
      statusEl.textContent = 'Caméra bloquée (HTTP non sécurisé). Ouvre via http://localhost/UniSmartPay/... sur le PC, ou active HTTPS. (Dev) Chrome: chrome://flags → "Insecure origins treated as secure".';
      dbgEl.textContent = 'URL actuelle: ' + window.location.href + ' • Astuce: en test, ajoute cette origine dans le flag: ' + (window.location.origin || '');
      return;
    }

    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
      statusEl.textContent = 'Caméra non supportée par ce navigateur.';
      dbgEl.textContent = 'Essaie Google Chrome (Android/PC).';
      return;
    }

    // Note: BUVETTE flow requires the ESP32 terminal to be in MODE_BUVETTE.
    // If your Serial Monitor shows lines like "[RESTO] Prix ticket...", the terminal is in RESTO mode
    // and it will NOT claim/pay buvette orders (it will debit the fixed ticket instead).

    statusEl.textContent = 'Caméra: démarrage…';
    dbgEl.textContent = '';
    postTerminalLog('INFO', 'Caméra activée (buvette)');
    scanner.start('scanner');
  }

  document.getElementById('btn_camera').addEventListener('click', startCamera);
  document.getElementById('btn_camera_stop').addEventListener('click', () => {
    scanner.stop();
    document.getElementById('scan_status').textContent = 'Caméra: arrêtée';
    postTerminalLog('INFO', 'Caméra arrêtée (buvette)');
  });

  // Terminal payment flow
  let pollTimer = 0;
  async function pollStatus(orderId) {
    if (pollTimer) {
      clearInterval(pollTimer);
      pollTimer = 0;
    }

    const payStatus = document.getElementById('pay_status');
    const payDbg = document.getElementById('debug_pay');

    pollTimer = setInterval(async () => {
      try {
        const res = await fetch('<?php echo htmlspecialchars(BASE_URL); ?>/api/terminal_order_status.php?order_id=' + encodeURIComponent(orderId), { credentials: 'same-origin' });
        const data = await res.json();
        payDbg.textContent = 'HTTP ' + res.status + ' • ' + JSON.stringify(data);
        if (!data.ok) return;

        const o = data.order;
        if (o.statut === 'PAYE') {
          payStatus.innerHTML = '<div class="alert alert-success">Paiement réussi. Réf: ' + escapeHtml(o.reference || '') + '</div>';
          postTerminalLog('SUCCESS', 'Paiement validé ✔ ref=' + (o.reference || '') + ' (order=' + orderId + ')', { order_id: orderId, reference: o.reference || null });
          clearInterval(pollTimer);
          pollTimer = 0;
          cart.clear();
          renderCart();
        } else if (o.statut === 'ECHEC' || o.statut === 'EXPIRE' || o.statut === 'ANNULE') {
          payStatus.innerHTML = '<div class="alert alert-danger">Paiement échoué: ' + escapeHtml(o.error || o.statut) + '</div>';
          postTerminalLog('ERROR', 'Paiement échoué: ' + (o.error || o.statut) + ' (order=' + orderId + ')', { order_id: orderId, statut: o.statut, error: o.error || null });
          clearInterval(pollTimer);
          pollTimer = 0;
        } else {
          // still waiting
        }
      } catch (e) {
        // ignore transient polling errors
      }
    }, 1000);
  }

  document.getElementById('btn_pay').addEventListener('click', async () => {
    const payStatus = document.getElementById('pay_status');
    const payDbg = document.getElementById('debug_pay');
    payDbg.textContent = '';

    if (cart.size === 0) {
      payStatus.innerHTML = '<div class="alert alert-warning">Panier vide.</div>';
      postTerminalLog('WARNING', 'Tentative paiement: panier vide');
      return;
    }

    const items = Array.from(cart.values()).map(i => ({ id_produit: i.id_produit, quantite: i.quantite }));

    payStatus.innerHTML = '<div class="alert">Création commande…</div>';
    postTerminalLog('PAIEMENT', 'Création commande en cours…');

    const res = await fetch('<?php echo htmlspecialchars(BASE_URL); ?>/api/terminal_order_create_buvette.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      credentials: 'same-origin',
      body: JSON.stringify({ csrf_token: csrfToken, terminal_id: terminalId, items })
    });

    const data = await res.json();
    payDbg.textContent = 'HTTP ' + res.status + ' • ' + JSON.stringify(data);

    if (!data.ok) {
      payStatus.innerHTML = '<div class="alert alert-danger">' + escapeHtml(data.error || 'Erreur') + '</div>';
      postTerminalLog('ERROR', 'Création commande échouée: ' + (data.error || 'Erreur'));
      return;
    }

    const orderId = data.order_id;
    const montant = parseFloat(data.montant);
    payStatus.innerHTML = '<div class="alert alert-warning">Commande créée (TND ' + montant.toFixed(2) + '). Passez la carte RFID sur le terminal…</div>';
    postTerminalLog('PAIEMENT', 'Montant total envoyé : ' + montant.toFixed(2) + ' DT (order=' + orderId + ')', { order_id: orderId, montant: montant });
    pollStatus(orderId);
  });

  // Web "serial monitor" (device logs)
  // (Logs du terminal BD supprimés sur demande)

  window.addEventListener('beforeunload', () => {
    scanner.stop();
    if (pollTimer) clearInterval(pollTimer);
  });

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
