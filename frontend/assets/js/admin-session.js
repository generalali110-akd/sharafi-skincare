// ===== Sharafi Admin session guard =====
(() => {
  const redirectToLogin = () => {
    const target = new URL('login.html', window.location.href);
    window.location.replace(target.href);
  };

  const bindLogout = () => {
    const logoutLink = document.querySelector('.admin-sidebar-footer a[href="login.html"]');
    if (!logoutLink) return;

    logoutLink.addEventListener('click', async (event) => {
      event.preventDefault();
      logoutLink.setAttribute('aria-disabled', 'true');
      try {
        await window.SharafiAPI.auth.logout();
      } catch (error) {
        if (!(error instanceof window.SharafiAPI.ApiError) || error.status !== 401) {
          console.error('Admin logout failed.', error);
        }
      } finally {
        redirectToLogin();
      }
    });
  };

  const applySession = (session) => {
    const data = session?.data;
    if (!data || !Array.isArray(data.permissions)) throw new Error('Invalid admin session payload.');

    document.documentElement.dataset.adminReady = 'true';
    document.documentElement.dataset.adminPermissions = data.permissions.join(' ');

    document.querySelectorAll('.admin-profile strong').forEach((element) => {
      element.textContent = data.name || 'مدیر فروشگاه';
    });
  };

  document.addEventListener('DOMContentLoaded', async () => {
    if (!window.SharafiAPI || !window.SharafiAdminAPI) {
      redirectToLogin();
      return;
    }

    try {
      const session = await window.SharafiAdminAPI.session();
      applySession(session);
      bindLogout();
    } catch (error) {
      if (error instanceof window.SharafiAPI.ApiError && [401, 403].includes(error.status)) {
        if (error.status === 403) {
          try {
            await window.SharafiAPI.auth.logout();
          } catch {
            // The local browser session is invalid for Admin either way; continue to login.
          }
        }
        redirectToLogin();
        return;
      }

      console.error('Unable to validate admin session.', error);
      window.toastAdmin?.('اعتبارسنجی نشست مدیریت انجام نشد. دوباره تلاش کنید.');
    }
  });
})();
