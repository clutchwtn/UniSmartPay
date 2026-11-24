<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/functions.php';
require_student_login();

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
    <h1 class="h1">Paiement buvette</h1>
    <div class="text-muted" style="margin-top:6px;">Scannez les produits, puis payez</div>
  </div>

  <div class="grid">
    <div class="card col-6">
      <div class="card-title"><i class="fa-solid fa-camera"></i> Scanner</div>
      <div id="scanner" style="width:100%;height:280px;border:1px dashed var(--border);border-radius:12px;overflow:hidden;"></div>
      <div style="margin-top:10px;color:var(--muted);font-size:12px;">Autorisez la caméra. Scannez un code-barres (EAN/Code128).</div>
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
        <button id="btn_pay" class="btn btn-success" type="button"><i class="fa-solid fa-credit-card"></i> Payer</button>
      </div>
      <div id="pay_status" style="margin-top:12px;"></div>
      <div id="debug_pay" style="margin-top:10px;color:var(--muted);font-size:12px;"></div>
    </div>
  </div>
</div>

<script src="/UniSmartPay/assets/vendor/zxing.min.js"></script>
<script src="/UniSmartPay/assets/js/barcode_scanner.js"></script>
<script>
  const csrfToken = <?php echo json_encode(csrf_token(), JSON_UNESCAPED_SLASHES); ?>;
  const cart = new Map(); // id_produit -> {id_produit, nom, prix, quantite}

  // One request id per basket: resending the same basket (double click,
  // network retry) is charged once. A new id is made whenever the basket changes.
  let requestId = null;
  function newRequestId() {
    const bytes = new Uint8Array(16);
    crypto.getRandomValues(bytes);
    return Array.from(bytes, b => b.toString(16).padStart(2, '0')).join('');
  }

  function renderCart() {
    requestId = null;
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
      // Ne pas ajouter au panier si le produit n'existe pas.
      // Message demandé: inviter l'admin à ajouter le produit en BD.
      const baseMsg = 'Je ne connais pas ce produit. Ajoutez-le à la base de données (Admin → Ajouter produit) s\'il vous plaît.';
      const detail = (data && data.error) ? String(data.error) : 'Produit introuvable';
      status.textContent = baseMsg + ' (Code: ' + code + ') • ' + detail;
      return;
    }

    const p = data.produit;
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
  });

  document.getElementById('btn_pay').addEventListener('click', async () => {
    const payStatus = document.getElementById('pay_status');
    const payDbg = document.getElementById('debug_pay');
    payDbg.textContent = '';
    if (cart.size === 0) {
      payStatus.innerHTML = '<div class="alert alert-warning">Panier vide.</div>';
      return;
    }

    const items = Array.from(cart.values()).map(i => ({ id_produit: i.id_produit, quantite: i.quantite }));

    payStatus.innerHTML = '<div class="alert">Paiement en cours…</div>';
    requestId = requestId || newRequestId();

    const res = await fetch('<?php echo htmlspecialchars(BASE_URL); ?>/api/buvette_checkout.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      credentials: 'same-origin',
      body: JSON.stringify({ csrf_token: csrfToken, items, request_id: requestId })
    });

    const data = await res.json();
    payDbg.textContent = 'HTTP ' + res.status + ' • ' + JSON.stringify(data);
    if (!data.ok) {
      payStatus.innerHTML = '<div class="alert alert-danger">' + escapeHtml(data.error || 'Paiement échoué') + '</div>';
      return;
    }

    payStatus.innerHTML = '<div class="alert alert-success">Paiement réussi. Réf: ' + escapeHtml(data.reference) + ' • Nouveau solde: TND ' + parseFloat(data.solde_apres).toFixed(2) + '</div>';
    cart.clear();
    renderCart();
  });

  const scanner = UniSmartBarcodeScanner({
    cooldownMs: 900,
    preferNative: true,
    preferZXing: true,
    allowQuagga: false,
    // FAST SCAN: restrict formats + reduce decoder work
    allowedFormats: ['EAN_13', 'EAN_8', 'CODE_128', 'UPC_A', 'UPC_E'],
    zxingTryHarder: false,
    zxingAlsoInverted: false,
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
      statusEl.textContent = 'Caméra bloquée: ouvre le site en HTTPS ou via localhost/127.0.0.1';
      dbgEl.textContent = 'URL actuelle: ' + window.location.href;
      return;
    }

    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
      statusEl.textContent = 'Caméra non supportée par ce navigateur.';
      dbgEl.textContent = 'Essaie Google Chrome (Android/PC).';
      return;
    }

    statusEl.textContent = 'Caméra: démarrage…';
    dbgEl.textContent = '';
    // The scanner module will request permissions and start either native BarcodeDetector or Quagga.
    scanner.start('scanner');
  }

  document.getElementById('btn_camera').addEventListener('click', startCamera);
  document.getElementById('btn_camera_stop').addEventListener('click', () => {
    scanner.stop();
    document.getElementById('scan_status').textContent = 'Caméra: arrêtée';
  });

  window.addEventListener('beforeunload', () => scanner.stop());
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
