<?php
require_once __DIR__ . '/inc/functions.php';
require_admin();

$user = current_user();

$message = '';
$error = '';
$editProduct = null;

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (isset($_SESSION['flash_message'])) {
    $message = $_SESSION['flash_message'];
    unset($_SESSION['flash_message']);
}
if (isset($_SESSION['flash_error'])) {
    $error = $_SESSION['flash_error'];
    unset($_SESSION['flash_error']);
}

function flash_success($msg) {
    $_SESSION['flash_message'] = $msg;
}
function flash_error($msg) {
    $_SESSION['flash_error'] = $msg;
}

try {
    $posName = get_pos_name();
    $trademark = get_trademark();
    $products = load_products();
    $imageOptions = get_image_options();
    $receipts = load_receipts();
    $users = get_all_users();
    $activitySessions = load_activity_log();
    if (is_super_admin()) {
        $loginActivities = load_all_login_activities();
        $activationStatus = get_trial_status();
        $activationPeriodMinutes = get_activation_period_minutes();
        $themePrimaryColor = get_theme_primary_color();
        $themeSecondaryColor = get_theme_secondary_color();
        $themeBackgroundColor = get_theme_background_color();
        $themeLoginBrandImage = get_theme_login_brand_image();
    }
} catch (Exception $e) {
    $error = 'Unable to load admin data. Please try again later.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save_product') {
        $productId = isset($_POST['product_id']) ? intval($_POST['product_id']) : null;
        $name = trim($_POST['name'] ?? '');
        $category = trim($_POST['category'] ?? '');
        $sellingPrice = floatval($_POST['selling_price'] ?? 0);
        $costPrice = floatval($_POST['cost_price'] ?? 0);
        $quantity = intval($_POST['quantity'] ?? 0);
        $bulkQuantityThreshold = intval($_POST['bulk_quantity_threshold'] ?? 0);
        $bulkDiscountPercentage = floatval($_POST['bulk_discount_percentage'] ?? 0);
        $code = trim($_POST['code'] ?? '');
        $expiryDate = trim($_POST['expiry_date'] ?? '');
        $imageMenu = trim($_POST['image_menu'] ?? '');
        $existingImage = trim($_POST['existing_image'] ?? 'images/uploads/asano.jpg');
        $image = $existingImage;

        if (isset($_FILES['image_file']) && $_FILES['image_file']['error'] === UPLOAD_ERR_OK) {
            $uploadedImage = store_uploaded_image($_FILES['image_file']);
            if ($uploadedImage !== null) {
                $image = $uploadedImage;
            } else {
                $message = 'Uploaded image is not valid. Use PNG or JPG.';
            }
        } elseif ($imageMenu !== '' && isset($imageOptions[$imageMenu])) {
            $image = $imageMenu;
        }

        if ($name === '' || $category === '' || $sellingPrice <= 0 || $code === '') {
            $message = 'Please fill in every field with valid values.';
        } elseif ($message === '') {
            $duplicate = false;
            foreach ($products as $product) {
                if ($product['code'] === $code && $productId !== $product['id']) {
                    $duplicate = true;
                    break;
                }
            }

            if ($duplicate) {
                $message = 'A product with this barcode already exists.';
            } else {
                if ($productId) {
                    foreach ($products as &$product) {
                        if ($product['id'] === $productId) {
                            $product['name'] = $name;
                            $product['category'] = $category;
                            $product['selling_price'] = $sellingPrice;
                            $product['cost_price'] = $costPrice;
                            $product['quantity'] = $quantity;
                            $product['bulk_quantity_threshold'] = $bulkQuantityThreshold;
                            $product['bulk_discount_percentage'] = $bulkDiscountPercentage;
                            $product['code'] = $code;
                            $product['image'] = $image;
                            // Don't allow changing expiry date on already-expired products
                            if (!is_product_expired($product['expiry_date'] ?? null)) {
                                $product['expiry_date'] = $expiryDate !== '' ? $expiryDate : null;
                            }
                            break;
                        }
                    }
                    unset($product);
                } else {
                    $newId = 1;
                    foreach ($products as $product) {
                        $newId = max($newId, $product['id'] + 1);
                    }
                    $products[] = [
                        'id' => $newId,
                        'code' => $code,
                        'name' => $name,
                        'category' => $category,
                        'selling_price' => $sellingPrice,
                        'cost_price' => $costPrice,
                        'quantity' => $quantity,
                        'bulk_quantity_threshold' => $bulkQuantityThreshold,
                        'bulk_discount_percentage' => $bulkDiscountPercentage,
                        'image' => $image,
                        'expiry_date' => $expiryDate !== '' ? $expiryDate : null,
                    ];
                }

                save_products($products);
                flash_success('Product saved successfully.');
                log_activity($user['username'], $user['role'], 'product_saved', 'Saved product: ' . $name . ' (Code: ' . $code . ')');
                header('Location: admin.php');
                exit;
            }
        }
    }

    if ($action === 'delete_product') {
        $productId = intval($_POST['product_id'] ?? 0);
        $productName = '';
        foreach ($products as $product) {
            if ($product['id'] === $productId) {
                $productName = $product['name'];
                break;
            }
        }
        $products = array_values(array_filter($products, fn($product) => $product['id'] !== $productId));
        save_products($products);
        flash_success('Product deleted successfully.');
        log_activity($user['username'], $user['role'], 'product_deleted', 'Deleted product: ' . $productName);
        header('Location: admin.php');
        exit;
    }

    if ($action === 'delete_sale') {
        $orderId = intval($_POST['order_id'] ?? 0);
        if ($orderId > 0 && delete_sale($orderId, 'admin')) {
            flash_success('Sale deleted successfully.');
            log_activity($user['username'], $user['role'], 'sale_deleted', 'Deleted sale #' . str_pad((string)$orderId, 8, '0', STR_PAD_LEFT));
            header('Location: admin.php');
            exit;
        }
        flash_error('Failed to delete sale.');
        header('Location: admin.php');
        exit;
    }

    if ($action === 'update_return_status') {
        $receiptId = intval($_POST['receipt_id'] ?? 0);
        $status = $_POST['return_status'] ?? 'Active';
        
        if ($receiptId > 0 && in_array($status, ['Active', 'Returned'], true)) {
            if (update_receipt_status($receiptId, $status)) {
                flash_success('Receipt status updated successfully.');
                log_activity($user['username'], $user['role'], 'return_status_updated', 'Updated receipt #' . str_pad((string)$receiptId, 8, '0', STR_PAD_LEFT) . ' status to ' . $status);
                header('Location: admin.php');
                exit;
            }
        }
        flash_error('Failed to update receipt status.');
        header('Location: admin.php');
        exit;
    }

    if ($action === 'create_user') {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        $role = $_POST['role'] ?? '';

        if ($username === '' || $password === '' || !in_array($role, ['admin', 'sales'], true)) {
            $error = 'Please fill in all fields with valid values.';
        } elseif (strlen($password) < 6) {
            $error = 'Password must be at least 6 characters long.';
        } else {
            if (create_user($username, $password, $role)) {
                flash_success('User account created successfully.');
                log_activity($user['username'], $user['role'], 'user_created', 'Created user account: ' . $username . ' (Role: ' . $role . ')');
                header('Location: admin.php');
                exit;
            } else {
                flash_error('Failed to create user account. Username might already exist.');
                header('Location: admin.php');
                exit;
            }
        }
    }

    if ($action === 'delete_user') {
        $userId = intval($_POST['user_id'] ?? 0);
        $deletedUsername = '';
        foreach ($users as $u) {
            if ($u['id'] === $userId) {
                $deletedUsername = $u['username'];
                break;
            }
        }
        if ($userId > 0) {
            if (delete_user($userId)) {
                flash_success('User account deleted successfully.');
                log_activity($user['username'], $user['role'], 'user_deleted', 'Deleted user account: ' . $deletedUsername);
                header('Location: admin.php');
                exit;
            } else {
                flash_error('Failed to delete user account.');
                header('Location: admin.php');
                exit;
            }
        } else {
            flash_error('Invalid user selected for deletion.');
            header('Location: admin.php');
            exit;
        }
    }

    if ($action === 'update_stock') {
        $productId = intval($_POST['product_id'] ?? 0);
        $newQuantity = intval($_POST['quantity'] ?? 0);
        $productName = '';
        foreach ($products as $product) {
            if ($product['id'] === $productId) {
                $productName = $product['name'];
                break;
            }
        }
        
        if ($productId > 0) {
            if (update_product_stock($productId, $newQuantity)) {
                flash_success('Stock updated successfully.');
                log_activity($user['username'], $user['role'], 'stock_updated', 'Updated stock for ' . $productName . ' to ' . $newQuantity . ' units');
                header('Location: admin.php');
                exit;
            } else {
                flash_error('Failed to update stock.');
                header('Location: admin.php');
                exit;
            }
        }
    }

    if ($action === 'restock_expired') {
        $productId = intval($_POST['product_id'] ?? 0);
        $restockQty = intval($_POST['restock_quantity'] ?? 0);
        $newExpiryDate = trim($_POST['new_expiry_date'] ?? '');

        if ($productId <= 0 || $restockQty <= 0) {
            flash_error('Please enter a valid quantity to restock.');
            header('Location: admin.php');
            exit;
        }

        if ($newExpiryDate === '') {
            flash_error('Please enter a new expiry date for the restocked batch.');
            header('Location: admin.php');
            exit;
        }

        if (is_product_expired($newExpiryDate)) {
            flash_error('The new expiry date is already in the past. Please enter a future date.');
            header('Location: admin.php');
            exit;
        }

        // Find the product and verify it is expired
        foreach ($products as &$product) {
            if ($product['id'] === $productId) {
                if (!is_product_expired($product['expiry_date'] ?? null)) {
                    flash_error('This product is not expired. Use the regular stock update form instead.');
                    header('Location: admin.php');
                    exit;
                }

                $productName = $product['name'];
                $oldQty = (int) $product['quantity'];
                $expiredLoss = round((float) $product['cost_price'] * $oldQty, 2);

                // Update quantity and set new expiry date
                $product['quantity'] = $oldQty + $restockQty;
                $product['expiry_date'] = $newExpiryDate;

                save_products($products);

                log_activity($user['username'], $user['role'], 'restock_expired',
                    'Restocked expired product: ' . $productName .
                    ' — added ' . $restockQty . ' units (loss on expired batch: GH₵' . number_format($expiredLoss, 2) . ')' .
                    ' — new expiry: ' . $newExpiryDate
                );

                flash_success(
                    'Restocked ' . $productName . ' with ' . $restockQty . ' new unit(s).\n' .
                    'Expired batch loss (GH₵' . number_format($expiredLoss, 2) . ') has been recorded.\n' .
                    'New expiry date: ' . date('M d, Y', strtotime($newExpiryDate))
                );
                header('Location: admin.php');
                exit;
            }
        }
        unset($product);
        flash_error('Product not found.');
        header('Location: admin.php');
        exit;
    }

    if ($action === 'update_pos_name') {
        $newName = trim($_POST['pos_name'] ?? '');
        if ($newName !== '') {
            if (set_pos_name($newName)) {
                flash_success('POS name updated successfully.');
                log_activity($user['username'], $user['role'], 'settings_updated', 'Updated POS name to: ' . $newName);
                header('Location: admin.php');
                exit;
            } else {
                flash_error('Failed to update POS name.');
                header('Location: admin.php');
                exit;
            }
        } else {
            flash_error('POS name cannot be empty.');
            header('Location: admin.php');
            exit;
        }
    }

    if ($action === 'update_trademark') {
        if (isset($_FILES['trademark_image']) && $_FILES['trademark_image']['error'] === UPLOAD_ERR_OK) {
            $uploaded = save_trademark_image($_FILES['trademark_image']);
            if ($uploaded !== null) {
                set_trademark($uploaded);
                flash_success('Trademark image updated successfully.');
                log_activity($user['username'], $user['role'], 'settings_updated', 'Updated trademark/logo image');
                header('Location: admin.php');
                exit;
            } else {
                flash_error('Uploaded image is not valid. Use PNG, JPG, SVG or WebP.');
                header('Location: admin.php');
                exit;
            }
        } else {
            flash_error('Please select an image to upload.');
            header('Location: admin.php');
            exit;
        }
    }

    if ($action === 'admin_activate_app' && is_super_admin()) {
        if (admin_activate_app()) {
            flash_success('Application activated successfully. All features are now permanently unlocked.');
            log_activity($user['username'], $user['role'], 'admin_activation', 'Activated app via admin panel');
            header('Location: admin.php');
            exit;
        }
        flash_error('Failed to activate application.');
        header('Location: admin.php');
        exit;
    }

    if ($action === 'admin_cancel_activation' && is_super_admin()) {
        if (admin_cancel_activation()) {
            flash_success('Application activation cancelled. Access has been revoked.');
            log_activity($user['username'], $user['role'], 'admin_activation', 'Cancelled app activation via admin panel');
            header('Location: admin.php');
            exit;
        }
        flash_error('Failed to cancel application activation.');
        header('Location: admin.php');
        exit;
    }

    if ($action === 'set_activation_period' && is_super_admin()) {
        $periodMinutes = intval($_POST['activation_period_minutes'] ?? 0);
        if ($periodMinutes > 0 && set_activation_period_minutes($periodMinutes)) {
            flash_success('Activation period updated successfully to ' . $periodMinutes . ' minutes.');
            log_activity($user['username'], $user['role'], 'admin_activation', 'Set activation period to ' . $periodMinutes . ' minutes');
            header('Location: admin.php');
            exit;
        }
        flash_error('Failed to update activation period. Please enter a valid number of minutes.');
        header('Location: admin.php');
        exit;
    }

    if ($action === 'update_theme' && is_super_admin()) {
        $primary = trim($_POST['theme_primary_color'] ?? '');
        $secondary = trim($_POST['theme_secondary_color'] ?? '');
        $background = trim($_POST['theme_background_color'] ?? '');
        $brandImage = $_FILES['theme_login_brand_image'] ?? null;

        if (!preg_match('/^#[0-9a-fA-F]{6}$/', $primary) || !preg_match('/^#[0-9a-fA-F]{6}$/', $secondary) || !preg_match('/^#[0-9a-fA-F]{6}$/', $background)) {
            flash_error('Invalid color format. Please use hex codes like #001a4a.');
            header('Location: admin.php');
            exit;
        }

        set_theme_primary_color($primary);
        set_theme_secondary_color($secondary);
        set_theme_background_color($background);

        if ($brandImage && $brandImage['error'] === UPLOAD_ERR_OK) {
            $uploaded = save_theme_brand_image($brandImage);
            if ($uploaded) {
                set_theme_login_brand_image($uploaded);
            }
        }

        log_activity($user['username'], $user['role'], 'theme_updated', 'Updated theme colors and brand image');
        flash_success('Theme updated successfully.');
        header('Location: admin.php');
        exit;
    }
}

if (isset($_GET['edit'])) {
    $editId = intval($_GET['edit']);
    foreach ($products as $product) {
        if ($product['id'] === $editId) {
            $editProduct = $product;
            break;
        }
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover" />
  <meta name="apple-mobile-web-app-capable" content="yes" />
  <meta name="apple-mobile-web-app-status-bar-style" content="default" />
  <meta name="apple-mobile-web-app-title" content="<?php echo htmlspecialchars($posName); ?>" />
  <link rel="apple-touch-icon" href="<?php echo htmlspecialchars(get_trademark_src()); ?>" />
  <title><?php echo htmlspecialchars($posName); ?> - Admin Dashboard</title>
  <link rel="icon" type="image/svg+xml" href="<?php echo htmlspecialchars(get_trademark_src()); ?>" />
  <link rel="stylesheet" href="styles.css" />
  <style id="theme-overrides">
    :root {
      --theme-primary: <?php echo htmlspecialchars(get_theme_primary_color()); ?>;
      --theme-secondary: <?php echo htmlspecialchars(get_theme_secondary_color()); ?>;
      --theme-bg: <?php echo htmlspecialchars(get_theme_background_color()); ?>;
    }
  </style>
</head>
<body>
  <div class="app-shell">
    <header class="header">
      <div class="brand">
        <img class="brand-icon" src="<?php echo htmlspecialchars(get_trademark_src()); ?>" alt="Trademark" />
        <div>
          <h1><?php echo htmlspecialchars($posName); ?></h1>
          <p class="subtitle">Admin dashboard for product management.</p>
        </div>
      </div>

      <button class="menu-toggle" id="menu-toggle" aria-label="Toggle navigation menu" aria-expanded="false" aria-controls="header-actions">
        <span></span><span></span><span></span>
      </button>

      <div class="header-actions" id="header-actions">
        <?php if ($user['role'] === 'admin'): ?>
        <a href="returns.php" class="secondary">All sales</a>
        <a href="returned_products.php" class="secondary">Returned Products</a>
        <?php endif; ?>
        <a href="sales.php" class="secondary">Sales register</a>
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
      <article class="panel overview-panel">
        <div class="panel-label">
          <span class="badge admin-badge">Admin</span>
          <h2>Inventory control</h2>
        </div>
        <p class="panel-copy">Add, update, or delete products securely with a professional admin view.</p>
        <div class="highlight-row">
          <div>
            <span class="metric-label">Role</span>
            <strong>Administrator</strong>
          </div>
          <div>
            <span class="metric-label">Products</span>
            <strong><?php echo count($products); ?></strong>
          </div>
        </div>
      </article>

      <?php
        $expiredSummary = get_expired_stock_summary();
        $expiringSoonSummary = get_expiring_soon_summary(7);
        $hasAlerts = $expiredSummary['count'] > 0 || $expiringSoonSummary['count'] > 0;
        if ($hasAlerts):
      ?>
      <?php if ($expiredSummary['count'] > 0): ?>
      <article class="panel expired-products-alert">
        <div class="panel-header">
          <h2>🔴 Expired Products Alert</h2>
        </div>
        <p class="error-text" style="font-weight:700;">
          <?php echo htmlspecialchars($expiredSummary['count']); ?> product(s) with <?php echo htmlspecialchars($expiredSummary['total_items']); ?> total unit(s) have expired.
          Stock loss: <strong>GH₵<?php echo htmlspecialchars(number_format($expiredSummary['total_loss'], 2)); ?></strong>
        </p>
        <p class="panel-copy">Expired products are automatically blocked from sales.</p>
        <?php foreach ($expiredSummary['items'] as $expiredItem): ?>
          <div class="alert-item alert-expired">
            <span class="alert-product-name"><?php echo htmlspecialchars($expiredItem['product']['name']); ?></span>
            <span class="alert-details">Qty: <?php echo htmlspecialchars($expiredItem['product']['quantity']); ?> • Cost: GH₵<?php echo htmlspecialchars(number_format($expiredItem['product']['cost_price'], 2)); ?> • Loss: GH₵<?php echo htmlspecialchars(number_format($expiredItem['loss'], 2)); ?></span>
          </div>
        <?php endforeach; ?>
      </article>
      <?php endif; ?>
      <?php if ($expiringSoonSummary['count'] > 0): ?>
      <article class="panel expiring-soon-alert">
        <div class="panel-header">
          <h2>⏰ Expiring Soon</h2>
        </div>
        <p class="warning-text" style="font-weight:700;">
          <?php echo htmlspecialchars($expiringSoonSummary['count']); ?> product(s) with <?php echo htmlspecialchars($expiringSoonSummary['total_items']); ?> total unit(s) expiring within 7 days.
        </p>
        <p class="panel-copy">These products will expire soon and should be prioritized for sale.</p>
        <?php foreach ($expiringSoonSummary['items'] as $expiringItem): ?>
          <div class="alert-item alert-expiring">
            <span class="alert-product-name"><?php echo htmlspecialchars($expiringItem['product']['name']); ?></span>
            <span class="alert-details">Qty: <?php echo htmlspecialchars($expiringItem['product']['quantity']); ?> • Expires: <?php echo htmlspecialchars(date('M d, Y', strtotime($expiringItem['product']['expiry_date']))); ?> • <?php echo htmlspecialchars($expiringItem['days_left']); ?> day(s) left</span>
          </div>
        <?php endforeach; ?>
      </article>
      <?php endif; ?>
      <?php endif; ?>

      <section class="panel admin-management">
        <div class="panel-header">
          <h2>Product manager</h2>
        </div>

        <?php if ($message): ?>
          <p class="login-hint text-success" data-toast-message data-toast-type="success"><?php echo htmlspecialchars($message); ?></p>
        <?php endif; ?>
        <?php if ($error): ?>
          <p class="error-text" data-toast-message data-toast-type="error"><?php echo htmlspecialchars($error); ?></p>
        <?php endif; ?>

        <form id="product-form" class="admin-form" method="post" action="admin.php" enctype="multipart/form-data">
          <input type="hidden" name="action" value="save_product" />
          <input type="hidden" name="product_id" value="<?php echo htmlspecialchars($editProduct['id'] ?? ''); ?>" />

          <div class="form-section">
            <div class="section-title">Basic Information</div>
            <div class="field-grid">
              <label class="input-group">
                <span class="field-label">Product Name</span>
                <input id="product-name" name="name" type="text" maxlength="30" required value="<?php echo htmlspecialchars($editProduct['name'] ?? ''); ?>" placeholder="Enter product name" />
                <span id="name-counter" style="font-size:0.8rem;color:#64748b;text-align:right;">0 / 30</span>
              </label>
              <label class="input-group">
                <span class="field-label">Category</span>
                <input id="product-category" name="category" type="text" required value="<?php echo htmlspecialchars($editProduct['category'] ?? ''); ?>" placeholder="e.g., Electronics" />
              </label>
              <label class="input-group">
                <span class="field-label">Cost Price (GH₵)</span>
                <input id="product-cost-price" name="cost_price" type="number" min="0" step="0.01" required value="<?php echo htmlspecialchars($editProduct['cost_price'] ?? '0'); ?>" placeholder="0.00" />
              </label>
              <label class="input-group">
                <span class="field-label">Selling Price (GH₵)</span>
                <input id="product-selling-price" name="selling_price" type="number" min="0.01" step="0.01" required value="<?php echo htmlspecialchars($editProduct['selling_price'] ?? ''); ?>" placeholder="0.00" />
              </label>
              <label class="input-group">
                <span class="field-label">Barcode</span>
                <input id="product-code" name="code" type="text" required value="<?php echo htmlspecialchars($editProduct['code'] ?? ''); ?>" placeholder="Product code" />
              </label>
            </div>
          </div>

          <div class="form-section">
            <div class="section-title">Inventory & Pricing</div>
            <div class="field-grid">
              <label class="input-group">
                <span class="field-label">Stock Quantity</span>
                <input id="product-quantity" name="quantity" type="number" min="0" required value="<?php echo htmlspecialchars($editProduct['quantity'] ?? '0'); ?>" placeholder="0" />
              </label>
              <label class="input-group">
                <span class="field-label">Bulk Discount Threshold (qty)</span>
                <input id="product-bulk-quantity" name="bulk_quantity_threshold" type="number" min="0" value="<?php echo htmlspecialchars($editProduct['bulk_quantity_threshold'] ?? '0'); ?>" placeholder="0" />
                <span class="field-hint">Minimum quantity to trigger bulk discount</span>
              </label>
              <label class="input-group">
                <span class="field-label">Bulk Discount Percentage (%)</span>
                <input id="product-bulk-discount" name="bulk_discount_percentage" type="number" min="0" max="100" step="0.01" value="<?php echo htmlspecialchars($editProduct['bulk_discount_percentage'] ?? '0'); ?>" placeholder="0" />
                <span class="field-hint">Discount applied when threshold is met</span>
              </label>
              <label class="input-group">
                <span class="field-label">Expiry Date</span>
                <?php if (!empty($editProduct['expiry_date']) && is_product_expired($editProduct['expiry_date'])): ?>
                  <input id="product-expiry-date" name="expiry_date" type="date" value="<?php echo htmlspecialchars($editProduct['expiry_date']); ?>" disabled />
                  <input type="hidden" name="expiry_date" value="<?php echo htmlspecialchars($editProduct['expiry_date']); ?>" />
                  <span class="field-hint" style="color:#dc2626;font-weight:700;">⚠ Expired — expiry date cannot be changed. Use Restock to add new stock with a new expiry date.</span>
                <?php else: ?>
                  <input id="product-expiry-date" name="expiry_date" type="date" value="<?php echo htmlspecialchars($editProduct['expiry_date'] ?? ''); ?>" />
                  <span class="field-hint">Leave empty for products without expiry (e.g. electronics)</span>
                <?php endif; ?>
              </label>
            </div>
          </div>

          <div class="form-section">
            <div class="section-title">Product Image</div>
            <div class="image-section">
              <div class="upload-section">
                <label class="input-group">
                  <span class="field-label">Upload new image</span>
                  <input id="product-image-file" name="image_file" type="file" accept=".png,.jpg,.jpeg,.svg" />
                </label>
                <input type="hidden" name="existing_image" value="<?php echo htmlspecialchars($editProduct['image'] ?? 'images/uploads/asano.jpg'); ?>" />
              </div>
              <div class="image-picker" data-image-options='<?php echo json_encode($imageOptions); ?>'>
                <input type="hidden" id="product-image-menu" name="image_menu" value="<?php echo htmlspecialchars($editProduct['image'] ?? 'images/uploads/asano.jpg'); ?>" />
                <div id="image-thumbnails" class="image-thumbnails"></div>
                <div class="image-preview-wrapper">
                  <img id="image-preview" src="<?php echo htmlspecialchars(product_image_src($editProduct['image'] ?? 'images/uploads/asano.jpg')); ?>" alt="Product preview" class="image-preview-large" />
                </div>
              </div>
            </div>
          </div>

          <div class="form-actions">
            <button id="save-product" class="primary" type="submit"><?php echo $editProduct ? 'Update product' : 'Save product'; ?></button>
            <?php if ($editProduct): ?>
              <a href="admin.php" class="secondary" role="button">Cancel edit</a>
            <?php endif; ?>
          </div>
        </form>

        <div id="admin-product-list" class="admin-product-list">
          <?php if (count($products) === 0): ?>
            <p class="empty-message">No products available.</p>
          <?php endif; ?>
          <?php foreach ($products as $product): ?>
            <article class="admin-product-card">
                <img src="<?php echo htmlspecialchars(product_image_src($product['image'])); ?>" alt="<?php echo htmlspecialchars($product['name']); ?>" />
              <div class="admin-info">
                <div class="admin-name"><?php echo htmlspecialchars($product['name']); ?></div>
                <div class="admin-price">
                  <div>Selling: GH₵<?php echo htmlspecialchars(number_format($product['selling_price'], 2)); ?></div>
                  <?php if ($user['role'] === 'admin'): ?>
                    <div style="font-size:0.75rem;color:#64748b;">Cost: GH₵<?php echo htmlspecialchars(number_format($product['cost_price'], 2)); ?></div>
                    <?php
                      $margin = $product['selling_price'] - $product['cost_price'];
                      $marginPercent = $product['selling_price'] > 0 ? round(($margin / $product['selling_price']) * 100, 1) : 0;
                      $marginClass = $margin >= 0 ? 'text-success' : 'text-error';
                    ?>
                    <div class="<?php echo $marginClass; ?>" style="font-size:0.8rem;font-weight:600;">
                      Margin: GH₵<?php echo htmlspecialchars(number_format($margin, 2)); ?> (<?php echo htmlspecialchars($marginPercent); ?>%)
                    </div>
                  <?php endif; ?>
                </div>
              </div>
              <div class="admin-lower">
                <div class="admin-details">
                  <div class="admin-description">
                    <div class="admin-meta"><?php echo htmlspecialchars($product['category']); ?> • Code <?php echo htmlspecialchars($product['code']); ?></div>
                    <?php
                      $isExpired = is_product_expired($product['expiry_date'] ?? null);
                      $isExpiringSoon = !$isExpired && is_product_expiring_soon($product['expiry_date'] ?? null);
                      $hasExpiry = !empty($product['expiry_date']);
                    ?>
                    <?php if ($hasExpiry): ?>
                      <div class="admin-expiry <?php echo $isExpired ? 'expiry-expired' : ($isExpiringSoon ? 'expiry-warning' : 'expiry-ok'); ?>">
                        <?php if ($isExpired): ?>
                          <span class="expiry-badge expired">⚠ EXPIRED</span>
                          <span>Expired on <?php echo htmlspecialchars(date('M d, Y', strtotime($product['expiry_date']))); ?></span>
                        <?php elseif ($isExpiringSoon): ?>
                          <span class="expiry-badge expiring">⏰ Expiring Soon</span>
                          <span>Expires <?php echo htmlspecialchars(date('M d, Y', strtotime($product['expiry_date']))); ?></span>
                        <?php else: ?>
                          <span class="expiry-badge valid">✓ Valid</span>
                          <span>Expires <?php echo htmlspecialchars(date('M d, Y', strtotime($product['expiry_date']))); ?></span>
                        <?php endif; ?>
                      </div>
                    <?php endif; ?>
                    <div class="admin-stock <?php echo ($product['quantity'] <= 0) ? 'out-of-stock' : 'in-stock'; ?>">
                      Stock: <?php echo htmlspecialchars($product['quantity']); ?> 
                      <?php if ($product['quantity'] <= 0): ?><span class="stock-warning">(Out of stock)</span><?php endif; ?>
                      <?php if ($isExpired && $product['quantity'] > 0): ?>
                        <span class="stock-warning">(Expired — Loss: GH₵<?php echo htmlspecialchars(number_format($product['cost_price'] * $product['quantity'], 2)); ?>)</span>
                      <?php endif; ?>
                      <?php if (!empty($product['bulk_quantity_threshold']) && !empty($product['bulk_discount_percentage'])): ?>
                        <span class="bulk-discount-badge">Bulk: <?php echo htmlspecialchars($product['bulk_quantity_threshold']); ?>+ @ <?php echo htmlspecialchars($product['bulk_discount_percentage']); ?>% off</span>
                      <?php endif; ?>
                      <form method="post" action="admin.php" class="stock-update-form">
                        <input type="hidden" name="action" value="update_stock" />
                        <input type="hidden" name="product_id" value="<?php echo $product['id']; ?>" />
  <input type="number" name="quantity" value="<?php echo htmlspecialchars($product['quantity']); ?>" min="0" class="stock-input" />
                        <button type="submit" class="stock-update-btn">Update</button>
                      </form>
                    </div>
                  </div>
                </div>
                <div class="admin-actions">
                  <a class="tertiary" href="admin.php?edit=<?php echo $product['id']; ?>">Edit</a>
  <form method="post" action="admin.php" class="inline-form">
                  <input type="hidden" name="action" value="delete_product" />
              <input type="hidden" name="product_id" value="<?php echo $product['id']; ?>" />
              <button class="danger" type="submit" onclick="return confirm('Delete this product?');">Delete</button>
            </form>
                </div>
              </div>
              <?php if ($isExpired): ?>
              <div class="restock-section">
                <div class="restock-header">🔄 Restock expired product</div>
                <form method="post" action="admin.php" class="restock-form" onsubmit="return confirm('Restock this expired product with new stock? The expired batch loss will be recorded.');">
                  <input type="hidden" name="action" value="restock_expired" />
                  <input type="hidden" name="product_id" value="<?php echo $product['id']; ?>" />
                  <div class="restock-fields">
                    <label class="input-group">
                      <span class="field-label">New qty to add</span>
                      <input type="number" name="restock_quantity" min="1" required placeholder="0" class="restock-input" />
                    </label>
                    <label class="input-group">
                      <span class="field-label">New expiry date</span>
                      <input type="date" name="new_expiry_date" required class="restock-input" min="<?php echo date('Y-m-d'); ?>" />
                    </label>
                    <button type="submit" class="primary restock-btn">Restock</button>
                  </div>
                  <span class="field-hint">Expired batch (<?php echo htmlspecialchars($product['quantity']); ?> units) loss: GH₵<?php echo htmlspecialchars(number_format($product['cost_price'] * $product['quantity'], 2)); ?> will be recorded.</span>
                </form>
              </div>
              <?php endif; ?>
            </article>
          <?php endforeach; ?>
        </div>
      </section>

      <section class="panel receipts-management-panel">
        <div class="panel-header">
          <h2>Sales & Returns Management</h2>
          <span class="hint">View all sales receipts, manage returns, and delete sales.</span>
        </div>

        <?php if (empty($receipts)): ?>
          <p class="empty-message">No receipts available yet.</p>
        <?php else: ?>
          <div class="receipts-list">
            <?php foreach ($receipts as $receipt): ?>
              <article class="receipt-management-card">
                <div class="receipt-management-info">
                  <div class="receipt-id">Receipt #<?php echo htmlspecialchars(str_pad((string)$receipt['order_id'], 8, '0', STR_PAD_LEFT)); ?></div>
                  <div class="receipt-meta">
                    Sales Rep: <?php echo htmlspecialchars($receipt['username']); ?> | 
                    Date: <?php echo htmlspecialchars(date('Y-m-d H:i', strtotime($receipt['created_at']))); ?>
                  </div>
                  <div class="receipt-return-status">
                    Status: 
                    <?php if ($receipt['return_status'] === 'Returned'): ?>
<span class="receipt-status returned">RETURNED</span>
                     <?php else: ?>
                       <span class="receipt-status active">ACTIVE</span>
                    <?php endif; ?>
                  </div>
                </div>
                <pre class="receipt-management-preview"><?php echo htmlspecialchars($receipt['receipt_content']); ?></pre>
                <div class="receipt-management-actions">
<form method="post" action="admin.php" class="inline-form">
                      <input type="hidden" name="action" value="update_return_status" />
                    <input type="hidden" name="receipt_id" value="<?php echo $receipt['id']; ?>" />
                    <select name="return_status" class="status-select" onchange="this.form.submit()">
                      <option value="Active" <?php echo $receipt['return_status'] === 'Active' ? 'selected' : ''; ?>>Mark as Active</option>
                      <option value="Returned" <?php echo $receipt['return_status'] === 'Returned' ? 'selected' : ''; ?>>Mark as Returned</option>
                    </select>
                  </form>
<form method="post" action="admin.php" class="inline-form">
                      <input type="hidden" name="action" value="delete_sale" />
                    <input type="hidden" name="order_id" value="<?php echo $receipt['order_id']; ?>" />
                    <button class="danger" type="submit" onclick="return confirm('Delete this sale permanently?');">Delete Sale</button>
                  </form>
                </div>
              </article>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </section>

      <section class="panel user-management-panel">
        <div class="panel-header">
          <h2>User Account Management</h2>
          <span class="hint">Create and manage sales accounts for your team.</span>
        </div>

        <div class="user-form-section">
          <h3>Create New User Account</h3>
          <form class="admin-form" method="post" action="admin.php">
            <input type="hidden" name="action" value="create_user" />
            <div class="field-grid">
              <label class="input-group">
                Username
                <input name="username" type="text" required placeholder="e.g., john_doe" />
              </label>
              <label class="input-group">
                Password
                <input name="password" type="password" required minlength="6" placeholder="Minimum 6 characters" />
              </label>
              <label class="input-group">
                Role
                <select name="role" required>
                  <option value="sales">Sales Representative</option>
                  <option value="admin">Administrator</option>
                </select>
              </label>
              <div class="form-actions">
                <button type="submit" class="primary">Create Account</button>
              </div>
            </div>
          </form>
        </div>

        <div class="user-list-section">
          <h3>Existing User Accounts</h3>
          <?php if (empty($users)): ?>
            <p class="empty-message">No user accounts found.</p>
          <?php else: ?>
            <div class="user-list">
              <?php foreach ($users as $user): ?>
                <article class="user-card">
                  <div class="user-info">
                    <div class="user-name"><?php echo htmlspecialchars($user['username']); ?></div>
                    <div class="user-role <?php echo $user['role'] === 'admin' ? 'admin-role' : 'sales-role'; ?>">
                      <?php echo ucfirst($user['role']); ?>
                    </div>
                    <div class="user-created">Created: <?php echo htmlspecialchars(date('M j, Y', strtotime($user['created_at']))); ?></div>
                  </div>
                  <div class="user-actions">
                    <?php if ($user['id'] !== $_SESSION['user']['id']): ?>
<form method="post" action="admin.php" class="inline-form">
                         <input type="hidden" name="action" value="delete_user" />
                        <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>" />
                        <button class="danger" type="submit" onclick="return confirm('Delete this user account? This action cannot be undone.')">Delete</button>
                      </form>
                    <?php else: ?>
                      <span class="current-user">Current Account</span>
                    <?php endif; ?>
                  </div>
                </article>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      </section>

      <?php if (is_super_admin()): ?>
      <section class="panel login-history-panel">
        <div class="panel-header">
          <h2>Login History</h2>
          <span class="hint">All successful and failed login attempts across the entire system.</span>
        </div>

        <?php if (empty($loginActivities)): ?>
          <p class="empty-message">No login activity recorded yet.</p>
        <?php else: ?>
          <div class="login-history-list">
            <?php foreach ($loginActivities as $activity): ?>
              <?php
                $time = date('Y-m-d H:i:s', strtotime($activity['created_at']));
                $status = $activity['status'] ?? 'success';
                $statusClass = $status === 'failed' ? 'login-failed' : 'login-success';
                $statusLabel = $status === 'failed' ? 'Failed' : 'Success';
                $reason = $activity['reason'] ?? '';
              ?>
              <div class="login-history-item <?php echo $statusClass; ?>">
                <span class="login-user"><?php echo htmlspecialchars($activity['username']); ?></span>
                <span class="user-role <?php echo $activity['role'] === 'admin' ? 'admin-role' : 'sales-role'; ?>">
                  <?php echo htmlspecialchars(ucfirst($activity['role'])); ?>
                </span>
                <span class="login-status <?php echo $statusClass; ?>"><?php echo htmlspecialchars($statusLabel); ?></span>
                <span class="login-time"><?php echo htmlspecialchars($time); ?></span>
                <?php if (!empty($activity['ip'])): ?>
                  <span class="login-ip"><?php echo htmlspecialchars($activity['ip']); ?></span>
                <?php endif; ?>
                <?php if ($reason): ?>
                  <span class="login-reason"><?php echo htmlspecialchars($reason); ?></span>
                <?php endif; ?>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </section>
      <?php endif; ?>

      <section class="panel login-history-panel">
        <div class="panel-header">
          <h2>Activity Log</h2>
          <span class="hint">Comprehensive log of all administrative actions, sales, returns, and system events.</span>
        </div>

        <?php
          $actionLabels = [
              'login' => 'Signed in',
              'login_logout' => 'Signed out',
              'product_saved' => 'Saved product',
              'product_deleted' => 'Deleted product',
              'sale_deleted' => 'Deleted sale',
              'return_status_updated' => 'Updated return status',
              'user_created' => 'Created user',
              'user_deleted' => 'Deleted user',
              'stock_updated' => 'Updated stock',
              'settings_updated' => 'Updated settings',
              'sale_completed' => 'Completed sale',
              'return_processed' => 'Processed return',
              'credit_paid' => 'Credit payment received',
          ];
        ?>

        <?php if (empty($activitySessions)): ?>
          <p class="empty-message">No activity recorded yet.</p>
        <?php else: ?>
          <div class="history-list">
            <?php foreach ($activitySessions as $session): ?>
              <?php
                $sessionStart = date('Y-m-d H:i:s', strtotime($session['session_started_at']));
                $sessionEnd = $session['session_ended_at'] ? date('Y-m-d H:i:s', strtotime($session['session_ended_at'])) : null;
                $isActive = empty($session['session_ended_at']);
                $sessionStatusClass = $isActive ? 'active' : 'logged-out';
                if ($isActive) {
                    $sessionStatusLabel = date('H:i', strtotime($sessionStart));
                } else {
                    $sessionStatusLabel = date('H:i', strtotime($sessionStart)) . ' → ' . date('H:i', strtotime($sessionEnd));
                }
                $activities = $session['activities'] ?? [];
              ?>
              <article class="history-card session-card">
                <div class="session-header">
                  <strong class="session-user"><?php echo htmlspecialchars($session['username']); ?></strong>
                  <span class="user-role <?php echo $session['role'] === 'admin' ? 'admin-role' : 'sales-role'; ?>">
                    <?php echo htmlspecialchars(ucfirst($session['role'])); ?>
                  </span>
                  <span class="session-status <?php echo $sessionStatusClass; ?>">
                    <?php echo htmlspecialchars($sessionStatusLabel); ?>
                  </span>
                  <span class="session-meta">
                    <?php if (!empty($session['ip'])): ?>
                      · <?php echo htmlspecialchars($session['ip']); ?>
                    <?php endif; ?>
                  </span>
                </div>
                <?php if (!empty($activities)): ?>
                  <div class="session-activities">
                    <div class="activities-header"><?php echo count($activities); ?> activities</div>
                    <ul class="activity-list">
                      <?php foreach ($activities as $activity): ?>
                        <?php
                          $activityTime = date('H:i:s', strtotime($activity['timestamp'] ?? $session['session_started_at']));
                          $actionLabel = $actionLabels[$activity['action']] ?? ucwords(str_replace('_', ' ', $activity['action']));
                          $actionClass = strtolower($activity['action']);
                        ?>
                        <li class="activity-item">
                          <span class="activity-status action-status <?php echo htmlspecialchars($actionClass); ?>">
                            <?php echo htmlspecialchars($actionLabel); ?>
                          </span>
                          <span class="activity-description"><?php echo htmlspecialchars($activity['description']); ?></span>
                          <span class="activity-time"><?php echo htmlspecialchars($activityTime); ?></span>
                        </li>
                      <?php endforeach; ?>
                    </ul>
                  </div>
                <?php endif; ?>
              </article>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </section>

      <?php if (is_super_admin()): ?>
      <div class="admin-controls-grid">
        <section class="panel activation-panel">
          <div class="panel-header">
            <h2>App Activation Controls</h2>
            <span class="hint">Manage application activation state and access periods.</span>
          </div>
          <div class="panel-content">
            <div class="activation-status">
              <?php
                $isActivated = !empty($activationStatus) && $activationStatus['status'] === 'activated';
                $isExpired = !empty($activationStatus) && $activationStatus['status'] === 'expired';
                $statusClass = $isActivated ? 'activated' : ($isExpired ? 'expired' : 'trial');
                $statusLabel = $isActivated ? 'Activated' : ($isExpired ? 'Expired' : 'Trial Active');
              ?>
              <div class="activation-status-badge <?php echo $statusClass; ?>">
                <?php echo htmlspecialchars($statusLabel); ?>
              </div>
              <?php if (!empty($activationStatus) && $activationStatus['deadline']): ?>
                <div class="activation-meta">
                  <?php if ($isActivated): ?>
                    Permanently activated
                  <?php else: ?>
                    Deadline: <?php echo htmlspecialchars(date('Y-m-d H:i', $activationStatus['deadline'])); ?>
                    <?php if ($activationStatus['hours_remaining'] !== null && !$isExpired): ?>
                      (<?php echo htmlspecialchars($activationStatus['hours_remaining']); ?> hours remaining)
                    <?php endif; ?>
                  <?php endif; ?>
                </div>
              <?php endif; ?>
            </div>

            <form method="post" action="admin.php" class="settings-form" style="margin-top:18px;">
              <input type="hidden" name="action" value="admin_activate_app" />
              <button type="submit" class="primary" onclick="return confirm('Activate the application permanently? This will unlock all features.');">Activate App</button>
            </form>

            <form method="post" action="admin.php" class="settings-form" style="margin-top:18px;">
              <input type="hidden" name="action" value="set_activation_period" />
              <div class="form-group">
                <label for="activation_period_minutes">Activation Period (minutes)</label>
                <input type="number" id="activation_period_minutes" name="activation_period_minutes" value="<?php echo htmlspecialchars($activationPeriodMinutes ?? ''); ?>" min="1" required />
                <span class="field-hint">Current: <?php echo htmlspecialchars($activationPeriodMinutes ?? '0'); ?> minutes (<?php echo htmlspecialchars(round(($activationPeriodMinutes ?? 0) / 60 / 24, 1)); ?> days)</span>
              </div>
              <button type="submit" class="secondary">Set Activation Period</button>
            </form>

            <form method="post" action="admin.php" class="settings-form" style="margin-top:18px;">
              <input type="hidden" name="action" value="admin_cancel_activation" />
              <button type="submit" class="danger" onclick="return confirm('Cancel application activation? This will revoke access and require reactivation.');">Cancel Activation</button>
            </form>
          </div>
        </section>

        <section class="panel settings-panel">
          <div class="panel-header">
            <h2>Settings</h2>
            <span class="hint">Configure system settings like the POS name.</span>
          </div>
          <div class="panel-content">
            <form method="post" action="admin.php" class="settings-form">
              <input type="hidden" name="action" value="update_pos_name" />
              <div class="form-group">
                <label for="pos_name">Point of Sale Name</label>
                <input type="text" id="pos_name" name="pos_name" value="<?php echo htmlspecialchars($posName); ?>" required />
              </div>
              <button type="submit" class="primary">Update Name</button>
            </form>
            <form method="post" action="admin.php" class="settings-form" style="margin-top:18px;" enctype="multipart/form-data">
              <input type="hidden" name="action" value="update_trademark" />
              <div class="form-group">
                <label for="trademark_image">Trademark Image (Logo)</label>
                <input type="file" id="trademark_image" name="trademark_image" accept=".png,.jpg,.jpeg,.svg,.webp" required />
                <?php if ($trademark): ?>
                  <p class="login-hint">Current: <img src="<?php echo htmlspecialchars(get_trademark_src()); ?>" alt="Current trademark" style="height:40px;vertical-align:middle;" /></p>
                <?php endif; ?>
              </div>
              <button type="submit" class="primary">Upload Trademark</button>
            </form>
          </div>
        </section>

        <section class="panel theme-panel">
          <div class="panel-header">
            <h2>Theme Customization</h2>
            <span class="hint">Customize the app's visual identity. Changes apply globally.</span>
          </div>
          <div class="panel-content">
            <form method="post" action="admin.php" class="settings-form" enctype="multipart/form-data">
              <input type="hidden" name="action" value="update_theme" />
              <div class="form-group">
                <label for="theme_primary_color">Primary Color</label>
                <input type="color" id="theme_primary_color" name="theme_primary_color" value="<?php echo htmlspecialchars($themePrimaryColor ?? '#001a4a'); ?>" style="height:44px;padding:4px;width:100%;cursor:pointer;" />
                <span class="field-hint">Used for primary buttons and key accents.</span>
              </div>
              <div class="form-group">
                <label for="theme_secondary_color">Secondary Color</label>
                <input type="color" id="theme_secondary_color" name="theme_secondary_color" value="<?php echo htmlspecialchars($themeSecondaryColor ?? '#003080'); ?>" style="height:44px;padding:4px;width:100%;cursor:pointer;" />
                <span class="field-hint">Used for hover states and secondary accents.</span>
              </div>
              <div class="form-group">
                <label for="theme_background_color">Background Color</label>
                <input type="color" id="theme_background_color" name="theme_background_color" value="<?php echo htmlspecialchars($themeBackgroundColor ?? '#f1f5f9'); ?>" style="height:44px;padding:4px;width:100%;cursor:pointer;" />
                <span class="field-hint">Used for page and card backgrounds.</span>
              </div>
              <div class="form-group">
                <label for="theme_login_brand_image">Login Brand Image</label>
                <input type="file" id="theme_login_brand_image" name="theme_login_brand_image" accept=".png,.jpg,.jpeg,.svg,.webp" />
                <?php if (!empty($themeLoginBrandImage) && file_exists(__DIR__ . '/../' . $themeLoginBrandImage)): ?>
                  <p class="login-hint">Current: <img src="<?php echo htmlspecialchars($themeLoginBrandImage); ?>" alt="Current brand image" style="height:40px;vertical-align:middle;" /></p>
                <?php endif; ?>
              </div>
              <button type="submit" class="primary">Update Theme</button>
            </form>
          </div>
        </section>
      </div>
      <?php else: ?>
      <section class="panel settings-panel">
        <div class="panel-header">
          <h2>Settings</h2>
          <span class="hint">Configure system settings like the POS name.</span>
        </div>
        <div class="panel-content">
          <form method="post" action="admin.php" class="settings-form">
            <input type="hidden" name="action" value="update_pos_name" />
            <div class="form-group">
              <label for="pos_name">Point of Sale Name</label>
              <input type="text" id="pos_name" name="pos_name" value="<?php echo htmlspecialchars($posName); ?>" required />
            </div>
            <button type="submit" class="primary">Update Name</button>
          </form>
          <form method="post" action="admin.php" class="settings-form" style="margin-top:18px;" enctype="multipart/form-data">
            <input type="hidden" name="action" value="update_trademark" />
            <div class="form-group">
              <label for="trademark_image">Trademark Image (Logo)</label>
              <input type="file" id="trademark_image" name="trademark_image" accept=".png,.jpg,.jpeg,.svg,.webp" required />
              <?php if ($trademark): ?>
                <p class="login-hint">Current: <img src="<?php echo htmlspecialchars(get_trademark_src()); ?>" alt="Current trademark" style="height:40px;vertical-align:middle;" /></p>
              <?php endif; ?>
            </div>
            <button type="submit" class="primary">Upload Trademark</button>
          </form>
        </div>
      </section>
      <?php endif; ?>
    </main>
  </div>
  <script>
    document.addEventListener('DOMContentLoaded', function() {
      const imagePicker = document.querySelector('.image-picker');
      const hiddenInput = document.getElementById('product-image-menu');
      const preview = document.getElementById('image-preview');
      const thumbnailsContainer = document.getElementById('image-thumbnails');
      const imageOptions = JSON.parse(imagePicker.dataset.imageOptions || '{}');
      const currentValue = hiddenInput.value || 'images/uploads/asano.jpg';

      function renderThumbnails() {
        thumbnailsContainer.innerHTML = '';
        Object.entries(imageOptions).forEach(([path, label]) => {
          const thumb = document.createElement('img');
          thumb.src = path;
          thumb.alt = label;
          thumb.title = label;
          thumb.className = 'image-thumbnail' + (path === currentValue ? ' selected' : '');
          thumb.onclick = () => selectImage(path, thumb);
          thumbnailsContainer.appendChild(thumb);
        });
      }

      function selectImage(path, thumb) {
        hiddenInput.value = path;
        preview.src = path;
        thumbnailsContainer.querySelectorAll('.image-thumbnail').forEach(t => t.classList.remove('selected'));
        thumb.classList.add('selected');
      }

      renderThumbnails();

      // Handle upload preview
      const fileInput = document.getElementById('product-image-file');
      if (fileInput) {
        fileInput.onchange = function(e) {
          const file = e.target.files[0];
          if (file) {
            const url = URL.createObjectURL(file);
            preview.src = url;
            // Note: full path set on server after upload
          }
        };
      }

      // Product name character counter
      const productNameInput = document.getElementById('product-name');
      const nameCounter = document.getElementById('name-counter');
      if (productNameInput && nameCounter) {
        const updateCounter = () => {
          const len = productNameInput.value.length;
          nameCounter.textContent = len + ' / 30';
        };
        updateCounter();
        productNameInput.addEventListener('input', updateCounter);
      }
    });
  </script>
  <script src="script.js"></script>
  <script src="nav.js"></script>
  <div id="toast-container" aria-live="polite" aria-atomic="false"></div>
  <footer class="app-footer">
    Designed by Ten12 Tech&copy;<br />
    &copy;2026<br />
    Contact: +233 55 850 4111
  </footer>
</body>
</html>
