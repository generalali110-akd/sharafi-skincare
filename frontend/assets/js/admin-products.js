// ===== Sharafi Admin Products =====
(() => {
  const api = window.SharafiAdminAPI;
  const u = window.SharafiAdminUtils;
  if (!api || !u) return;

  const state = { q: '', status: '', page: 1 };
  let canWrite = false;
  let categories = [];
  let brands = [];
  let currentVariantProductId = null;

  const tomanToIrr = (value) => Math.round(Number(value || 0) * 10);
  const irrToToman = (value) => Math.round(Number(value || 0) / 10);

  function suggestSlug(name) {
    return String(name || '')
      .trim()
      .replace(/\s+/g, '-')
      .replace(/[^\p{L}\p{N}-]+/gu, '')
      .replace(/-+/g, '-')
      .replace(/^-|-$/g, '')
      .toLowerCase();
  }

  function ensureOption(select, item) {
    if (!select || !item?.id) return;
    if ([...select.options].some((option) => String(option.value) === String(item.id))) return;
    select.appendChild(u.element('option', { text: item.name || `#${item.id}`, attrs: { value: item.id } }));
  }

  function populateTaxonomy() {
    const form = u.$('#productForm');
    if (!form) return;
    const brandSelect = form.elements.namedItem('brand_id');
    const categorySelect = form.elements.namedItem('category_ids');
    u.clear(brandSelect);
    brandSelect.appendChild(u.element('option', { text: 'بدون برند', attrs: { value: '' } }));
    brands.forEach((brand) => brandSelect.appendChild(u.element('option', { text: brand.name, attrs: { value: brand.id } })));
    u.clear(categorySelect);
    categories.forEach((category) => categorySelect.appendChild(u.element('option', { text: category.name, attrs: { value: category.id } })));
  }

  function renderRows(items) {
    const tbody = u.$('#productsTable tbody');
    if (!tbody) return;
    u.clear(tbody);

    items.forEach((product) => {
      const tr = u.element('tr');
      const productCell = u.element('td');
      productCell.append(
        u.element('strong', { text: product.name }),
        u.element('div', { className: 'table-subtext', text: `${Number(product.variant_count || 0).toLocaleString('fa-IR')} تنوع` }),
      );
      const skuText = Array.isArray(product.skus) && product.skus.length
        ? product.skus.slice(0, 3).join('، ') + (product.skus.length > 3 ? '…' : '')
        : '—';
      const statusCell = u.element('td');
      statusCell.appendChild(u.statusPill(product.status));
      const actions = u.element('td');
      if (canWrite) {
        const edit = u.element('button', { className: 'icon-action js-edit-product', text: '✎', attrs: { type: 'button', title: 'ویرایش محصول' } });
        edit.dataset.id = product.id;
        const variants = u.element('button', { className: 'icon-action js-manage-variants', text: '≡', attrs: { type: 'button', title: 'مدیریت تنوع‌ها و قیمت' } });
        variants.dataset.id = product.id;
        variants.dataset.name = product.name;
        actions.append(edit, variants);
      } else {
        actions.textContent = 'فقط مشاهده';
      }
      tr.append(
        productCell,
        u.element('td', { text: skuText }),
        u.element('td', { text: product.brand?.name || '—' }),
        u.element('td', { text: product.min_price_irr === null ? '—' : u.formatIrr(product.min_price_irr) }),
        u.element('td', { text: Number(product.available_stock || 0).toLocaleString('fa-IR') }),
        statusCell,
        actions,
      );
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

  function resetProductForm() {
    const form = u.$('#productForm');
    form.reset();
    form.elements.namedItem('id').value = '';
    form.elements.namedItem('status').value = 'draft';
    u.$('.js-product-modal-title').textContent = 'افزودن محصول';
    u.$('.js-create-variant-fields').hidden = false;
    form.elements.namedItem('sku').required = true;
    form.elements.namedItem('price_toman').required = true;
    populateTaxonomy();
  }

  async function editProduct(id) {
    const response = await api.products.show(id);
    const product = response?.data;
    if (!product) throw new Error('اطلاعات محصول دریافت نشد.');
    const form = u.$('#productForm');
    form.reset();
    populateTaxonomy();
    form.elements.namedItem('id').value = product.id;
    form.elements.namedItem('name').value = product.name || '';
    form.elements.namedItem('slug').value = product.slug || '';
    ensureOption(form.elements.namedItem('brand_id'), product.brand);
    form.elements.namedItem('brand_id').value = product.brand?.id || '';
    (product.categories || []).forEach((category) => ensureOption(form.elements.namedItem('category_ids'), category));
    [...form.elements.namedItem('category_ids').options].forEach((option) => {
      option.selected = (product.categories || []).some((category) => String(category.id) === String(option.value));
    });
    form.elements.namedItem('status').value = product.status || 'draft';
    form.elements.namedItem('short_description').value = product.short_description || '';
    form.elements.namedItem('is_featured').checked = Boolean(product.is_featured);
    u.$('.js-product-modal-title').textContent = 'ویرایش محصول';
    u.$('.js-create-variant-fields').hidden = true;
    form.elements.namedItem('sku').required = false;
    form.elements.namedItem('price_toman').required = false;
    openModal('productModal');
  }

  function productPayload(form, creating) {
    const selectedCategories = [...form.elements.namedItem('category_ids').selectedOptions]
      .map((option) => Number(option.value));
    const payload = {
      name: form.elements.namedItem('name').value.trim(),
      slug: form.elements.namedItem('slug').value.trim(),
      brand_id: form.elements.namedItem('brand_id').value ? Number(form.elements.namedItem('brand_id').value) : null,
      category_ids: selectedCategories,
      short_description: form.elements.namedItem('short_description').value.trim() || null,
      status: form.elements.namedItem('status').value,
      is_featured: form.elements.namedItem('is_featured').checked,
    };

    if (creating) {
      const compare = form.elements.namedItem('compare_toman').value;
      payload.variants = [{
        sku: form.elements.namedItem('sku').value.trim(),
        title: form.elements.namedItem('variant_title').value.trim() || null,
        price_irr: tomanToIrr(form.elements.namedItem('price_toman').value),
        compare_at_price_irr: compare ? tomanToIrr(compare) : null,
        is_active: true,
      }];
    }
    return payload;
  }

  function renderVariantList(product) {
    const list = u.$('#variantList');
    u.clear(list);
    if (!(product.variants || []).length) {
      list.appendChild(u.element('div', { className: 'empty-state', text: 'تنوعی ثبت نشده است.' }));
      return;
    }
    (product.variants || []).forEach((variant) => {
      const row = u.element('div', { className: 'admin-summary-row' });
      const info = u.element('div');
      info.append(
        u.element('strong', { text: variant.title || variant.sku }),
        u.element('span', { text: `${variant.sku} | ${u.formatIrr(variant.price_irr)} | قابل فروش ${Number(variant.inventory?.available || 0).toLocaleString('fa-IR')}` }),
      );
      const edit = u.element('button', { className: 'btn btn-outline btn-sm js-edit-variant', text: 'ویرایش', attrs: { type: 'button' } });
      edit.dataset.variant = JSON.stringify({
        id: variant.id,
        sku: variant.sku,
        title: variant.title,
        price_irr: variant.price_irr,
        compare_at_price_irr: variant.compare_at_price_irr,
        is_active: variant.is_active,
      });
      row.append(info, edit);
      list.appendChild(row);
    });
  }

  function fillVariantForm(variant = null) {
    const form = u.$('#variantForm');
    form.reset();
    form.elements.namedItem('id').value = variant?.id || '';
    form.elements.namedItem('sku').value = variant?.sku || '';
    form.elements.namedItem('title').value = variant?.title || '';
    form.elements.namedItem('price_toman').value = variant ? irrToToman(variant.price_irr) : '';
    form.elements.namedItem('compare_toman').value = variant?.compare_at_price_irr ? irrToToman(variant.compare_at_price_irr) : '';
    form.elements.namedItem('is_active').checked = variant ? Boolean(variant.is_active) : true;
    u.$('.js-variant-form-title').textContent = variant ? 'ویرایش تنوع' : 'افزودن تنوع';
  }

  async function refreshVariantProduct() {
    const response = await api.products.show(currentVariantProductId);
    renderVariantList(response?.data || { variants: [] });
  }

  async function openVariants(productId, productName) {
    currentVariantProductId = productId;
    u.$('.js-variant-product-name').textContent = productName || 'محصول';
    fillVariantForm();
    await refreshVariantProduct();
    openModal('variantModal');
  }

  async function load() {
    const response = await api.products.list({ q: state.q, status: state.status, page: state.page, per_page: 25 });
    const page = u.paginator(response);
    renderRows(page.items);
    u.setEmpty(u.$('.js-page-empty'), page.items.length === 0, 'محصولی مطابق فیلترها پیدا نشد.');
    u.renderPagination(u.$('.js-pagination'), page, (nextPage) => {
      state.page = nextPage;
      u.updateUrl(state);
      load().catch(showError);
    });
    return page;
  }

  function showError(error) {
    window.toastAdmin?.(error?.message || 'بارگذاری محصولات ناموفق بود.', 'error');
  }

  document.addEventListener('DOMContentLoaded', async () => {
    try {
      const session = await window.SharafiAdminReady;
      canWrite = session.permissions?.includes('catalog.write');
      const canReadInventory = session.permissions?.includes('inventory.read');

      const [categoryResponse, brandResponse] = await Promise.all([api.taxonomy.categories(), api.taxonomy.brands()]);
      categories = categoryResponse?.data || [];
      brands = brandResponse?.data || [];
      populateTaxonomy();

      const params = new URLSearchParams(window.location.search);
      state.q = params.get('q') || '';
      state.status = params.get('status') || '';
      state.page = Math.max(1, Number(params.get('page')) || 1);
      const search = u.$('.js-page-search');
      const status = u.$('#productStatus');
      if (search) search.value = state.q;
      if (status) status.value = state.status;

      const counts = [
        api.products.list({ per_page: 1 }),
        api.products.list({ status: 'active', per_page: 1 }),
        api.products.list({ status: 'draft', per_page: 1 }),
      ];
      if (canReadInventory) counts.push(api.inventory.list({ low_stock: 1, per_page: 1 }));
      const [allProducts, activeProducts, draftProducts, lowStock] = await Promise.all(counts);
      u.setKpi('products-total', u.paginator(allProducts).total.toLocaleString('fa-IR'), 'همه محصولات');
      u.setKpi('products-active', u.paginator(activeProducts).total.toLocaleString('fa-IR'), 'قابل نمایش در فروشگاه');
      u.setKpi('products-draft', u.paginator(draftProducts).total.toLocaleString('fa-IR'), 'هنوز منتشر نشده');
      if (canReadInventory) u.setKpi('products-low', u.paginator(lowStock).total.toLocaleString('fa-IR'), 'تنوع‌های کم‌موجود');
      else u.setKpi('products-low', '—', 'مجوز مشاهده موجودی ندارید');

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

      const form = u.$('#productForm');
      u.$('.js-add-product')?.addEventListener('click', () => {
        resetProductForm();
        openModal('productModal');
      });
      form?.elements.namedItem('name')?.addEventListener('input', () => {
        if (!form.elements.namedItem('id').value && !form.elements.namedItem('slug').dataset.touched) {
          form.elements.namedItem('slug').value = suggestSlug(form.elements.namedItem('name').value);
        }
      });
      form?.elements.namedItem('slug')?.addEventListener('input', () => {
        form.elements.namedItem('slug').dataset.touched = 'true';
      });

      document.addEventListener('click', async (event) => {
        const edit = event.target.closest('.js-edit-product');
        if (edit) {
          edit.disabled = true;
          try {
            await editProduct(edit.dataset.id);
          } catch (error) {
            showError(error);
          } finally {
            edit.disabled = false;
          }
          return;
        }
        const manage = event.target.closest('.js-manage-variants');
        if (manage) {
          manage.disabled = true;
          try {
            await openVariants(manage.dataset.id, manage.dataset.name);
          } catch (error) {
            showError(error);
          } finally {
            manage.disabled = false;
          }
          return;
        }
        const variantEdit = event.target.closest('.js-edit-variant');
        if (variantEdit) {
          fillVariantForm(JSON.parse(variantEdit.dataset.variant));
        }
      });

      form?.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (!form.reportValidity()) return;
        const submit = form.querySelector('[type="submit"]');
        submit.disabled = true;
        try {
          const id = form.elements.namedItem('id').value;
          const payload = productPayload(form, !id);
          if (id) await api.products.update(id, payload);
          else await api.products.create(payload);
          closeModal(form.closest('.modal-overlay'));
          window.toastAdmin?.(id ? 'محصول به‌روزرسانی شد.' : 'محصول ایجاد شد؛ موجودی را از صفحه انبار ثبت کنید.');
          await load();
        } catch (error) {
          showError(error);
        } finally {
          submit.disabled = false;
        }
      });

      const variantForm = u.$('#variantForm');
      u.$('.js-new-variant')?.addEventListener('click', () => fillVariantForm());
      variantForm?.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (!variantForm.reportValidity()) return;
        const submit = variantForm.querySelector('[type="submit"]');
        submit.disabled = true;
        const compare = variantForm.elements.namedItem('compare_toman').value;
        const payload = {
          sku: variantForm.elements.namedItem('sku').value.trim(),
          title: variantForm.elements.namedItem('title').value.trim() || null,
          price_irr: tomanToIrr(variantForm.elements.namedItem('price_toman').value),
          compare_at_price_irr: compare ? tomanToIrr(compare) : null,
          is_active: variantForm.elements.namedItem('is_active').checked,
        };
        try {
          const variantId = variantForm.elements.namedItem('id').value;
          if (variantId) await api.products.updateVariant(variantId, payload);
          else await api.products.createVariant(currentVariantProductId, payload);
          window.toastAdmin?.(variantId ? 'تنوع به‌روزرسانی شد.' : 'تنوع جدید ایجاد شد.');
          fillVariantForm();
          await refreshVariantProduct();
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
