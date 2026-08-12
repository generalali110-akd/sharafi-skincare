// ===== Sharafi authentication UX =====
(() => {
  const views = document.querySelectorAll('.auth-view');
  const tabs = document.querySelectorAll('.auth-tab-v2');
  const echoTargets = document.querySelectorAll('.js-auth-mobile-echo');
  let timerId = null;
  let secondsLeft = 0;

  const normalizeDigits = (value) => value
    .replace(/[۰-۹]/g, (d) => String('۰۱۲۳۴۵۶۷۸۹'.indexOf(d)))
    .replace(/[٠-٩]/g, (d) => String('٠١٢٣٤٥٦٧٨٩'.indexOf(d)));

  const isValidIranMobile = (value) => /^09\d{9}$/.test(normalizeDigits(value).replace(/\s|-/g, ''));

  const showView = (name) => {
    views.forEach((view) => {
      const active = view.dataset.authView === name;
      view.classList.toggle('is-active', active);
      view.setAttribute('aria-hidden', String(!active));
    });
    const firstField = document.querySelector(`[data-auth-view="${name}"] input:not([type="checkbox"])`);
    window.setTimeout(() => firstField?.focus(), 0);
  };

  tabs.forEach((tab) => {
    tab.addEventListener('click', () => {
      tabs.forEach((item) => {
        const active = item === tab;
        item.classList.toggle('is-active', active);
        item.setAttribute('aria-selected', String(active));
        item.tabIndex = active ? 0 : -1;
      });
      showView(tab.dataset.authTarget);
    });
  });

  const setFieldError = (input, message = '') => {
    if (!input) return;
    input.setAttribute('aria-invalid', String(Boolean(message)));
    const error = input.closest('.auth-field')?.querySelector('.auth-error');
    if (error) error.textContent = message;
  };

  const startTimer = () => {
    clearInterval(timerId);
    secondsLeft = 45;
    const paint = () => {
      document.querySelectorAll('.js-auth-timer').forEach((el) => {
        el.textContent = `۰۰:${String(secondsLeft).padStart(2, '0').replace(/\d/g, (d) => '۰۱۲۳۴۵۶۷۸۹'[d])}`;
      });
      document.querySelectorAll('.js-auth-resend').forEach((button) => {
        button.disabled = secondsLeft > 0;
      });
    };
    paint();
    timerId = setInterval(() => {
      secondsLeft -= 1;
      paint();
      if (secondsLeft <= 0) clearInterval(timerId);
    }, 1000);
  };

  document.querySelectorAll('.js-auth-request-otp').forEach((button) => {
    button.addEventListener('click', () => {
      const view = button.closest('.auth-view');
      const mobile = view?.querySelector('input[type="tel"]');
      if (!mobile) return;

      const value = normalizeDigits(mobile.value).replace(/\s|-/g, '');
      if (!isValidIranMobile(value)) {
        setFieldError(mobile, 'شماره موبایل باید با ۰۹ شروع شود و ۱۱ رقم باشد.');
        mobile.focus();
        return;
      }
      setFieldError(mobile);

      if (view.dataset.authView === 'register') {
        const name = view.querySelector('input[name="name"]');
        const terms = view.querySelector('input[name="terms"]');
        if (!name?.value.trim()) {
          setFieldError(name, 'نام و نام خانوادگی را وارد کنید.');
          name?.focus();
          return;
        }
        setFieldError(name);
        if (!terms?.checked) {
          toast('برای ادامه، قوانین و حریم خصوصی را بپذیرید.');
          return;
        }
      }

      echoTargets.forEach((target) => target.textContent = mobile.value.trim());
      showView('otp');
      startTimer();
      toast('رابط کاربری OTP آماده است؛ ارسال واقعی کد پس از اتصال Backend فعال می‌شود.');
    });
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
    button.addEventListener('click', () => {
      const row = document.querySelector('.auth-view.is-active .otp-row-v2');
      const code = [...(row?.querySelectorAll('input') || [])].map((input) => input.value).join('');
      if (!/^\d{6}$/.test(code)) {
        toast('کد تأیید ۶ رقمی را کامل وارد کنید.');
        return;
      }
      toast('اعتبارسنجی OTP باید توسط Backend انجام شود؛ ورود ساختگی غیرفعال است.');
    });
  });

  document.querySelectorAll('.js-auth-resend').forEach((button) => {
    button.addEventListener('click', () => {
      startTimer();
      toast('ارسال مجدد واقعی پس از اتصال سرویس OTP فعال می‌شود.');
    });
  });

  document.querySelectorAll('.js-auth-back').forEach((button) => {
    button.addEventListener('click', () => {
      const activeTab = document.querySelector('.auth-tab-v2.is-active');
      showView(activeTab?.dataset.authTarget || 'login');
    });
  });

  const initialTab = document.querySelector('.auth-tab-v2.is-active') || tabs[0];
  tabs.forEach((tab) => {
    const active = tab === initialTab;
    tab.setAttribute('aria-selected', String(active));
    tab.tabIndex = active ? 0 : -1;
  });
  showView(initialTab?.dataset.authTarget || 'login');
})();
