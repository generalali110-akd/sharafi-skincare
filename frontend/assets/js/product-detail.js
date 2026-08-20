// ===== Sharafi product detail interactions =====
(() => {
  const MAX_QTY = 99;
  const cart = window.SharafiCart;
  let api = window.SharafiAPI || null;
  let product = null;
  let selectedVariant = null;
  let quantity = 1;

  const quantityOutput = document.querySelector('.js-product-qty');
  const minusButtons = document.querySelectorAll('.js-product-minus');
  const plusButtons = document.querySelectorAll('.js-product-plus');
  const addButtons = document.querySelectorAll('.js-product-add');

  document.querySelector('.product-rating-v2')?.setAttribute('hidden', '');
  document.querySelector('.product-highlights')?.setAttribute('hidden', '');
  document.querySelector('.product-favorite')?.setAttribute('hidden', '');
  document.querySelector('.product-thumbs')?.setAttribute('hidden', '');
  document.querySelectorAll('.js-product-tab').forEach((tab) => {
    if (tab.dataset.tab !== 'desc') tab.hidden = true;
  });
  document.querySelectorAll('.js-product-tab-panel').forEach((panel) => {
    if (panel.dataset.panel !== 'desc') panel.hidden = true;
  });

  const getApi = async () => {
    if (api) return api;
    if (window.SharafiApiReady) api = await window.SharafiApiReady;
    else if (typeof window.ensureSharafiApi === 'function') api = await window.ensureSharafiApi();
    return api;
  };

  const safe = (value) => typeof escapeHTML === 'function' ? escapeHTML(value) : String(value ?? '');

  const renderProductUnavailable = (error) => {
    const message = safe(error?.message || 'برای نمایش اطلاعات واقعی محصول، سرویس فروشگاه باید در دسترس باشد.');
    const detail = document.querySelector('.product-detail-layout');
    if (detail) {
      detail.innerHTML = `
        <div class="catalog-api-state product-api-state">
          <h2>اتصال به کاتالوگ برقرار نیست</h2>
          <p>${message}</p>
          <a class="btn btn-primary" href="category.html">بازگشت به محصولات</a>
        </div>`;
    }

    const relatedGrid = document.querySelector('.product-page .section .prod-grid');
    if (relatedGrid) {
      relatedGrid.innerHTML = '<div class="catalog-api-state"><p>محصولات مرتبط فقط پس از اتصال به کاتالوگ واقعی نمایش داده می‌شوند.</p></div>';
    }

    document.querySelector('.product-tabs-v2')?.setAttribute('hidden', '');
    document.querySelector('.mobile-purchase-bar')?.setAttribute('hidden', '');
    const breadcrumb = document.querySelector('.breadcrumb');
    if (breadcrumb) breadcrumb.innerHTML = '<a href="index.html">صفحه اصلی</a> / <a href="category.html">محصولات</a> / خطای اتصال';
    document.title = 'اتصال به کاتالوگ برقرار نیست | فروشگاه شرفی';
  };

  const renderProductLoading = () => {
    document.title = 'در حال دریافت محصول | فروشگاه شرفی';
    setText('#product-title', 'در حال دریافت اطلاعات واقعی محصول...');
    setText('.product-brand-pill', 'کاتالوگ فروشگاه');
    setText('.product-sku', 'کد محصول: در حال دریافت');
    setText('.product-price-v2 .current', 'در حال دریافت قیمت');
    setText('.product-summary', 'اطلاعات محصول، قیمت و موجودی مستقیماً از سرویس فروشگاه دریافت می‌شود.');
    document.querySelector('.product-price-v2 .old')?.setAttribute('hidden', '');
    document.querySelector('.product-price-v2 .discount')?.setAttribute('hidden', '');
    document.querySelector('.product-main-badge')?.setAttribute('hidden', '');
    const mainMedia = document.querySelector('.product-main-media__visual');
    if (mainMedia) mainMedia.textContent = '...';
    addButtons.forEach((button) => {
      button.disabled = true;
      button.textContent = 'در حال دریافت محصول...';
    });
    document.querySelector('.mobile-purchase-bar')?.setAttribute('hidden', '');
  };

  const renderQuantity = () => {
    if (quantityOutput) quantityOutput.value = quantity;
    minusButtons.forEach((button) => { button.disabled = quantity <= 1; });
    plusButtons.forEach((button) => { button.disabled = quantity >= MAX_QTY; });
  };

  minusButtons.forEach((button) => {
    button.addEventListener('click', () => {
      quantity = Math.max(1, quantity - 1);
      renderQuantity();
    });
  });

  plusButtons.forEach((button) => {
    button.addEventListener('click', () => {
      quantity = Math.min(MAX_QTY, quantity + 1);
      renderQuantity();
    });
  });

  const setText = (selector, value) => {
    const element = document.querySelector(selector);
    if (element) element.textContent = value;
  };

  const renderVariant = () => {
    if (!api || !selectedVariant) return;
    const amount = Number(selectedVariant.price?.amount || 0);
    const compareAt = Number(selectedVariant.price?.compare_at || 0);
    setText('.product-sku', `کد محصول: ${selectedVariant.sku || '—'}`);
    setText('.product-price-v2 .current', api.formatIrr(amount));

    const old = document.querySelector('.product-price-v2 .old');
    const discount = document.querySelector('.product-price-v2 .discount');
    const discounted = compareAt > amount && amount > 0;
    if (old) {
      old.textContent = discounted ? api.formatIrr(compareAt) : '';
      old.hidden = !discounted;
    }
    if (discount) {
      const percent = discounted ? Math.round(((compareAt - amount) / compareAt) * 100) : 0;
      discount.textContent = discounted ? `${percent.toLocaleString('fa-IR')}٪ تخفیف` : '';
      discount.hidden = !discounted;
    }

    const stockBadge = document.querySelector('.product-main-badge');
    if (stockBadge) {
      stockBadge.hidden = false;
      stockBadge.textContent = selectedVariant.in_stock ? 'موجود' : 'ناموجود';
    }
    addButtons.forEach((button) => {
      button.disabled = !selectedVariant.in_stock;
      button.textContent = selectedVariant.in_stock ? 'افزودن به سبد خرید 🛒' : 'در حال حاضر ناموجود';
    });
    document.querySelector('.mobile-purchase-bar')?.removeAttribute('hidden');
  };

  const renderVariantSelector = () => {
    const variants = Array.isArray(product?.variants) ? product.variants : [];
    selectedVariant = variants.find((variant) => variant.in_stock) || variants[0] || null;
    const purchase = document.querySelector('.product-purchase');
    if (!purchase || variants.length <= 1) {
      renderVariant();
      return;
    }

    let wrapper = document.querySelector('.js-product-variant-wrap');
    if (!wrapper) {
      wrapper = document.createElement('label');
      wrapper.className = 'js-product-variant-wrap product-variant-wrap';
      wrapper.append(document.createTextNode('انتخاب گزینه'));
      const select = document.createElement('select');
      select.className = 'js-product-variant product-variant-select';
      wrapper.appendChild(select);
      purchase.prepend(wrapper);
    }

    const select = wrapper.querySelector('select');
    select.innerHTML = '';
    variants.forEach((variant) => {
      const option = document.createElement('option');
      option.value = String(variant.id);
      option.textContent = `${variant.title || variant.sku}${variant.in_stock ? '' : ' — ناموجود'}`;
      option.selected = variant.id === selectedVariant?.id;
      select.appendChild(option);
    });
    select.addEventListener('change', () => {
      selectedVariant = variants.find((variant) => String(variant.id) === select.value) || variants[0];
      quantity = 1;
      renderQuantity();
      renderVariant();
    });
    renderVariant();
  };

  const renderProduct = () => {
    if (!product) return;
    document.title = `${product.name} | فروشگاه شرفی`;
    setText('#product-title', product.name);
    setText('.product-brand-pill', product.brand?.name || 'شرفی');
    setText('.product-summary', product.short_description || product.description || '');
    const descPanel = document.querySelector('[data-panel="desc"]');
    if (descPanel) descPanel.textContent = product.description || product.short_description || 'توضیحات تکمیلی برای این محصول ثبت نشده است.';

    const images = Array.isArray(product.images) ? product.images : [];
    const mainMedia = document.querySelector('.product-main-media__visual');
    if (mainMedia) {
      const primary = images.find((image) => image.is_primary) || images[0];
      mainMedia.innerHTML = primary?.url
        ? `<img class="product-main-img" src="${safe(primary.url)}" alt="${safe(primary.alt_text || product.name)}">`
        : '🧴';
    }

    const breadcrumb = document.querySelector('.breadcrumb');
    if (breadcrumb) {
      const category = product.categories?.[0];
      breadcrumb.innerHTML = `<a href="index.html">صفحه اصلی</a> / <a href="category.html${category ? `?category=${encodeURIComponent(category.slug)}` : ''}">${safe(category?.name || 'محصولات')}</a> / ${safe(product.name)}`;
    }
    renderVariantSelector();
  };

  const relatedCard = (item) => {
    const variantId = Number(item?.purchase?.variant_id);
    const direct = Number.isInteger(variantId) && variantId > 0 && !item?.purchase?.requires_selection;
    const inStock = Boolean(item.in_stock);
    const detail = `product.html?slug=${encodeURIComponent(item.slug)}`;
    const action = direct
      ? `<button class="product-card-add" type="button" data-variant-id="${variantId}" data-cart-name="${safe(item.name)}" data-cart-slug="${safe(item.slug)}" data-price-irr="${Number(item.pricing?.min || 0)}" data-in-stock="${String(inStock)}" ${inStock ? '' : 'disabled'}>🛒 ${inStock ? 'افزودن به سبد' : 'ناموجود'}</button>`
      : `<a class="product-card-add" href="${detail}">${inStock ? 'انتخاب گزینه‌ها' : 'مشاهده محصول'}</a>`;
    const image = item.primary_image;
    const media = image?.url
      ? `<img class="product-card-img" src="${safe(image.url)}" alt="${safe(image.alt_text || item.name)}" loading="lazy">`
      : '<div class="product-placeholder" aria-hidden="true">🧴</div>';
    return `<article class="product-card-v2"><div class="product-card-media">${media}</div><div class="product-card-body"><span class="product-card-brand">${safe(item.brand?.name || 'شرفی')}</span><a class="product-card-link" href="${detail}"><h3 class="product-card-title">${safe(item.name)}</h3></a><div class="product-card-price-area"><div class="product-price-stack"><span class="product-current-price">${safe(api.formatIrr(item.pricing?.min || 0))}</span></div></div><div class="product-card-actions">${action}</div></div></article>`;
  };

  const renderRelatedProducts = async () => {
    const grid = document.querySelector('.product-page .section .prod-grid');
    if (!grid || !product || !api) return;
    grid.innerHTML = '<div class="cart-empty-v2 catalog-api-state"><p>در حال دریافت محصولات مرتبط واقعی...</p></div>';
    try {
      const category = product.categories?.[0]?.slug || null;
      const payload = await api.catalog.products({ category, per_page: 5 });
      const items = (Array.isArray(payload?.data) ? payload.data : []).filter((item) => item.slug !== product.slug).slice(0, 4);
      grid.innerHTML = items.length ? items.map(relatedCard).join('') : '<div class="cart-empty-v2"><p>محصول مرتبط دیگری برای نمایش وجود ندارد.</p></div>';
    } catch {
      grid.innerHTML = '<div class="cart-empty-v2 catalog-api-state"><p>اتصال به کاتالوگ برای نمایش محصولات مرتبط برقرار نیست.</p></div>';
    }
  };

  const resolveSlug = async () => {
    const params = new URLSearchParams(window.location.search);
    const direct = params.get('slug');
    if (direct) return direct;
    const payload = await api.catalog.products({ per_page: 1 });
    const first = payload?.data?.[0]?.slug;
    if (!first) return null;
    window.history.replaceState({}, '', `${window.location.pathname}?slug=${encodeURIComponent(first)}`);
    return first;
  };

  const loadProduct = async () => {
    try {
      renderProductLoading();
      const relatedGrid = document.querySelector('.product-page .section .prod-grid');
      if (relatedGrid) relatedGrid.innerHTML = '<div class="cart-empty-v2 catalog-api-state"><p>در حال دریافت محصولات مرتبط واقعی...</p></div>';
      api = await getApi();
      if (!api) throw new Error('ارتباط با API آماده نیست.');
      const slug = await resolveSlug();
      if (!slug) throw new Error('محصول فعالی برای نمایش وجود ندارد.');
      const payload = await api.catalog.product(slug);
      product = payload?.data || null;
      if (!product) throw new Error('اطلاعات محصول دریافت نشد.');
      renderProduct();
      await renderRelatedProducts();
    } catch (error) {
      addButtons.forEach((button) => { button.disabled = true; });
      renderProductUnavailable(error);
      toast(error?.message || 'دریافت اطلاعات محصول ناموفق بود.', 3500);
    }
  };

  addButtons.forEach((button) => {
    button.addEventListener('click', async () => {
      api = await getApi().catch(() => null);
      if (!api || !product || !selectedVariant || !selectedVariant.in_stock || !cart) return;
      button.disabled = true;
      try {
        await cart.add({
          variant_id: selectedVariant.id,
          name: product.name,
          slug: product.slug,
          variant_title: selectedVariant.title || '',
          price: api.toman(Number(selectedVariant.price?.amount || 0)),
          in_stock: selectedVariant.in_stock,
          icon: '🧴',
        }, quantity);
      } finally {
        button.disabled = !selectedVariant.in_stock;
      }
    });
  });

  const tabs = [...document.querySelectorAll('.js-product-tab')].filter((tab) => tab.dataset.tab === 'desc');
  const panels = [...document.querySelectorAll('.js-product-tab-panel')].filter((panel) => panel.dataset.panel === 'desc');
  const activateTab = (tab, focus = false) => {
    if (!tab) return;
    const target = tab.dataset.tab;
    tabs.forEach((item) => {
      const selected = item === tab;
      item.setAttribute('aria-selected', String(selected));
      item.tabIndex = selected ? 0 : -1;
    });
    panels.forEach((panel) => { panel.hidden = panel.dataset.panel !== target; });
    if (focus) tab.focus();
  };

  tabs.forEach((tab) => tab.addEventListener('click', () => activateTab(tab)));
  activateTab(tabs[0]);
  renderQuantity();
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', loadProduct, { once: true });
  else loadProduct();
})();
