// ===== Sharafi storefront shared utilities =====

const GUEST_CART_KEY = 'sharafi_guest_cart_v2';
const MAX_CART_QTY = 99;
let serverCartItems = [];
let cartLoadPromise = null;

function escapeHTML(value) {
  return String(value ?? '').replace(/[&<>'"]/g, (char) => ({
    '&': '&amp;',
    '<': '&lt;',
    '>': '&gt;',
    "'": '&#39;',
    '"': '&quot;',
  })[char]);
}

function fmtPrice(value) {
  const amount = Number(value);
  return `${(Number.isFinite(amount) ? amount : 0).toLocaleString('fa-IR')} تومان`;
}

function toast(message, duration = 2200) {
  let box = document.querySelector('.js-toast');
  if (!box) {
    box = document.createElement('div');
    box.className = 'js-toast';
    box.setAttribute('role', 'status');
    box.setAttribute('aria-live', 'polite');
    box.style.cssText = 'position:fixed;bottom:24px;left:50%;transform:translateX(-50%);background:#4a3a3c;color:#fff;padding:12px 24px;border-radius:999px;font-size:14px;z-index:999;box-shadow:0 8px 20px rgba(0,0,0,.2);transition:opacity .3s;max-width:min(90vw,520px);text-align:center;';
    document.body.appendChild(box);
  }

  box.textContent = String(message || '');
  box.style.opacity = '1';
  clearTimeout(box._t);
  box._t = setTimeout(() => { box.style.opacity = '0'; }, duration);
}

function normalizeGuestItem(item) {
  const variantId = Number.parseInt(item?.variant_id ?? item?.variantId, 10);
  if (!Number.isInteger(variantId) || variantId <= 0) return null;

  const qty = Math.min(MAX_CART_QTY, Math.max(1, Number.parseInt(item?.qty ?? item?.quantity, 10) || 1));
  return {
    variant_id: variantId,
    qty,
    name: String(item?.name || 'محصول').slice(0, 160),
    slug: String(item?.slug || '').slice(0, 180),
    variant_title: String(item?.variant_title || '').slice(0, 120),
    price: Math.max(0, Number(item?.price) || 0),
    icon: String(item?.icon || '🧴').slice(0, 8),
    in_stock: item?.in_stock !== false,
  };
}

function getGuestCart() {
  try {
    const parsed = JSON.parse(localStorage.getItem(GUEST_CART_KEY));
    if (!Array.isArray(parsed)) return [];
    return parsed.map(normalizeGuestItem).filter(Boolean);
  } catch {
    return [];
  }
}

function saveGuestCart(cart) {
  const safeCart = Array.isArray(cart) ? cart.map(normalizeGuestItem).filter(Boolean) : [];
  localStorage.setItem(GUEST_CART_KEY, JSON.stringify(safeCart));
  updateCartBadgeFromItems(safeCart);
}

function normalizeServerCart(payload) {
  const api = window.SharafiAPI;
  const items = Array.isArray(payload?.data?.items) ? payload.data.items : [];
  return items.map((item) => ({
    variant_id: Number(item.variant_id),
    qty: Number(item.quantity),
    name: String(item.product?.name || 'محصول'),
    slug: String(item.product?.slug || ''),
    variant_title: String(item.variant?.title || ''),
    sku: String(item.variant?.sku || ''),
    price_irr: Number(item.variant?.price_irr || 0),
    price: api ? api.toman(Number(item.variant?.price_irr || 0)) : 0,
    in_stock: Boolean(item.variant?.in_stock),
    icon: '🧴',
  })).filter((item) => Number.isInteger(item.variant_id) && item.variant_id > 0 && item.qty > 0);
}

async function currentUser() {
  if (!window.SharafiAPI) return null;
  try {
    return await window.SharafiAPI.currentUser();
  } catch {
    return null;
  }
}

function getCart() {
  return serverCartItems.length ? serverCartItems : getGuestCart();
}

async function loadCart(force = false) {
  if (!window.SharafiAPI) return getGuestCart();
  if (!force && cartLoadPromise) return cartLoadPromise;

  cartLoadPromise = (async () => {
    const user = await currentUser();
    if (!user) {
      serverCartItems = [];
      return getGuestCart();
    }

    try {
      const payload = await window.SharafiAPI.cart.get();
      serverCartItems = normalizeServerCart(payload);
      return serverCartItems;
    } catch (error) {
      if (error instanceof window.SharafiAPI.ApiError && error.status === 401) {
        window.SharafiAPI.clearSessionCache();
        serverCartItems = [];
        return getGuestCart();
      }
      throw error;
    }
  })().finally(() => { cartLoadPromise = null; });

  return cartLoadPromise;
}

function updateCartBadgeFromItems(items) {
  const count = items.reduce((sum, item) => sum + (Number(item.qty) || 0), 0);
  document.querySelectorAll('.js-cart-count').forEach((element) => {
    element.textContent = count.toLocaleString('fa-IR');
    element.hidden = count === 0;
  });
}

async function updateCartBadge() {
  try {
    updateCartBadgeFromItems(await loadCart());
  } catch {
    updateCartBadgeFromItems(getGuestCart());
  }
}

function guestAdd(product, quantity = 1) {
  const safeProduct = normalizeGuestItem({ ...product, qty: quantity });
  if (!safeProduct) return null;
  const cart = getGuestCart();
  const existing = cart.find((item) => item.variant_id === safeProduct.variant_id);
  if (existing) existing.qty = Math.min(MAX_CART_QTY, existing.qty + safeProduct.qty);
  else cart.push(safeProduct);
  saveGuestCart(cart);
  return safeProduct;
}

async function addToCart(product, quantity = 1) {
  const safeProduct = normalizeGuestItem({ ...product, qty: quantity });
  if (!safeProduct) {
    toast('برای این محصول ابتدا گزینه موردنظر را انتخاب کنید.');
    return null;
  }

  const user = await currentUser();
  if (!user || !window.SharafiAPI) {
    guestAdd(safeProduct, safeProduct.qty);
    toast(safeProduct.qty > 1
      ? `${safeProduct.qty.toLocaleString('fa-IR')} عدد «${safeProduct.name}» به سبد موقت اضافه شد`
      : `«${safeProduct.name}» به سبد خرید اضافه شد`);
    return safeProduct;
  }

  try {
    const payload = await window.SharafiAPI.cart.set(safeProduct.variant_id, safeProduct.qty);
    serverCartItems = normalizeServerCart(payload);
    updateCartBadgeFromItems(serverCartItems);
    toast(`«${safeProduct.name}» به سبد خرید اضافه شد`);
    return safeProduct;
  } catch (error) {
    toast(error?.message || 'افزودن محصول به سبد ناموفق بود.');
    return null;
  }
}

async function changeQty(id, delta) {
  const variantId = Number.parseInt(id, 10);
  if (!Number.isInteger(variantId) || variantId <= 0) return [];
  const user = await currentUser();

  if (!user || !window.SharafiAPI) {
    const cart = getGuestCart();
    const item = cart.find((entry) => entry.variant_id === variantId);
    if (!item) return cart;
    item.qty = Math.min(MAX_CART_QTY, Math.max(1, item.qty + Number(delta || 0)));
    saveGuestCart(cart);
    return cart;
  }

  const current = (await loadCart()).find((item) => item.variant_id === variantId);
  if (!current) return serverCartItems;
  const nextQty = Math.min(MAX_CART_QTY, Math.max(1, current.qty + Number(delta || 0)));
  const payload = await window.SharafiAPI.cart.set(variantId, nextQty);
  serverCartItems = normalizeServerCart(payload);
  updateCartBadgeFromItems(serverCartItems);
  return serverCartItems;
}

async function removeFromCart(id) {
  const variantId = Number.parseInt(id, 10);
  if (!Number.isInteger(variantId) || variantId <= 0) return [];
  const user = await currentUser();

  if (!user || !window.SharafiAPI) {
    const cart = getGuestCart().filter((item) => item.variant_id !== variantId);
    saveGuestCart(cart);
    return cart;
  }

  const payload = await window.SharafiAPI.cart.remove(variantId);
  serverCartItems = normalizeServerCart(payload);
  updateCartBadgeFromItems(serverCartItems);
  return serverCartItems;
}

function cartCount() {
  return getCart().reduce((sum, item) => sum + item.qty, 0);
}

function cartTotal() {
  return getCart().reduce((sum, item) => sum + (item.qty * item.price), 0);
}

async function syncGuestCart() {
  if (!window.SharafiAPI) return { synced: 0, failed: 0 };
  const user = await window.SharafiAPI.currentUser(true);
  const guest = getGuestCart();
  if (!user || guest.length === 0) return { synced: 0, failed: guest.length };

  const failed = [];
  let synced = 0;
  for (const item of guest) {
    try {
      await window.SharafiAPI.cart.set(item.variant_id, item.qty);
      synced += 1;
    } catch {
      failed.push(item);
    }
  }

  saveGuestCart(failed);
  await loadCart(true);
  updateCartBadgeFromItems(serverCartItems);
  return { synced, failed: failed.length };
}

function initProductCardCartActions() {
  document.addEventListener('click', async (event) => {
    const button = event.target.closest('.product-card-add[data-variant-id]');
    if (!button || button.disabled) return;

    button.disabled = true;
    try {
      await addToCart({
        variant_id: button.dataset.variantId,
        name: button.dataset.cartName,
        slug: button.dataset.cartSlug,
        variant_title: button.dataset.variantTitle,
        price: window.SharafiAPI?.toman(Number(button.dataset.priceIrr || 0)) || 0,
        in_stock: button.dataset.inStock !== 'false',
        icon: button.dataset.cartIcon || '🧴',
      });
    } finally {
      button.disabled = button.dataset.inStock === 'false';
    }
  });
}

window.SharafiCart = Object.freeze({
  load: loadCart,
  add: addToCart,
  changeQty,
  remove: removeFromCart,
  syncGuestCart,
  getGuestCart,
});

document.addEventListener('sharafi:authenticated', () => {
  syncGuestCart().catch(() => {});
});

document.addEventListener('DOMContentLoaded', () => {
  updateCartBadge();
  initProductCardCartActions();
});
