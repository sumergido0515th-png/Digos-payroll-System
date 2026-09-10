<!-- ==========================================================================
     employees.php - Employees only. Timekeepers and Departments/Offices/
     Functions, once bundled in this same file, now live in their own
     views/timekeepers.php and views/departments.php - three independent
     page modules with no cross-references between them, split the same way
     app/PrintDoc.php's seven form renderers were: EXECUTION_BUDGET.md named
     this file, at 415 lines, as one of the three most tempting-and-wasteful
     to fully rewrite for a small change; it had grown to 467 carrying all
     three unrelated screens.
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
      <div class="row g-2 mt-1">
        <div class="col-12 text-end">
          <!-- Same filters the list is showing - see public/export.php. -->
          <a class="btn btn-sm btn-outline-secondary" id="emp-export" href="export.php?entity=Employees" target="_blank">
            <span class="material-icons" style="font-size:16px;vertical-align:-3px">download</span> Export CSV</a>
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

<script>
/* ==================== Employees module ==================== */
Pages.employees = (function () {
  var state = { page: 1, pageSize: 20 };

  /**
   * The filter bar's state alone - what a shared link or an export means by
   * "these employees". Deliberately excludes page/pageSize: a copied link
   * should land on page 1 of the same filters rather than reproducing
   * whichever page someone happened to be looking at, and an export is every
   * matching row, never one page of them - both read from here, not from
   * state.page/pageSize.
   */
  function filters() {
    return {
      search: document.getElementById('emp-search').value,
      OfficeCode: document.getElementById('emp-f-office').value,
      EmploymentType: document.getElementById('emp-f-type').value,
      Status: document.getElementById('emp-f-status').value
    };
  }

  /** Loads and renders the employee table. */
  function load() {
    var f = filters();
    syncUrl('employees', f);
    document.getElementById('emp-export').href = 'export.php?entity=Employees&' + queryString(f);

    api('apiListEmployees', Object.assign({ page: state.page, pageSize: state.pageSize }, f))
      .then(function (d) {
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
    init: function (params) {
      var lk = App.lookups;
      document.getElementById('emp-f-type').innerHTML =
        options(lk.employmentTypes, null, null, '', 'All Types');
      document.getElementById('emp-f-status').innerHTML =
        options(lk.statuses, null, null, '', 'All Status');

      // Scoped - apiGetEmployeeFacets(), never the citywide App.lookups.offices
      // - so this dropdown can never offer an office the caller could not
      // already see an employee in.
      busy(api('apiGetEmployeeFacets')).then(function (facets) {
        document.getElementById('emp-f-office').innerHTML =
          options(facets.OfficeCode || [], null, null, '', 'All Offices (in your scope)');

        params = params || {};
        document.getElementById('emp-search').value = params.search || '';
        document.getElementById('emp-f-office').value = params.OfficeCode || '';
        document.getElementById('emp-f-type').value = params.EmploymentType || '';
        document.getElementById('emp-f-status').value = params.Status || '';
        state.page = 1;
        load();
      });

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
</script>
