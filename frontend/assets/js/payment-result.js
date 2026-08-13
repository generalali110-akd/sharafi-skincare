// ===== Sharafi verified payment result =====
(() => {
  const api = window.SharafiAPI;
  if (!api) return;

  const root = document.querySelector('.js-payment-result');
  const title = document.querySelector('.js-payment-result-title');
  const message = document.querySelector('.js-payment-result-message');
  const orderTarget = document.querySelector('.js-payment-order');
  const totalTarget = document.querySelector('.js-payment-total');
  const statusTarget = document.querySelector('.js-payment-status');

  const labels = {
    pending_payment: 'در انتظار پرداخت',
    paid: 'پرداخت موفق',
    processing: 'در حال پردازش',
    shipped: 'ارسال‌شده',
    delivered: 'تحویل‌شده',
    cancelled: 'لغوشده',
    expired: 'منقضی‌شده',
    refund_pending: 'در انتظار بازگشت وجه',
    refunded: 'مبلغ بازگردانده شده',
  };

  const render = (order, payment) => {
    const status = order?.status || 'unknown';
    if (orderTarget) orderTarget.textContent = order?.order_number || '—';
    if (totalTarget) totalTarget.textContent = order ? api.formatIrr(order.total_irr) : '—';
    if (statusTarget) statusTarget.textContent = labels[status] || 'نامشخص';

    if (status === 'paid' || ['processing', 'shipped', 'delivered'].includes(status)) {
      if (title) title.textContent = 'پرداخت با موفقیت تأیید شد ✓';
      if (message) message.textContent = 'پرداخت توسط سرور و درگاه تأیید شده و سفارش ثبت نهایی شده است.';
    } else if (status === 'refund_pending' || status === 'refunded') {
      if (title) title.textContent = 'پرداخت نیازمند بازگشت وجه است';
      if (message) message.textContent = status === 'refunded'
        ? 'بازگشت وجه برای این سفارش ثبت شده است.'
        : 'پرداخت بعد از پایان وضعیت قابل‌پرداخت سفارش دریافت شده و برای بازگشت وجه در حال پیگیری است.';
    } else if (status === 'pending_payment') {
      if (title) title.textContent = 'پرداخت هنوز نهایی نشده است';
      if (message) message.textContent = payment?.status === 'paid'
        ? 'وضعیت پرداخت در حال همگام‌سازی است. از بخش سفارش‌ها دوباره بررسی کنید.'
        : 'می‌توانید از بخش سفارش‌های حساب کاربری دوباره پرداخت را ادامه دهید.';
    } else {
      if (title) title.textContent = 'پرداخت تکمیل نشد';
      if (message) message.textContent = 'هیچ پرداخت موفقی برای این سفارش در وضعیت فعلی تأیید نشده است.';
    }
  };

  const init = async () => {
    const params = new URLSearchParams(window.location.search);
    const orderNumber = String(params.get('order') || sessionStorage.getItem('sharafi:last-order') || '').trim();
    if (orderTarget && orderNumber) orderTarget.textContent = orderNumber;

    if (!orderNumber || !/^SHR-[A-Za-z0-9-]{10,50}$/.test(orderNumber)) {
      if (title) title.textContent = 'شماره سفارش معتبر نیست';
      if (message) message.textContent = 'برای مشاهده نتیجه، از بخش سفارش‌های حساب کاربری وارد شوید.';
      if (statusTarget) statusTarget.textContent = 'نامشخص';
      root?.setAttribute('aria-busy', 'false');
      return;
    }

    try {
      const user = await api.currentUser();
      if (!user) {
        if (title) title.textContent = 'برای مشاهده نتیجه وارد حساب شوید';
        if (message) message.textContent = 'وضعیت موفق یا ناموفق پرداخت فقط پس از احراز هویت از سرور نمایش داده می‌شود.';
        if (statusTarget) statusTarget.textContent = 'نیازمند ورود';
        const login = document.createElement('a');
        login.className = 'btn btn-primary';
        login.href = `login.html?return=${encodeURIComponent(window.location.pathname + window.location.search)}`;
        login.textContent = 'ورود به حساب';
        root?.querySelector('.product-card-actions')?.prepend(login);
        return;
      }

      const [orderPayload, paymentPayload] = await Promise.all([
        api.orders.show(orderNumber),
        api.payments.show(orderNumber),
      ]);
      render(orderPayload?.data, paymentPayload?.data);
    } catch (error) {
      if (title) title.textContent = 'بررسی نتیجه پرداخت ناموفق بود';
      if (message) message.textContent = error?.message || 'لطفاً از بخش سفارش‌ها وضعیت را دوباره بررسی کنید.';
      if (statusTarget) statusTarget.textContent = 'خطا در بررسی';
    } finally {
      root?.setAttribute('aria-busy', 'false');
    }
  };

  document.addEventListener('DOMContentLoaded', init);
})();
