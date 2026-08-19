// ===== Sharafi authentication UX =====
(() => {
  const api = window.SharafiAPI;
  const views = document.querySelectorAll('.auth-view');
  const tabs = document.querySelectorAll('.auth-tab-v2');
  const echoTargets = document.querySelectorAll('.js-auth-mobile-echo');
  let timerId = null;
  let secondsLeft = 0;
  let challengeId = null;
  let pendingMobile = '';
  let pendingName = '';
  let sourceView = 'login';
  let requestInFlight = false;

  const params = new URLSearchParams(window.location.search);
  const returnTarget = api?.safeReturnTarget(params.get('return'), 'account.html') || 'account.html';
  const returningToCheckout = returnTarget.endsWith('/checkout.html') || returnTarget === 'checkout.html';

  const guestCartCount = () => {
    try {
      return (window.SharafiCart?.getGuestCart?.() || [])
        .reduce((sum, item) => sum + (Number(item.qty) || 0), 0);
    } catch {
      return 0;
    }
  };

  const paintReturnNotice = () => {
    const notice = document.querySelector('.js-auth-return-notice');
    const copy = document.querySelector('.js-auth-return-copy');
    if (!notice || !copy || !returningToCheckout) return;
    const count = guestCartCount();
    copy.textContent = count > 0
      ? `پس از ورود، ${count.toLocaleString('fa-IR')} قلم از سبد شما حفظ می‌شود و به تکمیل خرید برمی‌گردید.`
      : 'پس از ورود، مستقیم به تکمیل خرید برمی‌گردید.';
    notice.hidden = false;
  };

  const normalizeDigits = (value) => String(value || '')
    .replace(/[۰-۹]/g, (d) => String('۰۱۲۳۴۵۶۷۸۹'.indexOf(d)))
    .replace(/[٠-٩]/g, (d) => String('٠١٢٣٤٥٦٧٨٩'.indexOf(d)));

  const normalizeMobile = (value) => normalizeDigits(value).replace(/\s|-/g, '');
  const isValidIranMobile = (value) => /^09\d{9}$/.test(normalizeMobile(value));

  const showView = (name) => {
    views.forEach((view) => {
      const active = view.dataset.authView === name;
      view.classList.toggle('is-active', active);
      view.setAttribute('aria-hidden', String(!active));
    });
    const firstField = document.querySelector(`[data-auth-view="${name}"] input:not([type="checkbox"])`);
    window.setTimeout(() => firstField?.focus(), 0);
  };

  const setFieldError = (input, message = '') => {
    if (!input) return;
    input.setAttribute('aria-invalid', String(Boolean(message)));
    const error = input.closest('.auth-field')?.querySelector('.auth-error');
    if (error) error.textContent = message;
  };

  const setButtonBusy = (button, busy, busyText = 'در حال ارسال...') => {
    if (!button) return;
    if (!button.dataset.originalText) button.dataset.originalText = button.textContent;
    button.disabled = busy;
    button.textContent = busy ? busyText : button.dataset.originalText;
  };

  const startTimer = (seconds = 45) => {
    clearInterval(timerId);
    secondsLeft = Math.max(0, Number.parseInt(seconds, 10) || 45);
    const paint = () => {
      document.querySelectorAll('.js-auth-timer').forEach((el) => {
        const secondsText = String(secondsLeft).padStart(2, '0').replace(/\d/g, (d) => '۰۱۲۳۴۵۶۷۸۹'[d]);
        el.textContent = `۰۰:${secondsText}`;
      });
      document.querySelectorAll('.js-auth-resend').forEach((button) => {
        button.disabled = secondsLeft > 0 || requestInFlight;
      });
    };
    paint();
    timerId = setInterval(() => {
      secondsLeft = Math.max(0, secondsLeft - 1);
      paint();
      if (secondsLeft <= 0) clearInterval(timerId);
    }, 1000);
  };

  const requestOtp = async ({ resend = false, trigger = null } = {}) => {
    if (!api || requestInFlight) return;

    let mobileInput = null;
    let nameInput = null;
    if (!resend) {
      const view = trigger?.closest('.auth-view');
      if (!view) return;
      sourceView = view.dataset.authView || 'login';
      mobileInput = view.querySelector('input[type="tel"]');
      nameInput = view.querySelector('input[name="name"]');
      pendingMobile = normalizeMobile(mobileInput?.value);
      pendingName = nameInput?.value.trim() || '';

      if (!isValidIranMobile(pendingMobile)) {
        setFieldError(mobileInput, 'شماره موبایل باید با ۰۹ شروع شود و ۱۱ رقم باشد.');
        mobileInput?.focus();
        return;
      }
      setFieldError(mobileInput);

      if (sourceView === 'register') {
        const terms = view.querySelector('input[name="terms"]');
        if (!pendingName) {
          setFieldError(nameInput, 'نام و نام خانوادگی را وارد کنید.');
          nameInput?.focus();
          return;
        }
        setFieldError(nameInput);
        if (!terms?.checked) {
          toast('برای ادامه، قوانین و حریم خصوصی را بپذیرید.');
          return;
        }
      }
    }

    if (!isValidIranMobile(pendingMobile)) {
      showView(sourceView);
      return;
    }

    requestInFlight = true;
    setButtonBusy(trigger, true, resend ? 'در حال ارسال مجدد...' : 'در حال ارسال...');
    document.querySelectorAll('.js-auth-resend').forEach((button) => { button.disabled = true; });

    try {
      const payload = await api.auth.requestOtp(pendingMobile, pendingName || null);
      challengeId = payload?.data?.challenge_id || null;
      if (!challengeId) throw new Error('invalid_challenge');

      echoTargets.forEach((target) => {
        target.textContent = payload?.data?.mobile || pendingMobile;
      });
      document.querySelectorAll('.otp-row-v2 input').forEach((input) => { input.value = ''; });
      showView('otp');
      startTimer(payload?.data?.resend_after || 45);
      toast(resend ? 'کد جدید ارسال شد.' : 'کد تأیید ارسال شد.');
    } catch (error) {
      const message = error?.message || 'ارسال کد تأیید ناموفق بود.';
      toast(message, 3200);
      if (!resend && mobileInput && error?.payload?.errors?.mobile) {
        setFieldError(mobileInput, String(error.payload.errors.mobile[0] || message));
        showView(sourceView);
      }
    } finally {
      requestInFlight = false;
      setButtonBusy(trigger, false);
      if (secondsLeft <= 0) {
        document.querySelectorAll('.js-auth-resend').forEach((button) => { button.disabled = false; });
      }
    }
  };

  tabs.forEach((tab) => {
    tab.addEventListener('click', () => {
      tabs.forEach((item) => {
        const active = item === tab;
        item.classList.toggle('is-active', active);
        item.setAttribute('aria-selected', String(active));
        item.tabIndex = active ? 0 : -1;
      });
      sourceView = tab.dataset.authTarget || 'login';
      showView(sourceView);
    });
  });

  document.querySelectorAll('.js-auth-request-otp').forEach((button) => {
    button.addEventListener('click', () => requestOtp({ trigger: button }));
  });

  document.querySelectorAll('.otp-row-v2').forEach((row) => {
    const inputs = [...row.querySelectorAll('input')];
    inputs.forEach((input, index) => {
      input.addEventListener('input', () => {
        input.value = normalizeDigits(input.value).replace(/\D/g, '').slice(0, 1);
        if (input.value && index < inputs.length - 1) inputs[index + 1].focus();
      });
      input.addEventListener('keydown', (event) => {
        if (event.key === 'Backspace' && !input.value && index > 0) inputs[index - 1].focus();
        if (event.key === 'ArrowLeft' && index < inputs.length - 1) inputs[index + 1].focus();
        if (event.key === 'ArrowRight' && index > 0) inputs[index - 1].focus();
      });
      input.addEventListener('paste', (event) => {
        const pasted = normalizeDigits(event.clipboardData?.getData('text') || '').replace(/\D/g, '').slice(0, inputs.length);
        if (!pasted) return;
        event.preventDefault();
        pasted.split('').forEach((digit, i) => { if (inputs[i]) inputs[i].value = digit; });
        inputs[Math.min(pasted.length, inputs.length) - 1]?.focus();
      });
    });
  });

  document.querySelectorAll('.js-auth-verify').forEach((button) => {
    button.addEventListener('click', async () => {
      if (!api || requestInFlight) return;
      const row = document.querySelector('.auth-view.is-active .otp-row-v2');
      const code = [...(row?.querySelectorAll('input') || [])].map((input) => input.value).join('');
      if (!/^\d{6}$/.test(code)) {
        toast('کد تأیید ۶ رقمی را کامل وارد کنید.');
        return;
      }
      if (!challengeId) {
        toast('درخواست کد منقضی شده است. دوباره کد دریافت کنید.');
        showView(sourceView);
        return;
      }

      requestInFlight = true;
      setButtonBusy(button, true, 'در حال تأیید...');
      try {
        await api.auth.verifyOtp(challengeId, code);
        api.clearSessionCache();
        setButtonBusy(button, true, guestCartCount() > 0 ? 'در حال انتقال سبد...' : 'در حال ورود...');
        if (window.SharafiCart?.syncGuestCart) {
          const result = await window.SharafiCart.syncGuestCart();
          if (result.failed > 0) toast('ورود انجام شد؛ بعضی اقلام سبد موقت قابل انتقال نبودند.', 3200);
          else if (result.synced > 0) toast('سبد خرید شما حفظ شد.', 2200);
        }
        document.dispatchEvent(new CustomEvent('sharafi:authenticated'));
        window.location.assign(returnTarget);
      } catch (error) {
        toast(error?.message || 'کد تأیید معتبر نیست یا منقضی شده است.', 3200);
      } finally {
        requestInFlight = false;
        setButtonBusy(button, false);
      }
    });
  });

  document.querySelectorAll('.js-auth-resend').forEach((button) => {
    button.addEventListener('click', () => requestOtp({ resend: true, trigger: button }));
  });

  document.querySelectorAll('.js-auth-back').forEach((button) => {
    button.addEventListener('click', () => {
      challengeId = null;
      clearInterval(timerId);
      showView(sourceView || 'login');
    });
  });

  const initialTab = document.querySelector('.auth-tab-v2.is-active') || tabs[0];
  tabs.forEach((tab) => {
    const active = tab === initialTab;
    tab.setAttribute('aria-selected', String(active));
    tab.tabIndex = active ? 0 : -1;
  });
  sourceView = initialTab?.dataset.authTarget || 'login';
  showView(sourceView);
  paintReturnNotice();
})();
