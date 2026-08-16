<?php
require_once __DIR__ . '/inc/functions.php';
require_admin();

$user = current_user();
$posName = get_pos_name();
$status = get_trial_status();
$permanent = is_permanently_licensed();
$log = get_activation_log(100);
$startedAt = (int) get_setting('activation_started_at', '0');
$periodMinutes = (int) get_setting('activation_period_minutes', (string) DEFAULT_ACTIVATION_PERIOD_MINUTES);
$scheme = get_setting('activation_scheme', '');
$lastActivatedAt = get_setting('last_activated_at', '');

switch ($status['status']) {
    case 'activated':
        $statusLabel = 'Permanently licensed';
        $statusHint = 'The activation code has ended the trial. No deadline applies.';
        $statusClass = 'text-success';
        break;
    case 'expired':
        $statusLabel = 'Trial expired — app locked';
        $statusHint = 'The silent trial has elapsed and the app requires the activation code.';
        $statusClass = 'text-error';
        break;
    default:
        $statusLabel = 'Silent trial running';
        $statusHint = 'The trial is invisible to users (no countdown). The app locks when it elapses.';
        $statusClass = '';
        break;
}
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
  <title><?php echo htmlspecialchars($posName); ?> - Activation Status</title>
  <link rel="icon" type="image/svg+xml" href="<?php echo htmlspecialchars(get_trademark_src()); ?>" />
  <link rel="stylesheet" href="styles.css" />
</head>
<body>
  <div class="app-shell">
    <header class="header">
      <div class="brand">
        <img class="brand-icon" src="<?php echo htmlspecialchars(get_trademark_src()); ?>" alt="Trademark" />
        <div>
          <h1><?php echo htmlspecialchars($posName); ?></h1>
          <p class="subtitle">Activation and trial status.</p>
        </div>
      </div>

      <button class="menu-toggle" id="menu-toggle" aria-label="Toggle navigation menu" aria-expanded="false" aria-controls="header-actions">
        <span></span><span></span><span></span>
      </button>

      <div class="header-actions" id="header-actions">
        <a href="sales.php" class="secondary">Sales register</a>
        <a href="sales_report.php" class="secondary">Sales reports</a>
        <a href="credit_sales.php" class="secondary">Credit sales</a>
        <a href="balance_sheet.php" class="secondary">Balance sheet</a>
        <?php if ($user['role'] === 'admin'): ?>
          <a href="admin.php" class="secondary">Admin dashboard</a>
          <a href="activation_status.php" class="secondary">Activation status</a>
        <?php endif; ?>
        <a href="logout.php" class="tertiary">Logout</a>
      </div>
    </header>

    <main class="main-grid">
      <article class="panel overview-panel">
        <div class="panel-label">
          <span class="badge admin-badge">Admin</span>
          <h2>Activation status</h2>
        </div>
        <p class="panel-copy <?php echo $statusClass; ?>" style="font-weight:700;font-size:1.05rem;">
          <?php echo htmlspecialchars($statusLabel); ?>
        </p>
        <p class="panel-copy"><?php echo htmlspecialchars($statusHint); ?></p>
        <div class="highlight-row">
          <div>
            <span class="metric-label">Activation scheme</span>
            <strong><?php echo htmlspecialchars($scheme !== '' ? $scheme : 'not set'); ?></strong>
          </div>
          <div>
            <span class="metric-label">Trial period</span>
            <strong><?php echo htmlspecialchars(format_duration($periodMinutes * 60)); ?></strong>
          </div>
        </div>
      </article>

      <article class="panel">
        <div class="panel-header">
          <h2>Trial / license details</h2>
        </div>
        <div class="stats-grid">
          <div class="stat-item">
            <div class="stat-label">License status</div>
            <div class="stat-value <?php echo $statusClass; ?>"><?php echo htmlspecialchars($statusLabel); ?></div>
          </div>
          <div class="stat-item">
            <div class="stat-label">Trial started</div>
            <div class="stat-value"><?php echo $startedAt > 0 ? htmlspecialchars(date('Y-m-d H:i', $startedAt)) : '—'; ?></div>
          </div>
          <div class="stat-item">
            <div class="stat-label">Deadline</div>
            <div class="stat-value"><?php echo !empty($status['deadline']) ? htmlspecialchars(date('Y-m-d H:i', $status['deadline'])) : ($permanent ? 'None (permanent)' : '—'); ?></div>
          </div>
          <div class="stat-item">
            <div class="stat-label">Time remaining</div>
            <div class="stat-value"><?php echo $permanent ? '—' : htmlspecialchars(format_duration(max(0, (int) ($status['seconds_remaining'] ?? 0)))); ?></div>
          </div>
          <?php if ($lastActivatedAt !== ''): ?>
            <div class="stat-item">
              <div class="stat-label">Last activated</div>
              <div class="stat-value"><?php echo htmlspecialchars($lastActivatedAt); ?></div>
            </div>
          <?php endif; ?>
        </div>
      </article>

      <article class="panel">
        <div class="panel-header">
          <h2>Activation code</h2>
        </div>
        <p class="panel-copy">Entering this code permanently ends the running trial (it does not grant a new trial period).</p>
        <div class="admin-form" style="max-width:420px;">
          <label class="input-group">
            <span class="field-label">Activation code</span>
            <input type="text" readonly value="<?php echo htmlspecialchars(ACTIVATION_CODE); ?>" style="font-family:monospace;" />
          </label>
        </div>
      </article>

      <section class="panel">
        <div class="panel-header">
          <h2>Activation attempt log</h2>
          <span style="font-size:0.8rem;color:#64748b;">Most recent 100 attempts</span>
        </div>

        <?php if (count($log) === 0): ?>
          <p class="empty-message">No activation attempts recorded yet.</p>
        <?php else: ?>
          <div class="admin-product-list">
            <?php foreach ($log as $entry): ?>
              <article class="admin-product-card" style="grid-template-columns:1fr auto;min-height:auto;padding:14px 20px;">
                <div class="admin-details">
                  <div class="admin-name"><?php echo htmlspecialchars($entry['code']); ?></div>
                  <div class="admin-meta">
                    <?php echo htmlspecialchars($entry['action']); ?>
                    <?php if (!empty($entry['ip'])): ?> &bull; IP <?php echo htmlspecialchars($entry['ip']); ?><?php endif; ?>
                  </div>
                </div>
                <div class="admin-meta" style="text-align:right;white-space:nowrap;">
                  <?php echo htmlspecialchars(date('Y-m-d H:i:s', strtotime($entry['created_at']))); ?>
                </div>
              </article>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </section>
    </main>
  </div>
  <script src="nav.js"></script>
  <footer class="app-footer">
    Designed by Ten12 Tech&copy;<br />
    &copy;2026<br />
    Contact: +233 55 850 4111
  </footer>
</body>
</html>
