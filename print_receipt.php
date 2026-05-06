<?php
require_once __DIR__ . '/inc/functions.php';
require_login();

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
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title> HADI POS - Receipt #<?php echo str_pad((string)$receipt['order_id'], 8, '0', STR_PAD_LEFT); ?></title>
  <link rel="icon" type="image/svg+xml" href="images/pos-icon.svg" />
  <style>
    * {
      box-sizing: border-box;
    }
    body {
      font-family: 'Courier New', monospace;
      margin: 0;
      padding: 20px;
      background: #f5f5f5;
    }
    .print-container {
      max-width: 400px;
      margin: 0 auto;
      background: white;
      padding: 20px;
      border-radius: 8px;
      box-shadow: 0 2px 10px rgba(0,0,0,0.1);
    }
    .receipt-content {
      white-space: pre-wrap;
      font-size: 12px;
      line-height: 1.5;
      margin: 20px 0;
      color: #333;
    }
    .print-actions {
      display: flex;
      gap: 10px;
      justify-content: center;
      margin-top: 20px;
    }
    button {
      padding: 10px 20px;
      border: 1px solid #ddd;
      border-radius: 4px;
      background: #f9f9f9;
      cursor: pointer;
      font-size: 14px;
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
      }
      .print-container {
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
    <div class="receipt-content"><?php echo htmlspecialchars($receiptContent); ?></div>
    <div class="print-actions">
      <button class="print-btn" onclick="window.print()">Print Receipt</button>
      <button onclick="window.history.back()">Back</button>
    </div>
  </div>
</body>
</html>
