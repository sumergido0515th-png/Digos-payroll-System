/* ============================================================================
   app.js - SPA core for the PHP edition: fetch-based server bridge, router,
   session handling and shared widgets. Page modules register themselves on
   window.Pages from the view partials (which load after this file).
   ========================================================================== */

window.Pages = window.Pages || {};
var App = {
  session: null,        // apiGetSession payload
  lookups: null,        // apiGetLookups payload (dropdown data)
  current: '',          // active page name
  editorDirty: false    // full-page editors (payroll grid) have unsaved edits
};

/* Unsaved-changes guard state for the shared modal (wired up at boot). */
var modalDirty = false;   // the open modal form has been edited

/* ---- server bridge ------------------------------------------------------ */

/**
 * Calls a server endpoint (api.php) and resolves with its data payload.
 * Rejects (with a toast already shown) when the envelope reports failure.
 * @param {string} fn API action name, e.g. 'apiListEmployees'.
 * @param {Object=} payload Request payload.
 * @param {boolean=} silent Suppress the error toast.
 * @return {Promise<*>}
 */
function api(fn, payload, silent) {
  return fetch('api.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    credentials: 'same-origin',
    body: JSON.stringify({ action: fn, payload: payload || {} })
  }).then(function (r) { return r.json(); }).then(function (env) {
    if (env && env.ok) return env.data;
    var msg = (env && env.message) || 'Unexpected server response.';
    if (!silent) toast(msg, 'danger');
    if (/session expired|not signed in/i.test(msg)) {
      setTimeout(function () { location.href = 'login.php'; }, 1800);
    }
    throw new Error(msg);
  }, function (err) {
    if (!silent) toast('Network error: ' + err.message, 'danger');
    throw err;
  });
}

/** Shows the full-screen spinner while a promise settles. */
function busy(promise) {
  var el = document.getElementById('spinner');
  el.classList.add('show');
  return promise.finally(function () { el.classList.remove('show'); });
}

/* ---- formatting helpers ------------------------------------------------- */

/** Escapes text for HTML interpolation. */
function esc(s) {
  return String(s === null || s === undefined ? '' : s)
    .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
}

/** Formats money with separators + 2 decimals. */
function fmtMoney(n) {
  var v = Number(n) || 0;
  return v.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

/** Formats a date(-time) string as MM/DD/YYYY. */
function fmtDate(s) {
  if (!s) return '';
  var d = new Date(String(s).replace(' ', 'T'));
  return isNaN(d) ? String(s) : d.toLocaleDateString('en-PH');
}

/** Renders a coloured status badge. */
function badge(status) {
  return '<span class="badge-status st-' + esc(status) + '">' + esc(status) + '</span>';
}

/** Debounce helper for live-search boxes. */
function debounce(fn, ms) {
  var t;
  return function () {
    var args = arguments, self = this;
    clearTimeout(t);
    t = setTimeout(function () { fn.apply(self, args); }, ms || 300);
  };
}

/** Reads a form's fields (by name attribute) into an object. */
function formData(root) {
  var out = {};
  root.querySelectorAll('[name]').forEach(function (el) {
    out[el.name] = el.type === 'checkbox' ? el.checked : el.value;
  });
  return out;
}

/* ---- toasts, modal, confirm --------------------------------------------- */

/** Shows a Bootstrap toast. type: success | danger | warning | info */
function toast(message, type) {
  var host = document.getElementById('toast-host');
  var el = document.createElement('div');
  el.className = 'toast align-items-center text-bg-' + (type || 'success') + ' border-0 mb-2';
  el.innerHTML = '<div class="d-flex"><div class="toast-body">' + esc(message) +
    '</div><button class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button></div>';
  host.appendChild(el);
  var t = new bootstrap.Toast(el, { delay: type === 'danger' ? 7000 : 3500 });
  t.show();
  el.addEventListener('hidden.bs.toast', function () { el.remove(); });
}

/**
 * Opens the shared modal.
 * @param {string} title Modal title.
 * @param {string} bodyHtml Body markup.
 * @param {Array<{label,cls,onclick}>} buttons Footer buttons.
 */
function openModal(title, bodyHtml, buttons) {
  document.getElementById('app-modal-title').textContent = title;
  document.getElementById('app-modal-body').innerHTML = bodyHtml;
  modalDirty = false;                       // fresh form, nothing to lose yet
  var foot = document.getElementById('app-modal-footer');
  foot.innerHTML = '';
  (buttons || []).forEach(function (b) {
    var btn = document.createElement('button');
    btn.className = 'btn btn-sm ' + (b.cls || 'btn-gov');
    btn.textContent = b.label;
    btn.onclick = b.onclick;
    foot.appendChild(btn);
  });
  var m = bootstrap.Modal.getOrCreateInstance(document.getElementById('app-modal'));
  m.show();
  return m;
}

/**
 * Closes the modal. When the form has been edited this goes through the
 * unsaved-changes confirmation (see the hide.bs.modal guard), so Cancel,
 * Esc, X and backdrop clicks all behave the same.
 */
function closeModal() {
  var m = bootstrap.Modal.getInstance(document.getElementById('app-modal'));
  if (m) m.hide();
}

/** Closes the modal after a successful save - input is safe, never prompts. */
function closeModalSaved() {
  modalDirty = false;
  closeModal();
}

/** Confirmation dialog; runs onYes when confirmed. */
function confirmDlg(message, onYes) {
  openModal('Please confirm', '<p class="mb-0">' + esc(message) + '</p>', [
    { label: 'Cancel', cls: 'btn-outline-secondary', onclick: closeModal },
    { label: 'Confirm', cls: 'btn-danger', onclick: function () { closeModal(); onYes(); } }
  ]);
}

/* ---- permissions & router ----------------------------------------------- */

/** True when the signed-in user holds a permission. */
function can(perm) {
  if (!App.session) return false;
  var p = App.session.permissions || [];
  return p.indexOf('*') >= 0 || p.indexOf(perm) >= 0;
}

/** Page name -> topbar title. */
var PAGE_TITLES = {
  dashboard: 'Dashboard', employees: 'Employees', payroll: 'Payroll Transactions',
  timekeepers: 'Timekeepers', departments: 'Departments & Offices',
  periods: 'Payroll Period', reports: 'Payroll Reports', print: 'Print Payroll',
  users: 'User Management', logs: 'Audit Logs', settings: 'Settings',
  backup: 'Backup & Restore'
};

/** Activates a page and runs its module's init(). */
function showPage(name) {
  // Navigating away from a dirty full-page editor (payroll grid) confirms first.
  if (App.editorDirty &&
      !window.confirm('You have unsaved changes. Leave this page and discard them?')) {
    return;
  }
  App.editorDirty = false;
  App.current = name;
  document.querySelectorAll('.page').forEach(function (p) {
    p.classList.toggle('active', p.id === 'page-' + name);
  });
  document.querySelectorAll('.nav-item-link[data-page]').forEach(function (a) {
    a.classList.toggle('active', a.dataset.page === name);
  });
  document.getElementById('page-title').textContent = PAGE_TITLES[name] || name;
  document.body.classList.remove('sidebar-open');
  if (Pages[name] && Pages[name].init) Pages[name].init();
}

/** Refreshes the shared dropdown data (offices, periods, ...). */
function loadLookups() {
  return api('apiGetLookups').then(function (d) { App.lookups = d; return d; });
}

/** Builds <option> markup from lookup rows. */
function options(rows, valueKey, labelKey, selected, blankLabel) {
  var html = blankLabel !== undefined ?
    '<option value="">' + esc(blankLabel) + '</option>' : '';
  (rows || []).forEach(function (r) {
    var v = valueKey ? r[valueKey] : r;
    var l = labelKey ? r[labelKey] : r;
    html += '<option value="' + esc(v) + '"' + (String(v) === String(selected) ? ' selected' : '') +
      '>' + esc(l) + '</option>';
  });
  return html;
}

/* ---- boot --------------------------------------------------------------- */

document.addEventListener('DOMContentLoaded', function () {
  // Unsaved-changes guard: an accidental dismiss (X, Esc, backdrop click)
  // of an edited modal form asks for confirmation instead of losing input.
  var modalEl = document.getElementById('app-modal');
  ['input', 'change'].forEach(function (ev) {
    document.getElementById('app-modal-body').addEventListener(ev, function () {
      modalDirty = true;
    });
  });
  modalEl.addEventListener('hide.bs.modal', function (e) {
    if (modalDirty &&
        !window.confirm('You have unsaved changes. Close and discard them?')) {
      e.preventDefault();
      return;
    }
    modalDirty = false;
  });

  // Closing/reloading the tab with an edited form also warns first.
  window.addEventListener('beforeunload', function (e) {
    if (modalDirty || App.editorDirty) {
      e.preventDefault();
      e.returnValue = '';
    }
  });

  // Theme (persisted locally per device). data-bs-theme has to move with
  // data-theme: it is what repoints Bootstrap's own body/muted/border colours,
  // and without it the framework keeps painting near-black text on our dark
  // surfaces. Pages that draw their own text (charts) listen for themechange.
  function applyTheme(t) {
    document.documentElement.setAttribute('data-theme', t);
    document.documentElement.setAttribute('data-bs-theme', t);
    window.dispatchEvent(new CustomEvent('themechange', { detail: t }));
  }
  App.applyTheme = applyTheme;
  applyTheme(localStorage.getItem('dcpms-theme') || 'light');
  document.getElementById('btn-theme').onclick = function () {
    var next = document.documentElement.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
    applyTheme(next);
    localStorage.setItem('dcpms-theme', next);
  };

  // Sidebar toggle (desktop collapse / mobile drawer).
  document.getElementById('btn-sidebar').onclick = function () {
    if (window.innerWidth <= 992) document.body.classList.toggle('sidebar-open');
    else document.body.classList.toggle('sidebar-hidden');
  };

  // Sidebar navigation.
  document.querySelectorAll('.nav-item-link[data-page]').forEach(function (a) {
    a.onclick = function () { showPage(a.dataset.page); };
  });

  // Logout.
  document.getElementById('btn-logout').onclick = function () {
    location.href = 'logout.php';
  };

  // Session bootstrap.
  busy(api('apiGetSession')).then(function (s) {
    App.session = s;
    document.getElementById('user-name').textContent = s.fullName;
    document.getElementById('user-email').textContent = s.email;
    document.getElementById('user-role').textContent = s.role;
    if (s.settings && s.settings.governmentName) {
      document.getElementById('brand-name').textContent = s.settings.governmentName;
    }
    if (s.settings && s.settings.theme === 'dark' && !localStorage.getItem('dcpms-theme')) {
      applyTheme('dark');
    }

    // Hide menu items the role cannot use.
    document.querySelectorAll('.nav-item-link[data-perm]').forEach(function (a) {
      if (!can(a.dataset.perm)) a.style.display = 'none';
    });

    // Heartbeat keeps the idle timeout honest while the tab is open.
    var mins = (s.settings && s.settings.sessionTimeoutMinutes) || 30;
    setInterval(function () { api('apiHeartbeat', {}, true).catch(function () { }); },
      Math.min(mins, 10) * 60000 / 2);

    return loadLookups();
  }).then(function () {
    showPage('dashboard');
  }).catch(function () { /* toast/redirect already handled */ });
});
