// ===== Sharafi category filters & sorting =====
(() => {
  const drawer = document.querySelector('.js-filter-drawer');
  const openButtons = document.querySelectorAll('.js-filter-open');
  const closeButtons = document.querySelectorAll('.js-filter-close');
  const resetButtons = document.querySelectorAll('.js-filter-reset');
  const forms = document.querySelectorAll('.js-filter-form');
  const sort = document.querySelector('.js-category-sort');
  let lastFocusedElement = null;

  const focusableSelector = [
    'a[href]',
    'button:not([disabled])',
    'input:not([disabled])',
    'select:not([disabled])',
    'textarea:not([disabled])',
    '[tabindex]:not([tabindex="-1"])',
  ].join(',');

  const getDrawerFocusable = () => drawer
    ? [...drawer.querySelectorAll(focusableSelector)].filter((element) => !element.hidden)
    : [];

  const setDrawer = (open, trigger = null) => {
    if (!drawer) return;
    if (open) lastFocusedElement = trigger || document.activeElement;
    drawer.classList.toggle('is-open', open);
    document.body.classList.toggle('filters-open', open);
    openButtons.forEach((button) => button.setAttribute('aria-expanded', String(open)));

    if (open) {
      drawer.inert = false;
      drawer.setAttribute('aria-hidden', 'false');
      window.setTimeout(() => getDrawerFocusable()[0]?.focus(), 0);
      return;
    }

    if (lastFocusedElement instanceof HTMLElement) {
      lastFocusedElement.focus();
      lastFocusedElement = null;
    }
    drawer.inert = true;
    drawer.setAttribute('aria-hidden', 'true');
  };

  if (drawer) drawer.inert = drawer.getAttribute('aria-hidden') === 'true';

  openButtons.forEach((button) => button.addEventListener('click', () => setDrawer(true, button)));
  closeButtons.forEach((button) => button.addEventListener('click', () => setDrawer(false)));

  document.addEventListener('keydown', (event) => {
    if (!drawer?.classList.contains('is-open')) return;
    if (event.key === 'Escape') {
      event.preventDefault();
      setDrawer(false);
      return;
    }
    if (event.key !== 'Tab') return;
    const focusable = getDrawerFocusable();
    if (!focusable.length) return;
    const first = focusable[0];
    const last = focusable[focusable.length - 1];
    if (event.shiftKey && document.activeElement === first) {
      event.preventDefault();
      last.focus();
    } else if (!event.shiftKey && document.activeElement === last) {
      event.preventDefault();
      first.focus();
    }
  });

  const paramsToForm = (form) => {
    const params = new URLSearchParams(window.location.search);
    form.querySelectorAll('input[type="checkbox"]').forEach((input) => {
      const value = params.get(input.name);
      input.checked = value !== null && value === input.value;
    });
    form.querySelectorAll('input[type="number"]').forEach((input) => {
      const value = params.get(input.name);
      input.value = value ?? '';
    });
  };

  const hideUnsupportedGroups = () => {
    forms.forEach((form) => {
      form.querySelectorAll('.filter-group').forEach((group) => {
        const heading = group.querySelector('h3')?.textContent?.trim() || '';
        if (heading.includes('نوع پوست')) group.hidden = true;
      });
    });
    sort?.querySelector('option[value="bestseller"]')?.remove();
  };

  const enforceSingleChoice = (form) => {
    form.addEventListener('change', (event) => {
      const input = event.target.closest('input[type="checkbox"]');
      if (!input || !['category', 'brand'].includes(input.name) || !input.checked) return;
      form.querySelectorAll(`input[name="${input.name}"]`).forEach((candidate) => {
        if (candidate !== input) candidate.checked = false;
      });
    });
  };

  const hasValidPriceRange = (form) => {
    const minInput = form.querySelector('input[name="min_price"]');
    const maxInput = form.querySelector('input[name="max_price"]');
    if (!minInput || !maxInput) return true;
    const min = minInput.value === '' ? null : Number(minInput.value);
    const max = maxInput.value === '' ? null : Number(maxInput.value);
    const invalid = min !== null && max !== null && Number.isFinite(min) && Number.isFinite(max) && min > max;
    maxInput.setCustomValidity(invalid ? 'حداکثر قیمت باید بزرگ‌تر یا مساوی حداقل قیمت باشد.' : '');
    return !invalid;
  };

  const notifyCatalog = () => document.dispatchEvent(new CustomEvent('sharafi:catalog-query-changed'));

  const applyForm = (form) => {
    if (!hasValidPriceRange(form)) {
      form.reportValidity();
      return;
    }

    const params = new URLSearchParams(window.location.search);
    ['category', 'brand', 'skin', 'min_price', 'max_price', 'page'].forEach((key) => params.delete(key));
    const data = new FormData(form);
    for (const [key, value] of data.entries()) {
      if (['category', 'brand', 'min_price', 'max_price'].includes(key) && String(value).trim()) params.set(key, value);
    }
    if (sort?.value && sort.value !== 'default') params.set('sort', sort.value);
    else params.delete('sort');

    const query = params.toString();
    window.history.replaceState({}, '', `${window.location.pathname}${query ? `?${query}` : ''}`);
    forms.forEach(paramsToForm);
    setDrawer(false);
    notifyCatalog();
  };

  hideUnsupportedGroups();
  forms.forEach((form) => {
    paramsToForm(form);
    enforceSingleChoice(form);
    form.addEventListener('submit', (event) => {
      event.preventDefault();
      applyForm(form);
    });
  });

  resetButtons.forEach((button) => {
    button.addEventListener('click', () => {
      forms.forEach((form) => form.reset());
      if (sort) sort.value = 'default';
      const params = new URLSearchParams(window.location.search);
      ['category', 'brand', 'skin', 'min_price', 'max_price', 'sort', 'page'].forEach((key) => params.delete(key));
      const query = params.toString();
      window.history.replaceState({}, '', `${window.location.pathname}${query ? `?${query}` : ''}`);
      notifyCatalog();
    });
  });

  if (sort) {
    const params = new URLSearchParams(window.location.search);
    const requestedSort = params.get('sort');
    sort.value = ['newest', 'price-asc', 'price-desc'].includes(requestedSort) ? requestedSort : 'default';
    sort.addEventListener('change', () => {
      const next = new URLSearchParams(window.location.search);
      if (sort.value === 'default') next.delete('sort');
      else next.set('sort', sort.value);
      next.delete('page');
      const query = next.toString();
      window.history.replaceState({}, '', `${window.location.pathname}${query ? `?${query}` : ''}`);
      notifyCatalog();
    });
  }

  document.addEventListener('sharafi:taxonomies-updated', () => forms.forEach(paramsToForm));
  window.addEventListener('popstate', notifyCatalog);
})();
