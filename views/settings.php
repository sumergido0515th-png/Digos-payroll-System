<!-- ==========================================================================
     settings.html - Settings, Users, Audit Logs and Backup/Restore pages.
     ========================================================================== -->

<!-- ==================== SETTINGS ==================== -->
<section class="page" id="page-settings">
  <div class="row g-3">
    <div class="col-lg-6">
      <div class="card h-100">
        <div class="card-header py-2">Organization &amp; Payroll</div>
        <div class="card-body row g-2" id="set-org"></div>
      </div>
    </div>
    <div class="col-lg-6">
      <div class="card h-100">
        <div class="card-header py-2">Authorized Signatories (printed payroll)</div>
        <div class="card-body row g-2" id="set-sign"></div>
      </div>
    </div>
    <div class="col-12 text-end">
      <button class="btn btn-gov" id="set-save">
        <span class="material-icons">save</span> Save Settings</button>
    </div>
  </div>
</section>

<!-- ==================== USERS ==================== -->
<section class="page" id="page-users">
  <div class="card">
    <div class="card-body py-2 d-flex">
      <span class="text-muted small align-self-center">
        Users sign in with their Google account. Only registered, Active users can open the system.</span>
      <button class="btn btn-sm btn-gov ms-auto" id="usr-add">
        <span class="material-icons">person_add</span> New User</button>
    </div>
  </div>
  <div class="card mt-3"><div class="table-responsive">
    <table class="table table-hover">
      <thead><tr><th>Name</th><th>Email</th><th>Role</th><th>Office</th>
        <th>Last Login</th><th>Status</th><th></th></tr></thead>
      <tbody id="usr-rows"></tbody>
    </table>
  </div></div>

  <!-- Scope grants. A role says what someone may do; a grant says which
       office's records they may do it to. Without at least one grant a user
       signs in successfully and sees nothing, which is correct and looks
       exactly like a broken installation - hence the warning below. -->
  <div class="card mt-4">
    <div class="card-body py-2 d-flex gap-2 align-items-center">
      <span class="fw-semibold">Scope Grants</span>
      <span class="text-muted small">
        A role decides what someone may do; a grant decides whose records.
        <strong>A user with no grant can sign in but sees nothing.</strong></span>
      <button class="btn btn-sm btn-gov ms-auto" id="sg-add">
        <span class="material-icons">key</span> New Grant</button>
    </div>
  </div>
  <div class="card mt-3"><div class="table-responsive">
    <table class="table table-hover">
      <thead><tr><th>User</th><th>Role</th><th>Covers</th>
        <th>Valid</th><th>Granted By</th><th></th></tr></thead>
      <tbody id="sg-rows"></tbody>
    </table>
  </div></div>
</section>

<!-- ==================== AUDIT LOGS ==================== -->
<section class="page" id="page-logs">
  <div class="card">
    <div class="card-body py-2 d-flex gap-2">
      <input class="form-control form-control-sm" id="log-search" placeholder="Live search user, action, module...">
      <input class="form-control form-control-sm w-auto" type="date" id="log-from" title="From date">
      <input class="form-control form-control-sm w-auto" type="date" id="log-to" title="To date">
      <button class="btn btn-sm btn-outline-secondary" id="log-refresh">
        <span class="material-icons">refresh</span></button>
    </div>
  </div>
  <div class="card mt-3"><div class="table-responsive" style="max-height:70vh">
    <table class="table table-sm table-hover">
      <thead class="sticky-top"><tr><th>Timestamp</th><th>User</th><th>Action</th>
        <th>Module</th><th>Details</th></tr></thead>
      <tbody id="log-rows"></tbody>
    </table>
  </div></div>
</section>

<!-- ==================== BACKUP & RESTORE ==================== -->
<section class="page" id="page-backup">
  <div class="card">
    <div class="card-body d-flex gap-2 align-items-center flex-wrap">
      <button class="btn btn-gov" id="bak-now">
        <span class="material-icons">backup</span> Backup Now</button>
      <div class="ms-3">
        <label class="form-label mb-0">Automatic Backup Schedule</label>
        <div class="d-flex gap-2">
          <select class="form-select form-select-sm w-auto" id="bak-schedule">
            <option value="off">Off</option>
            <option value="daily">Daily (2 AM)</option>
            <option value="weekly">Weekly (Sunday 2 AM)</option>
          </select>
          <button class="btn btn-sm btn-outline-secondary" id="bak-apply">Apply</button>
        </div>
      </div>
      <span class="text-muted small ms-auto">
        Backups are full copies of the database spreadsheet saved to Google Drive.
        Restoring replaces current data (a safety backup is taken first).</span>
    </div>
  </div>
  <div class="card mt-3"><div class="table-responsive">
    <table class="table table-hover">
      <thead><tr><th>Date</th><th>File Name</th><th>Type</th><th>By</th><th class="text-end">Actions</th></tr></thead>
      <tbody id="bak-rows"></tbody>
    </table>
  </div></div>
</section>

<!-- ==================== IMPORT DATA ====================
     Bulk master data from a spreadsheet, a table copied off a web page, or a
     JSON export. Two steps on purpose: the column mapping is worked out from
     whatever somebody typed in the heading row, and a guess nobody reviewed is
     a silent error - so the guess is always shown before anything is written.
     The stepper mirrors that: Choose, then Map & review, then Import - a real
     order the screen cannot be used out of, not decoration. -->
<section class="page" id="page-import">
  <div class="card import-hero">
    <div class="wisp a" aria-hidden="true">
      <svg viewBox="0 0 400 60" width="100%" height="100%" preserveAspectRatio="none">
        <path d="M0 40 Q100 0 200 30 T400 20" fill="none" stroke="#fff" stroke-width="2"/>
      </svg>
    </div>
    <div class="wisp b" aria-hidden="true">
      <svg viewBox="0 0 400 60" width="100%" height="100%" preserveAspectRatio="none">
        <path d="M0 20 Q100 55 200 25 T400 45" fill="none" stroke="#fff" stroke-width="2"/>
      </svg>
    </div>
    <div class="import-stepper" id="imp-stepper">
      <div class="import-step" data-step="1"><span class="n">1</span><span class="t">Choose file</span></div>
      <div class="import-step" data-step="2"><span class="n">2</span><span class="t">Map &amp; review</span></div>
      <div class="import-step" data-step="3"><span class="n">3</span><span class="t">Import</span></div>
    </div>
    <div class="card-body pt-2">
      <div class="import-kicker">Bulk master data</div>
      <h5 class="mb-3">Bring in a spreadsheet — mapped, checked, and shown back to you before anything is saved.</h5>
      <div class="row g-3 align-items-end">
        <div class="col-md-3">
          <label class="form-label" for="imp-entity">What are you importing?</label>
          <select class="form-select form-select-sm" id="imp-entity"></select>
          <div class="form-text small text-white-50" id="imp-limits"></div>
        </div>
        <div class="col-md-6">
          <label class="form-label" for="imp-file">File</label>
          <label class="import-dropzone" id="imp-dropzone">
            <span class="material-icons">upload_file</span>
            <span class="txt">
              <strong id="imp-filename">Choose a file</strong> or drag it here
              <small>.csv, .tsv, .xlsx, .html, .json</small>
            </span>
            <input type="file" class="d-none" id="imp-file"
                   accept=".csv,.tsv,.txt,.xlsx,.html,.htm,.json">
          </label>
        </div>
        <div class="col-md-3 text-md-end">
          <button class="btn btn-light btn-sm fw-semibold" id="imp-preview">
            <span class="material-icons">preview</span> Preview</button>
          <button class="btn btn-outline-light btn-sm" id="imp-paste-toggle"
                  title="Paste a table instead of choosing a file">
            <span class="material-icons">content_paste</span></button>
        </div>
        <div class="col-12 d-none" id="imp-paste-wrap">
          <label class="form-label" for="imp-paste">…or paste a table copied from a web page or a spreadsheet</label>
          <textarea class="form-control form-control-sm" id="imp-paste" rows="5"
                    placeholder="Paste here. A table copied from a browser keeps its columns; from a spreadsheet it arrives tab-separated. Both are read."></textarea>
        </div>
        <div class="col-12">
          <div class="form-text small text-white-50">
            Column headings do not have to match this system's field names — they are matched for
            you and shown for confirmation before anything is saved.
            <span class="d-block mt-1">PDF cannot be imported: it stores text as positioned
            characters with no columns, so a figure read back from one would be a guess. Save
            the source as .xlsx or .csv instead.</span>
          </div>
        </div>
      </div>
    </div>
  </div>
  <div id="imp-result" class="mt-3"></div>
</section>

<script>
/* ==================== Settings module ==================== */
Pages.settings = (function () {
  /** [key, label, type, extra] descriptors per card. */
  var ORG = [
    ['GovernmentName', 'Government Name'], ['GovernmentSubtitle', 'Subtitle / City-Province'],
    ['GovernmentAddress', 'Office Address'], ['GovernmentContact', 'Contact Number'],
    ['GovernmentEmail', 'Email Address'], ['PagibigEmployerId', 'Pag-IBIG Employer ID No.'],
    ['CafoaExpenseCode', 'CAFOA Expense Code'],
    ['OfficeLogoUrl', 'Screen Logo', 'image',
      'Shown in the sidebar and on the sign-in page.'],
    ['PrintLogoUrl', 'Printed Seal', 'image',
      'Seal printed on the CAFOA header. Leave blank to print the screen logo.'],
    ['WatermarkUrl', 'Watermark Background', 'image',
      'Faint seal behind the dashboard and the sign-in page. Leave blank for none.'],
    ['WatermarkOpacity', 'Watermark Opacity (0.02 - 0.25)'],
    ['LoginBackgroundUrl', 'Sign-in Background Photo', 'image',
      'Photo behind the sign-in card. Auto-fits any size or aspect ratio without distorting it. Leave blank for the default look.'],
    ['LoginHeadline', 'Sign-in Headline',
      'text', 'Shown above the sign-in card. The last word is highlighted.'],
    ['LoginTagline', 'Sign-in Tagline',
      'text', 'Shown under the headline. Put "|" where the second half should be highlighted.'],
    ['PayrollPrefix', 'Payroll Number Prefix'],
    ['DefaultTaxRate', 'Default Tax Rate (%)', 'number'],
    ['OvertimeMultiplier', 'Overtime Multiplier', 'number'],
    ['WorkingDaysPerMonth', 'Working Days / Month', 'number'],
    ['WorkingHoursPerDay', 'Working Hours / Day', 'number'],
    ['MaxEmployeesPerPayroll', 'Max Employees / Payroll', 'number'],
    ['SessionTimeoutMinutes', 'Session Timeout (minutes)', 'number'],
    ['SystemTheme', 'Default Theme', 'select', ['light', 'dark']]
  ];
  var SIGN = [
    ['SignatoryPreparedBy', 'Prepared / Certified By (A)'],
    ['SignatoryPreparedByTitle', 'Title (A)'],
    ['SignatoryFundsAvailable', 'Funds Available By (B)'],
    ['SignatoryFundsAvailableTitle', 'Title (B)'],
    ['SignatoryApprovedBy', 'Approved By (C)'],
    ['SignatoryApprovedByTitle', 'Title (C)'],
    ['SignatoryCertifiedBy', 'Paid / Certified By (D)'],
    ['SignatoryCertifiedByTitle', 'Title (D)'],
    ['SignatoryBudgetOfficer', 'Budget Officer (CAFOA)'],
    ['SignatoryBudgetOfficerTitle', 'Title (Budget Officer)']
  ];

  /** Element ids for one image setting's controls, derived from its key. */
  function imageIds(key) {
    return { url: 'set-img-url-' + key, file: 'set-img-file-' + key,
      button: 'set-img-btn-' + key, preview: 'set-img-prev-' + key };
  }

  /**
   * The preview element: the image itself, or a placeholder gap.
   *
   * Sized to fill the field's width and shown against white, because that is
   * what the sidebar and the printed form put it on - a pale logo that will
   * vanish there should be visible as a problem here, not hidden by a preview
   * too small to judge.
   */
  function imagePreviewHtml(key, url) {
    var id = imageIds(key).preview;
    return url
      ? '<img id="' + id + '" src="' + esc(url) + '" alt="Preview" ' +
        'style="width:100%;max-height:180px;object-fit:contain;' +
        'border:1px solid #ddd;border-radius:6px;padding:6px;background:#fff;">'
      : '<div id="' + id + '" style="min-height:80px;"></div>';
  }

  /** Swaps one preview for the image at url, or for the placeholder gap. */
  function showImagePreview(key, url) {
    var preview = document.getElementById(imageIds(key).preview);
    if (preview) preview.outerHTML = imagePreviewHtml(key, url);
  }

  /**
   * Wires one image setting's Upload button. Called after render(), because
   * none of these elements exist until the settings come back from the server.
   *
   * api.php takes JSON only, so the file is read here and sent inline as a
   * data: URL. The server saves the setting itself and answers with the URL it
   * stored, which is what goes into the text field - the name the browser sent
   * is not the name it was saved under.
   */
  function bindImageUpload(key) {
    var id = imageIds(key);
    var fileInput = document.getElementById(id.file);
    var urlInput = document.getElementById(id.url);
    if (!fileInput || !urlInput) return;

    document.getElementById(id.button).onclick = function () { fileInput.click(); };
    urlInput.onchange = function () { showImagePreview(key, urlInput.value); };
    fileInput.onchange = function () {
      var file = fileInput.files && fileInput.files[0];
      if (!file) return;
      if (!/^image\//.test(file.type)) {
        toast('Please choose an image file (PNG, JPG, GIF or WEBP).', 'warning');
        fileInput.value = '';
        return;
      }
      var reader = new FileReader();
      reader.onerror = function () {
        toast('That file could not be read.', 'danger');
        fileInput.value = '';
      };
      reader.onload = function (evt) {
        busy(api('apiUploadImageSetting', { key: key, data: evt.target.result }))
          .then(function (d) {
            urlInput.value = d.url;
            showImagePreview(key, d.url);
            toast('Image uploaded and saved. Reload to see it applied.');
          }).catch(function () { /* message already shown by api() */ })
          .then(function () { fileInput.value = ''; });
      };
      reader.readAsDataURL(file);
    };
  }

  /** Renders one settings card from its descriptors. */
  function render(hostId, defs, values) {
    document.getElementById(hostId).innerHTML = defs.map(function (d) {
      var v = values[d[0]] === undefined ? '' : values[d[0]];
      if (d[2] === 'select') {
        return '<div class="col-md-6"><label class="form-label">' + d[1] + '</label>' +
          '<select class="form-select form-select-sm set-field" data-key="' + d[0] + '">' +
          options(d[3], null, null, v) + '</select></div>';
      }
      if (d[2] === 'image') {
        var id = imageIds(d[0]);
        return '<div class="col-md-6"><label class="form-label">' + d[1] + '</label>' +
          '<div class="input-group input-group-sm mb-1">' +
          '<input id="' + id.url + '" class="form-control set-field" type="text" ' +
          'data-key="' + d[0] + '" value="' + esc(v) + '">' +
          '<button type="button" class="btn btn-outline-secondary" id="' + id.button +
          '">Upload</button></div>' +
          '<input type="file" id="' + id.file + '" class="d-none" ' +
          'accept="image/png,image/jpeg,image/gif,image/webp">' +
          (d[3] ? '<div class="form-text small mb-1">' + esc(d[3]) + '</div>' : '') +
          '<div class="mb-2">' + imagePreviewHtml(d[0], v) + '</div></div>';
      }
      return '<div class="col-md-6"><label class="form-label">' + d[1] + '</label>' +
        '<input class="form-control form-control-sm set-field" type="' + (d[2] || 'text') +
        '" data-key="' + d[0] + '" value="' + esc(v) + '">' +
        (d[3] ? '<div class="form-text small">' + esc(d[3]) + '</div>' : '') + '</div>';
    }).join('');
  }

  var dirtyBound = false;   // page-level input listener attached once

  return {
    init: function () {
      // Editing settings marks the page dirty so navigating away confirms.
      if (!dirtyBound) {
        dirtyBound = true;
        document.getElementById('page-settings').addEventListener('input', function (e) {
          // Picking an image is not an unsaved edit - that upload saves itself.
          if (e.target.type !== 'file') App.editorDirty = true;
        });
      }
      busy(api('apiGetSettings')).then(function (values) {
        render('set-org', ORG, values);
        render('set-sign', SIGN, values);
        ORG.forEach(function (d) { if (d[2] === 'image') bindImageUpload(d[0]); });
      });
      document.getElementById('set-save').onclick = function () {
        var settings = {};
        document.querySelectorAll('.set-field').forEach(function (el) {
          settings[el.dataset.key] = el.value;
        });
        busy(api('apiSaveSettings', { settings: settings })).then(function () {
          App.editorDirty = false;
          toast('Settings saved.');
        });
      };
    }
  };
})();

/* ==================== Users module ==================== */
Pages.users = (function () {
  function load() {
    api('apiListUsers').then(function (rows) {
      document.getElementById('usr-rows').innerHTML = rows.map(function (u) {
        return '<tr><td class="fw-semibold">' + esc(u.FullName) + '</td>' +
          '<td>' + esc(u.Email) + '</td><td>' + esc(u.Role) + '</td>' +
          '<td>' + esc(u.OfficeCode) + '</td>' +
          '<td>' + (u.LastLogin ? fmtDate(u.LastLogin) : '<span class="text-muted">never</span>') + '</td>' +
          '<td>' + badge(u.Status) + '</td>' +
          '<td class="text-end text-nowrap">' +
          actionBtn('edit', 'Pages.users.edit(\'' + esc(u.Email) + '\')') +
          actionBtn('delete', 'Pages.users.remove(\'' + esc(u.Email) + '\')', 'text-danger') +
          '</td></tr>';
      }).join('');
    });
  }

  function openEditor(u) {
    u = u || {};
    openModal(u.Email ? 'Edit User' : 'New User',
      '<form id="usr-form" class="row g-2">' +
      '<div class="col-md-6"><label class="form-label">Google Email *</label>' +
      '<input class="form-control form-control-sm" name="Email" value="' + esc(u.Email || '') + '"' +
      (u.Email ? ' readonly' : '') + '></div>' +
      '<div class="col-md-6"><label class="form-label">Full Name *</label>' +
      '<input class="form-control form-control-sm" name="FullName" value="' + esc(u.FullName || '') + '"></div>' +
      '<div class="col-md-6"><label class="form-label">Password ' +
      (u.Email ? '(leave blank to keep current)' : '* (min 8 characters)') + '</label>' +
      '<input class="form-control form-control-sm" type="password" name="Password" autocomplete="new-password"></div>' +
      '<div class="col-md-4"><label class="form-label">Role *</label>' +
      '<select class="form-select form-select-sm" name="Role">' +
      // 'Internal Auditor' is the least-privileged role, and the default a new
      // user should land on. It used to read 'Viewer', which Phase 2's role
      // remap deleted - apiSaveUser validates against PERMISSIONS, so the form
      // was defaulting to a role the save would then refuse.
      options(App.lookups.roles, null, null, u.Role || 'Internal Auditor') + '</select></div>' +
      '<div class="col-md-4"><label class="form-label">Office</label>' +
      '<select class="form-select form-select-sm" name="OfficeCode">' +
      options(App.lookups.offices, 'OfficeCode', 'OfficeName', u.OfficeCode, '-- any --') + '</select></div>' +
      '<div class="col-md-4"><label class="form-label">Status</label>' +
      '<select class="form-select form-select-sm" name="Status">' +
      options(['Active', 'Inactive'], null, null, u.Status || 'Active') + '</select></div></form>',
      [
        { label: 'Cancel', cls: 'btn-outline-secondary', onclick: closeModal },
        {
          label: 'Save User', onclick: function () {
            busy(api('apiSaveUser', formData(document.getElementById('usr-form'))))
              .then(function () { toast('User saved.'); closeModalSaved(); load(); });
          }
        }
      ]);
  }

  return {
    init: function () {
      document.getElementById('usr-add').onclick = function () { openEditor(null); };
      document.getElementById('sg-add').onclick = function () { Pages.scopeGrants.add(); };
      load();
      Pages.scopeGrants.load();
    },
    edit: function (email) {
      api('apiListUsers').then(function (rows) {
        openEditor(rows.filter(function (u) { return u.Email === email; })[0]);
      });
    },
    remove: function (email) {
      confirmDlg('Remove system access for ' + email + '?', function () {
        busy(api('apiDeleteUser', { Email: email })).then(function () {
          toast('User removed.'); load();
        });
      });
    }
  };
})();

/* ==================== Scope grants module ====================
   Lives on the Users page: a grant is meaningless without the account it
   belongs to, and the two are almost always edited in the same sitting -
   creating a user and then not granting them anything is the mistake this
   layout is trying to prevent. */
Pages.scopeGrants = (function () {
  var rows = [];
  var users = [];

  function load() {
    // The user list comes from apiListUsers, not from lookups: lookups are
    // readable by every signed-in role, and the roster of accounts is not
    // something an Encoder needs. Both endpoints here require user.manage /
    // scope.manage, so this screen is administrator-only end to end.
    api('apiListUsers').then(function (list) { users = list; });

    api('apiListScopeGrants').then(function (data) {
      rows = data;
      document.getElementById('sg-rows').innerHTML = data.length ? data.map(function (g) {
        // "Covers" is computed server-side and spells out what the NULLs mean.
        // A row of blank cells reads as an unfinished record; it actually means
        // unrestricted, which is the opposite.
        var wildcard = g.Covers.indexOf('EVERYTHING') !== -1;
        return '<tr><td class="fw-semibold">' + esc(g.UserName || g.UserEmail) +
          '<div class="text-muted small">' + esc(g.UserEmail) + '</div></td>' +
          '<td>' + (g.RoleCode ? esc(g.RoleCode) : '<span class="text-muted">any role</span>') + '</td>' +
          '<td' + (wildcard ? ' class="text-danger fw-semibold"' : '') + '>' + esc(g.Covers) + '</td>' +
          '<td>' + validity(g) + '</td>' +
          '<td class="text-muted small">' + esc(g.GrantedBy || 'seeded by migration') + '</td>' +
          '<td class="text-end text-nowrap">' +
          actionBtn('edit', 'Pages.scopeGrants.edit(\'' + esc(g.GrantID) + '\')') +
          actionBtn('delete', 'Pages.scopeGrants.remove(\'' + esc(g.GrantID) + '\')', 'text-danger') +
          '</td></tr>';
      }).join('') :
        '<tr><td colspan="6" class="text-center text-muted py-3">' +
        'No grants yet. Only administrators can see anything until one is issued.</td></tr>';
    });
  }

  function validity(g) {
    if (!g.ValidFrom && !g.ValidTo) return '<span class="text-muted">no expiry</span>';
    var text = (g.ValidFrom ? fmtDate(g.ValidFrom) : 'always') + ' - ' +
      (g.ValidTo ? fmtDate(g.ValidTo) : 'always');
    return g.IsLive ? esc(text) : '<span class="text-danger">' + esc(text) + ' (expired)</span>';
  }

  function openEditor(g) {
    g = g || {};
    openModal(g.GrantID ? 'Edit Scope Grant' : 'New Scope Grant',
      '<form id="sg-form" class="row g-2">' +
      '<input type="hidden" name="GrantID" value="' + esc(g.GrantID || '') + '">' +
      '<div class="col-12"><div class="alert alert-warning py-2 mb-1 small">' +
      'Leaving a field blank means <strong>no restriction</strong> on it. ' +
      'A grant with every field blank covers the entire city.</div></div>' +
      '<div class="col-md-6"><label class="form-label">User *</label>' +
      '<select class="form-select form-select-sm" name="UserEmail">' +
      options(users, 'Email', 'FullName', g.UserEmail) + '</select></div>' +
      '<div class="col-md-6"><label class="form-label">Only in role</label>' +
      '<select class="form-select form-select-sm" name="RoleCode">' +
      options(App.lookups.roles, null, null, g.RoleCode, '-- any role --') + '</select></div>' +
      '<div class="col-md-6"><label class="form-label">Office</label>' +
      '<select class="form-select form-select-sm" name="OfficeCode">' +
      options(App.lookups.offices, 'OfficeCode', 'OfficeName', g.OfficeCode, '-- all offices --') + '</select></div>' +
      '<div class="col-md-6"><label class="form-label">Function / PPA</label>' +
      '<select class="form-select form-select-sm" name="FunctionCode">' +
      options(App.lookups.functions, 'FunctionCode', 'FunctionName', g.FunctionCode, '-- all funds --') + '</select></div>' +
      '<div class="col-md-3"><label class="form-label">Valid From</label>' +
      '<input class="form-control form-control-sm" type="date" name="ValidFrom" value="' + esc(g.ValidFrom || '') + '"></div>' +
      '<div class="col-md-3"><label class="form-label">Valid To</label>' +
      '<input class="form-control form-control-sm" type="date" name="ValidTo" value="' + esc(g.ValidTo || '') + '"></div>' +
      '<div class="col-md-6 d-flex align-items-end gap-3">' +
      '<div class="form-check"><input class="form-check-input" type="checkbox" name="CanRead" value="1" id="sg-read"' +
      (g.GrantID && !Number(g.CanRead) ? '' : ' checked') + '>' +
      '<label class="form-check-label" for="sg-read">Can read</label></div>' +
      '<div class="form-check"><input class="form-check-input" type="checkbox" name="CanWrite" value="1" id="sg-write"' +
      (Number(g.CanWrite) ? ' checked' : '') + '>' +
      '<label class="form-check-label" for="sg-write">Can write</label></div></div>' +
      '<div class="col-12"><label class="form-label">Reason</label>' +
      '<input class="form-control form-control-sm" name="Remarks" value="' + esc(g.Remarks || '') + '"' +
      ' placeholder="Why this person needs this access"></div></form>',
      [
        { label: 'Cancel', cls: 'btn-outline-secondary', onclick: closeModal },
        {
          label: 'Save Grant', onclick: function () {
            busy(api('apiSaveScopeGrant', formData(document.getElementById('sg-form'))))
              .then(function (r) { toast('Grant saved - ' + r.Covers); closeModalSaved(); load(); });
          }
        }
      ]);
  }

  return {
    load: load,
    init: load,
    add: function () { openEditor(null); },
    edit: function (id) {
      openEditor(rows.filter(function (g) { return g.GrantID === id; })[0]);
    },
    remove: function (id) {
      confirmDlg('Revoke this access grant?', function () {
        busy(api('apiDeleteScopeGrant', { GrantID: id })).then(function () {
          toast('Grant revoked.'); load();
        });
      });
    }
  };
})();

/* ==================== Audit Logs module ==================== */
Pages.logs = (function () {
  function load() {
    busy(api('apiGetLogs', {
      search: document.getElementById('log-search').value,
      dateFrom: document.getElementById('log-from').value,
      dateTo: document.getElementById('log-to').value
    })).then(function (rows) {
      document.getElementById('log-rows').innerHTML = rows.map(function (r) {
        return '<tr><td class="text-nowrap">' +
          esc(String(r.Timestamp).replace('T', ' ').slice(0, 19)) + '</td>' +
          '<td>' + esc(r.User) + '</td><td><b>' + esc(r.Action) + '</b></td>' +
          '<td>' + esc(r.Module) + '</td>' +
          '<td class="small text-muted" style="max-width:420px;overflow:hidden;text-overflow:ellipsis">' +
          esc(r.Details) + '</td></tr>';
      }).join('') || '<tr><td colspan="5" class="text-center text-muted py-4">No log entries.</td></tr>';
    });
  }
  return {
    init: function () {
      document.getElementById('log-search').oninput = debounce(load);
      document.getElementById('log-from').onchange = load;
      document.getElementById('log-to').onchange = load;
      document.getElementById('log-refresh').onclick = load;
      load();
    }
  };
})();

/* ==================== Backup & Restore module ==================== */
Pages.backup = (function () {
  function load() {
    api('apiListBackups').then(function (rows) {
      document.getElementById('bak-rows').innerHTML = rows.map(function (b) {
        return '<tr><td>' + esc(String(b.Timestamp).replace('T', ' ').slice(0, 19)) + '</td>' +
          '<td class="fw-semibold">' + esc(b.FileName) + '</td>' +
          '<td>' + esc(b.Type) + '</td><td>' + esc(b.User) + '</td>' +
          '<td class="text-end text-nowrap">' +
          '<a class="btn btn-sm btn-link p-1" target="_blank" href="' + esc(b.Url) +
          '" title="Open / download"><span class="material-icons" style="font-size:17px">open_in_new</span></a>' +
          actionBtn('settings_backup_restore', 'Pages.backup.restore(\'' + esc(b.FileID) + '\')', 'text-danger') +
          '</td></tr>';
      }).join('') || '<tr><td colspan="5" class="text-center text-muted py-4">No backups yet.</td></tr>';
    });
  }
  return {
    init: function () {
      api('apiGetSettings').then(function (s) {
        document.getElementById('bak-schedule').value = s.BackupSchedule || 'weekly';
      });
      document.getElementById('bak-now').onclick = function () {
        busy(api('apiBackupNow')).then(function (d) {
          toast('Backup created: ' + d.fileName); load();
        });
      };
      document.getElementById('bak-apply').onclick = function () {
        var schedule = document.getElementById('bak-schedule').value;
        busy(api('apiSaveSettings', { settings: { BackupSchedule: schedule } })
          .then(function () { return api('apiApplyBackupSchedule'); }))
          .then(function () { toast('Backup schedule applied: ' + schedule); });
      };
      load();
    },
    restore: function (fileId) {
      confirmDlg('RESTORE will replace ALL current data with this backup. ' +
        'A safety backup of the current state is taken first. Continue?', function () {
          busy(api('apiRestoreBackup', { FileID: fileId })).then(function (d) {
            toast('Restored tables: ' + d.restored.join(', '));
            loadLookups();
            load();
          });
        });
    }
  };
})();

/* ==================== Import module ====================
   Preview, then commit. The file is sent twice rather than parked on the
   server between the two calls: api.php is stateless, and an upload held
   somewhere waiting for a confirmation that may never come is its own cleanup
   problem. The server re-reads and re-validates on commit, so nothing here
   has to be trusted. */
Pages['import'] = (function () {
  var types = [];        // importable entities this role may write
  var preview = null;    // the last preview response
  var override = {};     // Field -> column index chosen by hand

  /**
   * The chosen file, or the pasted text, as a data: URL.
   *
   * Pasted text is encoded through TextEncoder rather than btoa() directly,
   * because btoa() throws on any character above U+00FF - which includes the
   * peso sign and the en dash Excel inserts into a copied heading.
   */
  function payload() {
    var file = document.getElementById('imp-file').files[0];
    if (file) {
      return new Promise(function (resolve, reject) {
        var reader = new FileReader();
        reader.onerror = function () { reject(new Error('That file could not be read.')); };
        reader.onload = function (e) { resolve({ data: e.target.result, fileName: file.name }); };
        reader.readAsDataURL(file);
      });
    }

    var text = document.getElementById('imp-paste').value;
    if (!text.trim()) return Promise.reject(new Error('Choose a file, or paste a table.'));

    var bytes = new TextEncoder().encode(text);
    var binary = '';
    bytes.forEach(function (b) { binary += String.fromCharCode(b); });
    // No file name: the pasted text has no extension to break a CSV/TSV tie,
    // and the sniffer works it out from the content instead.
    return Promise.resolve({ data: 'data:text/plain;base64,' + btoa(binary), fileName: '' });
  }

  function request(extra) {
    return payload().then(function (p) {
      var body = { entity: document.getElementById('imp-entity').value,
        data: p.data, fileName: p.fileName, mapping: override };
      Object.keys(extra || {}).forEach(function (k) { body[k] = extra[k]; });
      return body;
    });
  }

  function runPreview() {
    request().then(function (body) {
      return busy(api('apiPreviewImport', body));
    }).then(function (d) {
      preview = d;
      render();
    }).catch(function (e) {
      // A rejected payload() never reached api(), which shows its own errors.
      if (e && e.message) toast(e.message, 'warning');
    });
  }

  /** One row of the field-to-column table. */
  function mappingRow(field, m) {
    var required = preview.missingRequired.indexOf(field) >= 0;
    var options = ['<option value="">— not imported —</option>'].concat(
      preview.headers.map(function (h, i) {
        return '<option value="' + i + '"' + (m.column === i ? ' selected' : '') + '>' +
          esc(h || '(column ' + (i + 1) + ')') + '</option>';
      })).join('');

    var chip = m.column === null
      ? (required
          ? '<span class="imp-confidence missing"><span class="dot"></span>required — choose a column</span>'
          : '<span class="imp-confidence empty"><span class="dot"></span>not in this file</span>')
      : (m.confident
          ? '<span class="imp-confidence ok"><span class="dot"></span>matched</span>'
          : '<span class="imp-confidence low"><span class="dot"></span>low confidence — check this</span>');

    return '<tr' + (required && m.column === null ? ' class="table-danger"' : '') + '>' +
      '<td class="fw-semibold">' + esc(field) + '</td>' +
      '<td><select class="form-select form-select-sm imp-map" data-field="' + esc(field) + '">' +
      options + '</select></td>' +
      '<td class="small">' + chip + '</td></tr>';
  }

  function render() {
    var d = preview;
    var unmatched = Object.keys(d.unmatched).map(function (i) { return d.unmatched[i]; });

    var summary =
      '<div class="card"><div class="card-body py-2 d-flex flex-wrap gap-3 align-items-center">' +
      '<span><strong>' + esc(d.label) + '</strong></span>' +
      '<span class="badge text-bg-secondary">' + esc(d.format.toUpperCase()) +
      (d.sheet ? ' — ' + esc(d.sheet) : '') + '</span>' +
      '<span><span class="material-icons align-middle" style="font-size:15px">table_rows</span> ' +
      d.total + ' rows</span>' +
      '<span class="imp-confidence ok"><span class="dot"></span>' + d.validCount + ' ready</span>' +
      (d.invalidCount ? '<span class="imp-confidence missing"><span class="dot"></span>' +
        d.invalidCount + ' with problems</span>' : '') +
      '</div></div>';

    var warnings = '';
    if (d.missingRequired.length) {
      warnings += '<div class="alert alert-danger py-2 mt-3 mb-0">Required columns not mapped: <strong>' +
        esc(d.missingRequired.join(', ')) + '</strong>. Pick them below.</div>';
    }
    if (unmatched.length) {
      warnings += '<div class="alert alert-warning py-2 mt-3 mb-0">Columns in the file that nothing ' +
        'used: <strong>' + esc(unmatched.join(', ')) + '</strong>. Map one below if it was meant ' +
        'to be imported.</div>';
    }
    if (d.duplicateKeys.length) {
      warnings += '<div class="alert alert-warning py-2 mt-3 mb-0">The same record appears more ' +
        'than once in this file: <strong>' + esc(d.duplicateKeys.join(', ')) + '</strong>.</div>';
    }

    var mapping = '<div class="card mt-3"><div class="card-header py-2">Column mapping</div>' +
      '<div class="table-responsive" style="max-height:45vh"><table class="table table-sm mb-0">' +
      '<thead class="sticky-top"><tr><th>Field</th><th style="width:40%">Column in your file</th>' +
      '<th>Match</th></tr></thead><tbody>' +
      Object.keys(d.mapping).map(function (f) { return mappingRow(f, d.mapping[f]); }).join('') +
      '</tbody></table></div></div>';

    var rows = '<div class="card mt-3"><div class="card-header py-2">' +
      'Rows (problems first, first ' + d.rows.length + ' shown)</div>' +
      '<div class="table-responsive" style="max-height:45vh"><table class="table table-sm mb-0 import-rows-table">' +
      '<thead class="sticky-top"><tr><th>Row</th><th>Record</th><th>Values</th></tr></thead><tbody>' +
      d.rows.map(function (r) {
        var bad = r.errors.length > 0;
        return '<tr class="' + (bad ? 'row-bad' : 'row-ok') + '">' +
          '<td>' + r.line + '</td>' +
          '<td class="fw-semibold">' + esc(r.key || '—') + '</td>' +
          '<td class="small">' + (bad
            ? '<span class="text-danger">' + esc(r.errors.join('; ')) + '</span>'
            : esc(Object.keys(r.values).map(function (k) {
                return k + '=' + r.values[k];
              }).join('  '))) + '</td></tr>';
      }).join('') + '</tbody></table></div></div>';

    var canCommit = d.validCount > 0 && d.missingRequired.length === 0;
    var actions = '<div class="card mt-3"><div class="card-body py-2 d-flex gap-2 align-items-center">' +
      (d.invalidCount
        ? '<div class="form-check mb-0"><input class="form-check-input" type="checkbox" id="imp-skip">' +
          '<label class="form-check-label small" for="imp-skip">Import the ' + d.validCount +
          ' valid rows only and skip the ' + d.invalidCount + ' with problems</label></div>'
        : '<span class="text-muted small">Every row checks out.</span>') +
      '<button class="btn btn-gov btn-sm ms-auto" id="imp-commit"' + (canCommit ? '' : ' disabled') +
      '><span class="material-icons">upload</span> Import ' + d.validCount + ' rows</button>' +
      '</div></div>';

    document.getElementById('imp-result').innerHTML =
      summary + warnings + mapping + rows + actions;

    document.querySelectorAll('.imp-map').forEach(function (sel) {
      sel.onchange = function () {
        override[sel.dataset.field] = sel.value;
        runPreview();
      };
    });

    var commit = document.getElementById('imp-commit');
    if (commit) commit.onclick = doCommit;

    updateStepper();
  }

  function doCommit() {
    var skipBox = document.getElementById('imp-skip');
    var skip = !!(skipBox && skipBox.checked);

    confirmDlg('Import ' + preview.validCount + ' ' + preview.label + ' record(s)? ' +
      'Records whose code already exists will be updated.', function () {
        request({ skipInvalid: skip }).then(function (body) {
          return busy(api('apiCommitImport', body));
        }).then(function (d) {
          toast(d.created + ' created, ' + d.updated + ' updated' +
            (d.skipped ? ', ' + d.skipped + ' skipped' : '') + '.');
          document.getElementById('imp-result').innerHTML = '';
          document.getElementById('imp-file').value = '';
          document.getElementById('imp-paste').value = '';
          document.getElementById('imp-filename').textContent = 'Choose a file';
          preview = null;
          override = {};
          updateStepper();
          // Offices and periods feed dropdowns all over the SPA; without this
          // an imported office is missing from every picker until a reload.
          loadLookups();
        }).catch(function () { /* api() has already shown the message */ });
      });
  }

  /**
   * Reflects real state onto the stepper - not a fixed decoration. Choosing a
   * file or pasting text finishes step 1; loading a preview finishes step 2 and
   * hands the "current" mark to step 3, which is exactly the point at which the
   * screen has something committable.
   */
  function updateStepper() {
    var chosen = !!(document.getElementById('imp-file').files.length) ||
      !!document.getElementById('imp-paste').value.trim();
    var stage = preview ? 2 : (chosen ? 1 : 0);
    document.querySelectorAll('#imp-stepper .import-step').forEach(function (el, i) {
      el.classList.toggle('done', i < stage);
      el.classList.toggle('current', i === stage);
    });
  }

  /** "1.2 MB" / "480 KB" - however apiGetImportTypes reports the file cap. */
  function fmtBytes(n) {
    if (!n) return '';
    return n >= 1048576 ? (n / 1048576).toFixed(1) + ' MB' : Math.round(n / 1024) + ' KB';
  }

  return {
    init: function () {
      override = {};
      preview = null;
      document.getElementById('imp-result').innerHTML = '';
      document.getElementById('imp-filename').textContent = 'Choose a file';

      api('apiGetImportTypes').then(function (d) {
        types = d.types;
        document.getElementById('imp-entity').innerHTML = types.map(function (t) {
          return '<option value="' + esc(t.entity) + '">' + esc(t.label) + '</option>';
        }).join('') || '<option value="">Your role cannot import any record type</option>';
        document.getElementById('imp-limits').textContent =
          'Up to ' + fmtBytes(d.maxBytes) + ', ' + d.maxRows + ' rows per file.';
      });

      // Changing the target invalidates a mapping made against the old one.
      document.getElementById('imp-entity').onchange = function () {
        override = {};
        preview = null;
        document.getElementById('imp-result').innerHTML = '';
        updateStepper();
      };

      var fileInput = document.getElementById('imp-file');
      var dropzone = document.getElementById('imp-dropzone');
      fileInput.onchange = function () {
        override = {};
        var f = fileInput.files[0];
        document.getElementById('imp-filename').textContent = f ? f.name : 'Choose a file';
        preview = null;
        document.getElementById('imp-result').innerHTML = '';
        updateStepper();
      };
      // Native <label>+<input> already opens the file picker on click; only
      // the drag-and-drop path needs wiring by hand.
      ['dragenter', 'dragover'].forEach(function (ev) {
        dropzone.addEventListener(ev, function (e) {
          e.preventDefault();
          dropzone.classList.add('drag-over');
        });
      });
      ['dragleave', 'drop'].forEach(function (ev) {
        dropzone.addEventListener(ev, function (e) {
          e.preventDefault();
          dropzone.classList.remove('drag-over');
        });
      });
      dropzone.addEventListener('drop', function (e) {
        var files = e.dataTransfer && e.dataTransfer.files;
        if (files && files.length) {
          fileInput.files = files;
          fileInput.onchange();
        }
      });

      document.getElementById('imp-paste').oninput = function () {
        preview = null;
        updateStepper();
      };
      document.getElementById('imp-paste-toggle').onclick = function () {
        document.getElementById('imp-paste-wrap').classList.toggle('d-none');
      };
      document.getElementById('imp-preview').onclick = runPreview;

      updateStepper();
    }
  };
})();
</script>
