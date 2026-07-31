<?php
require_once __DIR__ . '/inc/functions.php';
require_login();

$user = current_user();
$posName = get_pos_name();
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $user['role'] === 'admin') {
    $action = $_POST['action'] ?? '';
    if ($action === 'mark_credit_paid') {
        $orderId = intval($_POST['order_id'] ?? 0);
        if ($orderId > 0 && mark_credit_paid($orderId)) {
            $message = 'Credit order marked as paid.';
        } else {
            $error = 'Failed to update credit status.';
        }
    }
}

$allReceipts = get_all_receipts_with_status();

// Extract items from receipts
$allItems = [];
foreach ($allReceipts as $receipt) {
    $db = get_database();
    $stmt = $db->prepare(
        'SELECT oi.id, oi.order_id, oi.product_id, oi.name, oi.price, oi.quantity, oi.subtotal,
                o.username, o.total, o.created_at, r.return_status, r.id as receipt_id,
                o.credit, o.customer_name, o.customer_phone, o.credit_status
         FROM order_items oi
         JOIN orders o ON o.id = oi.order_id
         JOIN receipts r ON r.order_id = o.id
         WHERE r.id = :receipt_id'
    );
    $stmt->execute([':receipt_id' => $receipt['id']]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($items as $item) {
        $allItems[] = $item;
    }
}

// Group by order
$groupedSales = [];
foreach ($allItems as $item) {
    $orderId = $item['order_id'];
    if (!isset($groupedSales[$orderId])) {
        $groupedSales[$orderId] = [
            'order_id' => $orderId,
            'username' => $item['username'],
            'total' => $item['total'],
            'created_at' => $item['created_at'],
            'return_status' => $item['return_status'],
            'receipt_id' => $item['receipt_id'],
            'credit' => $item['credit'] ?? 0,
            'customer_name' => $item['customer_name'] ?? '',
            'customer_phone' => $item['customer_phone'] ?? '',
            'credit_status' => $item['credit_status'] ?? 'Paid',
            'items' => []
        ];
    }
    $groupedSales[$orderId]['items'][] = $item;
}

// Sort by date descending
usort($groupedSales, fn($a, $b) => strtotime($b['created_at']) - strtotime($a['created_at']));
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title><?php echo htmlspecialchars($posName); ?> - All Sales & Returns</title>
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
        <a href="returns.php" class="secondary">All sales</a>
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
      <h1 class="report-title">All Sales & Returns</h1>
      <div class="report-meta">
        <b>View:</b> All Sales Transactions<br>
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
        <div class="stat-label">Total Sales</div>
        <div class="stat-value"><?php echo $totalSales; ?></div>
      </div>
      <div class="stat-item">
        <div class="stat-label">Items Sold</div>
        <div class="stat-value"><?php echo $totalItems; ?></div>
      </div>
      <div class="stat-item">
        <div class="stat-label">Gross Value</div>
        <div class="stat-value">GH₵<?php echo number_format($totalValue, 2); ?></div>
      </div>
    </div>

    <div class="actions">
      <a href="sales.php" class="secondary">← Back to Sales Register</a>
      <button class="print-btn" onclick="window.print()">Print Report</button>
    </div>

    <?php if (empty($groupedSales)): ?>
      <p class="empty-message">No sales records found.</p>
    <?php else: ?>
      <div class="returns-summary sales-list">
        <h3>Sales Details</h3>
        <?php foreach ($groupedSales as $order): ?>
          <div class="return-order <?php echo $order['return_status'] === 'Returned' ? 'returned' : ''; ?>">
            <div class="order-header">
              <div>
                <div class="order-id <?php echo $order['return_status'] === 'Returned' ? 'returned' : ''; ?>">
                  Order #<?php echo str_pad((string)$order['order_id'], 8, '0', STR_PAD_LEFT); ?>
                  <span class="order-status <?php echo $order['return_status'] === 'Returned' ? 'returned' : 'active'; ?>">
                    (<?php echo $order['return_status']; ?>)
                  </span>
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
                    <?php if ($user['role'] === 'admin' && $order['credit_status'] === 'Pending'): ?>
                      <form method="post" action="returns.php" class="inline-form" style="margin-left:8px;">
                        <input type="hidden" name="action" value="mark_credit_paid" />
                        <input type="hidden" name="order_id" value="<?php echo $order['order_id']; ?>" />
                        <button type="submit" class="tertiary" style="font-size:0.78rem;padding:6px 10px;">Mark as Paid</button>
                      </form>
                    <?php endif; ?>
                  <?php endif; ?>
                </div>
              </div>
              <div class="order-total <?php echo $order['return_status'] === 'Returned' ? 'returned' : 'active'; ?>">
                GH₵<?php echo htmlspecialchars(number_format($order['total'], 2)); ?>
              </div>
            </div>
            <div class="order-items">
              <?php foreach ($order['items'] as $item): ?>
                <div class="item-row">
                  <div class="item-details">
                    <div class="item-name"><?php echo htmlspecialchars($item['name']); ?></div>
                    <div class="item-meta"><?php echo $item['quantity']; ?> unit(s) × GH₵<?php echo number_format($item['price'], 2); ?></div>
                  </div>
                  <div class="item-price <?php echo $order['return_status'] === 'Returned' ? 'returned' : ''; ?>">
                    GH₵<?php echo htmlspecialchars(number_format($item['subtotal'], 2)); ?>
                  </div>
                </div>
              <?php endforeach; ?>
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

</body>
</html>
