// ===== Sharafi cart & checkout flow =====
(() => {
  const api = window.SharafiAPI;
  const cartApi = window.SharafiCart;
  const safe = (value) => typeof escapeHTML === 'function' ? escapeHTML(value) : String(value ?? '');
  let checkoutAddress = null;
  let checkoutCart = [];
  let checkoutQuote = null;
  let appliedCouponCode = null;
  let submitting = false;

  const formatIrr = (value) => api ? api.formatIrr(Number(value || 0)) : '۰ تومان';

  const stableIdempotencyKey = (storageKey, fingerprint, prefix) => {
    try {
      const current = JSON.parse(sessionStorage.getItem(storageKey) || 'null');
      if (current?.fingerprint === fingerprint && current?.key) return current.key;
      const key = api.idempotencyKey(prefix);
      sessionStorage.setItem(storageKey, JSON.stringify({ fingerprint, key }));
      return key;
    } catch {
      return api.idempotencyKey(prefix);
    }
  };

  async function renderCartV2() {
    const list = document.querySelector('.js-cart-list');
    if (!list || !cartApi) return;
    const checkoutLink = document.querySelector('.js-checkout-btn');
    const clearButton = document.querySelector('.js-cart-clear');
    const progress = document.querySelector('.js-free-shipping-progress');
    let cart;
    let user = null;

    try {
      [cart, user] = await Promise.all([
        cartApi.load(true),
        api?.currentUser().catch(() => null) || null,
      ]);
    } catch (error) {
      list.innerHTML = `<div class="cart-empty-v2"><h3>دریافت سبد خرید ناموفق بود</h3><p>${safe(error?.message || 'لطفاً دوباره تلاش کنید.')}</p></div>`;
      return;
    }

    if (cart.length === 0) {
      list.innerHTML = '<div class="cart-empty-v2"><div class="icon" aria-hidden="true">🛍️</div><h3>سبد خرید شما خالی است</h3><p>محصولات موردعلاقه‌تان را پیدا کنید و به سبد اضافه کنید.</p><a href="category.html" class="btn btn-primary cart-empty-action">مشاهده محصولات</a></div>';
      if (checkoutLink) {
        checkoutLink.setAttribute('aria-disabled', 'true');
        checkoutLink.removeAttribute('href');
        checkoutLink.tabIndex = -1;
      }
      if (clearButton) clearButton.hidden = true;
      if (progress) progress.textContent = 'برای محاسبه ارسال، ابتدا محصولی به سبد اضافه کنید.';
      document.querySelectorAll('.js-subtotal,.js-total').forEach((el) => { el.textContent = '۰ تومان'; });
      document.querySelectorAll('.js-shipping').forEach((el) => { el.textContent = '۰ تومان'; });
      return;
    }

    list.innerHTML = cart.map((item) => `
      <article class="cart-item-v2" data-cart-row="${item.variant_id}">
        <div class="thumb" aria-hidden="true">${safe(item.icon || '🧴')}</div>
        <div><h4>${safe(item.name)}</h4><div class="meta">${safe(item.variant_title || item.sku || '')}${item.in_stock === false ? ' · ناموجود' : ''}</div><div class="cart-qty" aria-label="تعداد ${safe(item.name)}"><button type="button" aria-label="کم کردن تعداد" data-cart-delta="-1" data-cart-id="${item.variant_id}">−</button><span>${Number(item.qty).toLocaleString('fa-IR')}</span><button type="button" aria-label="زیاد کردن تعداد" data-cart-delta="1" data-cart-id="${item.variant_id}">+</button></div></div>
        <div class="price-col">${fmtPrice(item.price * item.qty)}</div>
        <button class="cart-remove" type="button" aria-label="حذف ${safe(item.name)} از سبد" data-cart-remove="${item.variant_id}">حذف</button>
      </article>`).join('');

    list.querySelectorAll('[data-cart-delta]').forEach((button) => {
      button.addEventListener('click', async () => {
        button.disabled = true;
        try {
          await cartApi.changeQty(button.dataset.cartId, Number(button.dataset.cartDelta));
          await renderCartV2();
        } catch (error) {
          toast(error?.message || 'تغییر تعداد ناموفق بود.');
          button.disabled = false;
        }
      });
    });
    list.querySelectorAll('[data-cart-remove]').forEach((button) => {
      button.addEventListener('click', async () => {
        button.disabled = true;
        try {
          await cartApi.remove(button.dataset.cartRemove);
          await renderCartV2();
        } catch (error) {
          toast(error?.message || 'حذف محصول ناموفق بود.');
          button.disabled = false;
        }
      });
    });

    if (checkoutLink) {
      const destination = user ? 'checkout.html' : `login.html?return=${encodeURIComponent('checkout.html')}`;
      checkoutLink.setAttribute('href', destination);
      checkoutLink.setAttribute('aria-disabled', 'false');
      checkoutLink.removeAttribute('tabindex');
    }
    if (clearButton) clearButton.hidden = false;

    if (!user || !api) {
      const subtotal = cart.reduce((sum, item) => sum + (item.price * item.qty), 0);
      document.querySelectorAll('.js-subtotal').forEach((el) => { el.textContent = fmtPrice(subtotal); });
      document.querySelectorAll('.js-shipping,.js-total').forEach((el) => { el.textContent = 'پس از ورود'; });
      if (progress) progress.textContent = 'مبلغ نهایی، موجودی و هزینه ارسال پس از ورود از سرور محاسبه می‌شود.';
      return;
    }

    try {
      const quotePayload = await api.checkout.quote('standard');
      const quote = quotePayload?.data;
      document.querySelectorAll('.js-subtotal').forEach((el) => { el.textContent = formatIrr(quote?.subtotal_irr); });
      document.querySelectorAll('.js-shipping').forEach((el) => { el.textContent = Number(quote?.shipping_irr || 0) === 0 ? 'رایگان' : formatIrr(quote?.shipping_irr); });
      document.querySelectorAll('.js-total').forEach((el) => { el.textContent = formatIrr(quote?.total_irr); });
      if (progress) progress.textContent = Number(quote?.shipping_irr || 0) === 0 ? '✓ سفارش شما با ارسال استاندارد شامل ارسال رایگان است.' : 'هزینه ارسال و مبلغ نهایی مستقیماً توسط سرور محاسبه شده است.';
    } catch (error) {
      if (progress) progress.textContent = error?.message || 'محاسبه مبلغ نهایی ناموفق بود.';
    }
  }

  function initCartClearAction() {
    const clearButton = document.querySelector('.js-cart-clear');
    if (!clearButton || !cartApi) return;
    clearButton.addEventListener('click', async () => {
      const accepted = window.confirm('آیا مطمئن هستید که می‌خواهید از خرید انصراف دهید و سبد خرید خالی شود؟');
      if (!accepted) return;
      clearButton.disabled = true;
      try {
        await cartApi.clear();
        toast('سبد خرید خالی شد.');
        await renderCartV2();
      } catch (error) {
        toast(error?.message || 'خالی کردن سبد خرید ناموفق بود.');
      } finally {
        clearButton.disabled = false;
      }
    });
  }

  function syncOptionGroup(groupName) {
    document.querySelectorAll(`input[name="${groupName}"]`).forEach((input) => input.closest('.checkout-option')?.classList.toggle('is-selected', input.checked));
  }

  const selectedShipping = () => document.querySelector('input[name="shipping"]:checked')?.value || 'standard';

  function renderCheckoutSummary() {
    const summary = document.querySelector('.js-checkout-items');
    if (!summary) return;
    const submit = document.querySelector('.js-checkout-submit');
    const warning = document.querySelector('.js-checkout-empty-warning');

    if (!checkoutCart.length) {
      summary.innerHTML = '<div class="checkout-empty-warning">سبد خرید خالی است. برای ادامه، ابتدا محصولی به سبد خرید اضافه کنید.</div>';
      if (submit) submit.disabled = true;
      if (warning) warning.hidden = false;
      return;
    }

    const quoteItems = Array.isArray(checkoutQuote?.items) ? checkoutQuote.items : [];
    summary.innerHTML = quoteItems.map((item) => `<div class="checkout-summary-item"><span>${safe(item.product_name)}${item.variant_title ? ` — ${safe(item.variant_title)}` : ''} × ${Number(item.quantity).toLocaleString('fa-IR')}</span><strong>${formatIrr(item.line_total_irr)}</strong></div>`).join('');

    if (submit) submit.disabled = false;
    if (warning) warning.hidden = true;
    document.querySelectorAll('.js-subtotal').forEach((el) => { el.textContent = formatIrr(checkoutQuote?.subtotal_irr); });
    document.querySelectorAll('.js-shipping').forEach((el) => { el.textContent = Number(checkoutQuote?.shipping_irr || 0) === 0 ? 'رایگان' : formatIrr(checkoutQuote?.shipping_irr); });
    document.querySelectorAll('.js-total').forEach((el) => { el.textContent = formatIrr(checkoutQuote?.total_irr); });
    const discount = Number(checkoutQuote?.discount_irr || 0);
    document.querySelectorAll('.js-discount').forEach((el) => { el.textContent = discount > 0 ? `− ${formatIrr(discount)}` : '۰ تومان'; });
    document.querySelectorAll('.js-discount-row').forEach((el) => { el.hidden = discount <= 0; });
    const status = document.querySelector('.js-coupon-status');
    if (status) status.textContent = appliedCouponCode ? `کد ${appliedCouponCode} اعمال شد.` : '';
  }

  async function refreshQuote(couponCode = appliedCouponCode, showError = true) {
    if (!api || !checkoutCart.length) return false;
    try {
      const payload = await api.checkout.quote(selectedShipping(), couponCode || null);
      checkoutQuote = payload?.data || null;
      appliedCouponCode = checkoutQuote?.coupon_code || null;
      renderCheckoutSummary();
      return true;
    } catch (error) {
      if (showError) toast(error?.message || 'محاسبه سفارش ناموفق بود.', 3200);
      return false;
    }
  }

  function fillAddressForm(address) {
    if (!address) return;
    const form = document.querySelector('.js-checkout-form');
    if (!form) return;
    form.elements.full_name.value = address.recipient_name || '';
    form.elements.phone.value = address.mobile || '';
    form.elements.province.value = address.province || '';
    form.elements.city.value = address.city || '';
    form.elements.postal_code.value = address.postal_code || '';
    form.elements.address.value = address.address_line || '';
  }

  async function initCheckoutPage() {
    const form = document.querySelector('.js-checkout-form');
    if (!form || !api || !cartApi) return;
    let user;
    try {
      user = await api.currentUser();
    } catch (error) {
      toast(error?.message || 'بررسی حساب کاربری ناموفق بود.');
      return;
    }
    if (!user) {
      window.location.replace(`login.html?return=${encodeURIComponent('checkout.html')}`);
      return;
    }

    const cod = form.querySelector('input[name="payment"][value="cod"]');
    if (cod) {
      cod.disabled = true;
      cod.closest('.checkout-option')?.setAttribute('aria-disabled', 'true');
    }

    try {
      const [cart, addressesPayload] = await Promise.all([cartApi.load(true), api.addresses.list()]);
      checkoutCart = cart;
      const addresses = Array.isArray(addressesPayload?.data) ? addressesPayload.data : [];
      checkoutAddress = addresses.find((address) => address.is_default) || addresses[0] || null;
      if (checkoutAddress) fillAddressForm(checkoutAddress);
      else if (user.mobile) form.elements.phone.value = user.mobile;
      await refreshQuote(null);
    } catch (error) {
      toast(error?.message || 'دریافت اطلاعات تکمیل خرید ناموفق بود.', 3200);
    }
  }

  function initCheckoutOptions() {
    ['shipping', 'payment'].forEach((groupName) => {
      syncOptionGroup(groupName);
      document.querySelectorAll(`input[name="${groupName}"]`).forEach((input) => {
        input.addEventListener('change', async () => {
          syncOptionGroup(groupName);
          if (groupName === 'shipping' && document.querySelector('.js-checkout-form')) await refreshQuote();
        });
      });
    });

    const couponInput = document.querySelector('.js-coupon-code');
    const couponButton = document.querySelector('.js-apply-coupon');
    couponButton?.addEventListener('click', async () => {
      const desired = String(couponInput?.value || '').trim().toUpperCase();
      couponButton.disabled = true;
      const previousQuote = checkoutQuote;
      const previousCoupon = appliedCouponCode;
      const ok = await refreshQuote(desired || null, false);
      couponButton.disabled = false;
      const status = document.querySelector('.js-coupon-status');
      if (ok) {
        if (couponInput) couponInput.value = appliedCouponCode || '';
        if (status) status.textContent = appliedCouponCode ? `کد ${appliedCouponCode} اعمال شد.` : 'کد تخفیف حذف شد.';
      } else {
        checkoutQuote = previousQuote;
        appliedCouponCode = previousCoupon;
        renderCheckoutSummary();
        if (status) status.textContent = desired ? 'این کد قابل اعمال نیست؛ مبلغ سفارش تغییر نکرد.' : '';
      }
    });
  }

  async function saveCheckoutAddress(form) {
    const data = {
      title: checkoutAddress?.title || 'ارسال سفارش',
      recipient_name: form.elements.full_name.value.trim(),
      mobile: form.elements.phone.value.trim(),
      province: form.elements.province.value.trim(),
      city: form.elements.city.value.trim(),
      postal_code: form.elements.postal_code.value.trim(),
      address_line: form.elements.address.value.trim(),
      is_default: true,
    };
    const payload = checkoutAddress ? await api.addresses.update(checkoutAddress.id, data) : await api.addresses.create(data);
    checkoutAddress = payload?.data || null;
    return checkoutAddress;
  }

  function initCheckoutForm() {
    const form = document.querySelector('.js-checkout-form');
    if (!form || !api) return;
    form.addEventListener('submit', async (event) => {
      event.preventDefault();
      if (submitting) return;
      if (!checkoutCart.length) {
        toast('سبد خرید خالی است');
        return;
      }
      if (!form.checkValidity()) {
        form.reportValidity();
        return;
      }
      if (!checkoutQuote) {
        await refreshQuote();
        if (!checkoutQuote) return;
      }

      const submit = form.querySelector('.js-checkout-submit');
      submitting = true;
      if (submit) {
        submit.disabled = true;
        submit.dataset.originalText ||= submit.textContent;
        submit.textContent = 'در حال ثبت سفارش...';
      }

      let orderNumber = null;
      try {
        const address = await saveCheckoutAddress(form);
        if (!address?.id) throw new Error('ذخیره آدرس ناموفق بود.');
        const orderData = {
          address_id: address.id,
          shipping_method: selectedShipping(),
          ...(appliedCouponCode ? { coupon_code: appliedCouponCode } : {}),
        };
        const fingerprint = JSON.stringify(orderData);
        const orderKey = stableIdempotencyKey('sharafi:order-key', fingerprint, 'order');
        const orderPayload = await api.orders.create(orderData, orderKey);
        orderNumber = orderPayload?.data?.order_number;
        if (!orderNumber) throw new Error('شماره سفارش از سرور دریافت نشد.');

        sessionStorage.setItem('sharafi:last-order', orderNumber);
        if (submit) submit.textContent = 'در حال اتصال به درگاه...';
        const paymentKey = stableIdempotencyKey(`sharafi:payment-key:${orderNumber}`, String(orderNumber), 'payment');
        const paymentPayload = await api.payments.initiate(orderNumber, paymentKey);
        const redirectUrl = paymentPayload?.data?.attempt?.redirect_url;
        if (!redirectUrl) throw new Error('آدرس درگاه پرداخت دریافت نشد.');
        const target = new URL(redirectUrl, window.location.href);
        if (!['http:', 'https:'].includes(target.protocol)) throw new Error('آدرس درگاه معتبر نیست.');
        window.location.assign(target.href);
      } catch (error) {
        if (orderNumber) toast(`سفارش ${orderNumber} ثبت شد، اما اتصال به درگاه ناموفق بود. از حساب کاربری دوباره تلاش کنید.`, 5000);
        else toast(error?.message || 'ثبت سفارش ناموفق بود.', 4000);
      } finally {
        submitting = false;
        if (submit) {
          submit.disabled = false;
          submit.textContent = submit.dataset.originalText || 'ثبت سفارش و ادامه پرداخت';
        }
      }
    });
  }

  document.addEventListener('DOMContentLoaded', () => {
    renderCartV2();
    initCartClearAction();
    initCheckoutOptions();
    initCheckoutForm();
    initCheckoutPage();
  });
})();
