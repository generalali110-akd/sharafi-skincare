// ===== Sharafi storefront shared utilities =====

const GUEST_CART_KEY = 'sharafi_guest_cart_v2';
const DEFAULT_MAX_CART_QTY = 99;
const SHARAFI_MAIN_SCRIPT_URL = document.currentScript?.src || new URL('assets/js/main.js', window.location.href).href;
let maxCartQty = DEFAULT_MAX_CART_QTY;
let serverCartItems = [];
let cartLoadPromise = null;

function ensureCspSafeStylesheet() {
  const href = new URL('../css/csp-safe.css', SHARAFI_MAIN_SCRIPT_URL).href;
  if ([...document.styleSheets].some((sheet) => sheet.href === href)) return;
  const existing = [...document.querySelectorAll('link[rel="stylesheet"]')].find((link) => link.href === href);
  if (existing) return;
  const link = document.createElement('link');
  link.rel = 'stylesheet';
  link.href = href;
  document.head.appendChild(link);
}

ensureCspSafeStylesheet();

function ensureSharafiApi() {
  if (window.SharafiAPI) return Promise.resolve(window.SharafiAPI);
  if (window.SharafiApiReady) return window.SharafiApiReady;

  const src = new URL('api.js', SHARAFI_MAIN_SCRIPT_URL).href;
  window.SharafiApiReady = new Promise((resolve, reject) => {
    const existing = [...document.scripts].find((script) => script.src === src);
    const script = existing || document.createElement('script');
    const onReady = () => window.SharafiAPI ? resolve(window.SharafiAPI) : reject(new Error('API client unavailable'));
    if (existing) {
      if (window.SharafiAPI) onReady();
      else existing.addEventListener('load', onReady, { once: true });
      existing.addEventListener('error', reject, { once: true });
      return;
    }
    script.src = src;
    script.async = true;
    script.addEventListener('load', onReady, { once: true });
    script.addEventListener('error', () => reject(new Error('API client failed to load')), { once: true });
    document.head.appendChild(script);
  });
  return window.SharafiApiReady;
}

function loadSharafiModule(filename) {
  const src = new URL(filename, SHARAFI_MAIN_SCRIPT_URL).href;
  if ([...document.scripts].some((script) => script.src === src)) return;
  const script = document.createElement('script');
  script.src = src;
  script.async = true;
  document.body.appendChild(script);
}

function escapeHTML(value) {
  return String(value ?? '').replace(/[&<>'"]/g, (char) => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#39;', '"': '&quot;',
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
    document.body.appendChild(box);
  }
  box.textContent = String(message || '');
  box.classList.add('is-visible');
  clearTimeout(box._t);
  box._t = setTimeout(() => { box.classList.remove('is-visible'); }, duration);
}

function normalizeGuestItem(item) {
  const variantId = Number.parseInt(item?.variant_id ?? item?.variantId, 10);
  if (!Number.isInteger(variantId) || variantId <= 0) return null;
  const qty = Math.min(maxCartQty, Math.max(1, Number.parseInt(item?.qty ?? item?.quantity, 10) || 1));
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
  let api;
  try {
    api = await ensureSharafiApi();
  } catch {
    return null;
  }
  try {
    return await api.currentUser();
  } catch {
    return null;
  }
}

function getCart() {
  return serverCartItems.length ? serverCartItems : getGuestCart();
}

async function loadCart(force = false) {
  let api = null;
  try {
    api = await ensureSharafiApi();
  } catch {
    return getGuestCart();
  }
  if (!force && cartLoadPromise) return cartLoadPromise;

  cartLoadPromise = (async () => {
    const user = await currentUser();
    if (!user) {
      serverCartItems = [];
      return getGuestCart();
    }
    try {
      const payload = await api.cart.get();
      serverCartItems = normalizeServerCart(payload);
      return serverCartItems;
    } catch (error) {
      if (error instanceof api.ApiError && error.status === 401) {
        api.clearSessionCache();
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
  if (existing) existing.qty = Math.min(maxCartQty, existing.qty + safeProduct.qty);
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

  const api = await ensureSharafiApi().catch(() => null);
  const user = await currentUser();
  if (!user || !api) {
    guestAdd(safeProduct, safeProduct.qty);
    toast(safeProduct.qty > 1 ? `${safeProduct.qty.toLocaleString('fa-IR')} عدد «${safeProduct.name}» به سبد موقت اضافه شد` : `«${safeProduct.name}» به سبد خرید اضافه شد`);
    return safeProduct;
  }

  try {
    const currentItems = await loadCart(true);
    const existing = currentItems.find((item) => item.variant_id === safeProduct.variant_id);
    const nextQty = Math.min(maxCartQty, (existing?.qty || 0) + safeProduct.qty);
    const payload = await api.cart.set(safeProduct.variant_id, nextQty);
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
  const api = await ensureSharafiApi().catch(() => null);
  const user = await currentUser();
  if (!user || !api) {
    const cart = getGuestCart();
    const item = cart.find((entry) => entry.variant_id === variantId);
    if (!item) return cart;
    item.qty = Math.min(maxCartQty, Math.max(1, item.qty + Number(delta || 0)));
    saveGuestCart(cart);
    return cart;
  }

  const current = (await loadCart()).find((item) => item.variant_id === variantId);
  if (!current) return serverCartItems;
  const nextQty = Math.min(maxCartQty, Math.max(1, current.qty + Number(delta || 0)));
  const payload = await api.cart.set(variantId, nextQty);
  serverCartItems = normalizeServerCart(payload);
  updateCartBadgeFromItems(serverCartItems);
  return serverCartItems;
}

async function removeFromCart(id) {
  const variantId = Number.parseInt(id, 10);
  if (!Number.isInteger(variantId) || variantId <= 0) return [];
  const api = await ensureSharafiApi().catch(() => null);
  const user = await currentUser();
  if (!user || !api) {
    const cart = getGuestCart().filter((item) => item.variant_id !== variantId);
    saveGuestCart(cart);
    return cart;
  }

  const payload = await api.cart.remove(variantId);
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
  const api = await ensureSharafiApi().catch(() => null);
  if (!api) return { synced: 0, failed: getGuestCart().length };
  const user = await api.currentUser(true);
  const guest = getGuestCart();
  if (!user || guest.length === 0) return { synced: 0, failed: guest.length };

  const currentItems = await loadCart(true);
  const quantities = new Map(currentItems.map((item) => [item.variant_id, item.qty]));
  const failed = [];
  let synced = 0;
  for (const item of guest) {
    try {
      const nextQty = Math.min(maxCartQty, (quantities.get(item.variant_id) || 0) + item.qty);
      await api.cart.set(item.variant_id, nextQty);
      quantities.set(item.variant_id, nextQty);
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
    const button = event.target.closest('.product-card-add');
    if (!button || button.disabled) return;
    if (!button.dataset.variantId) {
      toast('در حال دریافت اطلاعات واقعی محصول؛ لطفاً چند لحظه دیگر دوباره تلاش کنید.');
      return;
    }
    button.disabled = true;
    try {
      const api = await ensureSharafiApi().catch(() => null);
      await addToCart({
        variant_id: button.dataset.variantId,
        name: button.dataset.cartName,
        slug: button.dataset.cartSlug,
        variant_title: button.dataset.variantTitle,
        price: api?.toman(Number(button.dataset.priceIrr || 0)) || 0,
        in_stock: button.dataset.inStock !== 'false',
        icon: button.dataset.cartIcon || '🧴',
      });
    } finally {
      button.disabled = button.dataset.inStock === 'false';
    }
  });
}

async function syncAccountLink() {
  const user = await currentUser();
  document.querySelectorAll('.user-account-link').forEach((link) => {
    link.href = user ? 'account.html' : 'login.html';
    link.setAttribute('aria-label', user ? 'حساب کاربری' : 'ورود به حساب کاربری');
  });
}

async function syncStorefrontConfig() {
  const api = await ensureSharafiApi();
  const payload = await api.storefront.config();
  const config = payload?.data;
  if (!config) return;
  window.SharafiStorefrontConfig = Object.freeze(config);

  const configuredMaxQty = Number(config.cart?.max_item_quantity);
  if (Number.isInteger(configuredMaxQty) && configuredMaxQty > 0 && configuredMaxQty <= 999) {
    maxCartQty = configuredMaxQty;
    saveGuestCart(getGuestCart());
  }

  const threshold = Number(config.shipping?.free_threshold_irr || 0);
  if (threshold > 0) {
    const label = `ارسال رایگان استاندارد برای خرید از ${api.formatIrr(threshold)}`;
    document.querySelectorAll('.announce-msg').forEach((element) => {
      if (element.textContent.includes('ارسال رایگان')) element.textContent = `${label} 🌸`;
    });
    document.querySelectorAll('.shipping-note').forEach((element) => {
      element.textContent = `🚚 ${label}`;
    });
  }
}

async function bootstrapPageModules() {
  await ensureSharafiApi();
  if (document.body.classList.contains('account-page')) {
    loadSharafiModule('account.js');
    return;
  }
  if (document.querySelector('.category-main .prod-grid')) {
    loadSharafiModule('catalog.js');
    return;
  }
  if (document.querySelector('.hero') && document.querySelector('.prod-grid')) loadSharafiModule('home-catalog.js');
}

window.SharafiCart = Object.freeze({
  load: loadCart,
  add: addToCart,
  changeQty,
  remove: removeFromCart,
  syncGuestCart,
  getGuestCart,
});
window.ensureSharafiApi = ensureSharafiApi;

ensureSharafiApi().catch(() => {});

document.addEventListener('sharafi:authenticated', () => {
  syncGuestCart().catch(() => {});
  syncAccountLink().catch(() => {});
});

document.addEventListener('DOMContentLoaded', () => {
  updateCartBadge();
  initProductCardCartActions();
  syncAccountLink().catch(() => {});
  syncStorefrontConfig().catch(() => {});
  bootstrapPageModules().catch(() => {});
});