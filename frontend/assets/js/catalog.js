// ===== Sharafi live catalog =====
(() => {
  const api = window.SharafiAPI;
  const grid = document.querySelector('.category-main .prod-grid');
  if (!api || !grid) return;

  const safe = (value) => typeof escapeHTML === 'function' ? escapeHTML(value) : String(value ?? '');
  const metaText = document.querySelector('.category-toolbar__meta');
  const categoryTitle = document.querySelector('#category-title');
  const categoryHeroText = document.querySelector('.category-hero p');
  const pagination = document.querySelector('.pagination, .category-pagination');
  let categories = [];
  let brands = [];
  let requestSerial = 0;

  const tomanToIrr = (value) => {
    if (value === null || value === undefined || String(value).trim() === '') return null;
    const toman = Number(value);
    if (!Number.isFinite(toman) || toman < 0) return null;
    const irr = Math.round(toman * 10);
    return Number.isSafeInteger(irr) ? irr : null;
  };

  const apiParams = () => {
    const params = new URLSearchParams(window.location.search);
    const sort = params.get('sort');
    return {
      q: params.get('q') || null,
      category: params.get('category') || null,
      brand: params.get('brand') || null,
      min_price: tomanToIrr(params.get('min_price')),
      max_price: tomanToIrr(params.get('max_price')),
      sort: ['newest', 'price-asc', 'price-desc'].includes(sort) ? sort : 'default',
      page: Math.max(1, Number.parseInt(params.get('page'), 10) || 1),
      per_page: 12,
    };
  };

  const priceMarkup = (product) => {
    const min = Number(product?.pricing?.min);
    const max = Number(product?.pricing?.max);
    if (!Number.isFinite(min)) return '<span class="product-current-price">قیمت نامشخص</span>';
    if (Number.isFinite(max) && max !== min) {
      return `<span class="product-current-price">از ${safe(api.formatIrr(min))}</span>`;
    }
    return `<span class="product-current-price">${safe(api.formatIrr(min))}</span>`;
  };

  const productCard = (product) => {
    const variantId = Number(product?.purchase?.variant_id);
    const canDirectAdd = Number.isInteger(variantId) && variantId > 0 && !product?.purchase?.requires_selection;
    const inStock = Boolean(product?.in_stock);
    const detailUrl = `product.html?slug=${encodeURIComponent(product.slug)}`;
    const action = canDirectAdd
      ? `<button class="product-card-add" type="button" data-variant-id="${variantId}" data-cart-name="${safe(product.name)}" data-cart-slug="${safe(product.slug)}" data-price-irr="${Number(product.pricing?.min || 0)}" data-in-stock="${String(inStock)}" ${inStock ? '' : 'disabled'}><span class="product-card-add-icon" aria-hidden="true">🛒</span>${inStock ? 'افزودن به سبد' : 'ناموجود'}</button>`
      : `<a class="product-card-add" href="${detailUrl}">${inStock ? 'انتخاب گزینه‌ها' : 'مشاهده محصول'}</a>`;

    return `
      <article class="product-card-v2">
        <div class="product-card-media">
          <div class="product-placeholder" aria-hidden="true">🧴</div>
        </div>
        <div class="product-card-body">
          <span class="product-card-brand">${safe(product.brand?.name || 'شرفی')}</span>
          <a class="product-card-link" href="${detailUrl}"><h3 class="product-card-title">${safe(product.name)}</h3></a>
          <div class="product-card-meta"><span>${safe(product.short_description || (inStock ? 'موجود' : 'ناموجود'))}</span></div>
          <div class="product-card-price-area"><div class="product-price-stack">${priceMarkup(product)}</div></div>
          <div class="product-card-actions">${action}</div>
        </div>
      </article>`;
  };

  const updateHero = (params, total) => {
    const category = categories.find((item) => item.slug === params.category);
    const brand = brands.find((item) => item.slug === params.brand);
    const query = params.q;
    if (categoryTitle) {
      categoryTitle.textContent = query ? `نتایج جستجو برای «${query}»` : category?.name || brand?.name || 'همه محصولات';
    }
    if (categoryHeroText) {
      categoryHeroText.textContent = query
        ? `${Number(total || 0).toLocaleString('fa-IR')} نتیجه از کاتالوگ فروشگاه پیدا شد.`
        : 'محصولات فعال و قابل خرید فروشگاه با قیمت و موجودی به‌روز.';
    }
  };

  const pageUrl = (page) => {
    const params = new URLSearchParams(window.location.search);
    if (page <= 1) params.delete('page');
    else params.set('page', String(page));
    const query = params.toString();
    return `${window.location.pathname}${query ? `?${query}` : ''}`;
  };

  const renderPagination = (meta) => {
    if (!pagination) return;
    const current = Number(meta?.current_page || 1);
    const last = Number(meta?.last_page || 1);
    pagination.innerHTML = '';
    pagination.hidden = last <= 1;
    if (last <= 1) return;

    const pages = new Set([1, last, current - 1, current, current + 1]);
    [...pages].filter((page) => page >= 1 && page <= last).sort((a, b) => a - b).forEach((page) => {
      const link = document.createElement('a');
      link.href = pageUrl(page);
      link.textContent = page.toLocaleString('fa-IR');
      link.setAttribute('aria-label', `صفحه ${page.toLocaleString('fa-IR')}`);
      if (page === current) {
        link.classList.add('is-active');
        link.setAttribute('aria-current', 'page');
      }
      link.addEventListener('click', (event) => {
        event.preventDefault();
        window.history.pushState({}, '', link.href);
        loadCatalog();
        window.scrollTo({ top: 0, behavior: 'smooth' });
      });
      pagination.appendChild(link);
    });
  };

  const loadCatalog = async () => {
    const serial = ++requestSerial;
    const params = apiParams();
    grid.setAttribute('aria-busy', 'true');
    if (metaText) metaText.textContent = 'در حال دریافت محصولات...';

    try {
      const payload = await api.catalog.products(params);
      if (serial !== requestSerial) return;
      const products = Array.isArray(payload?.data) ? payload.data : [];
      if (!products.length) {
        grid.innerHTML = '<div class="cart-empty-v2"><h3>محصولی پیدا نشد</h3><p>فیلترها یا عبارت جستجو را تغییر دهید.</p></div>';
      } else {
        grid.innerHTML = products.map(productCard).join('');
      }
      const total = Number(payload?.meta?.total || products.length);
      if (metaText) metaText.textContent = `${total.toLocaleString('fa-IR')} محصول`;
      updateHero(params, total);
      renderPagination(payload?.meta);
    } catch (error) {
      if (serial !== requestSerial) return;
      grid.innerHTML = `<div class="cart-empty-v2"><h3>دریافت محصولات ناموفق بود</h3><p>${safe(error?.message || 'لطفاً دوباره تلاش کنید.')}</p><button class="btn btn-primary js-catalog-retry" type="button">تلاش دوباره</button></div>`;
      grid.querySelector('.js-catalog-retry')?.addEventListener('click', loadCatalog);
      if (metaText) metaText.textContent = 'خطا در دریافت محصولات';
    } finally {
      if (serial === requestSerial) grid.setAttribute('aria-busy', 'false');
    }
  };

  const findFilterGroup = (form, titlePart) => [...form.querySelectorAll('.filter-group')]
    .find((group) => group.querySelector('h3')?.textContent?.includes(titlePart));

  const renderTaxonomyOptions = (group, name, items) => {
    if (!group) return;
    const heading = group.querySelector('h3');
    [...group.querySelectorAll('.filter-check')].forEach((node) => node.remove());
    const fragment = document.createDocumentFragment();
    items.forEach((item) => {
      const label = document.createElement('label');
      label.className = 'filter-check';
      const input = document.createElement('input');
      input.type = 'checkbox';
      input.name = name;
      input.value = item.slug;
      label.append(input, document.createTextNode(` ${item.name}`));
      fragment.appendChild(label);
    });
    heading?.after(fragment);
  };

  const loadTaxonomies = async () => {
    try {
      const [categoriesPayload, brandsPayload] = await Promise.all([
        api.catalog.categories(),
        api.catalog.brands(),
      ]);
      categories = Array.isArray(categoriesPayload?.data) ? categoriesPayload.data : [];
      brands = Array.isArray(brandsPayload?.data) ? brandsPayload.data : [];
      document.querySelectorAll('.js-filter-form').forEach((form) => {
        renderTaxonomyOptions(findFilterGroup(form, 'دسته‌بندی'), 'category', categories);
        renderTaxonomyOptions(findFilterGroup(form, 'برند'), 'brand', brands);
      });
      document.dispatchEvent(new CustomEvent('sharafi:taxonomies-updated'));
    } catch {
      // Existing static options remain available when taxonomy loading fails.
    }
  };

  document.addEventListener('sharafi:catalog-query-changed', loadCatalog);
  document.addEventListener('DOMContentLoaded', async () => {
    await loadTaxonomies();
    await loadCatalog();
  });
})();
