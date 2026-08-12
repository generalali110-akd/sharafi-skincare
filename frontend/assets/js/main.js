// ===== فروشگاه محصولات بهداشتی و آرایشی — منطق نمونه فرانت‌اند =====
// این فایل برای دموی UI/UX است؛ در نسخه نهایی وردپرسی، این منطق با PHP/ووکامرس جایگزین می‌شود.

const CART_KEY = "demo_cart_v1";

function getCart() {
  try { return JSON.parse(localStorage.getItem(CART_KEY)) || []; }
  catch (e) { return []; }
}
function saveCart(cart) {
  localStorage.setItem(CART_KEY, JSON.stringify(cart));
  updateCartBadge();
}
function addToCart(product, quantity = 1) {
  const qty = Math.max(1, Number.parseInt(quantity, 10) || 1);
  const cart = getCart();
  const existing = cart.find(i => i.id === product.id);
  if (existing) existing.qty += qty;
  else cart.push({ ...product, qty });
  saveCart(cart);
  toast(qty > 1
    ? `${qty.toLocaleString("fa-IR")} عدد «${product.name}» به سبد خرید اضافه شد`
    : `«${product.name}» به سبد خرید اضافه شد`);
}
function removeFromCart(id) {
  saveCart(getCart().filter(i => i.id !== id));
  renderCartPage();
}
function changeQty(id, delta) {
  const cart = getCart();
  const item = cart.find(i => i.id === id);
  if (!item) return;
  item.qty = Math.max(1, item.qty + delta);
  saveCart(cart);
  renderCartPage();
}
function cartCount() {
  return getCart().reduce((sum, i) => sum + i.qty, 0);
}
function cartTotal() {
  return getCart().reduce((sum, i) => sum + i.qty * i.price, 0);
}
function updateCartBadge() {
  document.querySelectorAll(".js-cart-count").forEach(el => {
    const c = cartCount();
    el.textContent = c;
    el.style.display = c > 0 ? "flex" : "none";
  });
}
function fmtPrice(n) {
  return n.toLocaleString("fa-IR") + " تومان";
}
function toast(msg) {
  let box = document.querySelector(".js-toast");
  if (!box) {
    box = document.createElement("div");
    box.className = "js-toast";
    box.style.cssText = "position:fixed;bottom:24px;left:50%;transform:translateX(-50%);background:#4a3a3c;color:#fff;padding:12px 24px;border-radius:999px;font-size:14px;z-index:999;box-shadow:0 8px 20px rgba(0,0,0,.2);transition:opacity .3s;";
    document.body.appendChild(box);
  }
  box.textContent = msg;
  box.style.opacity = "1";
  clearTimeout(box._t);
  box._t = setTimeout(() => (box.style.opacity = "0"), 1800);
}

// ===== رندر صفحه سبد خرید (cart.html) =====
function renderCartPage() {
  const listEl = document.querySelector(".js-cart-list");
  if (!listEl) return;
  const cart = getCart();

  if (cart.length === 0) {
    listEl.innerHTML = `<div class="cart-empty">
      <div style="font-size:50px;margin-bottom:14px;">🛍️</div>
      <p>سبد خریدتان خالی است</p>
      <a href="category.html" class="btn btn-primary" style="margin-top:14px;">مشاهده محصولات</a>
    </div>`;
  } else {
    listEl.innerHTML = cart.map(i => `
      <div class="cart-item">
        <div class="thumb">${i.icon}</div>
        <div class="info">
          <h4>${i.name}</h4>
          <span>${i.brand}</span>
          <div class="qty-stepper" style="margin-top:10px;">
            <button onclick="changeQty(${i.id},-1)">−</button>
            <span>${i.qty}</span>
            <button onclick="changeQty(${i.id},1)">+</button>
          </div>
        </div>
        <div class="price-col">${fmtPrice(i.price * i.qty)}</div>
        <button class="remove-btn" onclick="removeFromCart(${i.id})">✕</button>
      </div>`).join("");
  }

  const subtotal = cartTotal();
  const shipping = subtotal > 0 ? (subtotal > 800000 ? 0 : 45000) : 0;
  document.querySelectorAll(".js-subtotal").forEach(el => (el.textContent = fmtPrice(subtotal)));
  document.querySelectorAll(".js-shipping").forEach(el => (el.textContent = shipping === 0 ? "رایگان" : fmtPrice(shipping)));
  document.querySelectorAll(".js-total").forEach(el => (el.textContent = fmtPrice(subtotal + shipping)));
  document.querySelectorAll(".js-checkout-btn").forEach(el => el.toggleAttribute("disabled", cart.length === 0));
}

// ===== منوی موبایل =====
function initMobileMenu() {
  const toggle = document.querySelector(".js-mobile-toggle");
  const nav = document.querySelector(".main-nav");
  if (!toggle || !nav) return;
  toggle.addEventListener("click", () => {
    const open = nav.style.display === "flex";
    nav.style.display = open ? "none" : "flex";
    nav.style.cssText += "flex-direction:column;position:absolute;top:74px;right:20px;left:20px;background:#fff;padding:20px;border-radius:16px;box-shadow:0 8px 24px rgba(0,0,0,.1);";
  });
}

// ===== تب‌های صفحه محصول =====
function initTabs() {
  document.querySelectorAll(".tabs span").forEach(tab => {
    tab.addEventListener("click", () => {
      document.querySelectorAll(".tabs span").forEach(t => t.classList.remove("active"));
      tab.classList.add("active");
      document.querySelectorAll(".tab-content").forEach(c => c.style.display = "none");
      document.querySelector(`.tab-content[data-tab="${tab.dataset.tab}"]`).style.display = "block";
    });
  });
}

// ===== انتخاب روش پرداخت (checkout.html) =====
function initPayOptions() {
  document.querySelectorAll(".pay-option").forEach(opt => {
    opt.addEventListener("click", () => {
      document.querySelectorAll(".pay-option").forEach(o => o.classList.remove("selected"));
      opt.classList.add("selected");
      opt.querySelector('input[type="radio"]').checked = true;
    });
  });
}

// ===== استپر تعداد در صفحه محصول =====
function initProductStepper() {
  const stepper = document.querySelector(".js-qty-stepper");
  if (!stepper) return;
  let qty = 1;
  const span = stepper.querySelector("span");
  stepper.querySelector(".js-minus").addEventListener("click", () => {
    qty = Math.max(1, qty - 1);
    span.textContent = qty;
  });
  stepper.querySelector(".js-plus").addEventListener("click", () => {
    qty += 1;
    span.textContent = qty;
  });
}

// ===== رندر خلاصه سفارش در چک‌اوت =====
function renderCheckoutSummary() {
  const box = document.querySelector(".js-checkout-items");
  if (!box) return;
  const cart = getCart();
  box.innerHTML = cart.map(i => `
    <div class="summary-row"><span>${i.name} × ${i.qty}</span><span>${fmtPrice(i.price * i.qty)}</span></div>
  `).join("");
  const subtotal = cartTotal();
  const shipping = subtotal > 800000 || subtotal === 0 ? 0 : 45000;
  document.querySelectorAll(".js-subtotal").forEach(el => (el.textContent = fmtPrice(subtotal)));
  document.querySelectorAll(".js-shipping").forEach(el => (el.textContent = shipping === 0 ? "رایگان" : fmtPrice(shipping)));
  document.querySelectorAll(".js-total").forEach(el => (el.textContent = fmtPrice(subtotal + shipping)));
}

document.addEventListener("DOMContentLoaded", () => {
  updateCartBadge();
  initMobileMenu();
  initTabs();
  initPayOptions();
  initProductStepper();
  renderCartPage();
  renderCheckoutSummary();

  // فرم چک‌اوت — فقط نمایشی
  const form = document.querySelector(".js-checkout-form");
  if (form) {
    form.addEventListener("submit", (e) => {
      e.preventDefault();
      toast("این یک پروتوتایپ است — اتصال واقعی پرداخت در نسخه نهایی انجام می‌شود");
    });
  }
});
