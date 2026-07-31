<?php
require_once __DIR__ . '/inc/functions.php';
require_login();

$user = current_user();
$posName = get_pos_name();
$viewReceiptId = intval($_GET['view'] ?? 0);
$viewReceipt = null;
$error = '';

if ($viewReceiptId > 0) {
    $receiptContent = print_receipt($viewReceiptId);
    if ($receiptContent !== null) {
        // Verify user can access this receipt
        $stmt = get_database()->prepare(
            'SELECT r.id, r.order_id, r.username, r.receipt_content, r.return_status, r.created_at
             FROM receipts r
             WHERE r.id = :id'
        );
        $stmt->execute([':id' => $viewReceiptId]);
        $receiptData = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($receiptData && ($user['role'] === 'admin' || $receiptData['username'] === $user['username'])) {
            $viewReceipt = $receiptContent;
        } elseif (!$receiptData) {
            $error = 'Receipt not found.';
        } else {
            $error = 'Access denied. You can only view your own receipts.';
        }
    } else {
        $error = 'Receipt not found.';
    }
}

if ($user['role'] === 'admin') {
    $receipts = load_receipts();
} else {
    $receipts = load_receipts_by_user($user['username']);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title><?php echo htmlspecialchars($posName); ?> - Receipts</title>
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
      <div class="receipts-container">
    <a href="sales.php" class="back-link">← Back to Sales Register</a>

    <?php if (isset($error) && $error !== ''): ?>
      <p class="error-text"><?php echo htmlspecialchars($error); ?></p>
    <?php endif; ?>

    <?php if ($viewReceiptId > 0 && $viewReceipt): ?>
      <div class="single-view">
        <h2>Receipt #<?php echo str_pad((string)$viewReceiptId, 8, '0', STR_PAD_LEFT); ?></h2>
        <pre><?php echo htmlspecialchars($viewReceipt); ?></pre>
        <div class="receipt-actions">
          <a href="print_receipt.php?id=<?php echo $viewReceiptId; ?>" class="primary" target="_blank">Print Receipt</a>
          <a href="receipts.php" class="secondary">Back to All Receipts</a>
        </div>
      </div>
    <?php else: ?>
      <h1>All Receipts</h1>
      <p>View and manage all transaction receipts</p>

      <?php
      $totalReceipts = count($receipts);
      $totalValue = 0;
      foreach ($receipts as $receipt) {
          preg_match('/TOTAL\s+GH₵([\d.]+)/', $receipt['receipt_content'], $matches);
          if (!empty($matches[1])) {
              $totalValue += floatval($matches[1]);
          }
      }
      ?>

      <div class="stats-panel">
        <div class="stat-item">
          <div class="stat-label">Total Receipts</div>
          <div class="stat-value"><?php echo $totalReceipts; ?></div>
        </div>
        <div class="stat-item">
          <div class="stat-label">Total Value</div>
          <div class="stat-value">GH₵<?php echo number_format($totalValue, 2); ?></div>
        </div>
      </div>

      <?php if (empty($receipts)): ?>
        <p class="empty-message">No receipts found.</p>
      <?php else: ?>
        <div class="receipts-summary">
          <?php foreach ($receipts as $receipt): ?>
            <div class="receipt-card">
              <div class="receipt-header">
                <div>
                  <div style="display:flex;align-items:center;gap:10px;">
                    <img src="<?php echo htmlspecialchars(get_trademark_src()); ?>" alt="<?php echo htmlspecialchars($posName); ?> logo" style="width:32px;height:32px;object-fit:contain;" />
                    <div class="receipt-id">Receipt #<?php echo htmlspecialchars(str_pad((string)$receipt['order_id'], 8, '0', STR_PAD_LEFT)); ?></div>
                  </div>
                  <div class="receipt-meta">
                    By: <?php echo htmlspecialchars($receipt['username']); ?> | 
                    <?php echo htmlspecialchars(date('Y-m-d H:i', strtotime($receipt['created_at']))); ?>
                  </div>
                </div>
                <div class="text-right">
                  <span class="receipt-status <?php echo strtolower($receipt['return_status']); ?>">
                    <?php echo htmlspecialchars($receipt['return_status']); ?>
                  </span>
                </div>
              </div>
              <pre class="receipt-preview"><?php echo htmlspecialchars($receipt['receipt_content']); ?></pre>
              <div class="receipt-actions">
                <a href="print_receipt.php?id=<?php echo $receipt['id']; ?>" class="tertiary" target="_blank">Print</a>
                <a href="receipts.php?view=<?php echo $receipt['id']; ?>" class="secondary">View</a>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    <?php endif; ?>
    </main>
  </div>
</body>
</html>
