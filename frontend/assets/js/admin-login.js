// ===== Sharafi Admin OTP Login =====
(() => {
  const normalizeDigits = (value) => String(value || '')
    .replace(/[۰-۹]/g, (digit) => String('۰۱۲۳۴۵۶۷۸۹'.indexOf(digit)))
    .replace(/[٠-٩]/g, (digit) => String('٠١٢٣٤٥٦٧٨٩'.indexOf(digit)));

  const normalizeMobile = (value) => normalizeDigits(value).replace(/[\s-]/g, '');
  const isIranMobile = (value) => /^09\d{9}$/.test(value);

  document.addEventListener('DOMContentLoaded', () => {
    const api = window.SharafiAPI;
    const adminApi = window.SharafiAdminAPI;
    const requestForm = document.querySelector('.js-admin-otp-request-form');
    const verifyForm = document.querySelector('.js-admin-otp-verify-form');
    const mobileInput = document.querySelector('#admin-mobile');
    const codeInput = document.querySelector('#admin-otp');
    const requestError = document.querySelector('.js-admin-login-error');
    const verifyError = document.querySelector('.js-admin-verify-error');
    const requestButton = document.querySelector('.js-admin-request-button');
    const verifyButton = document.querySelector('.js-admin-verify-button');
    const resendButton = document.querySelector('.js-admin-resend');
    const changeMobileButton = document.querySelector('.js-admin-change-mobile');
    const mobileEcho = document.querySelector('.js-admin-mobile-echo');

    if (!api || !adminApi || !requestForm || !verifyForm || !mobileInput || !codeInput) return;

    let challengeId = null;
    let pendingMobile = '';
    let inFlight = false;
    let resendTimer = null;

    const setError = (target, message = '') => {
      if (target) target.textContent = message;
    };

    const setBusy = (button, busy, busyText) => {
      if (!button) return;
      if (!button.dataset.originalText) button.dataset.originalText = button.textContent;
      button.disabled = busy;
      button.textContent = busy ? busyText : button.dataset.originalText;
    };

    const showRequest = () => {
      verifyForm.hidden = true;
      requestForm.hidden = false;
      codeInput.value = '';
      challengeId = null;
      window.clearInterval(resendTimer);
      mobileInput.focus();
    };

    const startResendTimer = (seconds) => {
      window.clearInterval(resendTimer);
      let remaining = Math.max(1, Number.parseInt(seconds, 10) || 45);
      resendButton.disabled = true;

      const paint = () => {
        resendButton.textContent = remaining > 0
          ? `ارسال مجدد (${remaining.toLocaleString('fa-IR')})`
          : 'ارسال مجدد کد';
        resendButton.disabled = remaining > 0 || inFlight;
      };

      paint();
      resendTimer = window.setInterval(() => {
        remaining = Math.max(0, remaining - 1);
        paint();
        if (remaining === 0) window.clearInterval(resendTimer);
      }, 1000);
    };

    const adminTarget = () => {
      const fallback = 'dashboard.html';
      const raw = new URLSearchParams(window.location.search).get('return');
      if (!raw) return fallback;

      try {
        const target = new URL(raw, window.location.href);
        const adminRoot = new URL('./', window.location.href);
        if (target.origin !== window.location.origin || !target.pathname.startsWith(adminRoot.pathname)) return fallback;
        return `${target.pathname}${target.search}${target.hash}`;
      } catch {
        return fallback;
      }
    };

    const ensureAdministrativeSession = async () => {
      try {
        await adminApi.session();
        window.location.replace(adminTarget());
        return true;
      } catch (error) {
        if (error instanceof api.ApiError && error.status === 401) return false;
        if (error instanceof api.ApiError && error.status === 403) {
          try {
            await api.auth.logout();
          } catch {
            // Access is already denied; continue with the login UI.
          }
          setError(requestError, 'این حساب مجوز ورود به پنل مدیریت را ندارد.');
          return false;
        }
        setError(requestError, error?.message || 'بررسی نشست مدیریت ناموفق بود.');
        return false;
      }
    };

    requestForm.addEventListener('submit', async (event) => {
      event.preventDefault();
      if (inFlight) return;

      pendingMobile = normalizeMobile(mobileInput.value);
      if (!isIranMobile(pendingMobile)) {
        setError(requestError, 'شماره موبایل باید با ۰۹ شروع شود و ۱۱ رقم باشد.');
        mobileInput.focus();
        return;
      }

      setError(requestError);
      inFlight = true;
      setBusy(requestButton, true, 'در حال ارسال...');
      try {
        const payload = await api.auth.requestOtp(pendingMobile);
        challengeId = payload?.data?.challenge_id || null;
        if (!challengeId) throw new Error('پاسخ OTP معتبر نیست.');

        mobileEcho.textContent = payload?.data?.mobile || pendingMobile;
        requestForm.hidden = true;
        verifyForm.hidden = false;
        codeInput.value = '';
        codeInput.focus();
        startResendTimer(payload?.data?.resend_after || 45);
      } catch (error) {
        setError(requestError, error?.message || 'ارسال کد تأیید ناموفق بود.');
      } finally {
        inFlight = false;
        setBusy(requestButton, false, '');
      }
    });

    verifyForm.addEventListener('submit', async (event) => {
      event.preventDefault();
      if (inFlight) return;

      const code = normalizeDigits(codeInput.value).replace(/\D/g, '');
      if (!challengeId || !/^\d{6}$/.test(code)) {
        setError(verifyError, 'کد تأیید ۶ رقمی را کامل وارد کنید.');
        codeInput.focus();
        return;
      }

      setError(verifyError);
      inFlight = true;
      setBusy(verifyButton, true, 'در حال بررسی...');
      try {
        await api.auth.verifyOtp(challengeId, code);
        api.clearSessionCache();
        const session = await adminApi.session();
        if (!session?.data?.permissions?.length) throw new Error('مجوز مدیریت در پاسخ سرور وجود ندارد.');
        window.location.replace(adminTarget());
      } catch (error) {
        if (error instanceof api.ApiError && error.status === 403) {
          try {
            await api.auth.logout();
          } catch {
            // The account is unauthorized for Admin regardless of logout response.
          }
          showRequest();
          setError(requestError, 'این شماره موبایل به پنل مدیریت دسترسی ندارد.');
          return;
        }
        setError(verifyError, error?.message || 'کد تأیید معتبر نیست یا منقضی شده است.');
      } finally {
        inFlight = false;
        setBusy(verifyButton, false, '');
      }
    });

    resendButton?.addEventListener('click', async () => {
      if (inFlight || !isIranMobile(pendingMobile)) return;
      inFlight = true;
      setBusy(resendButton, true, 'در حال ارسال...');
      setError(verifyError);
      try {
        const payload = await api.auth.requestOtp(pendingMobile);
        challengeId = payload?.data?.challenge_id || null;
        if (!challengeId) throw new Error('پاسخ OTP معتبر نیست.');
        startResendTimer(payload?.data?.resend_after || 45);
      } catch (error) {
        setError(verifyError, error?.message || 'ارسال مجدد کد ناموفق بود.');
      } finally {
        inFlight = false;
        if (!resendButton.disabled) setBusy(resendButton, false, '');
      }
    });

    changeMobileButton?.addEventListener('click', showRequest);
    codeInput.addEventListener('input', () => {
      codeInput.value = normalizeDigits(codeInput.value).replace(/\D/g, '').slice(0, 6);
    });

    ensureAdministrativeSession();
  });
})();
