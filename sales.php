<?php
require_once __DIR__ . '/inc/functions.php';
require_login();

$user = current_user();
$posName = get_pos_name();
$products = load_products();
$message = '';
$error = '';

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
            } else {
                $error = 'Failed to update receipt status.';
            }
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
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title><?php echo htmlspecialchars($posName); ?> - Sales Register</title>
  <link rel="icon" type="image/svg+xml" href="images/pos-icon.svg" />
  <link rel="stylesheet" href="styles.css" />
</head>
<body data-page="sales">
  <div class="app-shell">
    <header class="header">
      <div class="brand">
        <img class="brand-icon" src="images/pos-icon.svg" alt="POS icon" />
        <div>
          <h1><?php echo htmlspecialchars($posName); ?></h1>
          <p class="subtitle">Secure sales register for your team.</p>
        </div>
      </div>

      <div class="header-actions">
        <a href="products_sold.php" class="secondary">View all products sold</a>
        <a href="returns.php" class="secondary">All sales</a>
        <a href="sales_report.php" class="secondary">Sales reports</a>
        <?php if ($user['role'] === 'admin'): ?>
          <a href="admin.php" class="secondary">Admin dashboard</a>
        <?php endif; ?>
        <a href="logout.php" class="tertiary">Logout</a>
      </div>
    </header>

    <main class="main-grid">
      <div class="dashboard-grid">
        <article class="panel overview-panel">
          <div class="panel-label">
            <span class="badge">Sales</span>
            <h2>Quick register</h2>
          </div>
          <p class="panel-copy">Scan barcodes, search inventory, and complete transactions quickly.</p>
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
            <span class="hint">Enter a product code or scan with a camera.</span>
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
          <p id="barcode-message" class="small-text">Camera scanning is supported in modern browsers.</p>
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
            <button id="clear-cart" class="secondary">Clear cart</button>
          </div>
          <div id="cart-items" class="cart-items"></div>
          <div class="cart-summary">
            <div class="summary-row"><span>Subtotal</span><span id="subtotal">$0.00</span></div>
            <div class="summary-row"><span>Tax (8%)</span><span id="tax">$0.00</span></div>
            <div class="summary-total"><span>Total</span><span id="total">$0.00</span></div>
          </div>
          <div class="cart-actions">
            <button id="checkout-button" class="primary">Complete sale</button>
          </div>
        </section>
      </div>

      <section class="panel sales-history-panel">
        <div class="panel-header">
          <h2><?php echo $user['role'] === 'admin' ? 'All Sales & Returns' : 'My Sales'; ?></h2>
          <span class="hint">Click receipt to view details or <?php echo $user['role'] === 'admin' ? 'manage returns' : 'delete sales'; ?></span>
        </div>

        <?php if ($message): ?>
          <p class="login-hint" style="color: green;"><?php echo htmlspecialchars($message); ?></p>
        <?php endif; ?>
        <?php if ($error): ?>
          <p class="error-text"><?php echo htmlspecialchars($error); ?></p>
        <?php endif; ?>

        <?php if (empty($userSales)): ?>
          <p class="empty-message">No sales or receipts available.</p>
        <?php else: ?>
          <div class="sales-history-list">
            <?php foreach ($userSales as $receipt): ?>
              <article class="sales-card">
                <div class="sales-info">
                  <div class="sales-id">Receipt #<?php echo htmlspecialchars(str_pad((string)$receipt['order_id'], 8, '0', STR_PAD_LEFT)); ?></div>
                  <div class="sales-meta">
                    By: <?php echo htmlspecialchars($receipt['username']); ?> | 
                    <?php echo htmlspecialchars(date('Y-m-d H:i', strtotime($receipt['created_at']))); ?>
                  </div>
                  <div class="sales-status">
                    Status: 
                    <?php if ($receipt['return_status'] === 'Returned'): ?>
                      <span style="color: #bf2d2d; font-weight: bold;">RETURNED</span>
                    <?php else: ?>
                      <span style="color: #2d8659; font-weight: bold;">ACTIVE</span>
                    <?php endif; ?>
                  </div>
                </div>
                <pre class="receipt-preview"><?php echo htmlspecialchars($receipt['receipt_content']); ?></pre>
                <div class="sales-actions">
                  <a href="print_receipt.php?id=<?php echo $receipt['id']; ?>" class="tertiary" target="_blank">Print</a>
                  <form method="post" action="sales.php" style="display:inline;">
                    <input type="hidden" name="action" value="update_return_status" />
                    <input type="hidden" name="receipt_id" value="<?php echo $receipt['id']; ?>" />
                    <select name="return_status" class="status-select" onchange="this.form.submit()">
                      <option value="Active" <?php echo $receipt['return_status'] === 'Active' ? 'selected' : ''; ?>>Active</option>
                      <option value="Returned" <?php echo $receipt['return_status'] === 'Returned' ? 'selected' : ''; ?>>Mark as Returned</option>
                    </select>
                  </form>
                  <form method="post" action="sales.php" style="display:inline;">
                    <input type="hidden" name="action" value="delete_sale" />
                    <input type="hidden" name="sale_id" value="<?php echo $receipt['order_id']; ?>" />
                    <input type="hidden" name="receipt_id" value="<?php echo $receipt['id']; ?>" />
                    <button class="secondary" type="submit" onclick="return confirm('Delete this sale?');">Delete</button>
                  </form>
                </div>
              </article>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </section>

      <div id="camera-preview" class="camera-preview hidden">
        <video id="barcode-video" autoplay muted playsinline></video>
        <button id="stop-camera-button" class="secondary" type="button">Stop scanner</button>
      </div>
    </main>
  </div>

  <script>
    window.pageConfig = {
      products: <?php echo json_encode($products, JSON_HEX_TAG); ?>,
      user: <?php echo json_encode($user, JSON_HEX_TAG); ?>
    };
  </script>
  <script src="script.js"></script>
</body>
</html>
