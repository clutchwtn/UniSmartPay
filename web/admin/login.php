<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/functions.php';

$email = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim((string)($_POST['email'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    $csrf = (string)($_POST['csrf_token'] ?? '');

    require_csrf_token($csrf);

    if ($email === '' || $password === '') {
        $error = 'Veuillez remplir tous les champs.';
    } else {
        try {
            $pdo = db();
            $stmt = $pdo->prepare('SELECT id_admin, id_faculte, nom, prenom, email, password_hash, role, actif FROM admins WHERE email = :email LIMIT 1');
            $stmt->execute([':email' => $email]);
            $admin = $stmt->fetch();

            if (!$admin || (int)$admin['actif'] !== 1 || !verify_password($password, (string)$admin['password_hash'])) {
                $error = 'Email ou mot de passe incorrect.';
                log_security(null, 'admin', 'LOGIN', 'Echec login admin email=' . $email, 'ECHEC');
            } else {
                session_regenerate_id(true);
                $_SESSION['admin_id'] = (int)$admin['id_admin'];
                $_SESSION['admin_role'] = (string)$admin['role'];
                $_SESSION['admin_faculte_id'] = $admin['id_faculte'] !== null ? (int)$admin['id_faculte'] : null;
                $_SESSION['admin_nom'] = (string)$admin['nom'];
                $_SESSION['admin_prenom'] = (string)$admin['prenom'];
                $_SESSION['login_time'] = time();

                $pdo->prepare('UPDATE admins SET derniere_connexion = NOW() WHERE id_admin = :id')->execute([':id' => (int)$admin['id_admin']]);

                log_security((int)$admin['id_admin'], 'admin', 'LOGIN', 'Connexion admin email=' . $email, 'SUCCES');

                header('Location: ' . BASE_URL . '/admin/dashboard.php');
                exit();
            }
        } catch (Throwable $e) {
          $error = (defined('APP_ENV') && APP_ENV === 'dev')
            ? ('Erreur serveur: ' . $e->getMessage())
            : 'Erreur serveur. Réessayez plus tard.';
            error_log('Admin login error: ' . $e->getMessage());
        }
    }
}

require_once __DIR__ . '/../includes/header.php';
?>
<div class="login-wrap">
  <div class="login-card">

    <div class="login-logo">
      <i class="fa-solid fa-shield-halved" style="-webkit-text-fill-color:var(--accent-light); color:var(--accent-light);"></i>
      <?php echo htmlspecialchars(APP_NAME); ?>
    </div>

    <h1 class="login-title">Espace administrateur</h1>
    <p class="login-sub">Connectez-vous avec votre adresse email</p>

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
        <label for="email" class="form-label">Adresse email</label>
        <input id="email" class="input" name="email"
               value="<?php echo htmlspecialchars($email); ?>"
               placeholder="admin@unismart.tn"
               autocomplete="username" type="email" required />
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

    <div style="text-align:center; margin-top:14px;">
      <a href="<?php echo htmlspecialchars(BASE_URL); ?>/" style="font-size:12.5px; color:var(--muted); text-decoration:none; transition:color 0.18s;"
         onmouseover="this.style.color='var(--text)'" onmouseout="this.style.color='var(--muted)'">
        <i class="fa-solid fa-arrow-left" style="margin-right:4px;"></i> Retour à l'accueil
      </a>
    </div>

  </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
