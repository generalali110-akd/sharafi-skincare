// ===== Sharafi storefront header v2 =====
(() => {
  const header = document.querySelector('.site-header');
  if (!header) return;

  const menuToggle = header.querySelector('.js-store-mobile-toggle');
  const mobileNav = header.querySelector('.js-mobile-nav-panel');
  const searchToggle = header.querySelector('.js-mobile-search-toggle');
  const searchPanel = header.querySelector('.js-mobile-search-panel');
  const searchClose = header.querySelector('.js-mobile-search-close');
  const dropdown = header.querySelector('.js-nav-dropdown');
  const dropdownToggle = header.querySelector('.js-nav-dropdown-toggle');

  const setExpanded = (button, value) => {
    if (button) button.setAttribute('aria-expanded', String(value));
  };

  const closeMenu = () => {
    mobileNav?.classList.remove('is-open');
    setExpanded(menuToggle, false);
  };

  const closeSearch = () => {
    searchPanel?.classList.remove('is-open');
    setExpanded(searchToggle, false);
  };

  const closeDropdown = () => {
    dropdown?.classList.remove('is-open');
    setExpanded(dropdownToggle, false);
  };

  menuToggle?.addEventListener('click', () => {
    const willOpen = !mobileNav?.classList.contains('is-open');
    closeSearch();
    closeDropdown();
    mobileNav?.classList.toggle('is-open', willOpen);
    setExpanded(menuToggle, willOpen);
  });

  searchToggle?.addEventListener('click', () => {
    const willOpen = !searchPanel?.classList.contains('is-open');
    closeMenu();
    closeDropdown();
    searchPanel?.classList.toggle('is-open', willOpen);
    setExpanded(searchToggle, willOpen);
    if (willOpen) window.setTimeout(() => searchPanel?.querySelector('input')?.focus(), 0);
  });

  searchClose?.addEventListener('click', closeSearch);

  dropdownToggle?.addEventListener('click', (event) => {
    event.stopPropagation();
    const willOpen = !dropdown?.classList.contains('is-open');
    dropdown?.classList.toggle('is-open', willOpen);
    setExpanded(dropdownToggle, willOpen);
  });

  document.addEventListener('click', (event) => {
    if (dropdown && !dropdown.contains(event.target)) closeDropdown();
  });

  document.addEventListener('keydown', (event) => {
    if (event.key !== 'Escape') return;
    closeMenu();
    closeSearch();
    closeDropdown();
  });

  header.querySelectorAll('.js-site-search-form').forEach((form) => {
    form.addEventListener('submit', (event) => {
      event.preventDefault();
      const input = form.querySelector('input[type="search"]');
      const query = input?.value.trim();
      if (!query) {
        input?.focus();
        return;
      }
      window.location.href = `category.html?q=${encodeURIComponent(query)}`;
    });
  });
})();
