// ===== Sharafi storefront shared utilities =====

const CART_KEY = "demo_cart_v1";
const MAX_CART_QTY = 99;

function normalizeCartItem(item) {
  if (!item || (typeof item.id !== "number" && typeof item.id !== "string")) return null;

  const price = Number(item.price);
  const qty = Math.min(MAX_CART_QTY, Math.max(1, Number.parseInt(item.qty, 10) || 1));
  if (!Number.isFinite(price) || price < 0) return null;

  return {
    id: item.id,
    name: String(item.name || "محصول").slice(0, 160),
    brand: String(item.brand || "").slice(0, 100),
    price,
    icon: String(item.icon || "🧴").slice(0, 8),
    qty,
  };
}

function getCart() {
  try {
    const parsed = JSON.parse(localStorage.getItem(CART_KEY));
    if (!Array.isArray(parsed)) return [];
    return parsed.map(normalizeCartItem).filter(Boolean);
  } catch {
    return [];
  }
}

function saveCart(cart) {
  const safeCart = Array.isArray(cart) ? cart.map(normalizeCartItem).filter(Boolean) : [];
  localStorage.setItem(CART_KEY, JSON.stringify(safeCart));
  updateCartBadge();
}

function addToCart(product, quantity = 1) {
  const safeProduct = normalizeCartItem({ ...product, qty: quantity });
  if (!safeProduct) return;

  const cart = getCart();
  const existing = cart.find((item) => String(item.id) === String(safeProduct.id));
  if (existing) existing.qty = Math.min(MAX_CART_QTY, existing.qty + safeProduct.qty);
  else cart.push(safeProduct);

  saveCart(cart);
  toast(safeProduct.qty > 1
    ? `${safeProduct.qty.toLocaleString("fa-IR")} عدد «${safeProduct.name}» به سبد خرید اضافه شد`
    : `«${safeProduct.name}» به سبد خرید اضافه شد`);
}

function removeFromCart(id) {
  saveCart(getCart().filter((item) => String(item.id) !== String(id)));
}

function changeQty(id, delta) {
  const cart = getCart();
  const item = cart.find((entry) => String(entry.id) === String(id));
  if (!item) return;
  item.qty = Math.min(MAX_CART_QTY, Math.max(1, item.qty + Number(delta || 0)));
  saveCart(cart);
}

function cartCount() {
  return getCart().reduce((sum, item) => sum + item.qty, 0);
}

function cartTotal() {
  return getCart().reduce((sum, item) => sum + (item.qty * item.price), 0);
}

function updateCartBadge() {
  const count = cartCount();
  document.querySelectorAll(".js-cart-count").forEach((element) => {
    element.textContent = count.toLocaleString("fa-IR");
    element.hidden = count === 0;
  });
}

function fmtPrice(value) {
  const amount = Number(value);
  return `${(Number.isFinite(amount) ? amount : 0).toLocaleString("fa-IR")} تومان`;
}

function escapeHTML(value) {
  return String(value ?? "").replace(/[&<>'"]/g, (char) => ({
    "&": "&amp;",
    "<": "&lt;",
    ">": "&gt;",
    "'": "&#39;",
    '"': "&quot;",
  })[char]);
}

function toast(message) {
  let box = document.querySelector(".js-toast");
  if (!box) {
    box = document.createElement("div");
    box.className = "js-toast";
    box.setAttribute("role", "status");
    box.setAttribute("aria-live", "polite");
    box.style.cssText = "position:fixed;bottom:24px;left:50%;transform:translateX(-50%);background:#4a3a3c;color:#fff;padding:12px 24px;border-radius:999px;font-size:14px;z-index:999;box-shadow:0 8px 20px rgba(0,0,0,.2);transition:opacity .3s;max-width:min(90vw,520px);text-align:center;";
    document.body.appendChild(box);
  }

  box.textContent = String(message || "");
  box.style.opacity = "1";
  clearTimeout(box._t);
  box._t = setTimeout(() => { box.style.opacity = "0"; }, 1800);
}

document.addEventListener("DOMContentLoaded", updateCartBadge);
