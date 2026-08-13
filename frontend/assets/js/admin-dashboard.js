// ===== Sharafi Admin Dashboard =====
(() => {
  const api = window.SharafiAdminAPI;
  const u = window.SharafiAdminUtils;
  if (!api || !u) return;

  function renderSales(rows) {
    const container = u.$('#sales7d');
    if (!container) return;
    u.clear(container);
    const table = u.element('table', { className: 'admin-table compact' });
    const head = u.element('thead');
    const headerRow = u.element('tr');
    ['روز', 'فروش موفق'].forEach((label) => headerRow.appendChild(u.element('th', { text: label })));
    head.appendChild(headerRow);
    const body = u.element('tbody');
    rows.forEach((row) => {
      const tr = u.element('tr');
      tr.append(
        u.element('td', { text: u.formatDate(`${row.date}T12:00:00+03:30`) }),
        u.element('td', { text: u.formatIrr(row.paid_sales_irr) }),
      );
      body.appendChild(tr);
    });
    table.append(head, body);
    container.appendChild(table);
  }

  function renderRecentOrders(rows) {
    const container = u.$('#dashboardRecentOrders');
    if (!container) return;
    u.clear(container);
    if (!rows.length) {
      container.appendChild(u.element('div', { className: 'empty-state', text: 'هنوز سفارشی ثبت نشده است.' }));
      return;
    }

    const list = u.element('div', { className: 'admin-summary-list' });
    rows.forEach((order) => {
      const item = u.element('a', { className: 'admin-summary-row', attrs: { href: `orders.html?q=${encodeURIComponent(order.order_number)}` } });
      const main = u.element('div');
      main.append(
        u.element('strong', { text: order.order_number }),
        u.element('span', { text: u.formatDate(order.created_at, true) }),
      );
      const side = u.element('div');
      side.append(u.statusPill(order.status), u.element('strong', { text: u.formatIrr(order.total_irr) }));
      item.append(main, side);
      list.appendChild(item);
    });
    container.appendChild(list);
  }

  function renderLowStock(rows) {
    const container = u.$('#dashboardLowStock');
    if (!container) return;
    u.clear(container);
    if (!rows.length) {
      container.appendChild(u.element('div', { className: 'empty-state', text: 'هشدار کمبود موجودی وجود ندارد.' }));
      return;
    }

    const list = u.element('div', { className: 'admin-summary-list' });
    rows.forEach((row) => {
      const item = u.element('a', { className: 'admin-summary-row', attrs: { href: `inventory.html?q=${encodeURIComponent(row.sku || '')}` } });
      const main = u.element('div');
      main.append(
        u.element('strong', { text: row.product_name || row.sku || 'تنوع محصول' }),
        u.element('span', { text: row.sku || '—' }),
      );
      const side = u.element('div');
      side.append(
        u.element('strong', { text: `قابل فروش: ${Number(row.available).toLocaleString('fa-IR')}` }),
        u.element('span', { text: `حد هشدار: ${Number(row.reorder_level).toLocaleString('fa-IR')}` }),
      );
      item.append(main, side);
      list.appendChild(item);
    });
    container.appendChild(list);
  }

  document.addEventListener('DOMContentLoaded', async () => {
    try {
      await window.SharafiAdminReady;
      const response = await api.dashboard();
      const data = response?.data;
      if (!data) throw new Error('پاسخ داشبورد ناقص است.');

      u.setKpi('today-sales', u.formatIrr(data.today.paid_sales_irr), `بر اساس روز کاری ${data.timezone}`);
      u.setKpi('today-orders', Number(data.today.new_orders).toLocaleString('fa-IR'), 'سفارش‌های ثبت‌شده امروز');
      u.setKpi('today-customers', Number(data.today.new_customers).toLocaleString('fa-IR'), 'حساب‌های ایجادشده امروز');
      u.setKpi('low-stock', Number(data.today.low_stock_variants).toLocaleString('fa-IR'), 'بر اساس موجودی قابل فروش و حد هشدار');
      renderSales(data.sales_7d || []);
      renderRecentOrders(data.recent_orders || []);
      renderLowStock(data.low_stock || []);
    } catch (error) {
      window.toastAdmin?.(error?.message || 'بارگذاری داشبورد ناموفق بود.', 'error');
    }
  });
})();
