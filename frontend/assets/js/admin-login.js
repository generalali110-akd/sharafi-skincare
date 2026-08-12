// ===== Sharafi Admin Login UI =====
(() => {
  document.addEventListener('DOMContentLoaded', () => {
    const form = document.querySelector('.js-admin-login-form');
    const error = document.querySelector('.js-admin-login-error');
    if (!form) return;

    form.addEventListener('submit', (event) => {
      event.preventDefault();
      if (!form.checkValidity()) {
        form.reportValidity();
        return;
      }

      if (error) {
        error.textContent = 'ورود مدیر پس از اتصال Backend، احراز هویت امن و بررسی Role فعال می‌شود.';
      }
    });
  });
})();
