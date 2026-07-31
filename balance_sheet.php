<?php
require_once __DIR__ . '/inc/functions.php';
require_login();

$user = current_user();
$posName = get_pos_name();
$db = get_database();

// Get period from URL parameter
$period = $_GET['period'] ?? 'day';
$periods = ['day' => 'Today', 'yesterday' => 'Yesterday', 'week' => 'This Week', 'month' => 'This Month', 'year' => 'This Year', 'all' => 'All Time', 'custom' => 'Custom Range'];

if (!isset($periods[$period])) {
    $period = 'day';
}

// Handle custom date range
$customStart = $_GET['start'] ?? null;
$customEnd = $_GET['end'] ?? null;
$useCustomRange = false;

if ($period === 'custom' && $customStart && $customEnd) {
    try {
        $startDate = new DateTime($customStart);
        $endDate = new DateTime($customEnd);
        if ($startDate > $endDate) {
            $useCustomRange = false;
        } else {
            $useCustomRange = true;
        }
    } catch (Exception $e) {
        $useCustomRange = false;
    }
}

if (!$useCustomRange) {
    $now = new DateTime();
    $startDate = clone $now;
    $endDate = clone $now;

    switch ($period) {
        case 'day':
            $startDate->setTime(0, 0, 0);
            $endDate->setTime(23, 59, 59);
            break;
        case 'yesterday':
            $startDate->modify('yesterday')->setTime(0, 0, 0);
            $endDate->modify('yesterday')->setTime(23, 59, 59);
            break;
        case 'week':
            $startDate->modify('monday this week')->setTime(0, 0, 0);
            $endDate->modify('sunday this week')->setTime(23, 59, 59);
            break;
        case 'month':
            $startDate->modify('first day of this month')->setTime(0, 0, 0);
            $endDate->modify('last day of this month')->setTime(23, 59, 59);
            break;
        case 'year':
            $startDate->modify('first day of January this year')->setTime(0, 0, 0);
            $endDate->modify('last day of December this year')->setTime(23, 59, 59);
            break;
        case 'all':
            $startDate = null;
            $endDate = null;
            break;
    }
}

$startDateStr = $startDate ? $startDate->format('Y-m-d H:i:s') : '';
$endDateStr = $endDate ? $endDate->format('Y-m-d H:i:s') : '';

// Get stock movements
$stockMovements = get_stock_movements([
    'start_date' => $startDateStr,
    'end_date' => $endDateStr,
]);

// Filter stock movements by user if not admin
if ($user['role'] !== 'admin') {
    $userOrderIdsStmt = $db->prepare('SELECT id FROM orders WHERE username = :username');
    $userOrderIdsStmt->execute([':username' => $user['username']]);
    $userOrderIds = array_column($userOrderIdsStmt->fetchAll(PDO::FETCH_ASSOC), 'id');
    $stockMovements = array_filter($stockMovements, function($m) use ($userOrderIds) {
        return $m['reference_type'] !== 'order' || in_array($m['reference_id'], $userOrderIds);
    });
}

// Get sales data for the period
if ($period === 'all') {
    $query = 'SELECT oi.id, oi.order_id, oi.product_id, oi.name, oi.price, oi.quantity, oi.subtotal,
                     o.username, o.total, o.created_at, o.status,
                     r.return_status, r.receipt_content
              FROM order_items oi
              JOIN orders o ON o.id = oi.order_id
              LEFT JOIN receipts r ON r.order_id = o.id';
} else {
    $query = 'SELECT oi.id, oi.order_id, oi.product_id, oi.name, oi.price, oi.quantity, oi.subtotal,
                     o.username, o.total, o.created_at, o.status,
                     r.return_status, r.receipt_content
              FROM order_items oi
              JOIN orders o ON o.id = oi.order_id
              LEFT JOIN receipts r ON r.order_id = o.id
              WHERE o.created_at >= :start_date AND o.created_at <= :end_date';
}

if ($user['role'] !== 'admin') {
    $query .= ($period === 'all' ? ' WHERE ' : ' AND ') . 'o.username = :username';
}

$query .= ' ORDER BY o.created_at DESC';

$stmt = $db->prepare($query);

if ($period !== 'all') {
    $stmt->bindValue(':start_date', $startDateStr);
    $stmt->bindValue(':end_date', $endDateStr);
}

if ($user['role'] !== 'admin') {
    $stmt->bindValue(':username', $user['username']);
}

$stmt->execute();
$salesData = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Group by order
$groupedSales = [];
foreach ($salesData as $item) {
    $orderId = $item['order_id'];
    if (!isset($groupedSales[$orderId])) {
        $groupedSales[$orderId] = [
            'order_id' => $orderId,
            'username' => $item['username'],
            'total' => $item['total'],
            'created_at' => $item['created_at'],
            'status' => $item['status'],
            'return_status' => $item['return_status'],
            'receipt_content' => $item['receipt_content'],
            'items' => []
        ];
    }
    $groupedSales[$orderId]['items'][] = $item;
}

// Calculate totals
$totalSales = count($groupedSales);
$totalRevenue = 0;
$totalItems = 0;
$returnedSales = 0;
$returnedRevenue = 0;
$returnedItems = 0;

foreach ($groupedSales as $order) {
    $totalRevenue += $order['total'];
    $totalItems += count($order['items']);
    if ($order['return_status'] === 'Returned') {
        $returnedSales++;
        $returnedRevenue += $order['total'];
        $returnedItems += count($order['items']);
    }
}

$netRevenue = $totalRevenue - $returnedRevenue;

// Get inventory status
$inventory = $db->query('SELECT * FROM products ORDER BY name')->fetchAll(PDO::FETCH_ASSOC);

// Get credit sales breakdown
$creditQuery = 'SELECT * FROM orders WHERE credit = 1';
if ($user['role'] !== 'admin') {
    $creditQuery .= ' AND username = :username';
}
$creditQuery .= ' ORDER BY created_at DESC';
$creditStmt = $db->prepare($creditQuery);
if ($user['role'] !== 'admin') {
    $creditStmt->execute([':username' => $user['username']]);
} else {
    $creditStmt->execute();
}
$creditSales = $creditStmt->fetchAll(PDO::FETCH_ASSOC);

$totalCredit = 0;
$pendingCredit = 0;
$paidCredit = 0;
foreach ($creditSales as $cs) {
    $totalCredit++;
    if ($cs['credit_status'] === 'Pending') {
        $pendingCredit++;
    } else {
        $paidCredit++;
    }
}

// Stock movement summary
$stockIn = array_sum(array_map(fn($m) => $m['movement_type'] === 'in' ? $m['quantity'] : 0, $stockMovements));
$stockOut = array_sum(array_map(fn($m) => $m['movement_type'] === 'out' ? $m['quantity'] : 0, $stockMovements));
$currentInventoryValue = array_sum(array_map(fn($p) => $p['quantity'] * $p['price'], $inventory));
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title><?php echo htmlspecialchars($posName); ?> - Balance Sheet - <?php echo $periods[$period]; ?></title>
  <link rel="icon" type="image/svg+xml" href="<?php echo htmlspecialchars(get_trademark_src()); ?>" />
  <link rel="stylesheet" href="styles.css" />
  <style>
    .balance-sheet-container {
      max-width: 1200px;
      margin: 0 auto;
      padding: 24px;
      font-size: 12px;
    }
    .back-link {
      display: inline-block;
      margin-bottom: 20px;
      color: #4e5b7f;
      text-decoration: none;
      font-weight: 500;
      font-size: 12px;
    }
    .back-link:hover {
      color: #182033;
    }
    h1 {
      font-size: 14px;
      margin: 0 0 8px 0;
    }
    .period-selector {
      display: flex;
      gap: 8px;
      margin-bottom: 20px;
      flex-wrap: wrap;
    }
    .period-selector a {
      display: inline-block;
      padding: 8px 16px;
      text-decoration: none;
      color: #334155;
      border: 1px solid #e2e8f0;
      border-radius: 12px;
      background: #ffffff;
      font-weight: 600;
      font-size: 12px;
    }
    .period-selector a.active {
      background: #0f172a;
      color: #ffffff;
      border-color: #0f172a;
    }
    .stats-grid {
      display: flex;
      flex-wrap: wrap;
      gap: 6px;
      margin-bottom: 6px;
    }
    .stat-item {
      background: #ffffff;
      border: 1px solid #e2e8f0;
      border-radius: 8px;
      padding: 6px 10px;
      box-shadow: 0 1px 3px rgba(0,0,0,0.04);
      flex: 1 1 120px;
      min-width: 100px;
    }
    .stat-label {
      font-size: 10px;
      color: #64748b;
      text-transform: uppercase;
      letter-spacing: 0.06em;
      font-weight: 600;
    }
    .stat-value {
      margin-top: 2px;
    }
    .stat-value.positive {
      color: #059669;
    }
    .stat-value.pending {
      color: #d97706;
    }
    .stat-value.negative {
      color: #dc2626;
    }
    .section-card {
      background: #ffffff;
      border: 1px solid #e2e8f0;
      border-radius: 20px;
      padding: 20px;
      margin-bottom: 20px;
      box-shadow: 0 1px 3px rgba(0,0,0,0.04);
      height: 600px;
      overflow-y: auto;
    }
    .section-card h3 {
      margin-top: 0;
      margin-bottom: 16px;
      color: #0f172a;
      font-size: 12px;
    }
    .ledger-table {
      width: 100%;
      border-collapse: collapse;
      font-size: 12px;
    }
    .ledger-table th,
    .ledger-table td {
      text-align: left;
      padding: 10px 12px;
      border-bottom: 1px solid #e2e8f0;
      font-size: 12px;
    }
    .ledger-table th {
      background: #f8fafc;
      color: #475569;
      font-weight: 600;
      text-transform: uppercase;
      letter-spacing: 0.04em;
      font-size: 12px;
    }
    .ledger-table tr:last-child td {
      border-bottom: none;
    }
    .badge {
      display: inline-block;
      padding: 3px 10px;
      border-radius: 999px;
      font-size: 12px;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 0.04em;
    }
    .badge.credit {
      background: #fef3c7;
      color: #92400e;
    }
    .badge.pending {
      background: #fef3c7;
      color: #92400e;
    }
    .badge.paid {
      background: #dcfce7;
      color: #166534;
    }
    .badge.in {
      background: #dbeafe;
      color: #1e40af;
    }
    .badge.out {
      background: #fee2e2;
      color: #991b1b;
    }
    .empty-message {
      color: #64748b;
      text-align: center;
      padding: 24px;
      font-size: 12px;
    }
    .actions {
      margin-top: 24px;
      display: flex;
      gap: 12px;
      flex-wrap: wrap;
      justify-content: flex-end;
    }
    .actions a,
    .actions button {
      padding: 10px 18px;
      border-radius: 12px;
      border: 1px solid #e2e8f0;
      background: #ffffff;
      color: #0f172a;
      text-decoration: none;
      cursor: pointer;
      font-weight: 600;
      font-size: 12px;
    }
    .actions button {
      background: #0f172a;
      color: #ffffff;
      border-color: #0f172a;
    }
    .meta-info {
      color: #475569;
      margin-bottom: 6px;
      font-size: 12px;
    }
    @media (max-width: 960px) {
      .ledger-table {
        display: block;
        overflow-x: auto;
      }
    }
  </style>
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
      <div class="balance-sheet-container">
    <a href="sales.php" class="back-link">← Back to Sales Register</a>
    <h1>Balance Sheet</h1>
    <p class="meta-info">Period: <?php echo $periods[$period]; ?></p>
    <?php if ($useCustomRange): ?>
      <p class="meta-info"><?php echo $startDate->format('M j, Y g:i A'); ?> - <?php echo $endDate->format('M j, Y g:i A'); ?></p>
    <?php elseif ($period !== 'all'): ?>
      <p class="meta-info"><?php echo $startDate->format('M j, Y'); ?> - <?php echo $endDate->format('M j, Y'); ?></p>
    <?php endif; ?>
    <p class="meta-info">Generated on: <?php echo date('M j, Y g:i A'); ?></p>
    <p class="meta-info"><?php echo $user['role'] === 'admin' ? 'All Users' : 'User: ' . htmlspecialchars($user['username']); ?></p>

    <div class="period-selector">
      <a href="?period=day" class="<?php echo $period === 'day' ? 'active' : ''; ?>">Today</a>
      <a href="?period=yesterday" class="<?php echo $period === 'yesterday' ? 'active' : ''; ?>">Yesterday</a>
      <a href="?period=week" class="<?php echo $period === 'week' ? 'active' : ''; ?>">This Week</a>
      <a href="?period=month" class="<?php echo $period === 'month' ? 'active' : ''; ?>">This Month</a>
      <a href="?period=year" class="<?php echo $period === 'year' ? 'active' : ''; ?>">This Year</a>
      <a href="?period=all" class="<?php echo $period === 'all' ? 'active' : ''; ?>">All Time</a>
      <a href="?period=custom" class="<?php echo $period === 'custom' ? 'active' : ''; ?>">Custom</a>
    </div>

    <?php if ($period === 'custom'): ?>
    <form method="get" action="balance_sheet.php" class="custom-range-form" style="display:inline-flex;gap:8px;align-items:center;flex-wrap:wrap;margin-bottom:16px;">
      <input type="hidden" name="period" value="custom" />
      <label style="font-size:0.85rem;color:#475569;">From:
        <input type="datetime-local" name="start" value="<?php echo htmlspecialchars($customStart ?? ''); ?>" required />
      </label>
      <label style="font-size:0.85rem;color:#475569;">To:
        <input type="datetime-local" name="end" value="<?php echo htmlspecialchars($customEnd ?? ''); ?>" required />
      </label>
      <button type="submit" class="primary" style="padding:6px 12px;font-size:0.85rem;">Apply</button>
      <a href="balance_sheet.php?period=day" class="secondary" style="padding:6px 12px;font-size:0.85rem;">Clear</a>
    </form>
    <?php endif; ?>

    <!-- Summary Stats -->
    <div class="stats-grid">
      <div class="stat-item">
        <div class="stat-label">Total Sales</div>
        <div class="stat-value"><?php echo $totalSales; ?></div>
      </div>
      <div class="stat-item">
        <div class="stat-label">Gross Revenue</div>
        <div class="stat-value positive">GH₵<?php echo number_format($totalRevenue, 2); ?></div>
      </div>
      <div class="stat-item">
        <div class="stat-label">Net Revenue</div>
        <div class="stat-value">GH₵<?php echo number_format($netRevenue, 2); ?></div>
      </div>
      <div class="stat-item">
        <div class="stat-label">Returns</div>
        <div class="stat-value negative"><?php echo $returnedSales; ?> (GH₵<?php echo number_format($returnedRevenue, 2); ?>)</div>
      </div>
      <div class="stat-item">
        <div class="stat-label">Pending Credit</div>
        <div class="stat-value pending"><?php echo $pendingCredit; ?></div>
      </div>
      <div class="stat-item">
        <div class="stat-label">Inventory Value</div>
        <div class="stat-value">GH₵<?php echo number_format($currentInventoryValue, 2); ?></div>
      </div>
    </div>

    <!-- Stock Movements Ledger -->
    <div class="section-card">
      <h3>Stock Movements Ledger</h3>
      <?php if (empty($stockMovements)): ?>
        <p class="empty-message">No stock movements recorded.</p>
      <?php else: ?>
        <table class="ledger-table">
          <thead>
            <tr>
              <th>Date/Time</th>
              <th>Product</th>
              <th>Code</th>
              <th>Type</th>
              <th>Quantity</th>
              <th>Reference</th>
              <th>Notes</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($stockMovements as $movement): ?>
              <tr>
                <td><?php echo htmlspecialchars(date('M j, Y g:i A', strtotime($movement['created_at']))); ?></td>
                <td><?php echo htmlspecialchars($movement['product_name']); ?></td>
                <td><?php echo htmlspecialchars($movement['product_code']); ?></td>
                <td>
                  <span class="badge <?php echo $movement['movement_type']; ?>">
                    <?php echo htmlspecialchars($movement['movement_type'] === 'in' ? 'IN' : 'OUT'); ?>
                  </span>
                </td>
                <td><?php echo htmlspecialchars($movement['quantity']); ?></td>
                <td>
                  <?php echo htmlspecialchars($movement['reference_type']); ?>
                  <?php if ($movement['reference_id']): ?>
                    #<?php echo htmlspecialchars($movement['reference_id']); ?>
                  <?php endif; ?>
                </td>
                <td><?php echo htmlspecialchars($movement['notes'] ?? ''); ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>

    <!-- Current Inventory -->
    <div class="section-card">
      <h3>Current Inventory</h3>
      <?php if (empty($inventory)): ?>
        <p class="empty-message">No products in inventory.</p>
      <?php else: ?>
        <table class="ledger-table">
          <thead>
            <tr>
              <th>Product</th>
              <th>Code</th>
              <th>Category</th>
              <th>Price</th>
              <th>In Stock</th>
              <th>Value</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($inventory as $product): ?>
              <tr>
                <td style="font-weight:600;"><?php echo htmlspecialchars($product['name']); ?></td>
                <td><?php echo htmlspecialchars($product['code']); ?></td>
                <td><?php echo htmlspecialchars($product['category']); ?></td>
                <td>GH₵<?php echo htmlspecialchars(number_format($product['price'], 2)); ?></td>
                <td>
                  <?php if ($product['quantity'] <= 0): ?>
                    <span style="color:#dc2626;font-weight:700;">Out of stock</span>
                  <?php else: ?>
                    <span style="color:#059669;font-weight:700;"><?php echo htmlspecialchars($product['quantity']); ?></span>
                  <?php endif; ?>
                </td>
                <td>GH₵<?php echo htmlspecialchars(number_format($product['quantity'] * $product['price'], 2)); ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>

    <!-- Credit Sales -->
    <div class="section-card">
      <h3>Credit Sales</h3>
      <?php if (empty($creditSales)): ?>
        <p class="empty-message">No credit sales recorded.</p>
      <?php else: ?>
        <table class="ledger-table">
          <thead>
            <tr>
              <th>Order</th>
              <th>Customer</th>
              <th>Phone</th>
              <th>Total</th>
              <th>Status</th>
              <th>Date</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($creditSales as $cs): ?>
              <tr>
                <td>#<?php echo str_pad((string)$cs['id'], 8, '0', STR_PAD_LEFT); ?></td>
                <td><?php echo htmlspecialchars($cs['customer_name'] ?? 'N/A'); ?></td>
                <td><?php echo htmlspecialchars($cs['customer_phone'] ?? 'N/A'); ?></td>
                <td>GH₵<?php echo htmlspecialchars(number_format($cs['total'], 2)); ?></td>
                <td>
                  <span class="badge <?php echo strtolower($cs['credit_status']); ?>">
                    <?php echo htmlspecialchars($cs['credit_status']); ?>
                  </span>
                </td>
                <td><?php echo htmlspecialchars(date('M j, Y g:i A', strtotime($cs['created_at']))); ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>

    <div class="actions">
      <a href="sales.php" class="secondary">Sales Register</a>
      <a href="credit_sales.php" class="secondary">Credit Sales</a>
      <button onclick="window.print()">Print Balance Sheet</button>
    </div>
    </main>
  </div>
  <script src="nav.js"></script>
</body>
</html>
