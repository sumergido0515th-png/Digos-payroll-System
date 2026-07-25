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

<script>
/* ==================== Settings module ==================== */
Pages.settings = (function () {
  /** [key, label, type] descriptors per card. */
  var ORG = [
    ['GovernmentName', 'Government Name'], ['GovernmentSubtitle', 'Subtitle / City-Province'],
    ['GovernmentAddress', 'Office Address'], ['GovernmentContact', 'Contact Number'],
    ['GovernmentEmail', 'Email Address'], ['PagibigEmployerId', 'Pag-IBIG Employer ID No.'],
    ['CafoaExpenseCode', 'CAFOA Expense Code'],
    ['OfficeLogoUrl', 'Office Logo URL'], ['PayrollPrefix', 'Payroll Number Prefix'],
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

  /** Renders one settings card from its descriptors. */
  function render(hostId, defs, values) {
    document.getElementById(hostId).innerHTML = defs.map(function (d) {
      var v = values[d[0]] === undefined ? '' : values[d[0]];
      if (d[2] === 'select') {
        return '<div class="col-md-6"><label class="form-label">' + d[1] + '</label>' +
          '<select class="form-select form-select-sm set-field" data-key="' + d[0] + '">' +
          options(d[3], null, null, v) + '</select></div>';
      }
      return '<div class="col-md-6"><label class="form-label">' + d[1] + '</label>' +
        '<input class="form-control form-control-sm set-field" type="' + (d[2] || 'text') +
        '" data-key="' + d[0] + '" value="' + esc(v) + '"></div>';
    }).join('');
  }

  var dirtyBound = false;   // page-level input listener attached once

  return {
    init: function () {
      // Editing settings marks the page dirty so navigating away confirms.
      if (!dirtyBound) {
        dirtyBound = true;
        document.getElementById('page-settings').addEventListener('input', function () {
          App.editorDirty = true;
        });
      }
      busy(api('apiGetSettings')).then(function (values) {
        render('set-org', ORG, values);
        render('set-sign', SIGN, values);
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
      options(App.lookups.roles, null, null, u.Role || 'Viewer') + '</select></div>' +
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
      load();
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
</script>
