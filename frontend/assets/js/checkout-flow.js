// ===== Sharafi cart & checkout flow =====
(() => {
  const FREE_SHIPPING_THRESHOLD = 800000;
  const STANDARD_SHIPPING = 45000;
  const COURIER_SHIPPING = 65000;

  const safe = (value) => typeof escapeHTML === 'function' ? escapeHTML(value) : String(value ?? '');

  function renderCartV2() {
    const list = document.querySelector('.js-cart-list');
    if (!list || typeof getCart !== 'function') return;

    const cart = getCart();
    const checkoutLink = document.querySelector('.js-checkout-btn');
    const progress = document.querySelector('.js-free-shipping-progress');

    if (cart.length === 0) {
      list.innerHTML = `
        <div class="cart-empty-v2">
          <div class="icon" aria-hidden="true">🛍️</div>
          <h3>سبد خرید شما خالی است</h3>
          <p>محصولات موردعلاقه‌تان را پیدا کنید و به سبد اضافه کنید.</p>
          <a href="category.html" class="btn btn-primary" style="margin-top:16px;">مشاهده محصولات</a>
        </div>`;

      if (checkoutLink) {
        checkoutLink.setAttribute('aria-disabled', 'true');
        checkoutLink.removeAttribute('href');
        checkoutLink.tabIndex = -1;
      }
      if (progress) progress.textContent = 'برای محاسبه ارسال رایگان، ابتدا محصولی به سبد اضافه کنید.';
      return;
    }

    list.innerHTML = cart.map((item) => {
      const id = safe(item.id);
      const name = safe(item.name);
      const brand = safe(item.brand);
      const icon = safe(item.icon || '🧴');
      const qty = Number(item.qty).toLocaleString('fa-IR');
      return `
        <article class="cart-item-v2">
          <div class="thumb" aria-hidden="true">${icon}</div>
          <div>
            <h4>${name}</h4>
            <div class="meta">${brand}</div>
            <div class="cart-qty" aria-label="تعداد ${name}">
              <button type="button" aria-label="کم کردن تعداد" data-cart-delta="-1" data-cart-id="${id}">−</button>
              <span>${qty}</span>
              <button type="button" aria-label="زیاد کردن تعداد" data-cart-delta="1" data-cart-id="${id}">+</button>
            </div>
          </div>
          <div class="price-col">${fmtPrice(item.price * item.qty)}</div>
          <button class="cart-remove" type="button" aria-label="حذف ${name} از سبد" data-cart-remove="${id}">✕</button>
        </article>`;
    }).join('');

    list.querySelectorAll('[data-cart-delta]').forEach((button) => {
      button.addEventListener('click', () => {
        changeQty(button.dataset.cartId, Number(button.dataset.cartDelta));
        renderCartV2();
      });
    });

    list.querySelectorAll('[data-cart-remove]').forEach((button) => {
      button.addEventListener('click', () => {
        removeFromCart(button.dataset.cartRemove);
        renderCartV2();
      });
    });

    if (checkoutLink) {
      checkoutLink.setAttribute('href', 'checkout.html');
      checkoutLink.setAttribute('aria-disabled', 'false');
      checkoutLink.removeAttribute('tabindex');
    }

    const subtotal = cartTotal();
    if (progress) {
      const remaining = Math.max(0, FREE_SHIPPING_THRESHOLD - subtotal);
      progress.textContent = remaining === 0
        ? '✓ سفارش شما شامل ارسال رایگان است.'
        : `با خرید ${fmtPrice(remaining)} دیگر، ارسال سفارش رایگان می‌شود.`;
    }
  }

  function syncOptionGroup(groupName) {
    document.querySelectorAll(`input[name="${groupName}"]`).forEach((input) => {
      input.closest('.checkout-option')?.classList.toggle('is-selected', input.checked);
    });
  }

  function shippingCost() {
    const subtotal = typeof cartTotal === 'function' ? cartTotal() : 0;
    if (!subtotal) return 0;
    const shipping = document.querySelector('input[name="shipping"]:checked')?.value || 'standard';
    if (shipping === 'courier') return COURIER_SHIPPING;
    return subtotal >= FREE_SHIPPING_THRESHOLD ? 0 : STANDARD_SHIPPING;
  }

  function renderCheckoutV2() {
    const summary = document.querySelector('.js-checkout-items');
    if (!summary || typeof getCart !== 'function') return;

    const cart = getCart();
    const submit = document.querySelector('.js-checkout-submit');
    const warning = document.querySelector('.js-checkout-empty-warning');

    if (cart.length === 0) {
      summary.innerHTML = '<div class="checkout-empty-warning">سبد خرید خالی است. برای ادامه، ابتدا محصولی به سبد خرید اضافه کنید.</div>';
      if (submit) submit.disabled = true;
      if (warning) warning.hidden = false;
    } else {
      summary.innerHTML = cart.map((item) => `
        <div class="checkout-summary-item">
          <span>${safe(item.name)} × ${Number(item.qty).toLocaleString('fa-IR')}</span>
          <strong>${fmtPrice(item.price * item.qty)}</strong>
        </div>`).join('');
      if (submit) submit.disabled = false;
      if (warning) warning.hidden = true;
    }

    const subtotal = cartTotal();
    const shipping = shippingCost();
    document.querySelectorAll('.js-subtotal').forEach((el) => el.textContent = fmtPrice(subtotal));
    document.querySelectorAll('.js-shipping').forEach((el) => el.textContent = shipping === 0 ? 'رایگان' : fmtPrice(shipping));
    document.querySelectorAll('.js-total').forEach((el) => el.textContent = fmtPrice(subtotal + shipping));
  }

  function initCheckoutOptions() {
    ['shipping', 'payment'].forEach((groupName) => {
      syncOptionGroup(groupName);
      document.querySelectorAll(`input[name="${groupName}"]`).forEach((input) => {
        input.addEventListener('change', () => {
          syncOptionGroup(groupName);
          if (groupName === 'shipping') renderCheckoutV2();
        });
      });
    });
  }

  function initCheckoutForm() {
    const form = document.querySelector('.js-checkout-form');
    if (!form) return;

    form.addEventListener('submit', (event) => {
      event.preventDefault();
      if (getCart().length === 0) {
        toast('سبد خرید خالی است');
        return;
      }
      if (!form.checkValidity()) {
        form.reportValidity();
        return;
      }
      toast('اطلاعات سفارش معتبر است — اتصال درگاه در Backend انجام می‌شود');
    });
  }

  document.addEventListener('DOMContentLoaded', () => {
    renderCartV2();
    initCheckoutOptions();
    renderCheckoutV2();
    initCheckoutForm();
  });
})();
