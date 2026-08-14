// ===== Sharafi secure API client =====
(() => {
  const metaBase = document.querySelector('meta[name="sharafi-api-base"]')?.content;
  const apiBase = String(window.SHARAFI_API_BASE || metaBase || '/api/v1').replace(/\/+$/, '');
  const apiUrl = new URL(apiBase, window.location.origin);
  const backendRoot = `${apiUrl.origin}${apiUrl.pathname.replace(/\/api\/v1\/?$/, '')}`.replace(/\/+$/, '');
  const DEFAULT_TIMEOUT_MS = 12000;
  const paymentKeyMemory = new Map();
  let csrfPromise = null;
  let currentUserPromise = null;

  class ApiError extends Error {
    constructor(status, payload = null, fallbackMessage = 'در ارتباط با سرور مشکلی پیش آمد.') {
      const message = ApiError.messageFrom(status, payload, fallbackMessage);
      super(message);
      this.name = 'ApiError';
      this.status = status;
      this.payload = payload;
    }

    static messageFrom(status, payload, fallbackMessage) {
      if (status >= 500) return status === 503 ? 'سرویس موردنظر موقتاً در دسترس نیست.' : 'خطای موقت سرور رخ داد. لطفاً دوباره تلاش کنید.';
      if (payload?.message && typeof payload.message === 'string') return payload.message;
      const errors = payload?.errors;
      if (errors && typeof errors === 'object') {
        for (const value of Object.values(errors)) {
          if (Array.isArray(value) && value[0]) return String(value[0]);
        }
      }
      return fallbackMessage;
    }
  }

  const cookie = (name) => document.cookie
    .split('; ')
    .find((row) => row.startsWith(`${name}=`))
    ?.slice(name.length + 1);

  const xsrfToken = () => {
    const value = cookie('XSRF-TOKEN');
    if (!value) return null;
    try {
      return decodeURIComponent(value);
    } catch {
      return value;
    }
  };

  const ensureCsrf = async (force = false) => {
    if (force) csrfPromise = null;
    if (!csrfPromise) {
      csrfPromise = fetch(`${backendRoot}/sanctum/csrf-cookie`, {
        method: 'GET',
        credentials: 'include',
        headers: {
          Accept: 'application/json',
          'X-Requested-With': 'XMLHttpRequest',
        },
      }).then((response) => {
        if (!response.ok && response.status !== 204) throw new ApiError(response.status, null, 'دریافت نشست امن ناموفق بود.');
      }).catch((error) => {
        csrfPromise = null;
        throw error;
      });
    }
    return csrfPromise;
  };

  const parsePayload = async (response) => {
    if (response.status === 204) return null;
    const type = response.headers.get('content-type') || '';
    if (!type.includes('application/json')) return null;
    try {
      return await response.json();
    } catch {
      return null;
    }
  };

  const request = async (path, options = {}, retriedAfterCsrf = false) => {
    const method = String(options.method || 'GET').toUpperCase();
    const mutating = !['GET', 'HEAD', 'OPTIONS'].includes(method);
    if (mutating && options.csrf !== false) await ensureCsrf();

    const controller = new AbortController();
    const timeoutMs = Number(options.timeoutMs) > 0 ? Number(options.timeoutMs) : DEFAULT_TIMEOUT_MS;
    const timer = window.setTimeout(() => controller.abort(), timeoutMs);
    const headers = new Headers(options.headers || {});
    headers.set('Accept', 'application/json');
    headers.set('X-Requested-With', 'XMLHttpRequest');

    const token = xsrfToken();
    if (mutating && token) headers.set('X-XSRF-TOKEN', token);

    let body = options.body;
    if (options.json !== undefined) {
      headers.set('Content-Type', 'application/json');
      body = JSON.stringify(options.json);
    }

    let response;
    try {
      response = await fetch(`${apiBase}${path.startsWith('/') ? path : `/${path}`}`, {
        method,
        credentials: 'include',
        headers,
        body,
        signal: controller.signal,
      });
    } catch (error) {
      if (error?.name === 'AbortError') throw new ApiError(0, null, 'ارتباط با سرور بیش از حد طول کشید. دوباره تلاش کنید.');
      throw new ApiError(0, null, 'ارتباط با سرور برقرار نشد. اتصال اینترنت را بررسی کنید.');
    } finally {
      window.clearTimeout(timer);
    }

    const payload = await parsePayload(response);

    if (response.status === 419 && mutating && !retriedAfterCsrf) {
      await ensureCsrf(true);
      return request(path, options, true);
    }

    if (!response.ok) throw new ApiError(response.status, payload);
    return payload;
  };

  const queryString = (params = {}) => {
    const query = new URLSearchParams();
    Object.entries(params).forEach(([key, value]) => {
      if (value === undefined || value === null || value === '') return;
      query.set(key, String(value));
    });
    const value = query.toString();
    return value ? `?${value}` : '';
  };

  const currentUser = async (force = false) => {
    if (force) currentUserPromise = null;
    if (!currentUserPromise) {
      currentUserPromise = request('/me')
        .then((payload) => payload?.data || null)
        .catch((error) => {
          if (error instanceof ApiError && error.status === 401) return null;
          throw error;
        });
    }
    return currentUserPromise;
  };

  const clearSessionCache = () => {
    currentUserPromise = null;
  };

  const idempotencyKey = (prefix = 'web') => {
    const random = window.crypto?.randomUUID?.() || `${Date.now()}-${Math.random().toString(36).slice(2)}-${Math.random().toString(36).slice(2)}`;
    return `${prefix}:${random}`.replace(/[^A-Za-z0-9._:-]/g, '').slice(0, 100);
  };

  const retireOrderIdempotencyKey = (key) => {
    try {
      const stored = JSON.parse(sessionStorage.getItem('sharafi:order-key') || 'null');
      if (stored?.key === key) sessionStorage.removeItem('sharafi:order-key');
    } catch {
      // Session storage is optional; server-side idempotency remains authoritative.
    }
  };

  const createOrder = async (data, key) => {
    const payload = await request('/orders', {
      method: 'POST',
      headers: { 'Idempotency-Key': key },
      json: data,
    });

    if (payload?.data?.order_number) retireOrderIdempotencyKey(key);
    return payload;
  };

  const paymentStorageKey = (orderNumber) => `sharafi:payment-idempotency:${orderNumber}`;

  const paymentIdempotencyKey = (orderNumber, rotate = false) => {
    const storageKey = paymentStorageKey(orderNumber);
    if (!rotate) {
      try {
        const stored = sessionStorage.getItem(storageKey);
        if (stored) return stored;
      } catch {
        const memory = paymentKeyMemory.get(orderNumber);
        if (memory) return memory;
      }
    }

    const key = idempotencyKey('payment');
    paymentKeyMemory.set(orderNumber, key);
    try {
      sessionStorage.setItem(storageKey, key);
    } catch {
      // In-memory fallback keeps idempotency within the current page lifecycle.
    }
    return key;
  };

  const initiatePayment = async (orderNumber, recovered = false) => {
    const key = paymentIdempotencyKey(orderNumber, false);
    try {
      return await request(`/orders/${encodeURIComponent(orderNumber)}/payment-attempts`, {
        method: 'POST',
        headers: { 'Idempotency-Key': key },
        json: {},
      });
    } catch (error) {
      const retryRequired = error instanceof ApiError
        && error.status === 503
        && error.payload?.code === 'payment_attempt_retry_required';
      const initiationUnavailable = error instanceof ApiError
        && error.status === 503
        && error.payload?.code === 'payment_unavailable';

      if (!recovered && retryRequired) {
        paymentIdempotencyKey(orderNumber, true);
        return initiatePayment(orderNumber, true);
      }

      if (initiationUnavailable) {
        // A failed or unknown initiation must not pin the next explicit user retry
        // to the same attempt. The next click gets a fresh server-side idempotency key.
        paymentIdempotencyKey(orderNumber, true);
      }

      throw error;
    }
  };

  const safeReturnTarget = (raw, fallback = 'account.html') => {
    if (!raw) return fallback;
    try {
      const target = new URL(raw, window.location.href);
      if (target.origin !== window.location.origin) return fallback;
      return `${target.pathname}${target.search}${target.hash}`;
    } catch {
      return fallback;
    }
  };

  const toman = (irr) => {
    const value = Number(irr);
    return Number.isSafeInteger(value) ? Math.round(value / 10) : 0;
  };

  const formatIrr = (irr) => `${toman(irr).toLocaleString('fa-IR')} تومان`;

  window.SharafiAPI = Object.freeze({
    ApiError,
    apiBase,
    backendRoot,
    ensureCsrf,
    request,
    currentUser,
    clearSessionCache,
    idempotencyKey,
    safeReturnTarget,
    toman,
    formatIrr,
    storefront: Object.freeze({
      config: () => request('/storefront/config'),
    }),
    auth: Object.freeze({
      requestOtp: (mobile, name = null) => request('/auth/otp/request', { method: 'POST', json: { mobile, ...(name ? { name } : {}) } }),
      verifyOtp: (challengeId, code) => request('/auth/otp/verify', { method: 'POST', json: { challenge_id: challengeId, code } }),
      logout: () => request('/auth/logout', { method: 'POST' }).finally(clearSessionCache),
    }),
    catalog: Object.freeze({
      products: (params = {}) => request(`/catalog/products${queryString(params)}`),
      product: (slug) => request(`/catalog/products/${encodeURIComponent(slug)}`),
      categories: () => request('/catalog/categories'),
      brands: () => request('/catalog/brands'),
    }),
    addresses: Object.freeze({
      list: () => request('/addresses'),
      create: (data) => request('/addresses', { method: 'POST', json: data }),
      update: (id, data) => request(`/addresses/${encodeURIComponent(id)}`, { method: 'PATCH', json: data }),
      remove: (id) => request(`/addresses/${encodeURIComponent(id)}`, { method: 'DELETE' }),
    }),
    cart: Object.freeze({
      get: () => request('/cart'),
      set: (variantId, quantity) => request(`/cart/items/${encodeURIComponent(variantId)}`, { method: 'PUT', json: { quantity } }),
      remove: (variantId) => request(`/cart/items/${encodeURIComponent(variantId)}`, { method: 'DELETE' }),
    }),
    checkout: Object.freeze({
      quote: (shippingMethod, couponCode = null) => request('/checkout/quote', {
        method: 'POST',
        json: { shipping_method: shippingMethod, ...(couponCode ? { coupon_code: couponCode } : {}) },
      }),
    }),
    orders: Object.freeze({
      list: (page = 1) => request(`/orders?page=${encodeURIComponent(page)}`),
      show: (orderNumber) => request(`/orders/${encodeURIComponent(orderNumber)}`),
      create: createOrder,
      cancel: (orderNumber) => request(`/orders/${encodeURIComponent(orderNumber)}/cancel`, { method: 'POST' }),
    }),
    payments: Object.freeze({
      show: (orderNumber) => request(`/orders/${encodeURIComponent(orderNumber)}/payment`),
      initiate: (orderNumber) => initiatePayment(orderNumber),
    }),
  });
})();
