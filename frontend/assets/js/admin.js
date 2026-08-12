// ===== Sharafi Admin UI Controller =====
(() => {
  const $ = (selector, root = document) => root.querySelector(selector);
  const $$ = (selector, root = document) => [...root.querySelectorAll(selector)];

  function toastAdmin(message) {
    const existing = $('.admin-toast');
    if (existing) existing.remove();
    const el = document.createElement('div');
    el.className = 'admin-toast';
    el.textContent = message;
    document.body.appendChild(el);
    window.setTimeout(() => el.remove(), 2200);
  }
  window.toastAdmin = toastAdmin;

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

  function initTableSearch() {
    $$('.js-table-search').forEach((input) => {
      input.addEventListener('input', () => {
        const table = $(input.dataset.target);
        if (!table) return;
        const query = input.value.trim().toLowerCase();
        $$('tbody tr', table).forEach((row) => {
          row.hidden = !row.textContent.toLowerCase().includes(query);
        });
      });
    });
  }

  function openModal(modal) {
    if (!modal) return;
    modal.classList.add('show');
    modal.setAttribute('aria-hidden', 'false');
    $('input,select,textarea,button', modal)?.focus();
  }

  function closeModal(modal) {
    if (!modal) return;
    modal.classList.remove('show');
    modal.setAttribute('aria-hidden', 'true');
  }

  function initModals() {
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
  }

  function initProductForm() {
    const modal = $('#productModal');
    const form = $('.js-product-form');
    const add = $('.js-add-product');
    if (!modal || !form) return;

    add?.addEventListener('click', () => {
      form.reset();
      $('.js-modal-title', modal).textContent = 'افزودن محصول';
      openModal(modal);
    });

    $$('.js-edit-product').forEach((button) => {
      button.addEventListener('click', () => {
        const row = button.closest('tr');
        $('.js-modal-title', modal).textContent = 'ویرایش محصول';
        const fields = {
          name: row?.dataset.name,
          category: row?.dataset.category,
          price: row?.dataset.price,
          stock: row?.dataset.stock,
        };
        Object.entries(fields).forEach(([name, value]) => {
          const field = form.elements.namedItem(name);
          if (field && value != null) field.value = value;
        });
        openModal(modal);
      });
    });

    form.addEventListener('submit', (event) => {
      event.preventDefault();
      if (!form.checkValidity()) {
        form.reportValidity();
        return;
      }
      closeModal(modal);
      toastAdmin('فرم معتبر است؛ ذخیره نهایی بعد از اتصال API انجام می‌شود.');
    });

    $$('.js-delete-product').forEach((button) => {
      button.addEventListener('click', () => {
        toastAdmin('حذف محصول باید پس از احراز مجوز مدیر و از طریق Backend انجام شود.');
      });
    });
  }

  function initBackendOnlyActions() {
    $$('[data-backend-action]').forEach((button) => {
      button.addEventListener('click', () => {
        toastAdmin(button.dataset.backendMessage || 'این عملیات پس از اتصال Backend فعال می‌شود.');
      });
    });
  }

  function initAdminSearch() {
    const form = $('.js-admin-global-search');
    if (!form) return;
    form.addEventListener('submit', (event) => {
      event.preventDefault();
      const query = $('input', form)?.value.trim();
      if (!query) return;
      toastAdmin(`جستجوی «${query}» پس از اتصال API سراسری فعال می‌شود.`);
    });
  }

  document.addEventListener('DOMContentLoaded', () => {
    initSidebar();
    initTableSearch();
    initModals();
    initProductForm();
    initBackendOnlyActions();
    initAdminSearch();
  });
})();
