// ===== Sharafi Admin Customers =====
(() => {
  const api = window.SharafiAdminAPI;
  const u = window.SharafiAdminUtils;
  if (!api || !u) return;

  const state = { q: '', status: '', page: 1 };

  function renderRows(items) {
    const tbody = u.$('#usersTable tbody');
    if (!tbody) return;
    u.clear(tbody);

    items.forEach((customer) => {
      const tr = u.element('tr');
      const customerCell = u.element('td');
      customerCell.append(
        u.element('strong', { text: customer.name || 'بدون نام' }),
        u.element('div', { className: 'table-subtext', text: `شناسه ${customer.id}` }),
      );
      tr.append(
        customerCell,
        u.element('td', { text: customer.mobile || '—' }),
        u.element('td', { text: Number(customer.orders_count || 0).toLocaleString('fa-IR') }),
        u.element('td', { text: u.formatDate(customer.last_order_at, true) }),
      );
      const statusCell = u.element('td');
      statusCell.appendChild(u.statusPill(customer.status));
      tr.appendChild(statusCell);
      tbody.appendChild(tr);
    });
  }

  async function load() {
    const response = await api.customers.list({
      q: state.q,
      status: state.status,
      page: state.page,
      per_page: 25,
    });
    const page = u.paginator(response);
    renderRows(page.items);
    u.setEmpty(u.$('.js-page-empty'), page.items.length === 0, 'مشتری مطابق فیلترها پیدا نشد.');
    u.renderPagination(u.$('.js-pagination'), page, (nextPage) => {
      state.page = nextPage;
      u.updateUrl(state);
      load().catch(showError);
    });
    return page;
  }

  function showError(error) {
    window.toastAdmin?.(error?.message || 'بارگذاری مشتریان ناموفق بود.', 'error');
  }

  document.addEventListener('DOMContentLoaded', async () => {
    try {
      await window.SharafiAdminReady;
      const params = new URLSearchParams(window.location.search);
      state.q = params.get('q') || '';
      state.status = params.get('status') || '';
      state.page = Math.max(1, Number(params.get('page')) || 1);

      const search = u.$('.js-page-search');
      const status = u.$('#customerStatus');
      if (search) search.value = state.q;
      if (status) status.value = state.status;

      const [allCustomers, activeCustomers] = await Promise.all([
        api.customers.list({ per_page: 1 }),
        api.customers.list({ status: 'active', per_page: 1 }),
      ]);
      u.setKpi('customers-total', u.paginator(allCustomers).total.toLocaleString('fa-IR'), 'همه حساب‌های مشتری');
      u.setKpi('customers-active', u.paginator(activeCustomers).total.toLocaleString('fa-IR'), 'حساب‌های فعال');

      const reloadFirstPage = () => {
        state.page = 1;
        u.updateUrl(state);
        load().catch(showError);
      };

      search?.addEventListener('input', u.debounce(() => {
        state.q = search.value.trim();
        reloadFirstPage();
      }));
      status?.addEventListener('change', () => {
        state.status = status.value;
        reloadFirstPage();
      });

      await load();
    } catch (error) {
      showError(error);
    }
  });
})();
