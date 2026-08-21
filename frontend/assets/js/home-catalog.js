// ===== Sharafi live homepage catalog =====
(() => {
  const api = window.SharafiAPI;
  const grid = document.querySelector('.hero ~ .section .prod-grid') || document.querySelector('.prod-grid');
  if (!grid) return;

  const safe = (value) => typeof escapeHTML === 'function' ? escapeHTML(value) : String(value ?? '');
  const unavailable = () => {
    grid.innerHTML = '<div class="cart-empty-v2 catalog-api-state"><h3>اتصال به کاتالوگ برقرار نیست</h3><p>برای نمایش محصولات واقعی، سرویس فروشگاه باید در دسترس باشد.</p></div>';
    grid.setAttribute('aria-busy', 'false');
  };
  if (!api) {
    unavailable();
    return;
  }
  const imageMarkup = (product) => {
    const image = product?.primary_image;
    if (!image?.url) return '<div class="product-placeholder" aria-hidden="true">🧴</div>';
    return `<img class="product-card-img" src="${safe(image.url)}" alt="${safe(image.alt_text || product.name)}" loading="lazy">`;
  };

  const card = (product) => {
    const variantId = Number(product?.purchase?.variant_id);
    const direct = Number.isInteger(variantId) && variantId > 0 && !product?.purchase?.requires_selection;
    const inStock = Boolean(product?.in_stock);
    const detail = `product.html?slug=${encodeURIComponent(product.slug)}`;
    const action = direct
      ? `<button class="product-card-add" type="button" data-variant-id="${variantId}" data-cart-name="${safe(product.name)}" data-cart-slug="${safe(product.slug)}" data-price-irr="${Number(product.pricing?.min || 0)}" data-in-stock="${String(inStock)}" ${inStock ? '' : 'disabled'}><span class="product-card-add-icon" aria-hidden="true">🛒</span>${inStock ? 'افزودن به سبد' : 'ناموجود'}</button>`
      : `<a class="product-card-add" href="${detail}">${inStock ? 'انتخاب گزینه‌ها' : 'مشاهده محصول'}</a>`;

    return `
      <article class="product-card-v2">
        <div class="product-card-media">${imageMarkup(product)}</div>
        <div class="product-card-body">
          <span class="product-card-brand">${safe(product.brand?.name || 'شرفی')}</span>
          <a class="product-card-link" href="${detail}"><h3 class="product-card-title">${safe(product.name)}</h3></a>
          <div class="product-card-meta"><span>${safe(product.short_description || (inStock ? 'موجود' : 'ناموجود'))}</span></div>
          <div class="product-card-price-area"><div class="product-price-stack"><span class="product-current-price">${safe(api.formatIrr(product.pricing?.min || 0))}</span></div></div>
          <div class="product-card-actions">${action}</div>
        </div>
      </article>`;
  };

  const init = async () => {
    grid.setAttribute('aria-busy', 'true');
    grid.innerHTML = '<div class="cart-empty-v2 catalog-api-state"><h3>در حال دریافت محصولات واقعی...</h3></div>';
    try {
      const payload = await api.catalog.products({ per_page: 4 });
      const products = Array.isArray(payload?.data) ? payload.data : [];
      if (products.length) grid.innerHTML = products.map(card).join('');
      else grid.innerHTML = '<div class="cart-empty-v2"><h3>هنوز محصول فعالی ثبت نشده است</h3></div>';
    } catch (error) {
      grid.innerHTML = `<div class="cart-empty-v2 catalog-api-state"><h3>اتصال به کاتالوگ برقرار نیست</h3><p>${safe(error?.message || 'برای نمایش محصولات واقعی، سرویس فروشگاه باید در دسترس باشد.')}</p><button class="btn btn-primary js-home-catalog-retry" type="button">تلاش دوباره</button></div>`;
      grid.querySelector('.js-home-catalog-retry')?.addEventListener('click', init);
    } finally {
      grid.setAttribute('aria-busy', 'false');
    }
  };

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init, { once: true });
  else init();
})();
