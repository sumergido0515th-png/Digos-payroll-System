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
  documents: 'Authority Documents', dtr: 'Daily Time Records', coverage: 'Coverage & Attachments', preaudit: 'Pre-Audit Worklist',
  timekeepers: 'Timekeepers', departments: 'Departments & Offices',
  periods: 'Payroll Period', reports: 'Payroll Reports', print: 'Print Payroll',
  users: 'User Management', logs: 'Audit Logs', settings: 'Settings',
  import: 'Import Data', backup: 'Backup & Restore'
};

/**
 * Pages that show the office watermark behind their content. The working
 * screens are left clean on purpose: a payroll grid is dense enough without a
 * seal behind it, and this is the list to add to if that changes.
 */
var WATERMARK_PAGES = ['dashboard'];

/**
 * Applies the uploaded branding to the shell: the logo into the sidebar seal,
 * and the one uploaded watermark photo into every surface that echoes it -
 * the watermark layer itself, a faint wash at the foot of the sidebar, and
 * the dashboard's hero banner. All three read the same custom property
 * pattern (app.css applies each surface's own opacity/fade) so one upload
 * lights up everywhere consistently instead of needing a separate asset per
 * surface.
 *
 * Both settings are optional and default to empty, so an installation that
 * has uploaded neither keeps the placeholder icon and a plain background.
 */
function applyBranding(settings) {
  settings = settings || {};

  if (settings.logoUrl) {
    var seal = document.getElementById('brand-seal');
    // has-logo swaps the placeholder icon's circle for a larger square frame -
    // see the note in app.css.
    seal.classList.add('has-logo');
    seal.innerHTML = '<img src="' + esc(settings.logoUrl) + '" alt="Office logo">';
  }

  var url = settings.watermarkUrl ? 'url("' + encodeURI(settings.watermarkUrl) + '")' : 'none';

  document.getElementById('watermark').style.setProperty('--watermark-url', url);
  // The server clamps this to a range that keeps page text readable; the
  // fallback matches WatermarkOpacity's seeded default.
  document.body.style.setProperty('--watermark-opacity',
    String(settings.watermarkOpacity || 0.2));

  var sidebar = document.getElementById('sidebar');
  sidebar.classList.toggle('has-photo', !!settings.watermarkUrl);
  sidebar.style.setProperty('--sidebar-photo-url', url);

  var hero = document.getElementById('dash-hero');
  if (hero) {
    hero.classList.toggle('has-photo', !!settings.watermarkUrl);
    hero.style.setProperty('--dash-photo-url', url);
  }
}

/** Activates a page and runs its module's init(params). */
function showPage(name, params) {
  // Navigating away from a dirty full-page editor (payroll grid) confirms first.
  if (App.editorDirty &&
      !window.confirm('You have unsaved changes. Leave this page and discard them?')) {
    return;
  }
  if (!Pages[name]) name = 'dashboard';
  App.editorDirty = false;
  App.current = name;
  document.querySelectorAll('.page').forEach(function (p) {
    p.classList.toggle('active', p.id === 'page-' + name);
  });
  document.querySelectorAll('.nav-item-link[data-page]').forEach(function (a) {
    a.classList.toggle('active', a.dataset.page === name);
  });
  document.getElementById('page-title').textContent = PAGE_TITLES[name] || name;
  document.body.classList.toggle('watermark-on', WATERMARK_PAGES.indexOf(name) >= 0);
  document.body.classList.remove('sidebar-open');
  if (Pages[name] && Pages[name].init) Pages[name].init(params || {});
}

/* ---- URL state: shareable filtered views --------------------------------
   Phase 9E. The hash carries {page, filters} - '#payroll?Status=DRAFT' -
   so a link copied out of the address bar reopens the same page filtered the
   same way. A page module that wants this calls syncUrl(name, params) after
   every filter change; parseHash() is what an init(params) call already
   receives, and reading it directly is only for the rare case of wanting it
   outside that lifecycle. */

/** The current hash as {page, params} - every param a plain string. */
function parseHash() {
  var h = location.hash.replace(/^#/, '');
  var qIdx = h.indexOf('?');
  var page = (qIdx >= 0 ? h.slice(0, qIdx) : h) || 'dashboard';
  var params = {};
  if (qIdx >= 0) {
    new URLSearchParams(h.slice(qIdx + 1)).forEach(function (v, k) { params[k] = v; });
  }
  return { page: page, params: params };
}

/**
 * A filter object as a query string, blank/null/undefined values dropped -
 * so a facet nobody chose does not show up as "OfficeCode=" in a shared link
 * or an export URL, and keys sort alphabetically so the same filters always
 * produce the same string whichever order the form fields happen to be in.
 * @param {Object<string,string>} params
 * @return {string} without a leading '?'
 */
function queryString(params) {
  var qs = new URLSearchParams();
  Object.keys(params || {}).sort().forEach(function (k) {
    var v = params[k];
    if (v !== '' && v !== null && v !== undefined) qs.set(k, v);
  });
  return qs.toString();
}

/**
 * Rewrites the address bar to reflect a page's current filters, without
 * navigating. history.replaceState() fires no hashchange event, which is the
 * whole point - a filter bar calls this after every change so the link stays
 * shareable, and none of those calls re-triggers showPage().
 */
function syncUrl(page, params) {
  var s = queryString(params);
  var url = '#' + page + (s ? '?' + s : '');
  if (location.hash !== url) history.replaceState(null, '', url);
}

/**
 * A real navigation to a page, optionally pre-filtered - the sidebar, a
 * dashboard watchlist card, or the global search box all go through this
 * rather than setting location.hash directly, so there is one code path
 * rather than one that also has to be replayed by the hashchange listener.
 */
function goToPage(page, params) {
  syncUrl(page, params || {});
  showPage(page, params || {});
}

/** Back/forward, or a hash pasted/typed directly into the address bar. */
window.addEventListener('hashchange', function () {
  var h = parseHash();
  showPage(h.page, h.params);
});

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

  // A few pixels of parallax on the watermark photo as the pointer moves -
  // only while it is actually showing, and never for a user who has asked
  // the OS for less motion.
  if (!window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
    var mark = document.getElementById('watermark');
    document.getElementById('main').addEventListener('mousemove', function (e) {
      if (!document.body.classList.contains('watermark-on')) return;
      var x = (e.clientX / window.innerWidth - 0.5) * 16;
      var y = (e.clientY / window.innerHeight - 0.5) * 12;
      mark.style.transform = 'translate(' + x.toFixed(1) + 'px,' + y.toFixed(1) + 'px)';
    });
  }

  // Sidebar navigation. goToPage() rather than showPage() directly, so a
  // fresh visit from the sidebar also clears any filter state left in the
  // address bar from a previous visit or a shared link - navigating IN is
  // what a role's default view (Phase 9E) applies against.
  document.querySelectorAll('.nav-item-link[data-page]').forEach(function (a) {
    a.onclick = function () { goToPage(a.dataset.page); };
  });

  // Logout.
  document.getElementById('btn-logout').onclick = function () {
    location.href = 'logout.php';
  };

  // Global control-number search (Phase 9E).
  document.getElementById('global-search-form').onsubmit = function (e) {
    e.preventDefault();
    var box = document.getElementById('global-search');
    var term = box.value.trim();
    if (!term) return;
    api('apiGlobalSearch', { q: term }).then(function (d) {
      if (!d.found) return toast('No record found for "' + term + '".', 'warning');
      var params = { search: d.term };
      if (d.tab) params.tab = d.tab;
      goToPage(d.page, params);
      box.value = '';
    });
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
    applyBranding(s.settings);
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
    // A shared link or a page reload lands back where it pointed; a plain
    // visit to the site (no hash yet) still opens the dashboard.
    var h = parseHash();
    showPage(h.page, h.params);
  }).catch(function () { /* toast/redirect already handled */ });
});
