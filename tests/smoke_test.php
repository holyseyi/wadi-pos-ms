<?php
/**
 * Activation smoke test - run against an INSTALLED copy of the app.
 *
 * The workflow copies this file into the app install directory and runs it with
 * the bundled php (php\php.exe smoke_test.php). It must live in the app root so
 * `require __DIR__ . '/inc/functions.php'` resolves to the installed copy.
 *
 * It expects the built-in PHP server to already be running on
 * http://127.0.0.1:8099 (override with the SMOKE_BASE_URL env var).
 *
 * Flow verified:
 *   1. fresh install => silent trial running, login page shows no countdown
 *      and no "Activation Required"
 *   2. trial expired  => login page shows "Activation Required"
 *   3. correct code   => trial ended permanently (type=permanent)
 *   4. old codes      => rejected
 *   5. permanent      => app stays unlocked regardless of any deadline
 */

require_once __DIR__ . '/inc/functions.php';

$baseUrl = rtrim(getenv('SMOKE_BASE_URL') ?: 'http://127.0.0.1:8099', '/');

$failures = 0;

function http_get(string $url): string {
    $ctx = stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 10]]);
    $body = @file_get_contents($url, false, $ctx);
    if ($body === false) {
        return '';
    }
    return $body;
}

function http_post_json(string $url, array $payload): array {
    $ctx = stream_context_create(['http' => [
        'method' => 'POST',
        'header' => "Content-Type: application/json\r\n",
        'content' => json_encode($payload),
        'ignore_errors' => true,
        'timeout' => 10,
    ]]);
    $body = @file_get_contents($url, false, $ctx);
    if ($body === false) {
        return ['success' => false, 'message' => 'request failed', 'raw' => ''];
    }
    $decoded = json_decode($body, true);
    return is_array($decoded) ? $decoded : ['success' => false, 'message' => 'invalid JSON: ' . substr($body, 0, 120), 'raw' => $body];
}

function check(string $name, bool $ok, string $detail = ''): void {
    global $failures;
    if (!$ok) {
        $failures++;
    }
    echo ($ok ? 'PASS' : 'FAIL') . '  ' . $name . ($detail !== '' ? '  [' . $detail . ']' : '') . PHP_EOL;
}

function set_window(string $periodMinutes, string $startedAt): void {
    $db = get_database();
    $set = $db->prepare('INSERT OR REPLACE INTO settings (key, value) VALUES (?, ?)');
    $set->execute(['activation_period_minutes', $periodMinutes]);
    $set->execute(['activation_started_at', $startedAt]);
}

// ---- 1. Fresh install: silent trial running, no countdown, no lock ----
echo "=== 1. Silent trial on fresh install ===\n";
$page = http_get($baseUrl . '/login.php');
check('server responds with login page', strpos($page, 'Login') !== false || strpos($page, 'login') !== false, strlen($page) . ' bytes');
check('no "Activation Required" while trial runs', strpos($page, 'Activation Required') === false);
check('no countdown on login page', stripos($page, 'Trial expires') === false && stripos($page, 'trial-timer') === false);
check('DB reports trial running', get_trial_status()['status'] === 'trial', get_trial_status()['status']);
check('app considered usable during trial', is_app_activated() === true);
check('not yet permanently licensed', is_permanently_licensed() === false);

// ---- 2. Trial expired => app locks ----
echo "\n=== 2. Trial expiry locks the app ===\n";
set_window('1', (string) (time() - 120));
$status = get_trial_status();
check('DB reports expired', $status['status'] === 'expired', $status['status']);
$page = http_get($baseUrl . '/login.php');
check('login page shows "Activation Required"', strpos($page, 'Activation Required') !== false);

// ---- 3. Old codes are rejected ----
echo "\n=== 3. Old activation codes rejected ===\n";
$oldPerm = http_post_json($baseUrl . '/activate.php', ['code' => 'Godloveis4all!']);
$oldReset = http_post_json($baseUrl . '/activate.php', ['code' => 'Ten12TechActivate']);
check('Godloveis4all! rejected', ($oldPerm['success'] ?? true) === false, json_encode($oldPerm));
check('Ten12TechActivate rejected', ($oldReset['success'] ?? true) === false, json_encode($oldReset));

// ---- 4. Correct code permanently ends the trial ----
echo "\n=== 4. Activation code permanently ends the trial ===\n";
$resp = http_post_json($baseUrl . '/activate.php', ['code' => ACTIVATION_CODE]);
check('activate endpoint accepts the code', ($resp['success'] ?? false) === true, json_encode($resp));
check('activation type is permanent', ($resp['type'] ?? '') === 'permanent', $resp['type'] ?? '');
check('status now activated', ($resp['trial']['status'] ?? '') === 'activated', $resp['trial']['status'] ?? '');
check('DB: permanently licensed', is_permanently_licensed() === true);
check('DB: status activated', get_trial_status()['status'] === 'activated');

// ---- 5. Permanent license survives any deadline ----
echo "\n=== 5. Permanent license never expires ===\n";
set_window('1', (string) (time() - 999999));
$status = get_trial_status();
check('still activated with deadline far in the past', $status['status'] === 'activated', $status['status']);
check('app still usable', is_app_activated() === true);
$page = http_get($baseUrl . '/login.php');
check('login page no longer shows "Activation Required"', strpos($page, 'Activation Required') === false);

echo PHP_EOL;
if ($failures === 0) {
    echo "ALL SMOKE TESTS PASSED\n";
    exit(0);
}
echo $failures . " SMOKE TEST(S) FAILED\n";
exit(1);
