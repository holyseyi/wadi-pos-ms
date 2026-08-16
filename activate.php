<?php
require_once __DIR__ . '/inc/functions.php';

header('Content-Type: application/json');

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input) || empty($input['code'])) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Missing activation code.',
    ]);
    exit;
}

$code = trim($input['code']);
$result = activate_app($code);

if (!$result) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => 'Invalid or expired activation code.',
    ]);
    exit;
}

$activationStatus = get_trial_status();
echo json_encode([
    'success' => true,
    'message' => 'App activated successfully. The trial period has ended and all features are permanently unlocked.',
    'type' => 'permanent',
    'trial' => $activationStatus,
]);
exit;
