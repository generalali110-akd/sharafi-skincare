// ===== Sharafi account API integration =====
(() => {
  const api = window.SharafiAPI;
  if (!api) return;

  const safe = (value) => typeof escapeHTML === 'function' ? escapeHTML(value) : String(value ?? '');
  const statusLabels = {
    pending_payment: 'در انتظار پرداخت',
    paid: 'پرداخت‌شده',
    processing: 'در حال پردازش',
    shipped: 'ارسال‌شده',
    delivered: 'تحویل‌شده',
    cancelled: 'لغوشده',
    expired: 'منقضی‌شده',
    refund_pending: 'در انتظار بازگشت وجه',
    refunded: 'بازگشت وجه',
  };

  const hideUnsupportedFeatures = () => {
    const subtitle = document.querySelector('.account-heading p');
    if (subtitle) subtitle.textContent = 'مدیریت سفارش‌ها، آدرس‌ها و اطلاعات حساب';

    document.querySelectorAll('a[href="#wishlist"], a[href="#support"], #wishlist, #support').forEach((element) => {
      element.hidden = true;
    });

    document.querySelectorAll('.account-stat').forEach((stat) => {
      if (stat.textContent.includes('موردعلاقه')) stat.hidden = true;
    });
  };

  const setStat = (index, value) => {
    const target = document.querySelectorAll('.account-stat .value')[index];
    if (target) target.textContent = Number(value || 0).toLocaleString('fa-IR');
  };

  const renderUser = (user) => {
    const heading = document.querySelector('.account-user h2');
    const detail = document.querySelector('.account-user p');
    const avatar = document.querySelector('.account-avatar');
    const security = document.querySelector('.account-security-badge');
    if (heading) heading.textContent = user.name ? `${user.name}، خوش آمدید` : 'خوش آمدید';
    if (detail) detail.textContent = `شماره حساب: ${user.mobile || '—'}`;
    if (avatar) avatar.textContent = String(user.name || 'ش').trim().charAt(0) || 'ش';
    if (security) security.textContent = '🛡️ ورود تأییدشده با OTP';
  };

  const paymentKey = (orderNumber) => {
    const storageKey = `sharafi:account-payment:${orderNumber}`;
    let key = sessionStorage.getItem(storageKey);
    if (!key) {
      key = api.idempotencyKey('payment');
      sessionStorage.setItem(storageKey, key);
    }
    return key;
  };

  const continuePayment = async (orderNumber, button) => {
    button.disabled = true;
    const oldText = button.textContent;
    button.textContent = 'در حال اتصال...';
    try {
      const payload = await api.payments.initiate(orderNumber, paymentKey(orderNumber));
      const redirect = payload?.data?.attempt?.redirect_url;
      if (!redirect) throw new Error('آدرس درگاه دریافت نشد.');
      const target = new URL(redirect, window.location.href);
      if (!['http:', 'https:'].includes(target.protocol)) throw new Error('آدرس درگاه معتبر نیست.');
      window.location.assign(target.href);
    } catch (error) {
      toast(error?.message || 'شروع پرداخت ناموفق بود.', 3500);
      button.disabled = false;
      button.textContent = oldText;
    }
  };

  const renderOrders = (ordersPayload) => {
    const section = document.querySelector('#orders');
    if (!section) return;
    const old = section.querySelector('.account-empty, .js-account-orders');
    const orders = Array.isArray(ordersPayload?.data) ? ordersPayload.data : [];
    setStat(0, Number(ordersPayload?.total ?? orders.length));

    if (!orders.length) {
      const title = old?.querySelector('h4');
      const text = old?.querySelector('p');
      if (title) title.textContent = 'هنوز سفارشی ثبت نشده';
      if (text) text.textContent = 'پس از اولین خرید، سفارش‌های شما در این بخش نمایش داده می‌شوند.';
      return;
    }

    const container = document.createElement('div');
    container.className = 'js-account-orders';
    container.style.cssText = 'display:grid;gap:12px;';
    container.innerHTML = orders.slice(0, 10).map((order) => `
      <article class="account-action" data-order="${safe(order.order_number)}" style="align-items:flex-start;">
        <span class="icon">📦</span>
        <div style="flex:1;display:grid;gap:5px;">
          <strong>سفارش ${safe(order.order_number)}</strong>
          <span>${safe(statusLabels[order.status] || order.status)} · ${safe(api.formatIrr(order.total_irr))}</span>
          <span>${Number(order.items?.length || 0).toLocaleString('fa-IR')} قلم</span>
        </div>
        ${order.status === 'pending_payment' ? `<button type="button" class="btn btn-primary btn-sm js-account-pay" data-order-pay="${safe(order.order_number)}">ادامه پرداخت</button>` : ''}
      </article>`).join('');
    old?.replaceWith(container);
    container.querySelectorAll('.js-account-pay').forEach((button) => {
      button.addEventListener('click', () => continuePayment(button.dataset.orderPay, button));
    });
  };

  const renderAddresses = (addressesPayload) => {
    const section = document.querySelector('#addresses');
    if (!section) return;
    const old = section.querySelector('.account-empty, .js-account-addresses');
    const addresses = Array.isArray(addressesPayload?.data) ? addressesPayload.data : [];
    setStat(1, addresses.length);

    if (!addresses.length) {
      const title = old?.querySelector('h4');
      const text = old?.querySelector('p');
      if (title) title.textContent = 'هنوز آدرسی ذخیره نشده';
      if (text) text.textContent = 'هنگام تکمیل اولین خرید می‌توانید آدرس تحویل را ذخیره کنید.';
      return;
    }

    const container = document.createElement('div');
    container.className = 'js-account-addresses';
    container.style.cssText = 'display:grid;gap:10px;';
    container.innerHTML = addresses.map((address) => `
      <div class="account-action">
        <span class="icon">📍</span>
        <div>
          <strong>${safe(address.title || 'آدرس')}${address.is_default ? ' · پیش‌فرض' : ''}</strong>
          <span>${safe(address.province)}، ${safe(address.city)} — ${safe(address.address_line)}</span>
          <span>${safe(address.recipient_name)} · ${safe(address.mobile)}</span>
        </div>
      </div>`).join('');
    old?.replaceWith(container);
  };

  const initLogout = () => {
    const logout = document.querySelector('.account-nav .danger');
    logout?.addEventListener('click', async (event) => {
      event.preventDefault();
      if (logout.getAttribute('aria-disabled') === 'true') return;
      logout.setAttribute('aria-disabled', 'true');
      try {
        await api.auth.logout();
        window.location.replace('login.html');
      } catch (error) {
        logout.removeAttribute('aria-disabled');
        toast(error?.message || 'خروج از حساب انجام نشد. لطفاً دوباره تلاش کنید.', 3500);
      }
    });
  };

  const init = async () => {
    hideUnsupportedFeatures();
    initLogout();
    let user;
    try {
      user = await api.currentUser();
    } catch (error) {
      toast(error?.message || 'دریافت حساب کاربری ناموفق بود.');
      return;
    }
    if (!user) {
      window.location.replace(`login.html?return=${encodeURIComponent('account.html')}`);
      return;
    }
    renderUser(user);

    try {
      const [orders, addresses] = await Promise.all([api.orders.list(), api.addresses.list()]);
      renderOrders(orders);
      renderAddresses(addresses);
    } catch (error) {
      toast(error?.message || 'دریافت اطلاعات حساب ناموفق بود.', 3500);
    }
  };

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init, { once: true });
  else init();
})();
