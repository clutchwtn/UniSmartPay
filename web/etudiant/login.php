<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/functions.php';

$matricule = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $matricule = trim((string)($_POST['matricule'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    $csrf = (string)($_POST['csrf_token'] ?? '');

    require_csrf_token($csrf);

    if ($matricule === '' || $password === '') {
        $error = 'Veuillez remplir tous les champs.';
    } else {
        try {
            $pdo = db();
            $locked = login_is_locked('etudiant', $matricule);
            $stmt = $pdo->prepare('SELECT id_etudiant, matricule, nom, prenom, email, password_hash, actif FROM etudiants WHERE matricule = :matricule LIMIT 1');
            $stmt->execute([':matricule' => $matricule]);
            $etu = $stmt->fetch();

            if ($locked) {
                $error = 'Trop de tentatives de connexion. Veuillez réessayer plus tard.';
                log_security(null, 'etudiant', 'LOGIN_LOCKOUT', 'Connexion refusée, trop d echecs id=' . $matricule, 'ALERTE');
            } elseif (!$etu || (int)$etu['actif'] !== 1 || !verify_password($password, (string)$etu['password_hash'])) {
                $error = 'Matricule ou mot de passe incorrect.';
                log_security(null, 'etudiant', 'LOGIN', 'Echec login matricule=' . $matricule, 'ECHEC');
                login_record_failure('etudiant', $matricule);
            } else {
                upgrade_password_hash($pdo, 'etudiants', 'id_etudiant', (int)$etu['id_etudiant'], $password, (string)$etu['password_hash']);

                session_regenerate_id(true);
                $_SESSION['etudiant_id'] = (int)$etu['id_etudiant'];
                $_SESSION['etudiant_matricule'] = (string)$etu['matricule'];
                $_SESSION['etudiant_nom'] = (string)$etu['nom'];
                $_SESSION['etudiant_prenom'] = (string)$etu['prenom'];
                $_SESSION['login_time'] = time();

                $pdo->prepare('UPDATE etudiants SET derniere_connexion = NOW() WHERE id_etudiant = :id')->execute([':id' => (int)$etu['id_etudiant']]);

                log_security((int)$etu['id_etudiant'], 'etudiant', 'LOGIN', 'Connexion etudiant matricule=' . $matricule, 'SUCCES');

                header('Location: ' . BASE_URL . '/etudiant/dashboard.php');
                exit();
            }
        } catch (Throwable $e) {
          $error = (defined('APP_ENV') && APP_ENV === 'dev')
            ? ('Erreur serveur: ' . $e->getMessage())
            : 'Erreur serveur. Réessayez plus tard.';
            error_log('Student login error: ' . $e->getMessage());
        }
    }
}

require_once __DIR__ . '/../includes/header.php';
?>
<div class="login-wrap">
  <div class="login-card">

    <div class="login-logo">
      <i class="fa-solid fa-bolt-lightning" style="-webkit-text-fill-color:var(--accent-light); color:var(--accent-light);"></i>
      <?php echo htmlspecialchars(APP_NAME); ?>
    </div>

    <h1 class="login-title">Connexion étudiant</h1>
    <p class="login-sub">Connectez-vous avec votre matricule universitaire</p>

    <?php if (!empty($_GET['expired'])): ?>
      <div class="alert alert-warning" style="margin-bottom:18px;">
        <i class="fa-solid fa-clock"></i> Session expirée. Veuillez vous reconnecter.
      </div>
    <?php endif; ?>

    <?php if ($error): ?>
      <div class="alert alert-danger" style="margin-bottom:18px;">
        <i class="fa-solid fa-triangle-exclamation"></i>
        <?php echo htmlspecialchars($error); ?>
      </div>
    <?php endif; ?>

    <form method="post" action="">
      <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(csrf_token()); ?>">

      <div class="form-group">
        <label for="matricule" class="form-label">Matricule</label>
        <input id="matricule" class="input" name="matricule"
               value="<?php echo htmlspecialchars($matricule); ?>"
               placeholder="2024/FST/0001"
               autocomplete="username" required />
      </div>

      <div class="form-group" style="margin-bottom:22px;">
        <label for="password" class="form-label">Mot de passe</label>
        <input id="password" class="input" name="password" type="password"
               placeholder="••••••••"
               autocomplete="current-password" required />
      </div>

      <button class="btn btn-primary btn-lg" type="submit" style="width:100%;">
        <i class="fa-solid fa-right-to-bracket"></i> Se connecter
      </button>
    </form>

    <div class="divider"></div>

    <p style="font-size:12px; color:var(--muted); text-align:center;">
      Démo&nbsp;: <code style="background:rgba(255,255,255,0.07);padding:2px 6px;border-radius:5px;color:var(--text);">2024/FST/0001</code>
      &nbsp;/&nbsp;
      <code style="background:rgba(255,255,255,0.07);padding:2px 6px;border-radius:5px;color:var(--text);">Etudiant@1234</code>
    </p>

    <div style="text-align:center; margin-top:14px;">
      <a href="<?php echo htmlspecialchars(BASE_URL); ?>/" style="font-size:12.5px; color:var(--muted); text-decoration:none; transition:color 0.18s;"
         onmouseover="this.style.color='var(--text)'" onmouseout="this.style.color='var(--muted)'">
        <i class="fa-solid fa-arrow-left" style="margin-right:4px;"></i> Retour à l'accueil
      </a>
    </div>

  </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
