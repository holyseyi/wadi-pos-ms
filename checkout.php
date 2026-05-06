<?php
require_once __DIR__ . '/inc/functions.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user'])) {
    echo json_encode(['success' => false, 'message' => 'You must be logged in to checkout.']);
    exit;
}

$payload = json_decode(file_get_contents('php://input'), true);
if (!is_array($payload) || !isset($payload['cart']) || !is_array($payload['cart'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid checkout payload.']);
    exit;
}

$user = current_user();
$orderId = save_order($payload['cart'], $user['username']);
if ($orderId === null) {
    echo json_encode(['success' => false, 'message' => 'Unable to save order. Please try again.']);
    exit;
}

// Save receipt
$receiptId = save_receipt($orderId, $payload['cart'], $user['username']);
if ($receiptId === null) {
    echo json_encode(['success' => false, 'message' => 'Order saved but receipt generation failed.']);
    exit;
}

echo json_encode(['success' => true, 'orderId' => $orderId, 'receiptId' => $receiptId]);

