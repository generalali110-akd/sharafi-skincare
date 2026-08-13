// ===== Sharafi Admin API contract =====
(() => {
  const api = window.SharafiAPI;
  if (!api) throw new Error('SharafiAPI must be loaded before admin-api.js');

  const queryString = (params = {}) => {
    const query = new URLSearchParams();
    Object.entries(params).forEach(([key, value]) => {
      if (value === undefined || value === null || value === '') return;
      query.set(key, String(value));
    });
    const value = query.toString();
    return value ? `?${value}` : '';
  };

  let sessionPromise = null;

  const session = async (force = false) => {
    if (force) sessionPromise = null;
    if (!sessionPromise) {
      sessionPromise = api.request('/admin/session')
        .then((payload) => payload?.data || null)
        .catch((error) => {
          sessionPromise = null;
          throw error;
        });
    }
    return sessionPromise;
  };

  const clearSession = () => {
    sessionPromise = null;
  };

  window.SharafiAdminAPI = Object.freeze({
    session,
    clearSession,
    dashboard: () => api.request('/admin/dashboard'),
    products: Object.freeze({
      list: (params = {}) => api.request(`/admin/catalog/products${queryString(params)}`),
      show: (id) => api.request(`/admin/catalog/products/${encodeURIComponent(id)}`),
      create: (data) => api.request('/admin/catalog/products', { method: 'POST', json: data }),
      update: (id, data) => api.request(`/admin/catalog/products/${encodeURIComponent(id)}`, { method: 'PATCH', json: data }),
      createVariant: (productId, data) => api.request(`/admin/catalog/products/${encodeURIComponent(productId)}/variants`, { method: 'POST', json: data }),
      updateVariant: (variantId, data) => api.request(`/admin/catalog/variants/${encodeURIComponent(variantId)}`, { method: 'PATCH', json: data }),
    }),
    inventory: Object.freeze({
      list: (params = {}) => api.request(`/admin/inventory${queryString(params)}`),
      adjust: (variantId, data) => api.request(`/admin/inventory/${encodeURIComponent(variantId)}/adjust`, { method: 'POST', json: data }),
      settings: (variantId, data) => api.request(`/admin/inventory/${encodeURIComponent(variantId)}/settings`, { method: 'PATCH', json: data }),
    }),
    orders: Object.freeze({
      list: (params = {}) => api.request(`/admin/orders${queryString(params)}`),
      show: (orderNumber) => api.request(`/admin/orders/${encodeURIComponent(orderNumber)}`),
      updateStatus: (orderNumber, data) => api.request(`/admin/orders/${encodeURIComponent(orderNumber)}/status`, { method: 'PATCH', json: data }),
    }),
    customers: Object.freeze({
      list: (params = {}) => api.request(`/admin/customers${queryString(params)}`),
    }),
    discounts: Object.freeze({
      list: (params = {}) => api.request(`/admin/discounts${queryString(params)}`),
      create: (data) => api.request('/admin/discounts', { method: 'POST', json: data }),
      update: (id, data) => api.request(`/admin/discounts/${encodeURIComponent(id)}`, { method: 'PATCH', json: data }),
    }),
    audit: Object.freeze({
      list: (params = {}) => api.request(`/admin/audit-logs${queryString(params)}`),
    }),
    taxonomy: Object.freeze({
      categories: () => api.catalog.categories(),
      brands: () => api.catalog.brands(),
    }),
  });
})();
