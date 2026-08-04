const config = window.pageConfig || {};
const products = config.products || [];
const checkoutEndpoint = 'checkout.php';

const state = {
  cart: [],
  filter: ""
};

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
  creditFields: document.getElementById("credit-fields")
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

function addToCart(product) {
  const existing = getCartItem(product.id);
  if (existing) {
    existing.quantity += 1;
  } else {
    state.cart.push({ product, quantity: 1 });
  }
  renderCart();
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

function renderProducts() {
  if (!elements.productList) return;

  const currentProducts = window.pageConfig.products || [];
  const searchTerm = state.filter.trim().toLowerCase();
  const filteredProducts = currentProducts.filter((product) => {
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

      return `
      <article class="product-card ${isOutOfStock ? 'out-of-stock' : ''}">
        <img src="${product.image}" alt="${product.name}" />
        <div class="product-info">
          <div class="product-name">${product.name}</div>
          <div class="product-category">${product.category} • Code ${product.code}</div>
          <div class="product-price">${formatMoney(product.selling_price)}</div>
          ${bulkBadge}
          <div class="product-bulk-price">Bulk unit price: ${discountedPrice}</div>
          <div class="product-stock ${stockClass}">${stockText}</div>
        </div>
        <div class="product-actions">
          <button class="${buttonClass}" data-action="add" data-id="${product.id}" ${buttonDisabled}>${isOutOfStock ? 'Out of Stock' : 'Add'}</button>
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
  const product = products.find((item) => item.id === id);
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

function getProductByCode(code) {
  return products.find((product) => product.code === code.trim());
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

  addToCart(product);
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
    showBarcodeMessage(result.message || 'Checkout failed.', 'error');
    return;
  }

  const receipt = createReceipt(result.orderId, creditEnabled ? { enabled: creditEnabled, customer_name: creditCustomerName, customer_phone: creditCustomerPhone } : null);
  console.log("Receipt:\n" + receipt);

  clearCart();
  if (elements.creditCustomerName) elements.creditCustomerName.value = '';
  if (elements.creditCustomerPhone) elements.creditCustomerPhone.value = '';
  if (elements.creditEnabled) elements.creditEnabled.checked = false;
  if (elements.creditFields) elements.creditFields.style.display = 'none';
  showBarcodeMessage(`Order ${result.orderId} stored successfully. Receipt saved to database.`, 'info');
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

  // Refresh product data every 30 seconds for real-time inventory updates
  setInterval(refreshProductData, 30000);
}

function initializeApp() {
  if (document.body.dataset.page === 'sales') {
    initSalesPage();
  }
}

document.addEventListener("DOMContentLoaded", initializeApp);
