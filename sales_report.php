<?php
require_once __DIR__ . '/inc/functions.php';
require_login();

$user = current_user();
$posName = get_pos_name();

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
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover" />
  <meta name="apple-mobile-web-app-capable" content="yes" />
  <meta name="apple-mobile-web-app-status-bar-style" content="default" />
  <meta name="apple-mobile-web-app-title" content="<?php echo htmlspecialchars($posName); ?>" />
  <link rel="apple-touch-icon" href="<?php echo htmlspecialchars(get_trademark_src()); ?>" />
  <title><?php echo htmlspecialchars($posName); ?> - Sales Report - <?php echo $periods[$period]; ?></title>
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
      <div class="report-container">
    <div class="report-header">
      <h1 class="report-title">Sales Report</h1>
      <div class="report-meta">
        <b>Period:</b> <?php echo $periods[$period]; ?><br>
        <?php if ($useCustomRange): ?>
          <b>Date Range:</b> <?php echo $startDate->format('M j, Y g:i A'); ?> - <?php echo $endDate->format('M j, Y g:i A'); ?><br>
        <?php elseif ($period !== 'all'): ?>
          <b>Date Range:</b> <?php echo $startDate->format('M j, Y'); ?> - <?php echo $endDate->format('M j, Y'); ?><br>
        <?php else: ?>
          <b>Date Range:</b> All Time<br>
        <?php endif; ?>
        <b>Generated on: </b><?php echo date('M j, Y g:i A'); ?><br>
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
      <a href="?period=year" class="<?php echo $period === 'year' ? 'active' : ''; ?>">This Year</a>
      <a href="?period=all" class="<?php echo $period === 'all' ? 'active' : ''; ?>">All Time</a>
      <a href="?period=custom" class="<?php echo $period === 'custom' ? 'active' : ''; ?>">Custom</a>
    </div>

    <?php if ($period === 'custom'): ?>
    <form method="get" action="sales_report.php" class="custom-range-form" style="display:inline-flex;gap:8px;align-items:center;flex-wrap:wrap;margin-bottom:16px;">
      <input type="hidden" name="period" value="custom" />
      <label style="font-size:0.85rem;color:#475569;">From:
        <input type="datetime-local" name="start" value="<?php echo htmlspecialchars($customStart ?? ''); ?>" required />
      </label>
      <label style="font-size:0.85rem;color:#475569;">To:
        <input type="datetime-local" name="end" value="<?php echo htmlspecialchars($customEnd ?? ''); ?>" required />
      </label>
      <button type="submit" class="primary" style="padding:6px 12px;font-size:0.85rem;">Apply</button>
      <a href="sales_report.php?period=day" class="secondary" style="padding:6px 12px;font-size:0.85rem;">Clear</a>
    </form>
    <?php endif; ?>

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
  <script src="nav.js"></script>
</body>
</html>
