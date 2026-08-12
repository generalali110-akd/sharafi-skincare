// ===== Sharafi category filters & sorting =====
(() => {
  const drawer = document.querySelector('.js-filter-drawer');
  const openButtons = document.querySelectorAll('.js-filter-open');
  const closeButtons = document.querySelectorAll('.js-filter-close');
  const resetButtons = document.querySelectorAll('.js-filter-reset');
  const forms = document.querySelectorAll('.js-filter-form');
  const sort = document.querySelector('.js-category-sort');

  const setDrawer = (open) => {
    if (!drawer) return;
    drawer.classList.toggle('is-open', open);
    drawer.setAttribute('aria-hidden', String(!open));
    document.body.classList.toggle('filters-open', open);
    openButtons.forEach((button) => button.setAttribute('aria-expanded', String(open)));
  };

  openButtons.forEach((button) => button.addEventListener('click', () => setDrawer(true)));
  closeButtons.forEach((button) => button.addEventListener('click', () => setDrawer(false)));

  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') setDrawer(false);
  });

  const paramsToForm = (form) => {
    const params = new URLSearchParams(window.location.search);
    form.querySelectorAll('input[type="checkbox"]').forEach((input) => {
      const values = params.getAll(input.name);
      if (values.length) input.checked = values.includes(input.value);
    });
    form.querySelectorAll('input[type="number"]').forEach((input) => {
      const value = params.get(input.name);
      if (value !== null) input.value = value;
    });
  };

  forms.forEach(paramsToForm);

  const applyForm = (form) => {
    const params = new URLSearchParams(window.location.search);
    ['category', 'brand', 'skin', 'min_price', 'max_price'].forEach((key) => params.delete(key));

    const data = new FormData(form);
    for (const [key, value] of data.entries()) {
      if (String(value).trim()) params.append(key, value);
    }

    if (sort?.value && sort.value !== 'default') params.set('sort', sort.value);
    else params.delete('sort');

    const query = params.toString();
    window.history.replaceState({}, '', `${window.location.pathname}${query ? `?${query}` : ''}`);
    setDrawer(false);
  };

  forms.forEach((form) => {
    form.addEventListener('submit', (event) => {
      event.preventDefault();
      applyForm(form);
    });
  });

  resetButtons.forEach((button) => {
    button.addEventListener('click', () => {
      forms.forEach((form) => form.reset());
      if (sort) sort.value = 'default';
      window.history.replaceState({}, '', window.location.pathname);
    });
  });

  if (sort) {
    const params = new URLSearchParams(window.location.search);
    sort.value = params.get('sort') || 'default';
    sort.addEventListener('change', () => {
      const params = new URLSearchParams(window.location.search);
      if (sort.value === 'default') params.delete('sort');
      else params.set('sort', sort.value);
      const query = params.toString();
      window.history.replaceState({}, '', `${window.location.pathname}${query ? `?${query}` : ''}`);
    });
  }
})();
