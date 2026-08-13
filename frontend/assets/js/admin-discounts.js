// ===== Sharafi Admin Discounts =====
(() => {
  const api = window.SharafiAdminAPI;
  const u = window.SharafiAdminUtils;
  if (!api || !u) return;

  const state = { search: '', is_active: '', page: 1 };
  let canWrite = false;
  const rules = new Map();

  const tomanToIrr = (value) => Math.round(Number(value || 0) * 10);
  const irrToToman = (value) => Math.round(Number(value || 0) / 10);

  function tehranInput(value) {
    if (!value) return '';
    const parts = new Intl.DateTimeFormat('en-CA', {
      timeZone: 'Asia/Tehran', year: 'numeric', month: '2-digit', day: '2-digit',
      hour: '2-digit', minute: '2-digit', hourCycle: 'h23',
    }).formatToParts(new Date(value));
    const part = (type) => parts.find((item) => item.type === type)?.value || '';
    return `${part('year')}-${part('month')}-${part('day')}T${part('hour')}:${part('minute')}`;
  }

  const tehranIso = (value) => value ? `${value}:00+03:30` : null;

  function computedStatus(rule) {
    const now = Date.now();
    if (!rule.is_active) return { label: 'غیرفعال', className: 'gray' };
    if (rule.starts_at && new Date(rule.starts_at).getTime() > now) return { label: 'زمان‌بندی‌شده', className: 'blue' };
    if (rule.ends_at && new Date(rule.ends_at).getTime() <= now) return { label: 'منقضی', className: 'gray' };
    return { label: 'فعال', className: 'green' };
  }

  function valueLabel(rule) {
    if (rule.kind === 'percentage') return `${(Number(rule.percentage_bps || 0) / 100).toLocaleString('fa-IR')}٪`;
    return u.formatIrr(rule.amount_irr);
  }

  function renderRows(items) {
    const tbody = u.$('#discountTable tbody');
    if (!tbody) return;
    u.clear(tbody);
    rules.clear();

    items.forEach((rule) => {
      rules.set(String(rule.id), rule);
      const tr = u.element('tr');
      const status = computedStatus(rule);
      const statusCell = u.element('td');
      statusCell.appendChild(u.element('span', { className: `status-pill ${status.className}`, text: status.label }));
      const actions = u.element('td');
      if (canWrite) {
        const edit = u.element('button', { className: 'btn btn-outline btn-sm js-edit-discount', text: 'ویرایش', attrs: { type: 'button' } });
        edit.dataset.id = rule.id;
        actions.appendChild(edit);
      } else {
        actions.textContent = 'فقط مشاهده';
      }

      const period = [u.formatDate(rule.starts_at, true), u.formatDate(rule.ends_at, true)].join(' تا ');
      const usage = rule.usage_limit_total ? `سقف ${Number(rule.usage_limit_total).toLocaleString('fa-IR')}` : 'بدون سقف کل';
      tr.append(
        u.element('td', { text: rule.name }),
        u.element('td', { text: rule.code }),
        u.element('td', { text: rule.kind === 'percentage' ? 'درصدی' : 'مبلغ ثابت' }),
        u.element('td', { text: valueLabel(rule) }),
        u.element('td', { text: period }),
        u.element('td', { text: usage }),
        statusCell,
        actions,
      );
      tbody.appendChild(tr);
    });
  }

  function openModal() {
    const modal = u.$('#discountModal');
    modal?.classList.add('show');
    modal?.setAttribute('aria-hidden', 'false');
  }

  function closeModal() {
    const modal = u.$('#discountModal');
    modal?.classList.remove('show');
    modal?.setAttribute('aria-hidden', 'true');
  }

  function syncKind(form) {
    const kind = form.elements.namedItem('kind').value;
    const unit = u.$('.js-discount-value-unit', form);
    const value = form.elements.namedItem('value_ui');
    if (unit) unit.textContent = kind === 'percentage' ? 'درصد (مثلاً 10)' : 'مبلغ (تومان)';
    if (value) {
      value.step = kind === 'percentage' ? '0.01' : '1';
      value.max = kind === 'percentage' ? '100' : '';
      value.min = kind === 'percentage' ? '0.01' : '1';
    }
  }

  function fillForm(rule = null) {
    const form = u.$('#discountForm');
    if (!form) return;
    form.reset();
    form.elements.namedItem('id').value = rule?.id || '';
    form.elements.namedItem('name').value = rule?.name || '';
    form.elements.namedItem('code').value = rule?.code || '';
    form.elements.namedItem('kind').value = rule?.kind || 'percentage';
    form.elements.namedItem('value_ui').value = rule
      ? (rule.kind === 'percentage' ? Number(rule.percentage_bps || 0) / 100 : irrToToman(rule.amount_irr))
      : '';
    form.elements.namedItem('min_subtotal_toman').value = rule ? irrToToman(rule.min_subtotal_irr) : 0;
    form.elements.namedItem('max_discount_toman').value = rule?.max_discount_irr ? irrToToman(rule.max_discount_irr) : '';
    form.elements.namedItem('usage_limit_total').value = rule?.usage_limit_total || '';
    form.elements.namedItem('usage_limit_per_user').value = rule?.usage_limit_per_user || '';
    form.elements.namedItem('starts_at').value = tehranInput(rule?.starts_at);
    form.elements.namedItem('ends_at').value = tehranInput(rule?.ends_at);
    form.elements.namedItem('is_active').checked = rule ? Boolean(rule.is_active) : true;
    const title = u.$('.js-discount-modal-title');
    if (title) title.textContent = rule ? 'ویرایش تخفیف' : 'ایجاد تخفیف';
    syncKind(form);
    openModal();
  }

  function formPayload(form) {
    const kind = form.elements.namedItem('kind').value;
    const rawValue = Number(form.elements.namedItem('value_ui').value);
    const payload = {
      code: form.elements.namedItem('code').value.trim().toUpperCase(),
      name: form.elements.namedItem('name').value.trim(),
      kind,
      min_subtotal_irr: tomanToIrr(form.elements.namedItem('min_subtotal_toman').value),
      max_discount_irr: form.elements.namedItem('max_discount_toman').value ? tomanToIrr(form.elements.namedItem('max_discount_toman').value) : null,
      starts_at: tehranIso(form.elements.namedItem('starts_at').value),
      ends_at: tehranIso(form.elements.namedItem('ends_at').value),
      usage_limit_total: form.elements.namedItem('usage_limit_total').value ? Number(form.elements.namedItem('usage_limit_total').value) : null,
      usage_limit_per_user: form.elements.namedItem('usage_limit_per_user').value ? Number(form.elements.namedItem('usage_limit_per_user').value) : null,
      is_active: form.elements.namedItem('is_active').checked,
    };
    if (kind === 'percentage') payload.percentage_bps = Math.round(rawValue * 100);
    else payload.amount_irr = tomanToIrr(rawValue);
    return payload;
  }

  async function load() {
    const response = await api.discounts.list({
      search: state.search,
      is_active: state.is_active,
      page: state.page,
      per_page: 25,
    });
    const page = u.paginator(response);
    renderRows(page.items);
    u.setEmpty(u.$('.js-page-empty'), page.items.length === 0, 'تخفیفی مطابق فیلترها پیدا نشد.');
    u.renderPagination(u.$('.js-pagination'), page, (nextPage) => {
      state.page = nextPage;
      u.updateUrl(state);
      load().catch(showError);
    });
    return page;
  }

  function showError(error) {
    window.toastAdmin?.(error?.message || 'بارگذاری تخفیف‌ها ناموفق بود.', 'error');
  }

  document.addEventListener('DOMContentLoaded', async () => {
    try {
      const session = await window.SharafiAdminReady;
      canWrite = session.permissions?.includes('discounts.write');

      const params = new URLSearchParams(window.location.search);
      state.search = params.get('search') || params.get('q') || '';
      state.is_active = params.get('is_active') || '';
      state.page = Math.max(1, Number(params.get('page')) || 1);

      const search = u.$('.js-page-search');
      const status = u.$('#discountStatus');
      if (search) search.value = state.search;
      if (status) status.value = state.is_active;

      const [allRules, activeRules] = await Promise.all([
        api.discounts.list({ per_page: 1 }),
        api.discounts.list({ is_active: 1, per_page: 1 }),
      ]);
      u.setKpi('discount-total', u.paginator(allRules).total.toLocaleString('fa-IR'), 'همه قوانین تخفیف');
      u.setKpi('discount-active', u.paginator(activeRules).total.toLocaleString('fa-IR'), 'قوانین فعال در سیستم');

      const reloadFirstPage = () => {
        state.page = 1;
        u.updateUrl({ search: state.search, is_active: state.is_active, page: state.page });
        load().catch(showError);
      };
      search?.addEventListener('input', u.debounce(() => {
        state.search = search.value.trim();
        reloadFirstPage();
      }));
      status?.addEventListener('change', () => {
        state.is_active = status.value;
        reloadFirstPage();
      });

      u.$('.js-add-discount')?.addEventListener('click', () => fillForm());
      document.addEventListener('click', (event) => {
        const button = event.target.closest('.js-edit-discount');
        if (button) fillForm(rules.get(button.dataset.id));
      });

      const form = u.$('#discountForm');
      form?.elements.namedItem('kind')?.addEventListener('change', () => syncKind(form));
      form?.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (!form.reportValidity()) return;
        const submit = form.querySelector('[type="submit"]');
        submit.disabled = true;
        try {
          const id = form.elements.namedItem('id').value;
          const payload = formPayload(form);
          if (id) await api.discounts.update(id, payload);
          else await api.discounts.create(payload);
          closeModal();
          window.toastAdmin?.(id ? 'تخفیف به‌روزرسانی شد.' : 'تخفیف ایجاد شد.');
          await load();
        } catch (error) {
          showError(error);
        } finally {
          submit.disabled = false;
        }
      });

      await load();
    } catch (error) {
      showError(error);
    }
  });
})();
