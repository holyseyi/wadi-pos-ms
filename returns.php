<?php
require_once __DIR__ . '/inc/functions.php';
require_login();

$user = current_user();
$allReceipts = get_all_receipts_with_status();

// Extract items from receipts
$allItems = [];
foreach ($allReceipts as $receipt) {
    $db = get_database();
    $stmt = $db->prepare(
        'SELECT oi.id, oi.order_id, oi.product_id, oi.name, oi.price, oi.quantity, oi.subtotal,
                o.username, o.total, o.created_at, r.return_status, r.id as receipt_id
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
  <title>HADI POS - Returned Products</title>
  <link rel="stylesheet" href="styles.css" />
  <link rel="icon" type="image/svg+xml" href="images/pos-icon.svg" />
  <style>
    .returns-container {
      max-width: 1160px;
      margin: 0 auto;
      padding: 24px;
    }
    .back-link {
      display: inline-block;
      margin-bottom: 20px;
      color: #4e5b7f;
      text-decoration: none;
      font-weight: 500;
    }
    .back-link:hover {
      color: #182033;
    }
    .returns-summary {
      display: grid;
      gap: 16px;
    }
    .return-order {
      background: #fff9f9;
      border: 1px solid #f5e6e6;
      border-radius: 12px;
      padding: 16px;
    }
    .order-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 12px;
    }
    .order-id {
      font-weight: 700;
      color: #bf2d2d;
    }
    .order-meta {
      font-size: 0.9rem;
      color: #5f6d8b;
    }
    .order-total {
      font-size: 1.1rem;
      font-weight: 600;
      color: #bf2d2d;
    }
    .order-items {
      background: white;
      border-radius: 8px;
      padding: 12px;
      margin-top: 12px;
      border: 1px solid #f5e6e6;
    }
    .item-row {
      display: flex;
      justify-content: space-between;
      align-items: center;
      padding: 8px 0;
      border-bottom: 1px solid #f5e6e6;
    }
    .item-row:last-child {
      border-bottom: none;
    }
    .item-details {
      flex: 1;
    }
    .item-name {
      font-weight: 500;
      color: #182033;
    }
    .item-meta {
      font-size: 0.85rem;
      color: #5f6d8b;
    }
    .item-price {
      text-align: right;
      font-weight: 600;
      color: #bf2d2d;
    }
    .stats-panel {
      background: #f8fafc;
      border: 1px solid #dde3f0;
      border-radius: 12px;
      padding: 16px;
      margin-bottom: 24px;
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
      gap: 16px;
    }
    .stat-item {
      text-align: center;
    }
    .stat-label {
      font-size: 0.9rem;
      color: #5f6d8b;
    }
    .stat-value {
      font-size: 1.8rem;
      font-weight: 700;
      color: #bf2d2d;
    }
  </style>
</head>
<body>
  <div class="returns-container">
    <a href="admin.php" class="back-link">← Back to Admin Dashboard</a>
    
    <h1>All Sales & Returns</h1>
    <p>View all sales transactions with their current status (Active or Returned)</p>

    <?php 
    $totalSales = count($groupedSales);
    $totalItems = 0;
    $totalValue = 0;
    $returnedCount = 0;
    foreach ($groupedSales as $order) {
        $totalItems += count($order['items']);
        $totalValue += $order['total'];
        if ($order['return_status'] === 'Returned') {
            $returnedCount++;
        }
    }
    ?>
    <div class="stats-panel">
      <div class="stat-item">
        <div class="stat-label">Total Sales</div>
        <div class="stat-value"><?php echo $totalSales; ?></div>
      </div>
      <div class="stat-item">
        <div class="stat-label">Items Sold</div>
        <div class="stat-value"><?php echo $totalItems; ?></div>
      </div>
      <div class="stat-item">
        <div class="stat-label">Total Value</div>
        <div class="stat-value">GH₵<?php echo number_format($totalValue, 2); ?></div>
      </div>
      <div class="stat-item">
        <div class="stat-label">Returns</div>
        <div class="stat-value"><?php echo $returnedCount; ?></div>
      </div>
    </div>

    <?php if (empty($groupedSales)): ?>
      <p style="color: #5f6d8b; margin-top: 20px;">No sales records found.</p>
    <?php else: ?>
      <div class="returns-summary">
        <?php foreach ($groupedSales as $order): ?>
          <div class="return-order" style="background: <?php echo $order['return_status'] === 'Returned' ? '#fff9f9' : '#f8fafc'; ?>; border-color: <?php echo $order['return_status'] === 'Returned' ? '#f5e6e6' : '#e6ecf5'; ?>;">
            <div class="order-header">
              <div>
                <div class="order-id" style="color: <?php echo $order['return_status'] === 'Returned' ? '#bf2d2d' : '#182033'; ?>;">
                  Order #<?php echo str_pad((string)$order['order_id'], 8, '0', STR_PAD_LEFT); ?>
                  <span style="font-size: 0.8em; font-weight: normal; color: <?php echo $order['return_status'] === 'Returned' ? '#bf2d2d' : '#2d8659'; ?>;">
                    (<?php echo $order['return_status']; ?>)
                  </span>
                </div>
                <div class="order-meta">
                  Sales Rep: <?php echo htmlspecialchars($order['username']); ?> | 
                  Date: <?php echo htmlspecialchars(date('Y-m-d H:i', strtotime($order['created_at']))); ?>
                </div>
              </div>
              <div class="order-total" style="color: <?php echo $order['return_status'] === 'Returned' ? '#bf2d2d' : '#2d8659'; ?>;">
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
                  <div class="item-price" style="color: <?php echo $order['return_status'] === 'Returned' ? '#bf2d2d' : '#182033'; ?>;">
                    GH₵<?php echo htmlspecialchars(number_format($item['subtotal'], 2)); ?>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</body>
</html>
