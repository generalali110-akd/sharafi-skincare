// ===== Sharafi Admin API facade =====
(() => {
  const api = window.SharafiAPI;
  if (!api) throw new Error('SharafiAPI must be loaded before admin-api.js.');

  const queryString = (params = {}) => {
    const query = new URLSearchParams();
    Object.entries(params).forEach(([key, value]) => {
      if (value === undefined || value === null || value === '') return;
      query.set(key, String(value));
    });
    const value = query.toString();
    return value ? `?${value}` : '';
  };

  const request = (path, options) => api.request(`/admin${path}`, options);

  window.SharafiAdminAPI = Object.freeze({
    session: () => request('/session'),
    products: Object.freeze({
      list: (params = {}) => request(`/catalog/products${queryString(params)}`),
      create: (data) => request('/catalog/products', { method: 'POST', json: data }),
      update: (productId, data) => request(`/catalog/products/${encodeURIComponent(productId)}`, { method: 'PATCH', json: data }),
      createVariant: (productId, data) => request(`/catalog/products/${encodeURIComponent(productId)}/variants`, { method: 'POST', json: data }),
      updateVariant: (variantId, data) => request(`/catalog/variants/${encodeURIComponent(variantId)}`, { method: 'PATCH', json: data }),
    }),
    taxonomy: Object.freeze({
      createBrand: (data) => request('/catalog/brands', { method: 'POST', json: data }),
      updateBrand: (brandId, data) => request(`/catalog/brands/${encodeURIComponent(brandId)}`, { method: 'PATCH', json: data }),
      createCategory: (data) => request('/catalog/categories', { method: 'POST', json: data }),
      updateCategory: (categoryId, data) => request(`/catalog/categories/${encodeURIComponent(categoryId)}`, { method: 'PATCH', json: data }),
    }),
    inventory: Object.freeze({
      list: (params = {}) => request(`/inventory${queryString(params)}`),
      adjust: (variantId, data) => request(`/inventory/${encodeURIComponent(variantId)}/adjust`, { method: 'POST', json: data }),
      updateSettings: (variantId, data) => request(`/inventory/${encodeURIComponent(variantId)}/settings`, { method: 'PATCH', json: data }),
    }),
    orders: Object.freeze({
      list: (params = {}) => request(`/orders${queryString(params)}`),
      show: (orderNumber) => request(`/orders/${encodeURIComponent(orderNumber)}`),
      updateStatus: (orderNumber, data) => request(`/orders/${encodeURIComponent(orderNumber)}/status`, { method: 'PATCH', json: data }),
    }),
    discounts: Object.freeze({
      list: (params = {}) => request(`/discounts${queryString(params)}`),
      create: (data) => request('/discounts', { method: 'POST', json: data }),
      update: (discountId, data) => request(`/discounts/${encodeURIComponent(discountId)}`, { method: 'PATCH', json: data }),
    }),
    audit: Object.freeze({
      list: (params = {}) => request(`/audit-logs${queryString(params)}`),
    }),
  });
})();
