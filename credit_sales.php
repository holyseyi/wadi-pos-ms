<?php
require_once __DIR__ . '/inc/functions.php';
require_login();

$user = current_user();
$posName = get_pos_name();
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'pay_credit') {
        $orderId = intval($_POST['order_id'] ?? 0);
        if ($orderId > 0 && mark_credit_paid($orderId)) {
            $message = 'Credit payment received. Order marked as paid.';
        } else {
            $error = 'Failed to process payment.';
        }
    }
}

$db = get_database();

// Fetch credit orders and their items
$sql = 'SELECT o.id, o.username, o.total, o.created_at, o.customer_name, o.customer_phone, o.credit_status,
               r.id as receipt_id
        FROM orders o
        LEFT JOIN receipts r ON r.order_id = o.id
        WHERE o.credit = 1';
$params = [];
if ($user['role'] !== 'admin') {
    $sql .= ' AND o.username = :username';
    $params[':username'] = $user['username'];
}
$sql .= ' ORDER BY o.created_at DESC';
$stmt = $db->prepare($sql);
$stmt->execute($params);
$creditOrders = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch all order items for these orders
$allCreditItems = [];
if (!empty($creditOrders)) {
    $orderIds = array_column($creditOrders, 'id');
    $in = str_repeat('?,', count($orderIds) - 1) . '?';
    $stmt = $db->prepare(
        "SELECT oi.id, oi.order_id, oi.product_id, oi.name, oi.price, oi.quantity, oi.subtotal
         FROM order_items oi
         WHERE oi.order_id IN ($in)"
    );
    $stmt->execute($orderIds);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($items as $item) {
        $allCreditItems[$item['order_id']][] = $item;
    }
}

// Group by order
$groupedCredits = [];
foreach ($creditOrders as $order) {
    $oid = $order['id'];
    $groupedCredits[$oid] = [
        'order_id' => $oid,
        'username' => $order['username'],
        'total' => $order['total'],
        'created_at' => $order['created_at'],
        'receipt_id' => $order['receipt_id'],
        'customer_name' => $order['customer_name'] ?? '',
        'customer_phone' => $order['customer_phone'] ?? '',
        'credit_status' => $order['credit_status'] ?? 'Pending',
        'items' => $allCreditItems[$oid] ?? []
    ];
}
usort($groupedCredits, fn($a, $b) => strtotime($b['created_at']) - strtotime($a['created_at']));
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title><?php echo htmlspecialchars($posName); ?> - Credit Sales</title>
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
      <h1 class="report-title">Credit Sales</h1>
      <div class="report-meta">
        <b>View:</b> <?php echo $user['role'] === 'admin' ? 'All Credit Transactions' : 'My Credit Sales'; ?><br>
        <b>Generated on:</b> <?php echo date('M j, Y g:i A'); ?>
      </div>
    </div>

    <?php if ($message): ?>
      <p class="login-hint text-success"><?php echo htmlspecialchars($message); ?></p>
    <?php endif; ?>
    <?php if ($error): ?>
      <p class="error-text"><?php echo htmlspecialchars($error); ?></p>
    <?php endif; ?>

    <?php
    $totalCredit = count($groupedCredits);
    $totalPending = 0;
    $totalPaid = 0;
    $totalValue = 0;
    foreach ($groupedCredits as $order) {
        $totalValue += $order['total'];
        if ($order['credit_status'] === 'Pending') {
            $totalPending++;
        } else {
            $totalPaid++;
        }
    }
    ?>
    <div class="stats-grid">
      <div class="stat-item">
        <div class="stat-label">Total Credit Sales</div>
        <div class="stat-value"><?php echo $totalCredit; ?></div>
      </div>
      <div class="stat-item">
        <div class="stat-label">Pending</div>
        <div class="stat-value"><?php echo $totalPending; ?></div>
      </div>
      <div class="stat-item">
        <div class="stat-label">Paid</div>
        <div class="stat-value"><?php echo $totalPaid; ?></div>
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

    <?php if (empty($groupedCredits)): ?>
      <p class="empty-message">No credit sales found.</p>
    <?php else: ?>
      <div class="returns-summary sales-list">
        <h3>Credit Sales Details</h3>
        <?php foreach ($groupedCredits as $order): ?>
          <div class="return-order <?php echo $order['credit_status'] === 'Paid' ? '' : 'pending-credit'; ?>">
            <div class="order-header">
              <div>
                <div class="order-id">
                  Order #<?php echo str_pad((string)$order['order_id'], 8, '0', STR_PAD_LEFT); ?>
                  <span class="credit-status <?php echo strtolower($order['credit_status']); ?>">
                    <?php echo htmlspecialchars($order['credit_status']); ?>
                  </span>
                </div>
                <div class="order-meta">
                  Sales Rep: <?php echo htmlspecialchars($order['username']); ?> |
                  Date: <?php echo htmlspecialchars(date('M j, Y g:i A', strtotime($order['created_at']))); ?>
                  <?php if ($order['credit_status'] === 'Pending'): ?>
                    <?php if (!empty($order['customer_name'])): ?>
                      | Customer: <?php echo htmlspecialchars($order['customer_name']); ?>
                    <?php endif; ?>
                    <?php if (!empty($order['customer_phone'])): ?>
                      | <?php echo htmlspecialchars($order['customer_phone']); ?>
                    <?php endif; ?>
                    <button type="button" class="tertiary" style="font-size:0.78rem;padding:6px 10px;margin-left:8px;"
                      onclick="openPayModal(<?php echo $order['order_id']; ?>, '<?php echo htmlspecialchars(addslashes($order['customer_name'])); ?>', '<?php echo htmlspecialchars(addslashes($order['customer_phone'])); ?>', <?php echo htmlspecialchars(number_format($order['total'], 2)); ?>)">
                      Pay
                    </button>
                  <?php else: ?>
                    <?php if (!empty($order['customer_name'])): ?>
                      | Customer: <?php echo htmlspecialchars($order['customer_name']); ?>
                    <?php endif; ?>
                    <?php if (!empty($order['customer_phone'])): ?>
                      | <?php echo htmlspecialchars($order['customer_phone']); ?>
                    <?php endif; ?>
                  <?php endif; ?>
                </div>
              </div>
              <div class="order-total active">
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
                  <div class="item-price">
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
  </div>

  <!-- Payment Modal -->
  <div id="credit-modal" class="modal-overlay hidden">
    <div class="modal">
      <h3>Pay Credit</h3>
      <form method="post" action="credit_sales.php">
        <input type="hidden" name="action" value="pay_credit" />
        <input type="hidden" name="order_id" id="modal-order-id" />
        <p><strong>Customer:</strong> <span id="modal-customer-name"></span></p>
        <p><strong>Phone:</strong> <span id="modal-customer-phone"></span></p>
        <p><strong>Amount Due:</strong> GH₵<span id="modal-amount"></span></p>
        <div class="modal-actions">
          <button type="submit" class="primary">Confirm Payment</button>
          <button type="button" class="secondary" onclick="closePayModal()">Cancel</button>
        </div>
      </form>
    </div>
  </div>

  <script>
    function openPayModal(orderId, customerName, customerPhone, amount) {
      document.getElementById('modal-order-id').value = orderId;
      document.getElementById('modal-customer-name').textContent = customerName;
      document.getElementById('modal-customer-phone').textContent = customerPhone;
      document.getElementById('modal-amount').textContent = amount;
      document.getElementById('credit-modal').classList.remove('hidden');
    }

    function closePayModal() {
      document.getElementById('credit-modal').classList.add('hidden');
    }

    document.getElementById('credit-modal').addEventListener('click', function(event) {
      if (event.target === this) {
        closePayModal();
      }
    });
  </script>
    </main>
  </div>
  <script src="nav.js"></script>
</body>
</html>
