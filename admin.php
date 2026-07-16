<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);
require_once __DIR__ . '/inc/functions.php';
require_admin();

try {
    $posName = get_pos_name();
    $products = load_products();
    $imageOptions = get_image_options();
    $receipts = load_receipts();
    $users = get_all_users();
    $loginEvents = load_login_events();
} catch (Exception $e) {
    die('Database error: ' . $e->getMessage());
}
$message = '';
$error = '';
$editProduct = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save_product') {
        $productId = isset($_POST['product_id']) ? intval($_POST['product_id']) : null;
        $name = trim($_POST['name'] ?? '');
        $category = trim($_POST['category'] ?? '');
        $price = floatval($_POST['price'] ?? 0);
        $quantity = intval($_POST['quantity'] ?? 0);
        $code = trim($_POST['code'] ?? '');
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

        if ($name === '' || $category === '' || $price <= 0 || $code === '') {
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
                            $product['price'] = $price;
                            $product['quantity'] = $quantity;
                            $product['code'] = $code;
                            $product['image'] = $image;
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
                        'price' => $price,
                        'quantity' => $quantity,
                        'image' => $image,
                    ];
                }

                save_products($products);
                header('Location: admin.php?success=1');
                exit;
            }
        }
    }

    if ($action === 'delete_product') {
        $productId = intval($_POST['product_id'] ?? 0);
        $products = array_values(array_filter($products, fn($product) => $product['id'] !== $productId));
        save_products($products);
        header('Location: admin.php?success=1');
        exit;
    }

    if ($action === 'delete_sale') {
        $orderId = intval($_POST['order_id'] ?? 0);
        if ($orderId > 0 && delete_sale($orderId, 'admin')) {
            $message = 'Sale deleted successfully.';
            header('Location: admin.php?success=1');
            exit;
        }
        $error = 'Failed to delete sale.';
    }

    if ($action === 'update_return_status') {
        $receiptId = intval($_POST['receipt_id'] ?? 0);
        $status = $_POST['return_status'] ?? 'Active';
        
        if ($receiptId > 0 && in_array($status, ['Active', 'Returned'], true)) {
            if (update_receipt_status($receiptId, $status)) {
                header('Location: admin.php?success=1');
                exit;
            }
        }
        $error = 'Failed to update receipt status.';
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
                $message = 'User account created successfully.';
            } else {
                $error = 'Failed to create user account. Username might already exist.';
            }
        }
    }

    if ($action === 'delete_user') {
        $userId = intval($_POST['user_id'] ?? 0);
        if ($userId > 0) {
            if (delete_user($userId)) {
                $message = 'User account deleted successfully.';
            } else {
                $error = 'Failed to delete user account.';
            }
        }
    }

    if ($action === 'update_stock') {
        $productId = intval($_POST['product_id'] ?? 0);
        $newQuantity = intval($_POST['quantity'] ?? 0);
        
        if ($productId > 0) {
            if (update_product_stock($productId, $newQuantity)) {
                $message = 'Stock updated successfully.';
            } else {
                $error = 'Failed to update stock.';
            }
        }
    }

    if ($action === 'update_pos_name') {
        $newName = trim($_POST['pos_name'] ?? '');
        if ($newName !== '') {
            if (set_pos_name($newName)) {
                $message = 'POS name updated successfully.';
            } else {
                $error = 'Failed to update POS name.';
            }
        } else {
            $error = 'POS name cannot be empty.';
        }
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

$success = isset($_GET['success']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title><?php echo htmlspecialchars($posName); ?> - Admin Dashboard</title>
  <link rel="icon" type="image/svg+xml" href="images/pos-icon.svg" />
  <link rel="stylesheet" href="styles.css" />
</head>
<body data-page="admin">
  <div class="app-shell">
    <header class="header">
      <div class="brand">
        <img class="brand-icon" src="images/pos-icon.svg" alt="POS icon" />
        <div>
          <h1><?php echo htmlspecialchars($posName); ?></h1>
          <p class="subtitle">Admin dashboard for product management.</p>
        </div>
      </div>
      <button class="menu-toggle" id="menu-toggle" aria-label="Toggle navigation menu" aria-expanded="false" aria-controls="header-actions">
        <span></span><span></span><span></span>
      </button>

      <div class="header-actions" id="header-actions">
        <a href="returns.php" class="secondary">All sales</a>
        <a href="sales_report.php" class="secondary">Sales reports</a>
        <a href="sales.php" class="secondary">Sales register</a>
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

      <section class="panel admin-management">
        <div class="panel-header">
          <h2>Product manager</h2>
        </div>

        <?php if ($success): ?>
          <p class="login-hint">Product list updated successfully.</p>
        <?php endif; ?>
        <?php if ($message): ?>
          <p class="error-text"><?php echo htmlspecialchars($message); ?></p>
        <?php endif; ?>

        <form id="product-form" class="admin-form" method="post" action="admin.php" enctype="multipart/form-data">
          <input type="hidden" name="action" value="save_product" />
          <input type="hidden" name="product_id" value="<?php echo htmlspecialchars($editProduct['id'] ?? ''); ?>" />
          <div class="field-grid">
            <label class="input-group">
              Name
              <input id="product-name" name="name" type="text" required value="<?php echo htmlspecialchars($editProduct['name'] ?? ''); ?>" />
            </label>
            <label class="input-group">
              Category
              <input id="product-category" name="category" type="text" required value="<?php echo htmlspecialchars($editProduct['category'] ?? ''); ?>" />
            </label>
            <label class="input-group">
              Price
              <input id="product-price" name="price" type="number" min="0.01" step="0.01" required value="<?php echo htmlspecialchars($editProduct['price'] ?? ''); ?>" />
            </label>
            <label class="input-group">
              Stock Quantity
              <input id="product-quantity" name="quantity" type="number" min="0" required value="<?php echo htmlspecialchars($editProduct['quantity'] ?? '0'); ?>" />
            </label>
            <label class="input-group">
              Barcode
              <input id="product-code" name="code" type="text" required value="<?php echo htmlspecialchars($editProduct['code'] ?? ''); ?>" />
            </label>
            <label class="input-group">
              Image
<div class="image-picker" data-image-options='<?php echo json_encode($imageOptions); ?>'>
                <input type="hidden" id="product-image-menu" name="image_menu" value="<?php echo htmlspecialchars($editProduct['image'] ?? 'images/uploads/asano.jpg'); ?>" />
                <div id="image-thumbnails" class="image-thumbnails"></div>
                <img id="image-preview" src="<?php echo htmlspecialchars(product_image_src($editProduct['image'] ?? 'images/uploads/asano.jpg')); ?>" alt="Product preview" class="image-preview-large" />
              </div>
            </label>
            <label class="input-group">
              Upload image
              <input id="product-image-file" name="image_file" type="file" accept=".png,.jpg,.jpeg,.svg" />
            </label>
            <input type="hidden" name="existing_image" value="<?php echo htmlspecialchars($editProduct['image'] ?? 'images/uploads/asano.jpg'); ?>" />
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
              <div class="admin-details">
                <div class="admin-description">
                  <div class="admin-name"><?php echo htmlspecialchars($product['name']); ?></div>
                  <div class="admin-meta"><?php echo htmlspecialchars($product['category']); ?> • Code <?php echo htmlspecialchars($product['code']); ?></div>
                  <div class="admin-stock <?php echo ($product['quantity'] <= 0) ? 'out-of-stock' : 'in-stock'; ?>">
                    Stock: <?php echo htmlspecialchars($product['quantity']); ?> 
                    <?php if ($product['quantity'] <= 0): ?><span class="stock-warning">(Out of stock)</span><?php endif; ?>
                    <form method="post" action="admin.php" class="stock-update-form">
                      <input type="hidden" name="action" value="update_stock" />
                      <input type="hidden" name="product_id" value="<?php echo $product['id']; ?>" />
<input type="number" name="quantity" value="<?php echo htmlspecialchars($product['quantity']); ?>" min="0" class="stock-input" />
                      <button type="submit" class="stock-update-btn">Update</button>
                    </form>
                  </div>
                </div>
                <div class="admin-price"><?php echo htmlspecialchars(number_format($product['price'], 2)); ?></div>
              </div>
              <div class="admin-actions">
                <a class="tertiary" href="admin.php?edit=<?php echo $product['id']; ?>">Edit</a>
<form method="post" action="admin.php" class="inline-form">
                      <input type="hidden" name="action" value="delete_product" />
                  <input type="hidden" name="product_id" value="<?php echo $product['id']; ?>" />
                  <button class="danger" type="submit" onclick="return confirm('Delete this product?');">Delete</button>
                </form>
              </div>
            </article>
          <?php endforeach; ?>
        </div>
      </section>

      <section class="panel receipts-management-panel">
        <div class="panel-header">
          <h2>Sales & Returns Management</h2>
          <span class="hint">View all sales receipts, manage returns, and delete sales.</span>
        </div>

        <?php if ($message): ?>
          <p class="login-hint text-success"><?php echo htmlspecialchars($message); ?></p>
        <?php endif; ?>
        <?php if ($error): ?>
          <p class="error-text"><?php echo htmlspecialchars($error); ?></p>
        <?php endif; ?>

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

      <section class="panel login-history-panel">
        <div class="panel-header">
          <h2>Login activity</h2>
          <span class="hint">Recent logins by sales representatives and administrators.</span>
        </div>

        <?php if (empty($loginEvents)): ?>
          <p class="empty-message">No login activity recorded yet.</p>
        <?php else: ?>
          <div class="history-list">
            <?php foreach ($loginEvents as $event): ?>
              <article class="history-card">
                <div class="history-main">
                  <strong><?php echo htmlspecialchars($event['username']); ?></strong>
                  <span class="user-role <?php echo $event['role'] === 'admin' ? 'admin-role' : 'sales-role'; ?>">
                    <?php echo htmlspecialchars(ucfirst($event['role'])); ?>
                  </span>
                </div>
                <div class="history-details">
                  <span>Signed in at <?php echo htmlspecialchars(date('Y-m-d H:i:s', strtotime($event['created_at']))); ?></span>
                  <?php if (!empty($event['ip'])): ?>
                    <span> | IP: <?php echo htmlspecialchars($event['ip']); ?></span>
                  <?php endif; ?>
                </div>
              </article>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
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
        </div>
      </section>
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
    });
  </script>
  <script src="nav.js"></script>
</body>
</html>
