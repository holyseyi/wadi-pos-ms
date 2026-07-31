<?php
require_once __DIR__ . '/inc/functions.php';
require_login();

$user = current_user();
$posName = get_pos_name();
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $user['role'] === 'admin') {
    $action = $_POST['action'] ?? '';
    if ($action === 'process_return') {
        $orderId = intval($_POST['order_id'] ?? 0);
        $productId = intval($_POST['product_id'] ?? 0);
        $quantity = intval($_POST['quantity'] ?? 0);
        $reason = trim($_POST['reason'] ?? '');
        
        if ($orderId > 0 && $productId > 0 && $quantity > 0) {
            if (process_return($orderId, $productId, $quantity, $reason, $user['username'])) {
                $message = 'Return processed successfully. Stock restored.';
            } else {
                $error = 'Failed to process return.';
            }
        } else {
            $error = 'Invalid return data.';
        }
    }
}

$returnedReceipts = get_returned_receipts();

$orderIds = array_unique(array_column($returnedReceipts, 'order_id'));

$groupedSales = [];
foreach ($orderIds as $orderId) {
    $db = get_database();
    $stmt = $db->prepare(
        'SELECT oi.id, oi.order_id, oi.product_id, oi.name, oi.price, oi.quantity, oi.subtotal,
                o.username, o.total, o.created_at, o.credit, o.customer_name, o.customer_phone, o.credit_status,
                r.return_status, r.id as receipt_id
         FROM order_items oi
         JOIN orders o ON o.id = oi.order_id
         JOIN receipts r ON r.order_id = o.id
         WHERE r.return_status = "Returned" AND o.id = :order_id'
    );
    $stmt->execute([':order_id' => $orderId]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (!empty($items)) {
        $groupedSales[$orderId] = [
            'order_id' => $orderId,
            'username' => $items[0]['username'],
            'total' => $items[0]['total'],
            'created_at' => $items[0]['created_at'],
            'return_status' => 'Returned',
            'receipt_id' => $items[0]['receipt_id'],
            'credit' => $items[0]['credit'] ?? 0,
            'customer_name' => $items[0]['customer_name'] ?? '',
            'customer_phone' => $items[0]['customer_phone'] ?? '',
            'credit_status' => $items[0]['credit_status'] ?? 'Paid',
            'items' => $items
        ];
        $groupedSales[$orderId]['return_summary'] = get_order_return_summary($orderId);
        $groupedSales[$orderId]['return_history'] = get_returns_for_order($orderId);
    }
}

usort($groupedSales, fn($a, $b) => strtotime($b['created_at']) - strtotime($a['created_at']));
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title><?php echo htmlspecialchars($posName); ?> - Returned Products</title>
  <link rel="stylesheet" href="styles.css" />
  <link rel="icon" type="image/svg+xml" href="<?php echo htmlspecialchars(get_trademark_src()); ?>" />
</head>
<body>
  <div class="app-shell">
    <header class="header">
      <div class="brand">
        <img class="brand-icon" src="<?php echo htmlspecialchars(get_trademark_src()); ?>" alt="Trademark" />
        <div>
          <h1><?php echo htmlspecialchars($posName); ?></h1>
          <p class="subtitle">Secure sales register for your team.</p>
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

    <main class="main-grid">
      <div class="report-container returns-container">
    <div class="report-header">
      <h1 class="report-title">Returned Products</h1>
      <div class="report-meta">
        <b>View:</b> Returned Sales Transactions<br>
        <b>Generated on:</b> <?php echo date('M j, Y g:i A'); ?><br>
        <?php if ($user['role'] === 'admin'): ?>
          <b>All Sales Representatives</b>
        <?php else: ?>
          <b>Sales Rep:</b> <?php echo htmlspecialchars($user['username']); ?>
        <?php endif; ?>
      </div>
    </div>

    <?php if ($message): ?>
      <p class="login-hint text-success"><?php echo htmlspecialchars($message); ?></p>
    <?php endif; ?>
    <?php if ($error): ?>
      <p class="error-text"><?php echo htmlspecialchars($error); ?></p>
    <?php endif; ?>

    <?php
    $totalSales = count($groupedSales);
    $totalItems = 0;
    $totalValue = 0;
    foreach ($groupedSales as $order) {
        $totalItems += count($order['items']);
        $totalValue += $order['total'];
    }
    ?>
    <div class="stats-grid">
      <div class="stat-item">
        <div class="stat-label">Returned Orders</div>
        <div class="stat-value"><?php echo $totalSales; ?></div>
      </div>
      <div class="stat-item">
        <div class="stat-label">Items Returned</div>
        <div class="stat-value"><?php echo $totalItems; ?></div>
      </div>
      <div class="stat-item">
        <div class="stat-label">Total Value</div>
        <div class="stat-value">GH₵<?php echo number_format($totalValue, 2); ?></div>
      </div>
    </div>

    <div class="actions">
      <a href="sales.php" class="secondary">← Back to Sales Register</a>
      <button class="print-btn" onclick="window.print()">Print Report</button>
    </div>

    <?php if (empty($groupedSales)): ?>
      <p class="empty-message">No returned products found.</p>
    <?php else: ?>
      <div class="returns-summary sales-list">
        <h3>Returned Orders</h3>
        <?php foreach ($groupedSales as $order): ?>
          <div class="return-order returned">
            <div class="order-header">
              <div>
                <div class="order-id returned">
                  Order #<?php echo str_pad((string)$order['order_id'], 8, '0', STR_PAD_LEFT); ?>
                  <span class="order-status returned">(Returned)</span>
                </div>
                <div class="order-meta">
                  Sales Rep: <?php echo htmlspecialchars($order['username']); ?> |
                  Date: <?php echo htmlspecialchars(date('M j, Y g:i A', strtotime($order['created_at']))); ?>
                  <?php if ($order['credit']): ?>
                    | <span class="credit-badge">Credit Sale</span>
                    <span class="credit-status <?php echo strtolower($order['credit_status']); ?>"><?php echo htmlspecialchars($order['credit_status']); ?></span>
                    <?php if (!empty($order['customer_name'])): ?>
                      | Customer: <?php echo htmlspecialchars($order['customer_name']); ?>
                    <?php endif; ?>
                    <?php if (!empty($order['customer_phone'])): ?>
                      | <?php echo htmlspecialchars($order['customer_phone']); ?>
                    <?php endif; ?>
                  <?php endif; ?>
                </div>
              </div>
              <div class="order-total returned">
                GH₵<?php echo htmlspecialchars(number_format($order['total'], 2)); ?>
              </div>
            </div>
            <div class="order-items">
              <?php 
              $returnSummary = $order['return_summary'] ?? null;
              $returnHistory = $order['return_history'] ?? [];
              if ($returnSummary && $returnSummary['total_returned'] > 0): ?>
              <div class="return-summary-banner" style="background:#fef3c7;color:#92400e;padding:8px 12px;border-radius:12px;margin-bottom:12px;font-size:0.85rem;font-weight:600;">
                <?php echo $returnSummary['total_returned']; ?> of <?php echo $returnSummary['total_items']; ?> items returned
                <?php if ($returnSummary['fully_returned']): ?> — Fully Returned<?php endif; ?>
              </div>
              <?php endif; ?>
              <?php foreach ($order['items'] as $item): 
                $returnedQty = 0;
                foreach ($returnHistory as $ret) {
                  if ($ret['product_id'] == $item['product_id']) {
                    $returnedQty += $ret['quantity'];
                  }
                }
              ?>
                <div class="item-row" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;">
                  <div class="item-details">
                    <div class="item-name"><?php echo htmlspecialchars($item['name']); ?></div>
                    <div class="item-meta"><?php echo $item['quantity']; ?> unit(s) × GH₵<?php echo number_format($item['price'], 2); ?></div>
                    <?php if ($returnedQty > 0): ?>
                      <div style="color:#d97706;font-size:0.8rem;font-weight:600;">Returned: <?php echo $returnedQty; ?> unit(s)</div>
                    <?php endif; ?>
                  </div>
                  <div class="item-price returned">
                    GH₵<?php echo htmlspecialchars(number_format($item['subtotal'], 2)); ?>
                  </div>
                </div>
              <?php endforeach; ?>
              <?php if (!empty($returnHistory)): ?>
                <div class="return-history" style="margin-top:12px;padding-top:10px;border-top:1px solid #e2e8f0;">
                  <div style="font-weight:700;color:#0f172a;margin-bottom:8px;font-size:0.85rem;">Return History</div>
                  <?php foreach ($returnHistory as $ret): ?>
                    <div style="font-size:0.8rem;color:#475569;padding:4px 0;">
                      <?php echo htmlspecialchars($ret['quantity']); ?>x <?php echo htmlspecialchars($ret['product_name']); ?> 
                      (<?php echo htmlspecialchars($ret['product_code']); ?>) — 
                      <?php echo htmlspecialchars(date('M j, Y g:i A', strtotime($ret['created_at']))); ?>
                      <?php if ($ret['reason']): ?> — "<?php echo htmlspecialchars($ret['reason']); ?>"<?php endif; ?>
                    </div>
                  <?php endforeach; ?>
                </div>
              <?php endif; ?>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
          <div class="actions">
      <a href="sales.php" class="secondary">← Back to Sales Register</a>
      <button class="print-btn" onclick="window.print()">Print Report</button>
    </div>
    </main>
  </div>
  <script src="nav.js"></script>
</body>
</html>
