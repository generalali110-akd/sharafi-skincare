// ===== Sharafi product detail interactions =====
(() => {
  const MAX_QTY = 99;
  const product = {
    id: 1,
    name: 'سرم آبرسان اسید هیالورونیک',
    brand: 'سی‌رو',
    price: 485000,
    icon: '🧴',
  };

  const quantityOutput = document.querySelector('.js-product-qty');
  const minusButtons = document.querySelectorAll('.js-product-minus');
  const plusButtons = document.querySelectorAll('.js-product-plus');
  let quantity = 1;

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

  document.querySelectorAll('.js-product-add').forEach((button) => {
    button.addEventListener('click', () => addToCart(product, quantity));
  });

  const mainVisual = document.querySelector('.js-product-main-visual');
  const thumbs = [...document.querySelectorAll('.js-product-thumb')];
  thumbs.forEach((thumb) => {
    thumb.addEventListener('click', () => {
      if (!mainVisual) return;
      mainVisual.textContent = thumb.dataset.visual || '🧴';
      thumbs.forEach((item) => {
        item.classList.remove('is-active');
        item.setAttribute('aria-pressed', 'false');
      });
      thumb.classList.add('is-active');
      thumb.setAttribute('aria-pressed', 'true');
    });
  });

  const tabs = [...document.querySelectorAll('.js-product-tab')];
  const panels = [...document.querySelectorAll('.js-product-tab-panel')];

  const activateTab = (tab, focus = false) => {
    if (!tab) return;
    const target = tab.dataset.tab;

    tabs.forEach((item) => {
      const selected = item === tab;
      item.setAttribute('aria-selected', String(selected));
      item.tabIndex = selected ? 0 : -1;
    });

    panels.forEach((panel) => {
      panel.hidden = panel.dataset.panel !== target;
    });

    if (focus) tab.focus();
  };

  tabs.forEach((tab, index) => {
    tab.addEventListener('click', () => activateTab(tab));
    tab.addEventListener('keydown', (event) => {
      let nextIndex = null;
      if (event.key === 'ArrowLeft') nextIndex = (index + 1) % tabs.length;
      if (event.key === 'ArrowRight') nextIndex = (index - 1 + tabs.length) % tabs.length;
      if (event.key === 'Home') nextIndex = 0;
      if (event.key === 'End') nextIndex = tabs.length - 1;
      if (nextIndex === null) return;

      event.preventDefault();
      activateTab(tabs[nextIndex], true);
    });
  });

  activateTab(tabs.find((tab) => tab.getAttribute('aria-selected') === 'true') || tabs[0]);
  renderQuantity();
})();
