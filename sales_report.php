<?php
require_once __DIR__ . '/inc/functions.php';
require_login();

$user = current_user();
$posName = get_pos_name();

// Get period from URL parameter
$period = $_GET['period'] ?? 'day';
$periods = ['day' => 'Today', 'yesterday' => 'Yesterday', 'week' => 'This Week', 'month' => 'This Month', 'all' => 'All Time'];

if (!isset($periods[$period])) {
    $period = 'day';
}

// Calculate date range based on period
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
    case 'all':
        // No date restrictions - all time
        $startDate = null;
        $endDate = null;
        break;
}



// Get sales data for the period
$db = get_database();

if ($period === 'all') {
    // No date filter for All Time
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
    // For the "all" period there is no WHERE yet, so use WHERE instead of AND
    // to avoid the condition being absorbed into the LEFT JOIN's ON clause.
    $query .= ($period === 'all' ? ' WHERE ' : ' AND ') . 'o.username = :username';
}

$query .= ' ORDER BY o.created_at DESC';

$stmt = $db->prepare($query);


if ($period !== 'all') {
    $stmt->bindValue(':start_date', $startDate->format('c'));
    $stmt->bindValue(':end_date', $endDate->format('c'));
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

foreach ($groupedSales as $order) {
    $totalRevenue += $order['total'];
    $totalItems += count($order['items']);
    if ($order['return_status'] === 'Returned') {
        $returnedSales++;
        $returnedRevenue += $order['total'];
    }
}

$netRevenue = $totalRevenue - $returnedRevenue;
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title><?php echo htmlspecialchars($posName); ?> - Sales Report - <?php echo $periods[$period]; ?></title>
  <link rel="icon" type="image/svg+xml" href="<?php echo htmlspecialchars(get_trademark_src()); ?>" />
  <style>
    * {
      box-sizing: border-box;
    }
    body {
      font-family: 'Courier New', monospace;
      margin: 0;
      padding: 20px;
      background: #f5f5f5;
      color: #333;center
    }
    .report-container {
      max-width: 800px;
      margin: 0 auto;
      background: white;
      padding: 30px;
      border-radius: 8px;
      box-shadow: 0 2px 10px rgba(0,0,0,0.1);
    }
    .report-header {
      text-align: center;
      border-bottom: 2px solid #333;
      padding-bottom: 20px;
      margin-bottom: 30px;
    }
    .report-title {
      font-size: 24px;
      font-weight: bold;
      margin: 0;
    }
    .report-meta {
      margin: 10px 0;
      color: #666;
      text-align:justify;
    }
    .stats-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
      background: #f8f9fa;
      border-radius: 8px;
    }
    .stat-item {
      text-align: center;
    }
    .stat-label {
      font-size: 12px;
      color: #666;
      text-transform: uppercase;
      letter-spacing: 1px;
    }
    .stat-value {
      font-size: 16px;
      font-weight: bold;
      color: #2d8659;
      margin-top: 5px;
    }
    .stat-value.negative {
      color: #bf2d2d;
    }
    .sales-list {
      margin-top: 30px;
    }
    .sale-item {
      border: 1px solid #ddd;
      border-radius: 8px;
      padding: 15px;
      margin-bottom: 15px;
      background: #fafafa;
    }
    .sale-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 10px;
    }
    .sale-id {
      font-weight: bold;
      font-size: 13px;
    }
    .sale-meta {
      font-size: 12px;
      color: #666;
    }
    .sale-total {
      font-size: 15px;
      font-weight: bold;
      color: #2d8659;
    }
    .sale-total.returned {
      color: #bf2d2d;
    }
    .sale-items {
      margin-top: 10px;
    }
    .item-row {
      display: flex;
      justify-content: space-between;
      padding: 5px 0;
      border-bottom: 1px solid #eee;
    }
    .item-row:last-child {
      border-bottom: none;
    }
    .item-name {
      font-weight: 500;
      font-size: 12px;
    }
    .item-price {
      font-weight: bold;
      font-size: 12px;
    }
    .actions {
      text-align: end;
      margin-top: 30px;
      padding-top: 20px;
      border-top: 1px solid #ddd;
    }
    button {
      padding: 10px 20px;
      border: 1px solid #ddd;
      border-radius: 4px;
      background: #f9f9f9;
      cursor: pointer;
      font-size: 14px;
      margin: 0 5px;
    }
    button:hover {
      background: #f0f0f0;
    }
    button.print-btn {
      background: #2d8659;
      color: white;
      border: none;
    }
    button.print-btn:hover {
      background: #1f6041;
    }
    .period-selector {
      text-align: center;
      margin-bottom: 20px;
    }
    .period-selector a {
      display: inline-block;
      padding: 8px 16px;
      margin: 0 5px;
      text-decoration: none;
      color: #666;
      border: 1px solid #ddd;
      border-radius: 4px;
      background: #f9f9f9;
    }
    .period-selector a.active {
      background: #2d8659;
      color: white;
      border-color: #2d8659;
    }
    @media print {
      body {
        padding: 0;
        background: white;
      }
      .report-container {
        padding: 20px;
        box-shadow: none;
        border-radius: 0;
      }
      .actions, .period-selector {
        display: none;
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
      <div class="report-container">
    <div class="report-header">
      <h1 class="report-title">Sales Report</h1>
      <div class="report-meta">
        <b>Period:</b> <?php echo $periods[$period]; ?></br>
        <?php if ($period !== 'all'): ?>
        <b>Date Range:</b> <?php echo $startDate->format('M j, Y'); ?> - <?php echo $endDate->format('M j, Y'); ?><br>
        <?php else: ?>
        <b>Date Range:</b> All Time</br>
        <?php endif; ?>
        <b>Generated on: </b><?php echo date('M j, Y g:i A'); ?></br>
        <?php if ($user['role'] === 'admin'): ?>
          <b>All Sales Representatives</b>
        <?php else: ?>
          <b>Sales Rep:</b> <?php echo htmlspecialchars($user['username']); ?>
        <?php endif; ?>
      </div>
    </div>

    <div class="period-selector">
      <a href="?period=day" class="<?php echo $period === 'day' ? 'active' : ''; ?>">Today</a>
      <a href="?period=yesterday" class="<?php echo $period === 'yesterday' ? 'active' : ''; ?>">Yesterday</a>
      <a href="?period=week" class="<?php echo $period === 'week' ? 'active' : ''; ?>">This Week</a>
      <a href="?period=month" class="<?php echo $period === 'month' ? 'active' : ''; ?>">This Month</a>
      <a href="?period=all" class="<?php echo $period === 'all' ? 'active' : ''; ?>">All Time</a>
    </div>

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
        <div class="stat-label">Gross Revenue</div>
        <div class="stat-value">GH₵<?php echo number_format($totalRevenue, 2); ?></div>
      </div>
    </div>
    <div class="actions">
      <button class="print-btn" onclick="window.print()">Print Report</button>
      <button onclick="window.history.back()">Back</button>
    </div>

    <?php if (!empty($groupedSales)): ?>
      <div class="sales-list">
        <h3>Sales Details</h3>
        <?php foreach ($groupedSales as $order): ?>
          <div class="sale-item">
            <div class="sale-header">
              <div>
                <div class="sale-id">Order #<?php echo str_pad((string)$order['order_id'], 8, '0', STR_PAD_LEFT); ?></div>
                <div class="sale-meta">
                  <?php echo htmlspecialchars($order['username']); ?> •
                  <?php echo date('M j, Y g:i A', strtotime($order['created_at'])); ?>
                  <?php if ($order['return_status'] === 'Returned'): ?>
                    <span style="color: #bf2d2d; font-weight: bold;">(RETURNED)</span>
                  <?php endif; ?>
                </div>
              </div>
              <div class="sale-total <?php echo $order['return_status'] === 'Returned' ? 'returned' : ''; ?>">
                GH₵<?php echo number_format($order['total'], 2); ?>
              </div>
            </div>
            <div class="sale-items">
              <?php foreach ($order['items'] as $item): ?>
                <div class="item-row">
                  <div class="item-name">
                    <?php echo htmlspecialchars($item['name']); ?> (<?php echo $item['quantity']; ?> × GH₵<?php echo number_format($item['price'], 2); ?>)
                  </div>
                  <div class="item-price">GH₵<?php echo number_format($item['subtotal'], 2); ?></div>
                </div>
              <?php endforeach; ?>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php else: ?>
      <p style="text-align: center; color: #666; margin: 40px 0;">No sales found for this period.</p>
    <?php endif; ?>
    <div class="actions">
      <button class="print-btn" onclick="window.print()">Print Report</button>
      <button onclick="window.history.back()">Back</button>
    </div>
    </main>
  </div>

</body>
</html>
