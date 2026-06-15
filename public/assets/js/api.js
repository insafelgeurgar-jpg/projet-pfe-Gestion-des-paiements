/**
 * PaymentModule - API Client & Auth
 * /public/assets/js/api.js
 */

'use strict';

const BASE = '/paymentmodule/public';

// ── Token storage ─────────────────────────────────────────────────────────
const Auth = {
  getToken()        { return sessionStorage.getItem('access_token') || localStorage.getItem('access_token'); },
  getRefreshToken() { return localStorage.getItem('refresh_token'); },
  getUser()         { try { return JSON.parse(localStorage.getItem('user') || 'null'); } catch { return null; } },

  setTokens(data) {
    sessionStorage.setItem('access_token', data.access_token);
    // also store in localStorage for page refresh persistence
    localStorage.setItem('access_token', data.access_token);
    // store in cookie as fallback for AuthMiddleware
    document.cookie = `access_token=${encodeURIComponent(data.access_token)}; path=/; SameSite=Lax`;
    localStorage.setItem('refresh_token', data.refresh_token);
    if (data.user) localStorage.setItem('user', JSON.stringify(data.user));
  },

  clear() {
    sessionStorage.removeItem('access_token');
    localStorage.removeItem('access_token');
    localStorage.removeItem('refresh_token');
    localStorage.removeItem('user');
  },

  isLoggedIn()    { return !!this.getToken(); },
  isAdmin()       { const u = this.getUser(); return u?.role === 'admin'; },

  async requireAuth(redirectTo = BASE + '/login') {
    if (!this.isLoggedIn()) { window.location = redirectTo; return false; }
    return true;
  },

  async requireAdmin() {
    if (!this.isLoggedIn() || !this.isAdmin()) { window.location = BASE + '/login'; return false; }
    return true;
  },
};

// ── API client ────────────────────────────────────────────────────────────
const API = {
  base: BASE + '/api',
  _refreshing: false,
  _refreshQueue: [],

  async request(method, path, body = null, opts = {}) {
    const headers = { 'Content-Type': 'application/json' };
    const token   = Auth.getToken();
    if (token) headers['Authorization'] = `Bearer ${token}`;

    const config = { method, headers, ...opts };
    if (body && method !== 'GET') config.body = JSON.stringify(body);

    let res = await fetch(this.base + path, config);

    if (res.status === 401 && Auth.getRefreshToken()) {
      if (this._refreshing) {
        await new Promise(r => this._refreshQueue.push(r));
        return this.request(method, path, body, opts);
      }
      this._refreshing = true;
      try {
        const refreshRes = await fetch(this.base + '/refresh', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ refresh_token: Auth.getRefreshToken() }),
        });
        if (refreshRes.ok) {
          const data = await refreshRes.json();
          Auth.setTokens(data.data);
          this._refreshQueue.forEach(r => r());
          this._refreshQueue = [];
          res = await fetch(this.base + path, { ...config, headers: { ...headers, Authorization: `Bearer ${data.data.access_token}` } });
        } else {
          Auth.clear();
          window.location = BASE + '/login';
          return;
        }
      } finally {
        this._refreshing = false;
      }
    }

    const json = await res.json().catch(() => ({}));
    if (!res.ok) {
      const err  = new Error(json.message || 'Request failed');
      err.status = res.status;
      err.errors = json.errors || {};
      throw err;
    }
    return json;
  },

  get(path, params = {})  { const q = new URLSearchParams(params).toString(); return this.request('GET', path + (q ? '?' + q : '')); },
  post(path, body)         { return this.request('POST',   path, body); },
  put(path, body)          { return this.request('PUT',    path, body); },
  patch(path, body)        { return this.request('PATCH',  path, body); },
  delete(path)             { return this.request('DELETE', path); },

  login(email, password)          { return this.post('/login', { email, password }); },
  register(name, email, password) { return this.post('/register', { name, email, password }); },
  logout()                        { return this.post('/logout', { refresh_token: Auth.getRefreshToken() }); },
  me()                            { return this.get('/me'); },
  plans()                         { return this.get('/plans'); },
  payments(params)                { return this.get('/payments', params); },
  invoices(params)                { return this.get('/invoices', params); },
  subscription()                  { return this.get('/subscription'); },

  checkout(data)                  { return this.post('/checkout', data); },
  verifyCheckout(data)            { return this.post('/checkout/verify', data); },
  cancelSubscription(immediately) { return this.post('/subscription/cancel', { immediately }); },
  refundPayment(uuid, amount, reason) { return this.post(`/payments/${uuid}/refund`, { amount, reason }); },

  adminDashboard()                { return this.get('/admin/dashboard'); },
  adminTransactions(p)            { return this.get('/admin/transactions', p); },
  adminUsers(p)                   { return this.get('/admin/users', p); },
  adminSubscriptions(p)           { return this.get('/admin/subscriptions', p); },
  adminLogs(p)                    { return this.get('/admin/logs', p); },
  adminRefund(txnUuid, amount, reason) { return this.post('/admin/refund', { transaction_uuid: txnUuid, amount, reason }); },
  adminCreateCoupon(data)         { return this.post('/admin/coupons', data); },
  adminUpdateUser(uuid, data)     { return this.put(`/admin/users/${uuid}`, data); },
  adminCreatePlan(data)           { return this.post('/admin/plans', data); },
  adminUpdatePlan(uuid, data)     { return this.put(`/admin/plans/${uuid}`, data); },
  adminDeletePlan(uuid)           { return this.delete(`/admin/plans/${uuid}`); },
};

// ── UI Utilities ──────────────────────────────────────────────────────────
const UI = {
  toast(message, type = 'info', duration = 4000) {
    const container = document.getElementById('toast-container') || (() => {
      const el = document.createElement('div');
      el.id = 'toast-container';
      el.style.cssText = 'position:fixed;top:20px;right:20px;z-index:9999;display:flex;flex-direction:column;gap:8px;';
      document.body.appendChild(el);
      return el;
    })();
    const colors = { success:'#22c55e', danger:'#ef4444', warning:'#f59e0b', info:'#6366f1' };
    const icons  = { success:'✓', danger:'✗', warning:'⚠', info:'ℹ' };
    const toast  = document.createElement('div');
    toast.style.cssText = `background:#fff;border-left:4px solid ${colors[type]||colors.info};border-radius:8px;padding:14px 18px;box-shadow:0 4px 16px rgba(0,0,0,.12);display:flex;align-items:center;gap:10px;min-width:280px;max-width:400px;animation:slideIn .3s ease;font-size:.9rem;`;
    toast.innerHTML = `<span style="color:${colors[type]};font-weight:700">${icons[type]||'ℹ'}</span><span>${message}</span>`;
    container.appendChild(toast);
    setTimeout(() => { toast.style.opacity='0'; toast.style.transform='translateX(100%)'; toast.style.transition='.3s'; setTimeout(() => toast.remove(), 300); }, duration);
  },

  loading(btn, state) {
    if (state) {
      btn.dataset.original = btn.innerHTML;
      btn.disabled = true;
      btn.innerHTML = `<span class="spinner"></span> ${btn.dataset.loadingText || 'Loading...'}`;
    } else {
      btn.disabled = false;
      btn.innerHTML = btn.dataset.original || btn.innerHTML;
    }
  },

  showErrors(form, errors) {
    form.querySelectorAll('.is-invalid').forEach(el => el.classList.remove('is-invalid'));
    form.querySelectorAll('.invalid-feedback').forEach(el => el.textContent = '');
    Object.entries(errors).forEach(([field, msg]) => {
      const input = form.querySelector(`[name="${field}"]`);
      if (input) {
        input.classList.add('is-invalid');
        const fb = input.parentElement.querySelector('.invalid-feedback');
        if (fb) fb.textContent = msg;
      }
    });
  },

  clearErrors(form) { form?.querySelectorAll('.is-invalid').forEach(el => el.classList.remove('is-invalid')); },

  formatMoney(amount, currency = 'USD') {
    return new Intl.NumberFormat('en-US', { style: 'currency', currency }).format(amount);
  },

  formatDate(dateStr) {
    return new Date(dateStr).toLocaleDateString('en-US', { year:'numeric', month:'short', day:'numeric' });
  },

  formatDateTime(dateStr) {
    return new Date(dateStr).toLocaleString('en-US', { year:'numeric', month:'short', day:'numeric', hour:'2-digit', minute:'2-digit' });
  },

  statusBadge(status) {
    const map = {
      completed:'success', active:'success', trialing:'info',
      pending:'warning', processing:'warning', past_due:'warning',
      failed:'danger', cancelled:'gray', expired:'gray',
      refunded:'info', partially_refunded:'warning',
    };
    return `<span class="badge badge-${map[status]||'gray'}">${status.replace(/_/g,' ')}</span>`;
  },

  modal: {
    open(id)  { document.getElementById(id)?.classList.add('open'); },
    close(id) { document.getElementById(id)?.classList.remove('open'); },
    closeAll(){ document.querySelectorAll('.modal-overlay.open').forEach(m => m.classList.remove('open')); },
  },

  paginate(total, page, limit, callback) {
    const pages = Math.ceil(total / limit);
    if (pages <= 1) return '';
    let html = '<div class="pagination" style="display:flex;gap:8px;align-items:center;justify-content:center;margin-top:24px;">';
    if (page > 1) html += `<button class="btn btn-outline btn-sm" onclick="(${callback})(${page-1})">← Prev</button>`;
    html += `<span class="text-muted" style="font-size:.85rem">Page ${page} of ${pages}</span>`;
    if (page < pages) html += `<button class="btn btn-outline btn-sm" onclick="(${callback})(${page+1})">Next →</button>`;
    html += '</div>';
    return html;
  },
};

// ── Navbar ─────────────────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
  const user    = Auth.getUser();
  const navAuth = document.getElementById('nav-auth');
  if (!navAuth) return;

  if (user) {
    navAuth.innerHTML = `
      <a href="${user.role === 'admin' ? BASE+'/admin' : BASE+'/dashboard'}" class="btn btn-outline btn-sm">Dashboard</a>
      <button class="btn btn-ghost btn-sm" id="logout-btn">${user.name}</button>
    `;
    document.getElementById('logout-btn')?.addEventListener('click', async () => {
      try { await API.logout(); } catch {}
      Auth.clear();
      window.location = BASE + '/pricing';
    });
  } else {
    navAuth.innerHTML = `
      <a href="${BASE}/login" class="btn btn-ghost btn-sm">Login</a>
      <a href="${BASE}/pricing" class="btn btn-primary btn-sm">Get Started</a>
    `;
  }
});