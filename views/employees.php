<!-- ==========================================================================
     employees.html - Employees, Timekeepers, and Departments/Offices/Functions.
     ========================================================================== -->

<!-- ==================== EMPLOYEES ==================== -->
<section class="page" id="page-employees">
  <div class="card">
    <div class="card-body py-2">
      <div class="row g-2 align-items-end">
        <div class="col-md-3"><label class="form-label">Live Search</label>
          <input class="form-control form-control-sm" id="emp-search" placeholder="Name, ID, position, TIN, cash card..."></div>
        <div class="col-md-2"><label class="form-label">Office</label>
          <select class="form-select form-select-sm" id="emp-f-office"></select></div>
        <div class="col-md-2"><label class="form-label">Employment Type</label>
          <select class="form-select form-select-sm" id="emp-f-type"></select></div>
        <div class="col-md-2"><label class="form-label">Status</label>
          <select class="form-select form-select-sm" id="emp-f-status"></select></div>
        <div class="col-md-3 text-end">
          <button class="btn btn-sm btn-gov" id="emp-add" style="display:none">
            <span class="material-icons">person_add</span> New Employee</button>
        </div>
      </div>
    </div>
  </div>

  <div class="card mt-3">
    <div class="table-responsive">
      <table class="table table-hover">
        <thead><tr>
          <th>Employee No.</th><th>Name</th><th>Position</th><th>Office</th>
          <th>Type</th><th>Cash Card</th><th class="text-end">Daily Rate</th><th>Status</th><th></th>
        </tr></thead>
        <tbody id="emp-rows"></tbody>
      </table>
    </div>
    <div class="card-footer py-2 d-flex justify-content-between align-items-center">
      <small class="text-muted" id="emp-count"></small>
      <div class="btn-group">
        <button class="btn btn-sm btn-outline-secondary" id="emp-prev">&laquo; Prev</button>
        <button class="btn btn-sm btn-outline-secondary" id="emp-next">Next &raquo;</button>
      </div>
    </div>
  </div>
</section>

<!-- ==================== TIMEKEEPERS ==================== -->
<section class="page" id="page-timekeepers">
  <div class="card">
    <div class="card-body py-2 d-flex gap-2">
      <input class="form-control form-control-sm w-auto flex-grow-1" id="tk-search" placeholder="Live search timekeepers...">
      <button class="btn btn-sm btn-gov" id="tk-add" style="display:none">
        <span class="material-icons">add</span> New Timekeeper</button>
    </div>
  </div>
  <div class="card mt-3"><div class="table-responsive">
    <table class="table table-hover">
      <thead><tr><th>ID</th><th>Name</th><th>Office</th><th>Department</th>
        <th>Contact</th><th>Email</th><th>Status</th><th></th></tr></thead>
      <tbody id="tk-rows"></tbody>
    </table>
  </div></div>
</section>

<!-- ==================== DEPARTMENTS / OFFICES / FUNCTIONS ==================== -->
<section class="page" id="page-departments">
  <ul class="nav nav-pills mb-3" id="dept-tabs">
    <li class="nav-item"><a class="nav-link active cursor-pointer" data-tab="offices">Offices</a></li>
    <li class="nav-item"><a class="nav-link cursor-pointer" data-tab="departments">Departments</a></li>
    <li class="nav-item"><a class="nav-link cursor-pointer" data-tab="functions">Functions / Funds</a></li>
  </ul>
  <div class="card">
    <div class="card-body py-2 d-flex gap-2">
      <input class="form-control form-control-sm flex-grow-1" id="org-search" placeholder="Live search...">
      <button class="btn btn-sm btn-gov" id="org-add" style="display:none">
        <span class="material-icons">add</span> New</button>
    </div>
  </div>
  <div class="card mt-3"><div class="table-responsive">
    <table class="table table-hover">
      <thead id="org-head"></thead><tbody id="org-rows"></tbody>
    </table>
  </div></div>
</section>

<script>
/* ==================== Employees module ==================== */
Pages.employees = (function () {
  var state = { page: 1, pageSize: 20 };

  /** Collects the filter bar into an apiListEmployees payload. */
  function payload() {
    return {
      search: document.getElementById('emp-search').value,
      OfficeCode: document.getElementById('emp-f-office').value,
      EmploymentType: document.getElementById('emp-f-type').value,
      Status: document.getElementById('emp-f-status').value,
      page: state.page, pageSize: state.pageSize
    };
  }

  /** Loads and renders the employee table. */
  function load() {
    api('apiListEmployees', payload()).then(function (d) {
      document.getElementById('emp-count').textContent =
        d.total + ' employee(s) - page ' + d.page;
      document.getElementById('emp-rows').innerHTML = d.rows.map(function (e) {
        return '<tr><td>' + esc(e.EmployeeNo || e.EmployeeID) + '</td>' +
          '<td class="fw-semibold">' + esc(e.FullName) + '</td>' +
          '<td>' + esc(e.Position) + '</td><td>' + esc(e.OfficeCode) + '</td>' +
          '<td>' + esc(e.EmploymentType) + '</td>' +
          '<td class="text-nowrap">' + (e.CashCard ? esc(e.CashCard) : '<span class="text-muted">&mdash;</span>') + '</td>' +
          '<td class="text-money">' + fmtMoney(e.DailyRate) + '</td>' +
          '<td>' + badge(e.Status) + '</td>' +
          '<td class="text-end text-nowrap">' +
          (can('employee.edit') ? actionBtn('edit', 'Pages.employees.edit(\'' + e.EmployeeID + '\')') : '') +
          (can('employee.delete') || can('*') ? actionBtn('delete', 'Pages.employees.remove(\'' + e.EmployeeID + '\')', 'text-danger') : '') +
          '</td></tr>';
      }).join('') || '<tr><td colspan="9" class="text-center text-muted py-4">No employees found.</td></tr>';
    });
  }

  /** Small icon action button. */
  window.actionBtn = function (icon, onclick, cls) {
    return '<button class="btn btn-sm btn-link p-1 ' + (cls || '') + '" onclick="' + onclick + '">' +
      '<span class="material-icons" style="font-size:17px">' + icon + '</span></button>';
  };

  /** Renders the employee editor form inside the shared modal. */
  function editorHtml(e) {
    e = e || {};
    var lk = App.lookups;
    function inp(label, name, type, col, extra) {
      return '<div class="col-md-' + (col || 4) + '"><label class="form-label">' + label +
        '</label><input class="form-control form-control-sm" type="' + (type || 'text') +
        '" name="' + name + '" value="' + esc(e[name] || '') + '" ' + (extra || '') + '></div>';
    }
    return '<form id="emp-form" class="row g-2">' +
      '<input type="hidden" name="EmployeeID" value="' + esc(e.EmployeeID || '') + '">' +
      '<div class="col-12 fw-bold text-primary small">PERSONAL INFORMATION</div>' +
      inp('Employee Number', 'EmployeeNo', 'text', 3) +
      inp('Last Name *', 'LastName', 'text', 3) + inp('First Name *', 'FirstName', 'text', 3) +
      inp('Middle Name', 'MiddleName', 'text', 2) + inp('Suffix', 'Suffix', 'text', 1) +
      inp('Birthdate', 'Birthdate', 'date', 3) +
      '<div class="col-md-3"><label class="form-label">Gender</label>' +
      '<select class="form-select form-select-sm" name="Gender">' +
      options(['', 'Male', 'Female'], null, null, e.Gender) + '</select></div>' +
      inp('Contact Number', 'Contact', 'text', 3) + inp('Email', 'Email', 'email', 3) +
      inp('Address', 'Address', 'text', 12) +
      '<div class="col-12 fw-bold text-primary small mt-2">GOVERNMENT IDs</div>' +
      inp('TIN', 'TIN', 'text', 3) + inp('GSIS', 'GSIS', 'text', 3) +
      inp('PhilHealth', 'PhilHealth', 'text', 3) + inp('Pag-IBIG', 'PagIBIG', 'text', 3) +
      inp('Cash Card No.', 'CashCard', 'text', 3) +
      '<div class="col-12 fw-bold text-primary small mt-2">BENEFITS &amp; DEDUCTIONS</div>' +
      '<div class="col-md-4 d-flex align-items-end pb-1">' +
      '<div class="form-check"><input class="form-check-input" type="checkbox" name="SSSDeductionApproved" ' +
      'id="emp-sss-approved"' + (e.SSSDeductionApproved ? ' checked' : '') + '>' +
      '<label class="form-check-label" for="emp-sss-approved">Employee approved SSS deduction</label></div></div>' +
      inp('BIR Tax Percent (%)', 'BIRTaxPercent', 'number', 4, 'step="0.01" min="0" max="100"') +
      '<div class="col-12 fw-bold text-primary small mt-2">EMPLOYMENT</div>' +
      '<div class="col-md-4"><label class="form-label">Office *</label>' +
      '<select class="form-select form-select-sm" name="OfficeCode">' +
      options(lk.offices, 'OfficeCode', 'OfficeName', e.OfficeCode, '-- select --') + '</select></div>' +
      '<div class="col-md-4"><label class="form-label">Department</label>' +
      '<select class="form-select form-select-sm" name="Department">' +
      options(lk.departments, 'DeptName', 'DeptName', e.Department, '') + '</select></div>' +
      inp('Division', 'Division', 'text', 4) +
      '<div class="col-md-4"><label class="form-label">Function / Fund</label>' +
      '<select class="form-select form-select-sm" name="Function">' +
      options(lk.functions, 'FunctionName', 'FunctionName', e.Function, '') + '</select></div>' +
      '<div class="col-md-4"><label class="form-label">Employment Type *</label>' +
      '<select class="form-select form-select-sm" name="EmploymentType">' +
      options(lk.employmentTypes, null, null, e.EmploymentType, '-- select --') + '</select></div>' +
      inp('Position *', 'Position', 'text', 4) +
      '<div class="col-md-3"><label class="form-label">Rate Basis</label>' +
      '<select class="form-select form-select-sm" name="RateBasis">' +
      options(['Daily', 'Monthly', 'Hourly'], null, null, e.RateBasis || 'Daily') + '</select></div>' +
      inp('Salary Rate', 'SalaryRate', 'number', 3, 'step="0.01" min="0"') +
      inp('Date Hired', 'DateHired', 'date', 2) +
      inp('Contract Start', 'ContractStart', 'date', 2) +
      inp('Contract End', 'ContractEnd', 'date', 2) +
      '<div class="col-md-3"><label class="form-label">Status</label>' +
      '<select class="form-select form-select-sm" name="Status">' +
      options(lk.statuses, null, null, e.Status || 'Active') + '</select></div>' +
      inp('Photo URL', 'PhotoURL', 'text', 4) +
      inp('Digital Signature URL', 'SignatureURL', 'text', 5) +
      inp('Remarks', 'Remarks', 'text', 12) +
      '</form>';
  }

  /** Opens the editor and saves via apiSaveEmployee. */
  function openEditor(e) {
    openModal(e ? 'Edit Employee' : 'New Employee', editorHtml(e), [
      { label: 'Cancel', cls: 'btn-outline-secondary', onclick: closeModal },
      {
        label: 'Save Employee', onclick: function () {
          var p = formData(document.getElementById('emp-form'));
          busy(api('apiSaveEmployee', p)).then(function () {
            toast('Employee saved.');
            closeModalSaved(); load();
          });
        }
      }
    ]);
  }

  return {
    init: function () {
      var lk = App.lookups;
      document.getElementById('emp-f-office').innerHTML =
        options(lk.offices, 'OfficeCode', 'OfficeName', '', 'All Offices');
      document.getElementById('emp-f-type').innerHTML =
        options(lk.employmentTypes, null, null, '', 'All Types');
      document.getElementById('emp-f-status').innerHTML =
        options(lk.statuses, null, null, '', 'All Status');

      var reload = function () { state.page = 1; load(); };
      document.getElementById('emp-search').oninput = debounce(reload);
      ['emp-f-office', 'emp-f-type', 'emp-f-status'].forEach(function (id) {
        document.getElementById(id).onchange = reload;
      });
      document.getElementById('emp-prev').onclick = function () {
        if (state.page > 1) { state.page--; load(); } };
      document.getElementById('emp-next').onclick = function () { state.page++; load(); };

      var add = document.getElementById('emp-add');
      add.style.display = can('employee.edit') ? '' : 'none';
      add.onclick = function () { openEditor(null); };
      state.page = 1;
      load();
    },
    edit: function (id) {
      busy(api('apiGetEmployee', { EmployeeID: id })).then(openEditor);
    },
    remove: function (id) {
      confirmDlg('Delete this employee? Employees on existing payrolls cannot be deleted.',
        function () {
          busy(api('apiDeleteEmployee', { EmployeeID: id })).then(function () {
            toast('Employee deleted.'); load();
          });
        });
    }
  };
})();

/* ==================== Timekeepers module ==================== */
Pages.timekeepers = (function () {
  function load() {
    api('apiListTimekeepers', { search: document.getElementById('tk-search').value })
      .then(function (rows) {
        document.getElementById('tk-rows').innerHTML = rows.map(function (t) {
          return '<tr><td>' + esc(t.TimekeeperID) + '</td>' +
            '<td class="fw-semibold">' + esc(t.EmployeeName) + '</td>' +
            '<td>' + esc(t.OfficeCode) + '</td><td>' + esc(t.Department) + '</td>' +
            '<td>' + esc(t.Contact) + '</td><td>' + esc(t.Email) + '</td>' +
            '<td>' + badge(t.Status) + '</td>' +
            '<td class="text-end text-nowrap">' +
            (can('timekeeper.edit') ?
              actionBtn('edit', 'Pages.timekeepers.edit(\'' + t.TimekeeperID + '\')') +
              actionBtn('delete', 'Pages.timekeepers.remove(\'' + t.TimekeeperID + '\')', 'text-danger') : '') +
            '</td></tr>';
        }).join('') || '<tr><td colspan="8" class="text-center text-muted py-4">No timekeepers.</td></tr>';
      });
  }

  function openEditor(t) {
    t = t || {};
    openModal(t.TimekeeperID ? 'Edit Timekeeper' : 'New Timekeeper',
      '<form id="tk-form" class="row g-2">' +
      '<input type="hidden" name="TimekeeperID" value="' + esc(t.TimekeeperID || '') + '">' +
      '<div class="col-md-6"><label class="form-label">Employee Name *</label>' +
      '<input class="form-control form-control-sm" name="EmployeeName" value="' + esc(t.EmployeeName || '') + '"></div>' +
      '<div class="col-md-6"><label class="form-label">Office *</label>' +
      '<select class="form-select form-select-sm" name="OfficeCode">' +
      options(App.lookups.offices, 'OfficeCode', 'OfficeName', t.OfficeCode, '-- select --') + '</select></div>' +
      '<div class="col-md-6"><label class="form-label">Department</label>' +
      '<input class="form-control form-control-sm" name="Department" value="' + esc(t.Department || '') + '"></div>' +
      '<div class="col-md-6"><label class="form-label">Contact</label>' +
      '<input class="form-control form-control-sm" name="Contact" value="' + esc(t.Contact || '') + '"></div>' +
      '<div class="col-md-6"><label class="form-label">Email</label>' +
      '<input class="form-control form-control-sm" name="Email" value="' + esc(t.Email || '') + '"></div>' +
      '<div class="col-md-6"><label class="form-label">Status</label>' +
      '<select class="form-select form-select-sm" name="Status">' +
      options(['Active', 'Inactive'], null, null, t.Status || 'Active') + '</select></div></form>',
      [
        { label: 'Cancel', cls: 'btn-outline-secondary', onclick: closeModal },
        {
          label: 'Save', onclick: function () {
            busy(api('apiSaveTimekeeper', formData(document.getElementById('tk-form'))))
              .then(function () { toast('Timekeeper saved.'); closeModalSaved(); load(); loadLookups(); });
          }
        }
      ]);
  }

  return {
    init: function () {
      document.getElementById('tk-search').oninput = debounce(load);
      var add = document.getElementById('tk-add');
      add.style.display = can('timekeeper.edit') ? '' : 'none';
      add.onclick = function () { openEditor(null); };
      load();
    },
    edit: function (id) {
      api('apiListTimekeepers', {}).then(function (rows) {
        openEditor(rows.filter(function (r) { return r.TimekeeperID === id; })[0]);
      });
    },
    remove: function (id) {
      confirmDlg('Delete this timekeeper?', function () {
        busy(api('apiDeleteTimekeeper', { TimekeeperID: id })).then(function () {
          toast('Timekeeper deleted.'); load(); loadLookups();
        });
      });
    }
  };
})();

/* ==================== Departments / Offices / Functions module ============ */
Pages.departments = (function () {
  var tab = 'offices';

  /** Per-tab configuration: table layout, endpoints and editor fields. */
  var CFG = {
    offices: {
      head: ['Code', 'Office Name', 'Department', 'Division', 'Function', 'Office Head', 'Status', ''],
      list: 'apiListOffices', save: 'apiSaveOffice', del: 'apiDeleteOffice', key: 'OfficeCode',
      cols: ['OfficeCode', 'OfficeName', 'Department', 'Division', 'Function', 'OfficeHead'],
      fields: [['OfficeCode', 'Office Code *'], ['OfficeName', 'Office Name *'],
        ['Department', 'Department'], ['Division', 'Division'],
        ['Function', 'Function'], ['OfficeHead', 'Office Head'],
        ['FunctionCode', 'Function / PPA charged', 'functions', 'FunctionCode', 'FunctionName'],
        ['ParentOfficeCode', 'Parent Office', 'offices', 'OfficeCode', 'OfficeName']]
    },
    departments: {
      head: ['Code', 'Department Name', 'Office', 'Head', 'Status', ''],
      list: 'apiListDepartments', save: 'apiSaveDepartment', del: 'apiDeleteDepartment', key: 'DeptCode',
      cols: ['DeptCode', 'DeptName', 'OfficeCode', 'Head'],
      fields: [['DeptCode', 'Department Code *'], ['DeptName', 'Department Name *'],
        ['OfficeCode', 'Office Code'], ['Head', 'Department Head'],
        ['ParentDeptCode', 'Parent Department', 'departments', 'DeptCode', 'DeptName']]
    },
    functions: {
      head: ['Code', 'Function Name', 'Description', 'Status', ''],
      list: 'apiListFunctions', save: 'apiSaveFunction', del: 'apiDeleteFunction', key: 'FunctionCode',
      cols: ['FunctionCode', 'FunctionName', 'Description'],
      fields: [['FunctionCode', 'Function Code *'], ['FunctionName', 'Function Name *'],
        ['Description', 'Description'],
        ['OwningOfficeCode', 'Owning Office', 'offices', 'OfficeCode', 'OfficeName']]
    }
  };

  function load() {
    var c = CFG[tab];
    document.getElementById('org-head').innerHTML =
      '<tr>' + c.head.map(function (h) { return '<th>' + h + '</th>'; }).join('') + '</tr>';
    api(c.list, { search: document.getElementById('org-search').value }).then(function (rows) {
      document.getElementById('org-rows').innerHTML = rows.map(function (r) {
        return '<tr>' + c.cols.map(function (col, i) {
          return '<td class="' + (i === 1 ? 'fw-semibold' : '') + '">' + esc(r[col]) + '</td>';
        }).join('') +
          '<td>' + badge(r.Status) + '</td>' +
          '<td class="text-end text-nowrap">' +
          (can('office.edit') ?
            actionBtn('edit', 'Pages.departments.edit(\'' + esc(r[c.key]) + '\')') +
            actionBtn('delete', 'Pages.departments.remove(\'' + esc(r[c.key]) + '\')', 'text-danger') : '') +
          '</td></tr>';
      }).join('') || '<tr><td colspan="9" class="text-center text-muted py-4">No records.</td></tr>';
    });
  }

  function openEditor(rec) {
    var c = CFG[tab];
    rec = rec || {};
    openModal((rec[c.key] ? 'Edit ' : 'New ') + tab.replace(/s$/, ''),
      '<form id="org-form" class="row g-2">' +
      c.fields.map(function (f) {
        // f[2] names a lookup: render a picker instead of a text box. These
        // fields are foreign keys, and a typed code that matches no row is a
        // constraint violation the user cannot act on - "-- none --" is the
        // only way to clear one.
        if (f[2]) {
          var lk = App.lookups[f[2]] || [];
          return '<div class="col-md-6"><label class="form-label">' + f[1] + '</label>' +
            '<select class="form-select form-select-sm" name="' + f[0] + '">' +
            options(lk, f[3], f[4], rec[f[0]] || '', '-- none --') + '</select></div>';
        }
        return '<div class="col-md-6"><label class="form-label">' + f[1] + '</label>' +
          '<input class="form-control form-control-sm" name="' + f[0] +
          '" value="' + esc(rec[f[0]] || '') + '"></div>';
      }).join('') +
      '<div class="col-md-6"><label class="form-label">Status</label>' +
      '<select class="form-select form-select-sm" name="Status">' +
      options(['Active', 'Inactive'], null, null, rec.Status || 'Active') + '</select></div></form>',
      [
        { label: 'Cancel', cls: 'btn-outline-secondary', onclick: closeModal },
        {
          label: 'Save', onclick: function () {
            busy(api(c.save, formData(document.getElementById('org-form'))))
              .then(function () { toast('Saved.'); closeModalSaved(); load(); loadLookups(); });
          }
        }
      ]);
  }

  return {
    init: function () {
      document.querySelectorAll('#dept-tabs a').forEach(function (a) {
        a.onclick = function () {
          document.querySelectorAll('#dept-tabs a').forEach(function (x) {
            x.classList.toggle('active', x === a);
          });
          tab = a.dataset.tab;
          load();
        };
      });
      document.getElementById('org-search').oninput = debounce(load);
      var add = document.getElementById('org-add');
      add.style.display = can('office.edit') ? '' : 'none';
      add.onclick = function () { openEditor(null); };
      load();
    },
    edit: function (key) {
      var c = CFG[tab];
      api(c.list, {}).then(function (rows) {
        openEditor(rows.filter(function (r) { return String(r[c.key]) === key; })[0]);
      });
    },
    remove: function (key) {
      var c = CFG[tab];
      confirmDlg('Delete this record?', function () {
        var p = {}; p[c.key] = key;
        busy(api(c.del, p)).then(function () { toast('Deleted.'); load(); loadLookups(); });
      });
    }
  };
})();
</script>
