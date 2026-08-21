// ===== Sharafi Admin UI Controller =====
(() => {
  const $ = (selector, root = document) => root.querySelector(selector);
  const $$ = (selector, root = document) => [...root.querySelectorAll(selector)];
  const api = window.SharafiAPI;
  const adminApi = window.SharafiAdminAPI;

  const PAGE_PERMISSION = Object.freeze({
    'dashboard.html': 'admin.dashboard.view',
    'orders.html': 'orders.read',
    'products.html': 'catalog.read',
    'inventory.html': 'inventory.read',
    'users.html': 'customers.read',
    'discounts.html': 'discounts.read',
  });

  const ROLE_LABELS = Object.freeze({
    'super-admin': 'مدیر کل',
    admin: 'مدیر',
    'catalog-manager': 'مدیر کاتالوگ',
    'inventory-manager': 'مدیر موجودی',
    'order-manager': 'مدیر سفارش‌ها',
    support: 'پشتیبانی',
  });

  let readyResolve;
  let readyReject;
  let activeModal = null;
  let modalRestoreTarget = null;
  window.SharafiAdminReady = new Promise((resolve, reject) => {
    readyResolve = resolve;
    readyReject = reject;
  });

  function toastAdmin(message, kind = 'info') {
    const existing = $('.admin-toast');
    if (existing) existing.remove();
    const el = document.createElement('div');
    el.className = `admin-toast ${kind === 'error' ? 'error' : ''}`;
    el.textContent = message;
    document.body.appendChild(el);
    window.setTimeout(() => el.remove(), kind === 'error' ? 4200 : 2600);
  }
  window.toastAdmin = toastAdmin;

  function currentPage() {
    return window.location.pathname.split('/').pop() || 'dashboard.html';
  }

  function hasPermission(session, permission) {
    return Array.isArray(session?.permissions) && session.permissions.includes(permission);
  }

  function firstAllowedPage(session) {
    return Object.entries(PAGE_PERMISSION)
      .find(([, permission]) => hasPermission(session, permission))?.[0] || null;
  }

  function redirectToLogin() {
    const returnTarget = `${currentPage()}${window.location.search}`;
    window.location.replace(`login.html?return=${encodeURIComponent(returnTarget)}`);
  }

  function applyPermissions(session) {
    $$('.admin-nav a[href]').forEach((link) => {
      const target = link.getAttribute('href')?.split('?')[0];
      const permission = PAGE_PERMISSION[target];
      if (permission && !hasPermission(session, permission)) link.hidden = true;
    });

    $$('[data-permission]').forEach((element) => {
      if (!hasPermission(session, element.dataset.permission)) element.hidden = true;
    });

    $$('[data-backend-action]').forEach((element) => {
      element.hidden = true;
    });
  }

  function renderProfile(session) {
    const profile = $('.admin-profile');
    if (!profile) return;
    const name = $('strong', profile);
    const role = $('span:last-child', profile.querySelector('div'));
    if (name) name.textContent = session.user?.name || session.user?.mobile || 'مدیر فروشگاه';
    if (role) {
      role.textContent = session.roles
        ?.map((slug) => ROLE_LABELS[slug] || slug)
        .join('، ') || 'دسترسی مدیریتی';
    }
  }

  function initSidebar() {
    const sidebar = $('.admin-sidebar');
    const toggle = $('.js-sidebar-toggle');
    if (!sidebar || !toggle) return;

    let backdrop = $('.admin-mobile-backdrop');
    if (!backdrop) {
      backdrop = document.createElement('div');
      backdrop.className = 'admin-mobile-backdrop';
      document.body.appendChild(backdrop);
    }

    const setOpen = (open) => {
      sidebar.classList.toggle('show', open);
      backdrop.classList.toggle('show', open);
      toggle.setAttribute('aria-expanded', String(open));
    };

    toggle.addEventListener('click', () => setOpen(!sidebar.classList.contains('show')));
    backdrop.addEventListener('click', () => setOpen(false));
    document.addEventListener('keydown', (event) => {
      if (event.key === 'Escape') setOpen(false);
    });
  }

  function openModal(modal) {
    if (!modal) return;
    modal.classList.add('show');
    modal.setAttribute('aria-hidden', 'false');
  }

  function closeModal(modal) {
    if (!modal) return;
    modal.classList.remove('show');
    modal.setAttribute('aria-hidden', 'true');
  }

  const modalFocusable = (modal) => $$([
    'a[href]',
    'button:not([disabled])',
    'input:not([disabled]):not([type="hidden"])',
    'select:not([disabled])',
    'textarea:not([disabled])',
    '[tabindex]:not([tabindex="-1"])',
  ].join(','), modal).filter((element) => !element.hidden && element.getAttribute('aria-hidden') !== 'true');

  function syncActiveModal() {
    const shown = $$('.modal-overlay.show').at(-1) || null;
    if (shown === activeModal) return;

    if (shown) {
      if (!activeModal) modalRestoreTarget = document.activeElement;
      activeModal = shown;
      window.setTimeout(() => {
        if (activeModal !== shown || shown.contains(document.activeElement)) return;
        (modalFocusable(shown)[0] || shown).focus();
      }, 0);
      return;
    }

    activeModal = null;
    const target = modalRestoreTarget;
    modalRestoreTarget = null;
    if (target instanceof HTMLElement && document.contains(target)) target.focus();
  }

  function initModals() {
    $$('.modal-overlay').forEach((modal) => {
      modal.setAttribute('role', 'dialog');
      modal.setAttribute('aria-modal', 'true');
      modal.tabIndex = -1;
      if (!modal.hasAttribute('aria-label') && !modal.hasAttribute('aria-labelledby')) {
        modal.setAttribute('aria-label', $('h3', modal)?.textContent?.trim() || 'پنجره مدیریت');
      }
      $$('.icon-action.js-modal-close', modal).forEach((button) => {
        if (!button.hasAttribute('aria-label')) button.setAttribute('aria-label', 'بستن پنجره');
      });
    });

    $$('.js-open-modal').forEach((button) => {
      button.addEventListener('click', () => openModal(document.getElementById(button.dataset.modal)));
    });
    $$('.js-modal-close').forEach((button) => {
      button.addEventListener('click', () => closeModal(button.closest('.modal-overlay')));
    });
    $$('.modal-overlay').forEach((modal) => {
      modal.addEventListener('click', (event) => {
        if (event.target === modal) closeModal(modal);
      });
    });

    const observer = new MutationObserver(syncActiveModal);
    $$('.modal-overlay').forEach((modal) => observer.observe(modal, { attributes: true, attributeFilter: ['class', 'aria-hidden'] }));

    document.addEventListener('keydown', (event) => {
      if (!activeModal) return;
      if (event.key === 'Escape') {
        event.preventDefault();
        closeModal(activeModal);
        return;
      }
      if (event.key !== 'Tab') return;

      const focusable = modalFocusable(activeModal);
      if (!focusable.length) {
        event.preventDefault();
        activeModal.focus();
        return;
      }

      const first = focusable[0];
      const last = focusable[focusable.length - 1];
      if (event.shiftKey && document.activeElement === first) {
        event.preventDefault();
        last.focus();
      } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault();
        first.focus();
      }
    });
  }

  function initGlobalSearch() {
    const form = $('.js-admin-global-search');
    if (!form) return;
    const input = $('input', form);
    const params = new URLSearchParams(window.location.search);
    if (input && params.get('q')) input.value = params.get('q');

    form.addEventListener('submit', (event) => {
      event.preventDefault();
      const query = input?.value.trim() || '';
      const next = new URLSearchParams(window.location.search);
      if (query) next.set('q', query);
      else next.delete('q');
      next.delete('page');
      const suffix = next.toString();
      window.location.assign(`${currentPage()}${suffix ? `?${suffix}` : ''}`);
    });
  }

  function initLogout() {
    $$('[data-admin-logout]').forEach((link) => {
      link.addEventListener('click', async (event) => {
        event.preventDefault();
        link.setAttribute('aria-disabled', 'true');
        try {
          await api.auth.logout();
          adminApi.clearSession();
          window.location.replace('login.html');
        } catch (error) {
          link.removeAttribute('aria-disabled');
          toastAdmin(error?.message || 'خروج امن انجام نشد. دوباره تلاش کنید.', 'error');
        }
      });
    });
  }

  async function authorizePage() {
    if (!api || !adminApi) throw new Error('Admin API dependencies are missing.');

    try {
      const session = await adminApi.session();
      const required = PAGE_PERMISSION[currentPage()];
      if (required && !hasPermission(session, required)) {
        const target = firstAllowedPage(session);
        if (target && target !== currentPage()) {
          window.location.replace(target);
          return null;
        }
        throw new api.ApiError(403, { message: 'مجوز لازم برای این صفحه وجود ندارد.' });
      }

      applyPermissions(session);
      renderProfile(session);
      document.documentElement.dataset.adminAuthorized = 'true';
      return session;
    } catch (error) {
      if (error instanceof api.ApiError && [401, 403].includes(error.status)) {
        redirectToLogin();
        return null;
      }
      throw error;
    }
  }

  document.addEventListener('DOMContentLoaded', async () => {
    initSidebar();
    initModals();
    initGlobalSearch();
    initLogout();

    try {
      const session = await authorizePage();
      if (session) readyResolve(session);
    } catch (error) {
      toastAdmin(error?.message || 'بارگذاری پنل مدیریت ناموفق بود.', 'error');
      readyReject(error);
    }
  });
})();
