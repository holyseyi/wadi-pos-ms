<?php
require_once __DIR__ . '/inc/functions.php';

migrate_default_users();

$error = '';
$activationError = '';
$activationSuccess = '';
$activationStatus = get_trial_status();
$activationExpired = $activationStatus['status'] === 'expired';

if (isset($_SESSION['user'])) {
    header('Location: sales.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['activate_app'])) {
        $code = trim($_POST['activation_code'] ?? '');
        if (activate_app($code)) {
            header('Location: login.php?activated=1');
            exit;
        }
        $activationError = 'Invalid activation code. Please contact the developer.';
    } else {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        $user = authenticate_user($username, $password);

        if ($user) {
            $_SESSION['user'] = $user;
            log_login_event($user['username'], $user['role']);
            header('Location: sales.php');
            exit;
        }

        $error = 'Invalid username or password. Please try again.';
    }
}

if (isset($_GET['activated'])) {
    $activationSuccess = 'App activated successfully! The trial period has ended and all features are permanently unlocked. You can now log in.';
}

$posName = get_pos_name();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover" />
  <meta name="apple-mobile-web-app-capable" content="yes" />
  <meta name="apple-mobile-web-app-status-bar-style" content="default" />
  <meta name="apple-mobile-web-app-title" content="<?php echo htmlspecialchars($posName); ?>" />
  <link rel="apple-touch-icon" href="<?php echo htmlspecialchars(get_trademark_src()); ?>" />
  <title><?php echo htmlspecialchars($posName); ?> - Login</title>
  <link rel="icon" type="image/svg+xml" href="<?php echo htmlspecialchars(get_trademark_src()); ?>" />
  <link rel="stylesheet" href="styles.css" />
</head>
<body>
  <section class="login-screen">
    <div class="login-card">
      <img class="brand-image" src="images/pos-hero.svg" alt="POS branding" />
      <div class="login-content">
        <?php if ($activationExpired): ?>
          <h1>Activation Required</h1>
          <p class="login-description">
            Access is locked. Enter your activation code to continue using <?php echo htmlspecialchars($posName); ?>.
          </p>

          <?php if ($activationSuccess): ?>
            <p class="login-hint text-success"><?php echo htmlspecialchars($activationSuccess); ?></p>
          <?php endif; ?>

          <form id="activation-form" class="login-form" method="post" action="login.php">
            <label class="input-group">
              Activation Code
              <input id="activation-code" name="activation_code" autocomplete="off" type="text" placeholder="Enter activation code" required />
            </label>
            <button id="activate-button" class="primary" type="submit" name="activate_app">Activate App</button>
            <p class="error-text" aria-live="polite"><?php echo htmlspecialchars($activationError); ?></p>
          </form>
          <footer class="app-footer">
            Designed by Ten12 Tech&copy;<br />
            &copy;2026<br />
            Contact: +233 55 850 4111
          </footer>
        <?php else: ?>
          <h1><?php echo htmlspecialchars($posName); ?> Login</h1>
          <p class="login-description">
            Enter your username and password to access the POS system.
          </p>

          <?php if ($activationSuccess): ?>
            <p class="login-hint text-success"><?php echo htmlspecialchars($activationSuccess); ?></p>
          <?php endif; ?>

          <form id="login-form" class="login-form" method="post" action="login.php">
            <label class="input-group">
              Username
              <input id="username" name="username" autocomplete="off" type="text" placeholder="Username" required />
            </label>
            <label class="input-group">
              Password
              <input id="password" name="password" autocomplete="new-password" type="password" placeholder="Password" required />
            </label>
            <button id="login-button" class="primary" type="submit">Sign in securely</button>
            <p class="error-text" aria-live="polite"><?php echo htmlspecialchars($error); ?></p>
          </form>
          <div class="demo-credentials">
            <p class="demo-credentials-title">Demo Credentials</p>
            <p class="demo-credentials-item"><strong>Sales:</strong> sales / sales123</p>
            <p class="demo-credentials-item"><strong>Admin:</strong> admin / adminSecure!23</p>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </section>
  <footer class="app-footer">
    Designed by Ten12 Tech&copy;<br />
    &copy;2026<br />
    Contact: +233 55 850 4111
  </footer>
</body>
</html>
