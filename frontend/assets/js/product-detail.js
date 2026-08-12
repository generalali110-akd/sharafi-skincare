// ===== Sharafi product detail interactions =====
(() => {
  const product = {
    id: 1,
    name: 'سرم آبرسان اسید هیالورونیک',
    brand: 'سی‌رو',
    price: 485000,
    icon: '🧴',
  };

  const quantityOutput = document.querySelector('.js-product-qty');
  let quantity = 1;

  const renderQuantity = () => {
    if (quantityOutput) quantityOutput.value = quantity;
  };

  document.querySelectorAll('.js-product-minus').forEach((button) => {
    button.addEventListener('click', () => {
      quantity = Math.max(1, quantity - 1);
      renderQuantity();
    });
  });

  document.querySelectorAll('.js-product-plus').forEach((button) => {
    button.addEventListener('click', () => {
      quantity += 1;
      renderQuantity();
    });
  });

  document.querySelectorAll('.js-product-add').forEach((button) => {
    button.addEventListener('click', () => addToCart(product, quantity));
  });

  const mainVisual = document.querySelector('.js-product-main-visual');
  const thumbs = document.querySelectorAll('.js-product-thumb');
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

  const tabs = document.querySelectorAll('.js-product-tab');
  const panels = document.querySelectorAll('.js-product-tab-panel');
  tabs.forEach((tab) => {
    tab.addEventListener('click', () => {
      const target = tab.dataset.tab;
      tabs.forEach((item) => item.setAttribute('aria-selected', String(item === tab)));
      panels.forEach((panel) => {
        panel.hidden = panel.dataset.panel !== target;
      });
    });
  });

  renderQuantity();
})();
