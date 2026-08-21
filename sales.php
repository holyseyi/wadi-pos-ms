<?php
require_once __DIR__ . '/inc/functions.php';
require_login();

$user = current_user();
$posName = get_pos_name();
$products = load_products();
$message = '';
$error = '';
$trialStatus = get_trial_status();
$trialDeadline = $trialStatus['deadline'] ?? 0;
$daysRemaining = $trialStatus['days_remaining'] ?? 7;
$expiringSoonSummary = get_expiring_soon_summary(7);
$expiredSummary = get_expired_stock_summary();

// Handle delete sale request (Sales rep can only delete own sales, admin can delete any)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    
    if ($action === 'delete_sale') {
        $saleId = intval($_POST['sale_id'] ?? 0);
        $receiptId = intval($_POST['receipt_id'] ?? 0);
        
        if ($saleId > 0 && $receiptId > 0) {
            // Check ownership for non-admin users
            $stmt = get_database()->prepare('SELECT username FROM orders WHERE id = :id');
            $stmt->execute([':id' => $saleId]);
            $order = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$order) {
                $error = 'Sale not found.';
            } elseif ($user['role'] !== 'admin' && $order['username'] !== $user['username']) {
                $error = 'You can only delete your own sales.';
            } else {
                if (delete_sale($saleId, $user['username'])) {
                    $message = 'Sale deleted successfully.';
                    log_activity($user['username'], $user['role'], 'sale_deleted', 'Deleted sale #' . str_pad((string)$saleId, 8, '0', STR_PAD_LEFT));
                } else {
                    $error = 'Failed to delete sale.';
                }
            }
        }
    } elseif ($action === 'update_return_status') {
        $receiptId = intval($_POST['receipt_id'] ?? 0);
        $status = $_POST['return_status'] ?? 'Active';
        
        if ($receiptId > 0 && in_array($status, ['Active', 'Returned'], true)) {
            if (update_receipt_status($receiptId, $status)) {
                $message = 'Receipt status updated successfully.';
                log_activity($user['username'], $user['role'], 'return_status_updated', 'Updated receipt #' . str_pad((string)$receiptId, 8, '0', STR_PAD_LEFT) . ' status to ' . $status);
            } else {
                $error = 'Failed to update receipt status.';
            }
        }
    } elseif ($action === 'print_current_receipt' || $action === 'view_current_receipt') {
        // Redirect to the receipts page with the current/latest receipt
        if ($user['role'] === 'admin') {
            $userSales = load_receipts();
        } else {
            $userSales = load_receipts_by_user($user['username']);
        }
        
        if (!empty($userSales)) {
            $latestReceipt = $userSales[0];
            if ($action === 'print_current_receipt') {
                header('Location: print_receipt.php?id=' . $latestReceipt['id']);
            } else {
                header('Location: receipts.php?view=' . $latestReceipt['id']);
            }
            exit;
        } else {
            $error = 'No receipts available to print.';
        }
    }
}

// Load user's sales/receipts
if ($user['role'] === 'admin') {
    $userSales = load_receipts();
} else {
    $userSales = load_receipts_by_user($user['username']);
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
  <title><?php echo htmlspecialchars($posName); ?> - Sales Register</title>
  <link rel="icon" type="image/svg+xml" href="<?php echo htmlspecialchars(get_trademark_src()); ?>" />
  <link rel="stylesheet" href="styles.css" />
  <style id="theme-overrides">
    :root {
      --theme-primary: <?php echo htmlspecialchars(get_theme_primary_color()); ?>;
      --theme-secondary: <?php echo htmlspecialchars(get_theme_secondary_color()); ?>;
      --theme-bg: <?php echo htmlspecialchars(get_theme_background_color()); ?>;
    }
  </style>
</head>
<body data-page="sales">
  <div class="app-shell">
    <header class="header">
      <div class="brand">
        <img class="brand-icon" src="<?php echo htmlspecialchars(get_trademark_src()); ?>" alt="Trademark" />
        <div>
          <h1><?php echo htmlspecialchars($posName); ?></h1>
          <p class="subtitle">Sales register</p>
        </div>
      </div>

      <button class="menu-toggle" id="menu-toggle" aria-label="Toggle navigation menu" aria-expanded="false" aria-controls="header-actions">
        <span></span><span></span><span></span>
      </button>

      <div class="header-actions" id="header-actions">
        <?php if ($user['role'] === 'admin'): ?>
        <a href="returns.php" class="secondary">All sales</a>
        <a href="returned_products.php" class="secondary">Returned Products</a>
        <?php endif; ?>
        <a href="sales.php" class="secondary">Sales register</a>
        <a href="sales_report.php" class="secondary">Sales reports</a>
        <a href="credit_sales.php" class="secondary">Credit sales</a>
        <a href="balance_sheet.php" class="secondary">Balance sheet</a>
        <?php if ($user['role'] === 'admin'): ?>
          <a href="admin.php" class="secondary">Admin dashboard</a>
        <?php endif; ?>
        <a href="logout.php" class="tertiary">Logout</a>
      </div>
    </header>

    <?php if (is_app_activated()): ?>
      <div class="deadline-banner licensed-banner">
        <span class="deadline-label">License Status:</span>
        <strong class="deadline-count">Licensed — All Features Unlocked</strong>
      </div>
    <?php else: ?>
      <div class="deadline-banner" id="deadline-banner" data-deadline="<?php echo $trialDeadline; ?>">
        <span class="deadline-label">Trial expires in</span>
        <strong class="deadline-count" id="deadline-count"><?php echo htmlspecialchars(format_duration(max(0, $trialDeadline - time()))); ?></strong>
      </div>
    <?php endif; ?>

    <?php if ($expiredSummary['count'] > 0): ?>
      <div class="expiry-banner expiry-banner-expired">
        <span class="expiry-banner-icon">🔴</span>
        <span class="expiry-banner-text">
          <strong><?php echo htmlspecialchars($expiredSummary['count']); ?> expired product(s)</strong> blocked from sales
          — GH₵<?php echo htmlspecialchars(number_format($expiredSummary['total_loss'], 2)); ?> stock loss
        </span>
        <a href="admin.php" class="expiry-banner-link">View in Admin</a>
      </div>
    <?php endif; ?>
    <?php if ($expiringSoonSummary['count'] > 0): ?>
      <div class="expiry-banner expiry-banner-warning">
        <span class="expiry-banner-icon">⏰</span>
        <span class="expiry-banner-text">
          <strong><?php echo htmlspecialchars($expiringSoonSummary['count']); ?> product(s) expiring within 7 days</strong>
          — <?php echo htmlspecialchars($expiringSoonSummary['total_items']); ?> unit(s) at risk
        </span>
        <a href="admin.php" class="expiry-banner-link">Manage</a>
      </div>
    <?php endif; ?>

    <main class="main-grid">
      <div class="dashboard-grid">
        <article class="panel overview-panel">
          <div class="panel-label">
            <span class="badge">Sales</span>
            <h2>Quick register</h2>
          </div>
          <div class="highlight-row">
            <div>
              <span class="metric-label">User</span>
              <strong><?php echo htmlspecialchars($user['username']); ?></strong>
            </div>
            <div>
              <span class="metric-label">Role</span>
              <strong><?php echo htmlspecialchars(ucfirst($user['role'])); ?></strong>
            </div>
          </div>
        </article>

        <article class="panel barcode-panel">
          <div class="panel-header">
            <h2>Barcode scanner</h2>
            <span class="hint">Enter a product code or scan with camera.</span>
          </div>
          <div class="barcode-fields">
            <label class="input-group">
              Code
              <input id="barcode-input" type="text" placeholder="1001 or scan now" />
            </label>
            <div class="scan-actions">
              <button id="scan-button" class="primary">Add by code</button>
              <button id="camera-button" class="tertiary" type="button">Scan with camera</button>
            </div>
          </div>
        </article>
      </div>

      <div class="layout-grid">
        <section class="panel products-panel">
          <div class="panel-header">
            <h2>Products</h2>
            <input id="product-search" type="search" placeholder="Search products or categories" />
          </div>
          <div id="product-list" class="product-list"></div>
        </section>

        <section class="panel cart-panel">
          <div class="panel-header">
            <h2>Cart</h2>
            <button id="clear-cart" class="danger">Clear cart</button>
          </div>
          <div id="cart-items" class="cart-items"></div>
          <div class="cart-summary">
            <div class="summary-row"><span>Subtotal</span><span id="subtotal">$0.00</span></div>
            <div class="summary-row"><span>Tax (0%)</span><span id="tax">$0.00</span></div>
            <div class="summary-total"><span>Total</span><span id="total">$0.00</span></div>
          </div>
          <div class="cart-actions">
            <button id="checkout-button" class="primary">Complete sale</button>
          </div>
        </section>
      </div>

      <section class="panel sales-history-panel">
        <div class="panel-header panel-header-row">
          <div class="panel-header-left">
            <h2><?php echo $user['role'] === 'admin' ? 'All Sales & Returns' : 'My Sales'; ?></h2>
          </div>
          <div class="panel-header-right">
            <label class="credit-toggle" title="Enable credit sale">
              <input type="checkbox" id="credit-enabled" />
              <span>Credit sale</span>
            </label>
            <div class="receipt-actions">
              <form method="post" action="sales.php">
                <input type="hidden" name="action" value="print_current_receipt" />
                <button type="submit" class="primary">Print Receipt</button>
              </form>
              <form method="post" action="sales.php">
                <input type="hidden" name="action" value="view_current_receipt" />
                <button type="submit" class="secondary">View</button>
              </form>
              <a href="receipts.php" class="secondary">Receipts</a>
            </div>
          </div>
        </div>
        <div id="credit-fields" class="credit-fields" style="display:none;">
          <label class="input-group">
            <span class="field-label">Customer</span>
            <input type="text" id="credit-customer-name" placeholder="Name" />
          </label>
          <label class="input-group">
            <span class="field-label">Phone</span>
            <input type="tel" id="credit-customer-phone" placeholder="Phone" />
          </label>
        </div>

        <?php if ($message): ?>
          <p class="login-hint text-success"><?php echo htmlspecialchars($message); ?></p>
        <?php endif; ?>
        <?php if ($error): ?>
          <p class="error-text"><?php echo htmlspecialchars($error); ?></p>
        <?php endif; ?>

      <div id="camera-preview" class="camera-preview hidden">
        <video id="barcode-video" autoplay muted playsinline></video>
        <button id="stop-camera-button" class="secondary" type="button">Stop scanner</button>
      </div>
    </main>
  </div>

  <!-- Duplicate product confirmation: shown when the same product is entered again within 1 minute -->
  <div id="duplicate-modal" class="modal-overlay hidden" role="dialog" aria-modal="true" aria-labelledby="duplicate-modal-title">
    <div class="modal">
      <h3 id="duplicate-modal-title">Already in this order</h3>
      <p><strong id="duplicate-product-name"></strong> was entered less than a minute ago. Are you sure you want to enter it again?</p>
      <p id="duplicate-cart-qty" class="small-text"></p>
      <div class="modal-actions">
        <button type="button" id="duplicate-cancel" class="secondary">No, cancel</button>
        <button type="button" id="duplicate-confirm" class="primary">Yes, add another</button>
      </div>
    </div>
  </div>

  <script>
    window.pageConfig = {
      products: <?php echo json_encode($products, JSON_HEX_TAG); ?>,
      user: <?php echo json_encode($user, JSON_HEX_TAG); ?>
    };
  </script>
<script>
    (function () {
      var banner = document.getElementById('deadline-banner');
      if (!banner) return;
      var deadline = parseInt(banner.getAttribute('data-deadline'), 10) * 1000;
      var countEl = document.getElementById('deadline-count');
      function formatRemaining(ms) {
        var total = Math.max(0, Math.floor(ms / 1000));
        var days = Math.floor(total / 86400);
        var hours = Math.floor((total % 86400) / 3600);
        var minutes = Math.floor((total % 3600) / 60);
        var seconds = total % 60;
        if (days > 0) return days + 'd ' + hours + 'h';
        if (hours > 0) return hours + 'h ' + minutes + 'm';
        if (minutes > 0) return minutes + 'm ' + seconds + 's';
        return seconds + 's';
      }

      function tick() {
        var diff = deadline - Date.now();
        if (diff <= 0) {
          countEl.textContent = 'expired';
          return;
        }
        countEl.textContent = formatRemaining(diff);
      }
      tick();
      setInterval(tick, 1000);
    })();
  </script>
  <script src="script.js?v=20260816"></script>
  <script src="nav.js"></script>
  <div id="toast-container" aria-live="polite" aria-atomic="false"></div>
  <footer class="app-footer">
    Designed by Ten12 Tech&copy;<br />
    &copy;2026<br />
    Contact: +233 55 850 4111
  </footer>
</body>
</html>
