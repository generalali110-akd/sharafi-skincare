// ===== Sharafi Admin Orders =====
(() => {
  const api = window.SharafiAdminAPI;
  const u = window.SharafiAdminUtils;
  if (!api || !u) return;

  const state = { q: '', status: '', page: 1 };
  let canWrite = false;
  let currentOrder = null;

  const NEXT_STATUS = Object.freeze({
    pending_payment: ['cancelled'],
    paid: ['processing'],
    processing: ['shipped'],
    shipped: ['delivered'],
  });

  function renderRows(items) {
    const tbody = u.$('#ordersTable tbody');
    if (!tbody) return;
    u.clear(tbody);

    items.forEach((order) => {
      const tr = u.element('tr');
      const customer = u.element('td');
      customer.append(
        u.element('strong', { text: order.customer?.name || 'بدون نام' }),
        u.element('div', { className: 'table-subtext', text: order.customer?.mobile || '—' }),
      );
      const payment = u.element('td');
      payment.appendChild(u.statusPill(order.payment?.status || 'pending'));
      const status = u.element('td');
      status.appendChild(u.statusPill(order.status));
      const actions = u.element('td');
      const view = u.element('button', { className: 'btn btn-outline btn-sm js-order-view', text: 'جزئیات', attrs: { type: 'button' } });
      view.dataset.orderNumber = order.order_number;
      actions.appendChild(view);

      tr.append(
        u.element('td', { text: order.order_number }),
        customer,
        u.element('td', { text: u.formatDate(order.created_at, true) }),
        u.element('td', { text: u.formatIrr(order.amounts?.total_irr) }),
        payment,
        status,
        actions,
      );
      tbody.appendChild(tr);
    });
  }

  function detailLine(label, value) {
    const row = u.element('div', { className: 'admin-detail-row' });
    row.append(u.element('span', { text: label }), u.element('strong', { text: value || '—' }));
    return row;
  }

  function renderOrderDetail(order) {
    currentOrder = order;
    const content = u.$('#orderDetailContent');
    if (!content) return;
    u.clear(content);

    const summary = u.element('div', { className: 'admin-detail-grid' });
    summary.append(
      detailLine('شماره سفارش', order.order_number),
      detailLine('وضعیت', u.statusLabel(order.status)),
      detailLine('مشتری', order.customer?.name || 'بدون نام'),
      detailLine('موبایل', order.customer?.mobile || '—'),
      detailLine('روش ارسال', order.shipping_method),
      detailLine('مبلغ نهایی', u.formatIrr(order.amounts?.total_irr)),
      detailLine('پرداخت', u.statusLabel(order.payment?.status || 'pending')),
      detailLine('ثبت سفارش', u.formatDate(order.created_at, true)),
    );
    content.appendChild(summary);

    if (order.address) {
      content.appendChild(u.element('h4', { text: 'آدرس ارسال' }));
      const address = u.element('div', { className: 'admin-detail-block' });
      address.append(
        u.element('strong', { text: order.address.recipient_name || 'گیرنده' }),
        u.element('div', { text: [order.address.province, order.address.city, order.address.address_line].filter(Boolean).join('، ') }),
        u.element('div', { text: `کدپستی: ${order.address.postal_code || '—'} | موبایل: ${order.address.mobile || '—'}` }),
      );
      content.appendChild(address);
    }

    content.appendChild(u.element('h4', { text: 'اقلام سفارش' }));
    const items = u.element('div', { className: 'admin-detail-list' });
    (order.items || []).forEach((item) => {
      items.appendChild(detailLine(
        `${item.product_name}${item.variant_title ? ` / ${item.variant_title}` : ''} × ${Number(item.quantity).toLocaleString('fa-IR')}`,
        u.formatIrr(item.line_total_irr),
      ));
    });
    content.appendChild(items);

    content.appendChild(u.element('h4', { text: 'Timeline' }));
    const timeline = u.element('div', { className: 'admin-timeline' });
    if (!(order.timeline || []).length) {
      timeline.appendChild(u.element('div', { className: 'table-subtext', text: 'رویدادی ثبت نشده است.' }));
    } else {
      (order.timeline || []).forEach((event) => {
        let label = 'رویداد';
        if (event.kind === 'order_status') label = `${u.statusLabel(event.from_status)} ← ${u.statusLabel(event.to_status)}`;
        if (event.kind === 'payment') label = `پرداخت: ${event.event_type || event.provider || 'رویداد'}`;
        if (event.kind === 'notification') label = `پیامک: ${event.status || 'pending'} / ${event.template || 'template'}`;
        const row = u.element('div', { className: 'admin-timeline-row' });
        row.append(
          u.element('strong', { text: label }),
          u.element('span', { text: u.formatDate(event.at, true) }),
        );
        if (event.reason) row.appendChild(u.element('div', { className: 'table-subtext', text: event.reason }));
        timeline.appendChild(row);
      });
    }
    content.appendChild(timeline);

    const statusForm = u.$('#orderStatusForm');
    const statusSelect = statusForm?.elements.namedItem('status');
    if (statusSelect) {
      u.clear(statusSelect);
      const targets = NEXT_STATUS[order.status] || [];
      targets.forEach((target) => statusSelect.appendChild(u.element('option', { text: u.statusLabel(target), attrs: { value: target } })));
      statusForm.hidden = !canWrite || targets.length === 0;
      statusForm.elements.namedItem('expected_status').value = order.status;
      statusForm.elements.namedItem('order_number').value = order.order_number;
    }
  }

  function openDetail() {
    const modal = u.$('#orderDetailModal');
    modal?.classList.add('show');
    modal?.setAttribute('aria-hidden', 'false');
  }

  function closeDetail() {
    const modal = u.$('#orderDetailModal');
    modal?.classList.remove('show');
    modal?.setAttribute('aria-hidden', 'true');
  }

  async function load() {
    const response = await api.orders.list({ q: state.q, status: state.status, page: state.page, per_page: 25 });
    const page = u.paginator(response);
    renderRows(page.items);
    u.setEmpty(u.$('.js-page-empty'), page.items.length === 0, 'سفارشی مطابق فیلترها پیدا نشد.');
    u.renderPagination(u.$('.js-pagination'), page, (nextPage) => {
      state.page = nextPage;
      u.updateUrl(state);
      load().catch(showError);
    });
    return page;
  }

  async function countStatus(status) {
    const payload = await api.orders.list({ status, per_page: 1 });
    return u.paginator(payload).total;
  }

  function showError(error) {
    window.toastAdmin?.(error?.message || 'بارگذاری سفارش‌ها ناموفق بود.', 'error');
  }

  document.addEventListener('DOMContentLoaded', async () => {
    try {
      const session = await window.SharafiAdminReady;
      canWrite = session.permissions?.includes('orders.write');

      const params = new URLSearchParams(window.location.search);
      state.q = params.get('q') || '';
      state.status = params.get('status') || '';
      state.page = Math.max(1, Number(params.get('page')) || 1);

      const search = u.$('.js-page-search');
      const status = u.$('#orderStatusFilter');
      if (search) search.value = state.q;
      if (status) status.value = state.status;

      const [pending, processing, shipped, cancelled, refundPending, refunded] = await Promise.all([
        countStatus('pending_payment'),
        countStatus('processing'),
        countStatus('shipped'),
        countStatus('cancelled'),
        countStatus('refund_pending'),
        countStatus('refunded'),
      ]);
      u.setKpi('orders-pending', pending.toLocaleString('fa-IR'), 'در انتظار پرداخت');
      u.setKpi('orders-processing', processing.toLocaleString('fa-IR'), 'آماده‌سازی سفارش');
      u.setKpi('orders-shipped', shipped.toLocaleString('fa-IR'), 'تحویل‌شده به حمل');
      u.setKpi('orders-closed', (cancelled + refundPending + refunded).toLocaleString('fa-IR'), 'لغو یا بازپرداخت');

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

      document.addEventListener('click', async (event) => {
        const button = event.target.closest('.js-order-view');
        if (!button) return;
        button.disabled = true;
        try {
          const response = await api.orders.show(button.dataset.orderNumber);
          renderOrderDetail(response?.data || {});
          openDetail();
        } catch (error) {
          showError(error);
        } finally {
          button.disabled = false;
        }
      });

      const statusForm = u.$('#orderStatusForm');
      statusForm?.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (!statusForm.reportValidity()) return;
        const submit = statusForm.querySelector('[type="submit"]');
        submit.disabled = true;
        try {
          const orderNumber = statusForm.elements.namedItem('order_number').value;
          const response = await api.orders.updateStatus(orderNumber, {
            expected_status: statusForm.elements.namedItem('expected_status').value,
            status: statusForm.elements.namedItem('status').value,
            reason: statusForm.elements.namedItem('reason').value.trim() || null,
          });
          renderOrderDetail(response?.data || currentOrder);
          window.toastAdmin?.('وضعیت سفارش ثبت شد.');
          await load();
        } catch (error) {
          showError(error);
          if (error?.status === 409 && currentOrder?.order_number) {
            const fresh = await api.orders.show(currentOrder.order_number).catch(() => null);
            if (fresh?.data) renderOrderDetail(fresh.data);
          }
        } finally {
          submit.disabled = false;
        }
      });

      u.$('#orderDetailModal .js-modal-close')?.addEventListener('click', closeDetail);
      await load();
    } catch (error) {
      showError(error);
    }
  });
})();
