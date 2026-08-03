<?php
require_once __DIR__ . '/inc/functions.php';
require_login();

$posName = get_pos_name();
$receiptId = intval($_GET['id'] ?? 0);
if ($receiptId <= 0) {
    http_response_code(404);
    echo '<h1>Receipt not found</h1>';
    exit;
}

$receiptContent = print_receipt($receiptId);
if (!$receiptContent) {
    http_response_code(404);
    echo '<h1>Receipt not found</h1>';
    exit;
}

// Get receipt details for verification
$stmt = get_database()->prepare(
    'SELECT id, order_id, username, return_status, created_at FROM receipts WHERE id = :id'
);
$stmt->execute([':id' => $receiptId]);
$receipt = $stmt->fetch(PDO::FETCH_ASSOC);

$user = current_user();

// Verify user can access this receipt
if ($user['role'] !== 'admin' && $receipt['username'] !== $user['username']) {
    http_response_code(403);
    echo '<h1>Access Denied</h1>';
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover" />
  <title><?php echo htmlspecialchars($posName); ?> - Receipt #<?php echo str_pad((string)$receipt['order_id'], 8, '0', STR_PAD_LEFT); ?></title>
  <link rel="icon" type="image/svg+xml" href="<?php echo htmlspecialchars(get_trademark_src()); ?>" />
  <style>
    * {
      box-sizing: border-box;
    }
    body {
      font-family: 'Courier New', monospace;
      margin: 0;
      padding: 16px;
      background: #f5f5f5;
      display: flex;
      justify-content: center;
    }
    .print-container {
      width: 280px;
      max-width: 100%;
      margin: 0 auto;
      background: white;
      padding: 18px;
      border-radius: 8px;
      box-shadow: 0 2px 10px rgba(0,0,0,0.1);
    }
    .receipt-brand {
      display: flex;
      align-items: center;
      gap: 8px;
      margin-bottom: 10px;
    }
    .receipt-brand img {
      width: 28px;
      height: 28px;
      object-fit: contain;
    }
    .receipt-brand .brand-name {
      font-size: 14px;
      font-weight: bold;
      letter-spacing: 1px;
      color: #0f172a;
      line-height: 28px;
    }
    .receipt-content {
      white-space: pre-wrap;
      font-size: 11px;
      line-height: 1.45;
      margin: 14px 0;
      color: #333;
    }
    .receipt-note {
      text-align: center;
      font-size: 10px;
      color: #64748b;
      border-top: 1px dashed #cbd5e1;
      padding-top: 10px;
      margin-top: 4px;
    }
    .print-actions {
      display: flex;
      gap: 10px;
      justify-content: center;
      margin-top: 16px;
    }
    button {
      padding: 8px 16px;
      border: 1px solid #ddd;
      border-radius: 4px;
      background: #f9f9f9;
      cursor: pointer;
      font-size: 13px;
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
    @media print {
      body {
        padding: 0;
        background: white;
        display: block;
      }
      .print-container {
        width: 300px;
        padding: 0;
        box-shadow: none;
        border-radius: 0;
      }
      .print-actions {
        display: none;
      }
    }
  </style>
</head>
<body>
  <div class="print-container">
    <div class="receipt-brand">
      <img src="<?php echo htmlspecialchars(get_trademark_src()); ?>" alt="<?php echo htmlspecialchars($posName); ?> logo" />
      <div class="brand-name"><?php echo htmlspecialchars($posName); ?></div>
    </div>
    <div class="receipt-content"><?php echo htmlspecialchars($receiptContent); ?></div>
    <div class="receipt-note">Products may be returned with receipt.</div>
    <div class="print-actions">
      <button class="print-btn" onclick="window.print()">Print Receipt</button>
      <button onclick="window.history.back()">Back</button>
    </div>
  </div>
</body>
</html>
