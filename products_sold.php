<?php
require_once __DIR__ . '/inc/functions.php';
require_login();

$user = current_user();
$posName = get_pos_name();
$allSalesItems = get_all_sales_items();

// Filter by user if not admin
if ($user['role'] !== 'admin') {
    $allSalesItems = array_filter($allSalesItems, fn($item) => $item['username'] === $user['username']);
}

// Group by order for display
$groupedSales = [];
foreach ($allSalesItems as $item) {
    $orderId = $item['order_id'];
    if (!isset($groupedSales[$orderId])) {
        $groupedSales[$orderId] = [
            'order_id' => $orderId,
            'username' => $item['username'],
            'total' => $item['total'],
            'created_at' => $item['created_at'],
            'return_status' => $item['return_status'],
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
  <title><?php echo htmlspecialchars($posName); ?> - All Products Sold</title>
  <link rel="stylesheet" href="styles.css" />
  <style>
    .sales-detail-container {
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
    .sales-summary {
      display: grid;
      gap: 16px;
    }
    .order-summary {
      background: #f8fafc;
      border: 1px solid #e6ecf5;
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
      color: #182033;
    }
    .order-meta {
      font-size: 0.9rem;
      color: #5f6d8b;
    }
    .order-total {
      font-size: 1.1rem;
      font-weight: 600;
      color: #2d8659;
    }
    .order-items {
      background: white;
      border-radius: 8px;
      padding: 12px;
      margin-top: 12px;
    }
    .item-row {
      display: flex;
      justify-content: space-between;
      align-items: center;
      padding: 8px 0;
      border-bottom: 1px solid #eee;
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
      color: #182033;
    }
    .return-badge {
      display: inline-block;
      padding: 4px 8px;
      border-radius: 4px;
      font-size: 0.8rem;
      font-weight: 600;
      margin-left: 8px;
    }
    .return-badge.returned {
      background: #fee5e5;
      color: #bf2d2d;
    }
    .return-badge.active {
      background: #e5f6ef;
      color: #2d8659;
    }
  </style>
  <link rel="icon" type="image/svg+xml" href="images/pos-icon.svg" />
</head>
<body>
  <div class="sales-detail-container">
    <a href="sales.php" class="back-link">← Back to Sales Register</a>
    
    <h1>All Products Sold</h1>
    <p>Viewing products sold <?php echo $user['role'] === 'admin' ? '(All sales representatives)' : '(Your sales)'; ?></p>

    <?php if (empty($groupedSales)): ?>
      <p style="color: #5f6d8b; margin-top: 20px;">No sales records found.</p>
    <?php else: ?>
      <div class="sales-summary">
        <?php foreach ($groupedSales as $order): ?>
          <div class="order-summary">
            <div class="order-header">
              <div>
                <div class="order-id">Order #<?php echo str_pad((string)$order['order_id'], 8, '0', STR_PAD_LEFT); ?></div>
                <div class="order-meta">
                  <?php echo $user['role'] === 'admin' ? 'Sales Rep: ' . htmlspecialchars($order['username']) . ' | ' : ''; ?>
                  Date: <?php echo htmlspecialchars(date('Y-m-d H:i', strtotime($order['created_at']))); ?>
                  <?php if ($order['return_status'] === 'Returned'): ?>
                    <span class="return-badge returned">RETURNED</span>
                  <?php elseif ($order['return_status'] === 'Active'): ?>
                    <span class="return-badge active">ACTIVE</span>
                  <?php endif; ?>
                </div>
              </div>
              <div class="order-total">GH₵<?php echo htmlspecialchars(number_format($order['total'], 2)); ?></div>
            </div>
            <div class="order-items">
              <?php foreach ($order['items'] as $item): ?>
                <div class="item-row">
                  <div class="item-details">
                    <div class="item-name"><?php echo htmlspecialchars($item['name']); ?></div>
                    <div class="item-meta"><?php echo $item['quantity']; ?> × GH₵<?php echo number_format($item['price'], 2); ?></div>
                  </div>
                  <div class="item-price">GH₵<?php echo htmlspecialchars(number_format($item['subtotal'], 2)); ?></div>
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
