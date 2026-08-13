// ===== Sharafi Admin OTP Login =====
(() => {
  const api = window.SharafiAPI;
  const adminApi = window.SharafiAdminAPI;
  let challengeId = null;
  let resendTimer = null;
  let resendRemaining = 0;

  const PAGE_PERMISSION = Object.freeze([
    ['dashboard.html', 'admin.dashboard.view'],
    ['orders.html', 'orders.read'],
    ['products.html', 'catalog.read'],
    ['inventory.html', 'inventory.read'],
    ['users.html', 'customers.read'],
    ['discounts.html', 'discounts.read'],
  ]);

  const normalizeDigits = (value) => String(value || '')
    .replace(/[۰-۹]/g, (digit) => String('۰۱۲۳۴۵۶۷۸۹'.indexOf(digit)))
    .replace(/[٠-٩]/g, (digit) => String('٠١٢٣٤٥٦٧٨٩'.indexOf(digit)))
    .replace(/\D/g, '');

  const preferredPage = (session) => PAGE_PERMISSION
    .find(([, permission]) => session?.permissions?.includes(permission))?.[0] || null;

  function clearResendTimer() {
    if (resendTimer) window.clearInterval(resendTimer);
    resendTimer = null;
  }

  function setError(element, message = '') {
    if (element) element.textContent = message;
  }

  function startResendCountdown(button, seconds) {
    clearResendTimer();
    resendRemaining = Math.max(0, Number(seconds) || 45);
    button.hidden = false;
    button.disabled = resendRemaining > 0;

    const render = () => {
      button.textContent = resendRemaining > 0
        ? `ارسال دوباره (${resendRemaining} ثانیه)`
        : 'ارسال دوباره کد';
      button.disabled = resendRemaining > 0;
    };

    render();
    resendTimer = window.setInterval(() => {
      resendRemaining -= 1;
      if (resendRemaining <= 0) {
        resendRemaining = 0;
        clearResendTimer();
      }
      render();
    }, 1000);
  }

  document.addEventListener('DOMContentLoaded', async () => {
    const form = document.querySelector('.js-admin-login-form');
    const mobile = form?.elements.namedItem('mobile');
    const code = form?.elements.namedItem('code');
    const codeField = document.querySelector('.js-admin-otp-field');
    const error = document.querySelector('.js-admin-login-error');
    const submit = document.querySelector('.js-admin-submit');
    const resend = document.querySelector('.js-admin-resend');
    if (!form || !mobile || !code || !submit || !resend || !api || !adminApi) return;

    try {
      const existing = await adminApi.session();
      const target = preferredPage(existing);
      if (target) {
        const requested = new URLSearchParams(window.location.search).get('return');
        window.location.replace(api.safeReturnTarget(requested, target));
        return;
      }
    } catch (sessionError) {
      if (!(sessionError instanceof api.ApiError) || ![401, 403].includes(sessionError.status)) {
        setError(error, sessionError?.message || 'بررسی نشست فعلی ناموفق بود.');
      }
    }

    const requestOtp = async () => {
      const normalized = normalizeDigits(mobile.value);
      mobile.value = normalized;
      if (!/^09\d{9}$/.test(normalized)) {
        setError(error, 'شماره موبایل را به‌صورت 09123456789 وارد کنید.');
        mobile.focus();
        return;
      }

      submit.disabled = true;
      resend.disabled = true;
      setError(error);
      try {
        const response = await api.auth.requestOtp(normalized);
        challengeId = response?.data?.challenge_id || null;
        if (!challengeId) throw new Error('شناسه درخواست OTP دریافت نشد.');
        codeField.hidden = false;
        code.required = true;
        mobile.readOnly = true;
        submit.textContent = 'تأیید و ورود';
        code.focus();
        startResendCountdown(resend, response?.data?.resend_after || 45);
      } catch (requestError) {
        setError(error, requestError?.message || 'ارسال کد تأیید ناموفق بود.');
      } finally {
        submit.disabled = false;
      }
    };

    form.addEventListener('submit', async (event) => {
      event.preventDefault();

      if (!challengeId) {
        await requestOtp();
        return;
      }

      const normalizedCode = normalizeDigits(code.value);
      code.value = normalizedCode;
      if (!/^\d{6}$/.test(normalizedCode)) {
        setError(error, 'کد تأیید باید ۶ رقم باشد.');
        code.focus();
        return;
      }

      submit.disabled = true;
      setError(error);
      try {
        await api.auth.verifyOtp(challengeId, normalizedCode);
        api.clearSessionCache();
        adminApi.clearSession();

        let session;
        try {
          session = await adminApi.session(true);
        } catch (accessError) {
          if (accessError instanceof api.ApiError && accessError.status === 403) {
            await api.auth.logout();
            adminApi.clearSession();
            setError(error, 'این حساب مجوز ورود به پنل مدیریت را ندارد.');
            challengeId = null;
            code.value = '';
            code.required = false;
            codeField.hidden = true;
            mobile.readOnly = false;
            submit.textContent = 'ارسال کد تأیید';
            resend.hidden = true;
            clearResendTimer();
            return;
          }
          throw accessError;
        }

        const fallback = preferredPage(session);
        if (!fallback) throw new Error('برای این حساب صفحه مدیریتی قابل دسترسی وجود ندارد.');
        const requested = new URLSearchParams(window.location.search).get('return');
        window.location.replace(api.safeReturnTarget(requested, fallback));
      } catch (verifyError) {
        setError(error, verifyError?.message || 'تأیید کد ناموفق بود.');
      } finally {
        submit.disabled = false;
      }
    });

    resend.addEventListener('click', async () => {
      if (resend.disabled || resendRemaining > 0) return;
      challengeId = null;
      code.value = '';
      mobile.readOnly = false;
      code.required = false;
      codeField.hidden = true;
      resend.hidden = true;
      submit.textContent = 'ارسال کد تأیید';
      await requestOtp();
    });
  });
})();
