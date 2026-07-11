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
  checkoutButton: document.getElementById("checkout-button")
};

function formatMoney(value) {
  return `GH₵${value.toFixed(2)}`;
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
  const subtotal = state.cart.reduce((sum, entry) => sum + entry.product.price * entry.quantity, 0);
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
      
      return `
      <article class="product-card ${isOutOfStock ? 'out-of-stock' : ''}">
        <img src="${product.image}" alt="${product.name}" />
        <div class="product-info">
          <div class="product-name">${product.name}</div>
          <div class="product-category">${product.category} • Code ${product.code}</div>
          <div class="product-price">${formatMoney(product.price)}</div>
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
    .map((entry) => `
      <article class="cart-item">
        <div class="cart-info">
          <div class="cart-name">${entry.product.name}</div>
          <div class="cart-meta">${entry.quantity} × ${formatMoney(entry.product.price)}</div>
        </div>
        <div class="cart-actions-row">
          <button class="tertiary" data-action="decrease" data-id="${entry.product.id}">−</button>
          <button class="tertiary" data-action="increase" data-id="${entry.product.id}">+</button>
          <button class="secondary" data-action="remove" data-id="${entry.product.id}">Remove</button>
        </div>
      </article>`)
    .join("");

  const totals = computeTotals();
  elements.subtotal.textContent = formatMoney(totals.subtotal);
  elements.tax.textContent = formatMoney(totals.tax);
  elements.total.textContent = formatMoney(totals.total);
}

function createReceipt(orderId) {
  if (state.cart.length === 0) {
    return "Your cart is empty. Add items before checking out.";
  }

  const totals = computeTotals();
  const lines = [
    "=== Receipt ===",
    `Order ID: ${orderId}`,
    `Date: ${new Date().toLocaleString()}`,
    "",
    ...state.cart.map((entry) => `${entry.quantity} × ${entry.product.name} @ ${formatMoney(entry.product.price)} = ${formatMoney(entry.product.price * entry.quantity)}`),
    "",
    `Subtotal: ${formatMoney(totals.subtotal)}`,
    `Tax (0%): ${formatMoney(totals.tax)}`,
    `Total: ${formatMoney(totals.total)}`,
    "",
    "Thank you for your purchase!"
  ];

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

  const response = await fetch(checkoutEndpoint, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json'
    },
    body: JSON.stringify({ cart: state.cart })
  });

  const result = await response.json();
  if (!result.success) {
    showBarcodeMessage(result.message || 'Checkout failed.', 'error');
    return;
  }

  clearCart();
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
