const config = window.pageConfig || {};
const products = config.products || [];
const checkoutEndpoint = 'checkout.php';

// Only warn when the same product is entered again within this window (1 minute).
const DUPLICATE_WINDOW_MS = 60 * 1000;

const state = {
  cart: [],
  filter: "",
  // product id -> timestamp of the last time it was added to the cart.
  // Deliberately NOT reset on checkout: consecutive sales of the same product
  // within 1 minute must still warn the sales rep.
  lastAddedAt: {}
};

// Persist the window across page reloads (same tab) so an accidental F5 between
// orders doesn't silently clear it.
(function restoreLastAddedAt() {
  try {
    const saved = sessionStorage.getItem("pos_last_added_at");
    if (saved) {
      const parsed = JSON.parse(saved);
      if (parsed && typeof parsed === "object") {
        state.lastAddedAt = parsed;
      }
    }
  } catch (e) {
    // sessionStorage unavailable (e.g. privacy mode) — the window just resets on reload.
  }
})();

// Product waiting for the user's decision in the duplicate confirmation dialog.
let pendingDuplicateProduct = null;

const elements = {
  barcodeInput: document.getElementById("barcode-input"),
  scanButton: document.getElementById("scan-button"),
  cameraButton: document.getElementById("camera-button"),
  barcodeMessage: document.getElementById("barcode-message"),
  cameraPreview: document.getElementById("camera-preview"),
  barcodeVideo: document.getElementById("barcode-video"),
  stopCameraButton: document.getElementById("stop-camera-button"),
  productSearch: document.getElementById("product-search"),
  productList: document.getElementById("product-list"),
  cartItems: document.getElementById("cart-items"),
  subtotal: document.getElementById("subtotal"),
  tax: document.getElementById("tax"),
  total: document.getElementById("total"),
  receiptOutput: document.getElementById("receipt-output"),
  clearCartButton: document.getElementById("clear-cart"),
  checkoutButton: document.getElementById("checkout-button"),
  creditEnabled: document.getElementById("credit-enabled"),
  creditCustomerName: document.getElementById("credit-customer-name"),
  creditCustomerPhone: document.getElementById("credit-customer-phone"),
  creditFields: document.getElementById("credit-fields"),
  duplicateModal: document.getElementById("duplicate-modal"),
  duplicateProductName: document.getElementById("duplicate-product-name"),
  duplicateCartQty: document.getElementById("duplicate-cart-qty"),
  duplicateConfirm: document.getElementById("duplicate-confirm"),
  duplicateCancel: document.getElementById("duplicate-cancel")
};

function formatMoney(value) {
  return `GH₵${value.toFixed(2)}`;
}

function roundTo(value, decimals) {
  const factor = Math.pow(10, decimals);
  return Math.round(value * factor) / factor;
}

function getBulkUnitPrice(product, quantity) {
  const threshold = parseInt(product.bulk_quantity_threshold, 10) || 0;
  const discount = parseFloat(product.bulk_discount_percentage) || 0;
  if (threshold > 0 && discount > 0 && quantity >= threshold) {
    return roundTo(product.selling_price * (1 - discount / 100), 2);
  }
  return product.selling_price;
}

function getCartItem(productId) {
  return state.cart.find((entry) => entry.product.id === productId);
}

function commitAddToCart(product) {
  const existing = getCartItem(product.id);
  if (existing) {
    existing.quantity += 1;
  } else {
    state.cart.push({ product, quantity: 1 });
  }
  state.lastAddedAt[product.id] = Date.now();
  try {
    sessionStorage.setItem("pos_last_added_at", JSON.stringify(state.lastAddedAt));
  } catch (e) {
    // ignore — persistence is best-effort
  }
  renderCart();
}

function showDuplicatePrompt(product) {
  if (!elements.duplicateModal) {
    commitAddToCart(product);
    return;
  }

  pendingDuplicateProduct = product;
  elements.duplicateProductName.textContent = product.name;

  const existing = getCartItem(product.id);
  if (existing && elements.duplicateCartQty) {
    const units = existing.quantity === 1 ? "unit" : "units";
    elements.duplicateCartQty.textContent = `Currently in cart: ${existing.quantity} ${units}.`;
    elements.duplicateCartQty.style.display = "";
  } else if (elements.duplicateCartQty) {
    elements.duplicateCartQty.style.display = "none";
  }

  elements.duplicateModal.classList.remove("hidden");
  if (elements.duplicateConfirm) {
    elements.duplicateConfirm.focus();
  }
}

function addToCart(product) {
  const lastAdded = state.lastAddedAt[product.id] || 0;
  if (Date.now() - lastAdded < DUPLICATE_WINDOW_MS) {
    showDuplicatePrompt(product);
    return;
  }
  commitAddToCart(product);
}

function updateQuantity(productId, delta) {
  const item = getCartItem(productId);
  if (!item) return;
  item.quantity += delta;
  if (item.quantity <= 0) {
    state.cart = state.cart.filter((entry) => entry.product.id !== productId);
  }
  renderCart();
}

function removeItem(productId) {
  state.cart = state.cart.filter((entry) => entry.product.id !== productId);
  renderCart();
}

function clearCart() {
  state.cart = [];
  // lastAddedAt is intentionally kept: consecutive sales of the same product
  // within 1 minute should still show the confirmation prompt.
  renderCart();
  if (elements.receiptOutput) {
    elements.receiptOutput.textContent = "Cart cleared. Ready for the next order.";
  }
}

function computeTotals() {
  const subtotal = state.cart.reduce((sum, entry) => {
    const unitPrice = getBulkUnitPrice(entry.product, entry.quantity);
    return sum + unitPrice * entry.quantity;
  }, 0);
  const tax = subtotal * 0;
  const total = subtotal + tax;
  return { subtotal, tax, total };
}

function isProductExpired(expiryDate) {
  if (!expiryDate) return false;
  const expiry = new Date(expiryDate + 'T00:00:00');
  const today = new Date();
  today.setHours(0, 0, 0, 0);
  return expiry < today;
}

function isProductExpiringSoon(expiryDate, daysWarning) {
  daysWarning = daysWarning || 7;
  if (!expiryDate) return false;
  const expiry = new Date(expiryDate + 'T00:00:00');
  const today = new Date();
  today.setHours(0, 0, 0, 0);
  const warning = new Date();
  warning.setDate(warning.getDate() + daysWarning);
  return expiry >= today && expiry <= warning;
}

function renderProducts() {
  if (!elements.productList) return;

  const currentProducts = window.pageConfig.products || [];
  const searchTerm = state.filter.trim().toLowerCase();
  const filteredProducts = currentProducts.filter((product) => {
    // Block expired products from being sold
    if (isProductExpired(product.expiry_date)) {
      return false;
    }
    return (
      product.name.toLowerCase().includes(searchTerm) ||
      product.category.toLowerCase().includes(searchTerm) ||
      product.code.toLowerCase().includes(searchTerm)
    );
  });

  if (filteredProducts.length === 0) {
    elements.productList.innerHTML = '<p class="empty-message">No products match your search.</p>';
    return;
  }

  elements.productList.innerHTML = filteredProducts
    .map((product) => {
      const isOutOfStock = product.quantity <= 0;
      const stockText = product.quantity <= 0 ? 'Out of stock' : `Stock: ${product.quantity}`;
      const stockClass = product.quantity <= 0 ? 'out-of-stock' : 'in-stock';
      const buttonDisabled = isOutOfStock ? 'disabled' : '';
      const buttonClass = isOutOfStock ? 'secondary' : 'primary';

      const bulkThreshold = parseInt(product.bulk_quantity_threshold, 10) || 0;
      const bulkDiscountPercent = parseFloat(product.bulk_discount_percentage) || 0;
      const bulkBadge = (bulkThreshold > 0 && bulkDiscountPercent > 0)
        ? `<div class="bulk-discount-badge">Bulk: ${bulkThreshold}+ @ ${bulkDiscountPercent}% off</div>`
        : '';
      const discountedPrice = (bulkThreshold > 0 && bulkDiscountPercent > 0)
        ? formatMoney(roundTo(product.selling_price * (1 - bulkDiscountPercent / 100), 2))
        : formatMoney(product.selling_price);

      // Expiry date badge
      let expiryBadge = '';
      if (product.expiry_date) {
        const expDate = new Date(product.expiry_date + 'T00:00:00');
        const formatted = expDate.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
        if (isProductExpiringSoon(product.expiry_date)) {
          expiryBadge = `<div class="product-expiry expiring">⏰ Expires ${formatted}</div>`;
        } else {
          expiryBadge = `<div class="product-expiry valid">✓ Expires ${formatted}</div>`;
        }
      }

      return `
      <article class="product-card ${isOutOfStock ? 'out-of-stock' : ''}">
        <img src="${product.image}" alt="${product.name}" />
        <div class="product-info">
          <div class="product-name">${product.name}</div>
          <div class="product-price">${formatMoney(product.selling_price)}</div>
        </div>
        <div class="product-lower">
          <div class="product-details">
            <div class="product-category">${product.category} • Code ${product.code}</div>
            ${expiryBadge}
            ${bulkBadge}
            <div class="product-bulk-price">Bulk unit price: ${discountedPrice}</div>
            <div class="product-stock ${stockClass}">${stockText}</div>
          </div>
          <div class="product-actions">
            <button class="${buttonClass}" data-action="add" data-id="${product.id}" ${buttonDisabled}>${isOutOfStock ? 'Out of Stock' : 'Add'}</button>
          </div>
        </div>
      </article>`;
    })
    .join("");
}

function renderCart() {
  if (!elements.cartItems) return;

  if (state.cart.length === 0) {
    elements.cartItems.innerHTML = '<p class="empty-message">Your cart is empty.</p>';
    const totals = computeTotals();
    elements.subtotal.textContent = formatMoney(totals.subtotal);
    elements.tax.textContent = formatMoney(totals.tax);
    elements.total.textContent = formatMoney(totals.total);
    return;
  }

  elements.cartItems.innerHTML = state.cart
    .map((entry) => {
      const unitPrice = getBulkUnitPrice(entry.product, entry.quantity);
      const threshold = parseInt(entry.product.bulk_quantity_threshold, 10) || 0;
      const discount = parseFloat(entry.product.bulk_discount_percentage) || 0;
      const bulkApplied = threshold > 0 && discount > 0 && entry.quantity >= threshold;
      const metaSuffix = bulkApplied ? ` <span class="bulk-applied">(${discount}% off)</span>` : '';
      return `
      <article class="cart-item">
        <div class="cart-info">
          <div class="cart-name">${entry.product.name}</div>
          <div class="cart-meta">${entry.quantity} × ${formatMoney(unitPrice)}${metaSuffix}</div>
        </div>
        <div class="cart-actions-row">
          <button class="tertiary" data-action="decrease" data-id="${entry.product.id}">−</button>
          <button class="tertiary" data-action="increase" data-id="${entry.product.id}">+</button>
          <button class="secondary" data-action="remove" data-id="${entry.product.id}">Remove</button>
        </div>
      </article>`
    })
    .join("");

  const totals = computeTotals();
  elements.subtotal.textContent = formatMoney(totals.subtotal);
  elements.tax.textContent = formatMoney(totals.tax);
  elements.total.textContent = formatMoney(totals.total);
}

function createReceipt(orderId, credit) {
  if (state.cart.length === 0) {
    return "Your cart is empty. Add items before checking out.";
  }

  const totals = computeTotals();
  const itemWidth = 22;
  const lines = [
    "========================================",
    "                  RECEIPT               ",
    "========================================",
    `#${String(orderId).padStart(8, '0')}  ${new Date().toLocaleString()}`,
    ""
  ];

  if (credit && credit.enabled) {
    lines.push("  ** CREDIT SALE **");
    lines.push("  Customer: " + (credit.customer_name || 'N/A'));
    lines.push("  Phone: " + (credit.customer_phone || 'N/A'));
    lines.push("");
  }

  lines.push("  Item                 Qty  Price      Total");
  lines.push("──────────────────────────────────────────");

  for (let i = 0; i < state.cart.length; i++) {
    const entry = state.cart[i];
    const unitPrice = getBulkUnitPrice(entry.product, entry.quantity);
    const threshold = parseInt(entry.product.bulk_quantity_threshold, 10) || 0;
    const discount = parseFloat(entry.product.bulk_discount_percentage) || 0;
    const bulkApplied = threshold > 0 && discount > 0 && entry.quantity >= threshold;
    const itemTotal = roundTo(unitPrice * entry.quantity, 2);

    const wrapped = entry.product.name.match(/.{1,22}/g) || [];
    lines.push(`  ${wrapped[0].padEnd(22)} ${String(entry.quantity).padStart(3)} ${formatMoney(unitPrice).slice(2).padStart(9)} ${formatMoney(itemTotal).slice(2).padStart(10)}`);
    
    for (let j = 1; j < wrapped.length; j++) {
      lines.push(`  ${wrapped[j].padEnd(22)}`);
    }

    if (bulkApplied) {
      const savings = roundTo((entry.product.selling_price - unitPrice) * entry.quantity, 2);
      lines.push(`  ${(" " + entry.quantity + "@" + formatMoney(unitPrice).slice(2) + "ea").padEnd(22)} ${"".padStart(3)} ${("" + "-" + formatMoney(savings).slice(2)).padStart(9)}`);
    }

    if (i < state.cart.length - 1) {
      lines.push("──────────────────────────────────────────");
    }
  }

  lines.push("──────────────────────────────────────────");
  lines.push(`  ${"Subtotal".padEnd(26)} ${formatMoney(totals.subtotal).slice(2).padStart(10)}`);
  lines.push(`  ${"Tax (0%)".padEnd(26)} ${formatMoney(0).slice(2).padStart(10)}`);
  lines.push(`  ${"TOTAL".padEnd(26)} ${formatMoney(totals.total).slice(2).padStart(10)}`);
  lines.push("========================================");
  lines.push("      Thank you for your purchase!      ");
  lines.push("========================================");

  return lines.join("\n");
}

function handleProductListClick(event) {
  const button = event.target.closest("button");
  if (!button || !button.dataset.action) return;

  const action = button.dataset.action;
  const id = Number(button.dataset.id);
  // Use the live product list so freshly refreshed stock levels are honored.
  const product = (window.pageConfig.products || []).find((item) => item.id === id);
  if (!product) return;

  if (action === "add") addToCart(product);
}

function handleCartClick(event) {
  const button = event.target.closest("button");
  if (!button || !button.dataset.action) return;

  const action = button.dataset.action;
  const id = Number(button.dataset.id);
  if (action === "increase") updateQuantity(id, 1);
  if (action === "decrease") updateQuantity(id, -1);
  if (action === "remove") removeItem(id);
}

function showBarcodeMessage(text, type = "info") {
  if (!elements.barcodeMessage) return;
  elements.barcodeMessage.textContent = text;
  elements.barcodeMessage.style.color = type === "error" ? "#bf2d2d" : "#5f6d8b";
}

function showToast(message, title, type = "success") {
  const container = document.getElementById("toast-container");
  if (!container) return;

  const toast = document.createElement("div");
  toast.className = `toast toast-${type}`;
  toast.setAttribute("role", "status");

  const iconSvg = type === "error"
    ? `<svg class="toast-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>`
    : type === "info"
      ? `<svg class="toast-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>`
      : `<svg class="toast-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>`;

  const displayTitle = title || (type === "error" ? "Error" : type === "info" ? "Notice" : "Success");

  toast.innerHTML = `
    ${iconSvg}
    <div class="toast-body">
      <p class="toast-title">${displayTitle}</p>
      <p class="toast-message">${message}</p>
    </div>
    <button class="toast-close" aria-label="Dismiss" type="button">&times;</button>
  `;

  container.appendChild(toast);

  const closeButton = toast.querySelector(".toast-close");
  const dismiss = () => {
    if (toast.classList.contains("removing")) return;
    toast.classList.add("removing");
    toast.classList.remove("visible");
    setTimeout(() => {
      if (toast.parentNode) toast.parentNode.removeChild(toast);
    }, 350);
  };

  closeButton.addEventListener("click", dismiss);

  const autoDismiss = setTimeout(dismiss, 4000);

  toast.addEventListener("mouseenter", () => clearTimeout(autoDismiss));
  toast.addEventListener("mouseleave", () => {
    const resumeDismiss = setTimeout(dismiss, 2000);
    toast._resumeDismiss = resumeDismiss;
  });

  requestAnimationFrame(() => {
    requestAnimationFrame(() => toast.classList.add("visible"));
  });
}

function getProductByCode(code) {
  return (window.pageConfig.products || []).find((product) => product.code === code.trim());
}

function handleBarcodeEntry() {
  if (!elements.barcodeInput) return;
  const code = elements.barcodeInput.value.trim();
  if (!code) {
    showBarcodeMessage("Enter a valid barcode or scan a product.", "error");
    return;
  }

  const product = getProductByCode(code);
  if (!product) {
    showBarcodeMessage("Product not found. Confirm the code and try again.", "error");
    return;
  }

  if (isProductExpired(product.expiry_date)) {
    showBarcodeMessage(`${product.name} is expired and cannot be sold.`, "error");
    return;
  }

  addToCart(product);
  if (pendingDuplicateProduct === product) {
    // Confirmation dialog is open; wait for the user's decision before clearing.
    return;
  }
  showBarcodeMessage(`${product.name} added to cart.`, "info");
  elements.barcodeInput.value = "";
}

function stopCameraScan() {
  if (!elements.barcodeVideo) return;
  const stream = elements.barcodeVideo.srcObject;
  if (stream) {
    stream.getTracks().forEach((track) => track.stop());
  }
  elements.barcodeVideo.srcObject = null;
  if (elements.cameraPreview) {
    elements.cameraPreview.classList.add("hidden");
  }
}

async function startCameraScan() {
  if (!window.BarcodeDetector || !navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
    showBarcodeMessage("Camera scanning is not supported in this browser.", "error");
    return;
  }

  try {
    const stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: "environment" } });
    elements.barcodeVideo.srcObject = stream;
    elements.cameraPreview.classList.remove("hidden");
    const detector = new BarcodeDetector({ formats: ["code_128", "ean_13", "ean_8", "qr_code"] });

    const scanFrame = async () => {
      if (!elements.cameraPreview || elements.cameraPreview.classList.contains("hidden")) return;
      try {
        const barcodes = await detector.detect(elements.barcodeVideo);
        if (barcodes.length > 0) {
          elements.barcodeInput.value = barcodes[0].rawValue;
          handleBarcodeEntry();
          stopCameraScan();
          return;
        }
      } catch (error) {
        console.warn("Barcode scan failed:", error);
      }
      requestAnimationFrame(scanFrame);
    };

    scanFrame();
  } catch (error) {
    showBarcodeMessage(`Camera unavailable: ${error.message}`, "error");
  }
}

async function checkoutOrder() {
  if (state.cart.length === 0) {
    showBarcodeMessage("Add items to cart before checkout.", "error");
    return;
  }

  const creditEnabled = elements.creditEnabled ? elements.creditEnabled.checked : false;
  const creditCustomerName = elements.creditCustomerName ? elements.creditCustomerName.value.trim() : '';
  const creditCustomerPhone = elements.creditCustomerPhone ? elements.creditCustomerPhone.value.trim() : '';

  if (creditEnabled && (!creditCustomerName || !creditCustomerPhone)) {
    showBarcodeMessage("Please provide customer name and phone for credit sale.", "error");
    return;
  }

  const payload = {
    cart: state.cart,
    credit: {
      enabled: creditEnabled,
      customer_name: creditCustomerName,
      customer_phone: creditCustomerPhone
    }
  };

  const response = await fetch(checkoutEndpoint, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json'
    },
    body: JSON.stringify(payload)
  });

  const result = await response.json();
  if (!result.success) {
    showToast(result.message || 'Checkout failed. Please try again.', "Checkout failed", "error");
    return;
  }

  const receipt = createReceipt(result.orderId, creditEnabled ? { enabled: creditEnabled, customer_name: creditCustomerName, customer_phone: creditCustomerPhone } : null);
  console.log("Receipt:\n" + receipt);

  clearCart();
  if (elements.creditCustomerName) elements.creditCustomerName.value = '';
  if (elements.creditCustomerPhone) elements.creditCustomerPhone.value = '';
  if (elements.creditEnabled) elements.creditEnabled.checked = false;
  if (elements.creditFields) elements.creditFields.style.display = 'none';
  showToast(`Order #${result.orderId} completed successfully.`, "Sale completed");

  // Stock changed on the server the moment the sale was saved; pull the new
  // quantities immediately instead of waiting for the 30-second poll.
  await refreshProductData();
}

async function refreshProductData() {
  try {
    const response = await fetch('api/products.php');
    if (response.ok) {
      const updatedProducts = await response.json();
      // Update the global products array
      window.pageConfig.products = updatedProducts;
      // Re-render products if we're on the sales page
      if (document.body.dataset.page === 'sales') {
        renderProducts();
      }
    }
  } catch (error) {
    console.log('Failed to refresh product data:', error);
  }
}

function initSalesPage() {
  if (!elements.productList) return;

  renderProducts();
  renderCart();

  elements.productList.addEventListener("click", handleProductListClick);
  elements.cartItems.addEventListener("click", handleCartClick);
  elements.checkoutButton.addEventListener("click", checkoutOrder);
  elements.clearCartButton.addEventListener("click", clearCart);

  if (elements.creditEnabled) {
    elements.creditEnabled.addEventListener("change", () => {
      const show = elements.creditEnabled.checked;
      if (elements.creditFields) {
        elements.creditFields.style.display = show ? "block" : "none";
      }
    });
  }

  elements.productSearch.addEventListener("input", (event) => {
    state.filter = event.target.value;
    renderProducts();
  });
  elements.scanButton.addEventListener("click", handleBarcodeEntry);
  elements.cameraButton.addEventListener("click", startCameraScan);
  elements.stopCameraButton.addEventListener("click", stopCameraScan);

  if (elements.duplicateModal) {
    const closeDuplicateModal = () => {
      elements.duplicateModal.classList.add("hidden");
      pendingDuplicateProduct = null;
    };

    elements.duplicateConfirm.addEventListener("click", () => {
      const product = pendingDuplicateProduct;
      closeDuplicateModal();
      if (product) {
        commitAddToCart(product);
        showBarcodeMessage(`${product.name} added to cart.`, "info");
      }
      if (elements.barcodeInput) elements.barcodeInput.value = "";
    });

    elements.duplicateCancel.addEventListener("click", () => {
      const product = pendingDuplicateProduct;
      closeDuplicateModal();
      if (product) {
        showBarcodeMessage(`${product.name} was not added again — it is already in this order.`, "info");
      }
      if (elements.barcodeInput) elements.barcodeInput.value = "";
    });

    // Clicking the dark backdrop behaves like choosing "No, cancel".
    elements.duplicateModal.addEventListener("click", (event) => {
      if (event.target === elements.duplicateModal) {
        elements.duplicateCancel.click();
      }
    });
  }

  // Refresh product data every 30 seconds for real-time inventory updates
  setInterval(refreshProductData, 30000);

  // Also refresh immediately when the tab regains focus, so stock stays
  // current even when another tab or register completes a sale.
  document.addEventListener("visibilitychange", () => {
    if (document.visibilityState === "visible") {
      refreshProductData();
    }
  });
}

function initializeApp() {
  initPageToasts();
  if (document.body.dataset.page === 'sales') {
    initSalesPage();
  }
}

function initPageToasts() {
  if (document._pageToastsInitialized) return;
  document._pageToastsInitialized = true;

  document.querySelectorAll('[data-toast-message]').forEach((el) => {
    const message = el.textContent.trim();
    const type = (el.dataset.toastType || 'success').toLowerCase();
    if (message) {
      showToast(message, type === 'error' ? 'Error' : 'Success', type);
      el.style.display = 'none';
    }
  });

  document.querySelectorAll('.login-hint').forEach((el) => {
    if (el.closest('form')) return;
    if (el.hasAttribute('data-toast-message')) return;
    const text = el.textContent.trim();
    if (text) {
      showToast(text, 'Success');
      el.style.display = 'none';
    }
  });

  document.querySelectorAll('.error-text').forEach((el) => {
    if (el.hasAttribute('data-toast-message')) return;
    const text = el.textContent.trim();
    if (text) {
      showToast(text, 'Error', 'error');
      el.style.display = 'none';
    }
  });
}

document.addEventListener("DOMContentLoaded", initializeApp);
