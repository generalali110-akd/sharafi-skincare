// ===== Sharafi Admin Page Utilities =====
(() => {
  const $ = (selector, root = document) => root?.querySelector(selector) || null;
  const $$ = (selector, root = document) => root ? [...root.querySelectorAll(selector)] : [];

  const element = (tag, options = {}) => {
    const node = document.createElement(tag);
    if (options.className) node.className = options.className;
    if (options.text !== undefined && options.text !== null) node.textContent = String(options.text);
    if (options.attrs) {
      Object.entries(options.attrs).forEach(([key, value]) => {
        if (value !== undefined && value !== null) node.setAttribute(key, String(value));
      });
    }
    return node;
  };

  const clear = (node) => {
    if (node) node.replaceChildren();
  };

  const formatIrr = (irr) => window.SharafiAPI.formatIrr(Number(irr) || 0);

  const formatDate = (value, withTime = false) => {
    if (!value) return '—';
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return '—';
    return new Intl.DateTimeFormat('fa-IR', withTime
      ? { dateStyle: 'short', timeStyle: 'short', timeZone: 'Asia/Tehran' }
      : { dateStyle: 'medium', timeZone: 'Asia/Tehran' }).format(date);
  };

  const paginator = (payload) => {
    if (payload?.meta) {
      return {
        items: Array.isArray(payload.data) ? payload.data : [],
        page: Number(payload.meta.current_page) || 1,
        lastPage: Number(payload.meta.last_page) || 1,
        total: Number(payload.meta.total) || 0,
      };
    }

    return {
      items: Array.isArray(payload?.data) ? payload.data : [],
      page: Number(payload?.current_page) || 1,
      lastPage: Number(payload?.last_page) || 1,
      total: Number(payload?.total) || 0,
    };
  };

  const renderPagination = (container, data, onPage) => {
    if (!container) return;
    clear(container);
    if (data.lastPage <= 1) return;

    const prev = element('button', { className: 'btn btn-outline btn-sm', text: 'قبلی', attrs: { type: 'button' } });
    const label = element('span', { className: 'status-pill gray', text: `صفحه ${data.page.toLocaleString('fa-IR')} از ${data.lastPage.toLocaleString('fa-IR')}` });
    const next = element('button', { className: 'btn btn-outline btn-sm', text: 'بعدی', attrs: { type: 'button' } });
    prev.disabled = data.page <= 1;
    next.disabled = data.page >= data.lastPage;
    prev.addEventListener('click', () => onPage(data.page - 1));
    next.addEventListener('click', () => onPage(data.page + 1));
    container.append(prev, label, next);
  };

  const debounce = (fn, wait = 300) => {
    let timer = null;
    return (...args) => {
      window.clearTimeout(timer);
      timer = window.setTimeout(() => fn(...args), wait);
    };
  };

  const statusLabel = (status) => ({
    draft: 'پیش‌نویس',
    active: 'فعال',
    archived: 'آرشیو',
    pending_payment: 'در انتظار پرداخت',
    paid: 'پرداخت‌شده',
    processing: 'در پردازش',
    shipped: 'ارسال‌شده',
    delivered: 'تحویل‌شده',
    cancelled: 'لغوشده',
    expired: 'منقضی',
    refund_pending: 'در انتظار بازپرداخت',
    refunded: 'بازپرداخت‌شده',
    pending: 'در انتظار',
    failed: 'ناموفق',
    succeeded: 'موفق',
  }[status] || status || '—');

  const statusClass = (status) => {
    if (['active', 'paid', 'delivered', 'succeeded'].includes(status)) return 'green';
    if (['processing', 'pending_payment', 'pending', 'refund_pending'].includes(status)) return 'gold';
    if (['cancelled', 'expired', 'failed', 'refunded'].includes(status)) return 'gray';
    if (status === 'shipped') return 'blue';
    return 'gray';
  };

  const statusPill = (status) => element('span', {
    className: `status-pill ${statusClass(status)}`,
    text: statusLabel(status),
  });

  const setKpi = (key, value, note = null) => {
    const card = document.querySelector(`[data-kpi="${key}"]`);
    if (!card) return;
    const valueNode = card.querySelector('.kpi-value');
    const noteNode = card.querySelector('.kpi-note');
    if (valueNode) valueNode.textContent = String(value);
    if (note !== null && noteNode) noteNode.textContent = note;
  };

  const setEmpty = (emptyNode, visible, message = null) => {
    if (!emptyNode) return;
    emptyNode.hidden = !visible;
    if (message) {
      const strong = emptyNode.querySelector('strong');
      if (strong) strong.textContent = message;
    }
  };

  const updateUrl = (params) => {
    const next = new URLSearchParams();
    Object.entries(params).forEach(([key, value]) => {
      if (value !== undefined && value !== null && value !== '' && value !== 1) next.set(key, String(value));
    });
    const query = next.toString();
    window.history.replaceState(null, '', `${window.location.pathname}${query ? `?${query}` : ''}`);
  };

  window.SharafiAdminUtils = Object.freeze({
    $,
    $$,
    element,
    clear,
    formatIrr,
    formatDate,
    paginator,
    renderPagination,
    debounce,
    statusLabel,
    statusPill,
    setKpi,
    setEmpty,
    updateUrl,
  });
})();
