// ===== Sharafi Admin Inventory =====
(() => {
  const api = window.SharafiAdminAPI;
  const u = window.SharafiAdminUtils;
  if (!api || !u) return;

  const state = { q: '', low_stock: '', page: 1 };
  let canWrite = false;

  function inventoryStatus(item) {
    const available = Number(item.inventory?.available || 0);
    const reorder = Number(item.inventory?.reorder_level || 0);
    if (available <= 0) return { label: 'ناموجود', className: 'gray' };
    if (available <= reorder) return { label: 'کم‌موجود', className: 'gold' };
    return { label: 'موجود', className: 'green' };
  }

  function renderRows(items) {
    const tbody = u.$('#inventoryTable tbody');
    if (!tbody) return;
    u.clear(tbody);

    items.forEach((item) => {
      const tr = u.element('tr');
      const product = u.element('td');
      product.append(
        u.element('strong', { text: item.product?.name || 'محصول' }),
        item.title ? u.element('div', { className: 'table-subtext', text: item.title }) : document.createTextNode(''),
      );
      tr.append(
        product,
        u.element('td', { text: item.sku || '—' }),
        u.element('td', { text: Number(item.inventory?.available || 0).toLocaleString('fa-IR') }),
        u.element('td', { text: Number(item.inventory?.reorder_level || 0).toLocaleString('fa-IR') }),
        u.element('td', { text: Number(item.inventory?.reserved || 0).toLocaleString('fa-IR') }),
      );

      const status = inventoryStatus(item);
      const statusCell = u.element('td');
      statusCell.appendChild(u.element('span', { className: `status-pill ${status.className}`, text: status.label }));
      tr.appendChild(statusCell);

      const actions = u.element('td');
      if (canWrite) {
        const adjust = u.element('button', { className: 'icon-action js-adjust-stock', text: '±', attrs: { type: 'button', title: 'اصلاح موجودی' } });
        adjust.dataset.variantId = item.variant_id;
        adjust.dataset.label = `${item.product?.name || ''} / ${item.sku || ''}`;
        const settings = u.element('button', { className: 'icon-action js-reorder-stock', text: '⚙', attrs: { type: 'button', title: 'حد هشدار' } });
        settings.dataset.variantId = item.variant_id;
        settings.dataset.reorderLevel = item.inventory?.reorder_level || 0;
        settings.dataset.label = `${item.product?.name || ''} / ${item.sku || ''}`;
        actions.append(adjust, settings);
      } else {
        actions.textContent = 'فقط مشاهده';
      }
      tr.appendChild(actions);
      tbody.appendChild(tr);
    });
  }

  function openModal(id) {
    const modal = document.getElementById(id);
    modal?.classList.add('show');
    modal?.setAttribute('aria-hidden', 'false');
  }

  function closeModal(modal) {
    modal?.classList.remove('show');
    modal?.setAttribute('aria-hidden', 'true');
  }

  async function load() {
    const response = await api.inventory.list({
      q: state.q,
      low_stock: state.low_stock || undefined,
      page: state.page,
      per_page: 50,
    });
    const page = u.paginator(response);
    renderRows(page.items);
    u.setEmpty(u.$('.js-page-empty'), page.items.length === 0, 'موجودی مطابق فیلترها پیدا نشد.');
    u.renderPagination(u.$('.js-pagination'), page, (nextPage) => {
      state.page = nextPage;
      u.updateUrl(state);
      load().catch(showError);
    });
    return page;
  }

  function showError(error) {
    window.toastAdmin?.(error?.message || 'بارگذاری موجودی ناموفق بود.', 'error');
  }

  document.addEventListener('DOMContentLoaded', async () => {
    try {
      const session = await window.SharafiAdminReady;
      canWrite = session.permissions?.includes('inventory.write');

      const params = new URLSearchParams(window.location.search);
      state.q = params.get('q') || '';
      state.low_stock = params.get('low_stock') || '';
      state.page = Math.max(1, Number(params.get('page')) || 1);

      const search = u.$('.js-page-search');
      const filter = u.$('#inventoryStatus');
      if (search) search.value = state.q;
      if (filter) filter.value = state.low_stock;

      const [allItems, lowItems] = await Promise.all([
        api.inventory.list({ per_page: 1 }),
        api.inventory.list({ low_stock: 1, per_page: 1 }),
      ]);
      u.setKpi('inventory-total', u.paginator(allItems).total.toLocaleString('fa-IR'), 'همه SKUها و تنوع‌ها');
      u.setKpi('inventory-low', u.paginator(lowItems).total.toLocaleString('fa-IR'), 'قابل فروش کمتر یا مساوی حد هشدار');

      const reloadFirstPage = () => {
        state.page = 1;
        u.updateUrl(state);
        load().catch(showError);
      };
      search?.addEventListener('input', u.debounce(() => {
        state.q = search.value.trim();
        reloadFirstPage();
      }));
      filter?.addEventListener('change', () => {
        state.low_stock = filter.value;
        reloadFirstPage();
      });

      const adjustForm = u.$('#inventoryAdjustForm');
      const reorderForm = u.$('#inventoryReorderForm');

      document.addEventListener('click', (event) => {
        const adjust = event.target.closest('.js-adjust-stock');
        if (adjust) {
          adjustForm.reset();
          adjustForm.elements.namedItem('variant_id').value = adjust.dataset.variantId;
          u.$('.js-adjust-label').textContent = adjust.dataset.label;
          openModal('inventoryAdjustModal');
          return;
        }
        const settings = event.target.closest('.js-reorder-stock');
        if (settings) {
          reorderForm.reset();
          reorderForm.elements.namedItem('variant_id').value = settings.dataset.variantId;
          reorderForm.elements.namedItem('reorder_level').value = settings.dataset.reorderLevel;
          u.$('.js-reorder-label').textContent = settings.dataset.label;
          openModal('inventoryReorderModal');
        }
      });

      adjustForm?.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (!adjustForm.reportValidity()) return;
        const submit = adjustForm.querySelector('[type="submit"]');
        submit.disabled = true;
        try {
          await api.inventory.adjust(adjustForm.elements.namedItem('variant_id').value, {
            delta: Number(adjustForm.elements.namedItem('delta').value),
            reason: adjustForm.elements.namedItem('reason').value.trim(),
          });
          closeModal(adjustForm.closest('.modal-overlay'));
          window.toastAdmin?.('اصلاح موجودی با موفقیت ثبت و Audit شد.');
          await load();
        } catch (error) {
          showError(error);
        } finally {
          submit.disabled = false;
        }
      });

      reorderForm?.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (!reorderForm.reportValidity()) return;
        const submit = reorderForm.querySelector('[type="submit"]');
        submit.disabled = true;
        try {
          await api.inventory.settings(reorderForm.elements.namedItem('variant_id').value, {
            reorder_level: Number(reorderForm.elements.namedItem('reorder_level').value),
          });
          closeModal(reorderForm.closest('.modal-overlay'));
          window.toastAdmin?.('حد هشدار موجودی ذخیره شد.');
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
