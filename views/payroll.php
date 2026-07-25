<!-- ==========================================================================
     payroll.html - Payroll Transactions (list + entry grid + workflow) and
     the Payroll Period register.
     ========================================================================== -->

<!-- ==================== PAYROLL TRANSACTIONS ==================== -->
<section class="page" id="page-payroll">

  <!-- list view -->
  <div id="pr-list-view">
    <div class="card">
      <div class="card-body py-2">
        <div class="row g-2 align-items-end">
          <div class="col-md-3"><label class="form-label">Live Search</label>
            <input class="form-control form-control-sm" id="pr-search" placeholder="Payroll no., office..."></div>
          <div class="col-md-2"><label class="form-label">Period</label>
            <select class="form-select form-select-sm" id="pr-f-period"></select></div>
          <div class="col-md-2"><label class="form-label">Office</label>
            <select class="form-select form-select-sm" id="pr-f-office"></select></div>
          <div class="col-md-2"><label class="form-label">Status</label>
            <select class="form-select form-select-sm" id="pr-f-status"></select></div>
          <div class="col-md-3 text-end">
            <button class="btn btn-sm btn-outline-secondary" id="pr-undo" title="Undo last change" style="display:none">
              <span class="material-icons">undo</span></button>
            <button class="btn btn-sm btn-gov" id="pr-add" style="display:none">
              <span class="material-icons">post_add</span> New Payroll</button>
          </div>
        </div>
      </div>
    </div>

    <div class="card mt-3"><div class="table-responsive">
      <table class="table table-hover">
        <thead><tr>
          <th>Payroll No.</th><th>Period</th><th>Office</th><th>Fund</th>
          <th class="text-end">Gross</th><th class="text-end">Net</th>
          <th>Status</th><th class="text-end">Actions</th>
        </tr></thead>
        <tbody id="pr-rows"></tbody>
      </table>
    </div></div>
  </div>

  <!-- editor view -->
  <div id="pr-edit-view" style="display:none">
    <div class="card">
      <div class="card-header py-2 d-flex align-items-center">
        <span id="pr-edit-title">New Payroll Transaction</span>
        <button class="btn btn-sm btn-outline-secondary ms-auto" id="pr-back">
          <span class="material-icons">arrow_back</span> Back to list</button>
      </div>
      <div class="card-body">
        <div class="row g-2">
          <div class="col-md-3"><label class="form-label">Payroll Period *</label>
            <select class="form-select form-select-sm" id="pr-period"></select></div>
          <div class="col-md-3"><label class="form-label">Office *</label>
            <select class="form-select form-select-sm" id="pr-office"></select></div>
          <div class="col-md-3"><label class="form-label">Timekeeper</label>
            <select class="form-select form-select-sm" id="pr-timekeeper"></select></div>
          <div class="col-md-3"><label class="form-label">Remarks</label>
            <input class="form-control form-control-sm" id="pr-remarks"></div>
        </div>

        <div class="d-flex align-items-center mt-3 mb-2 gap-3">
          <h6 class="mb-0">Employee Entry Grid <small class="text-muted" id="pr-grid-count"></small></h6>
          <button class="btn btn-sm btn-outline-primary" id="pr-add-row">
            <span class="material-icons">add</span> Add Employee</button>
          <div class="form-check ms-auto">
            <input class="form-check-input" type="checkbox" id="pr-allow-dup">
            <label class="form-check-label small" for="pr-allow-dup">Allow duplicates across payrolls</label>
          </div>
        </div>

        <div class="table-responsive">
          <table class="table table-sm" id="grid-table">
            <thead><tr>
              <th style="min-width:220px">Employee</th><th>Rate/Day</th>
              <th>Days</th><th>Hours</th><th>OT&nbsp;Hrs</th><th>Late&nbsp;(min)</th>
              <th>UT&nbsp;(min)</th><th>Absent</th>
              <th class="text-end">Gross</th><th>Tax</th><th>Cash&nbsp;Adv</th><th>Other&nbsp;Ded</th>
              <th class="text-end">Net</th><th style="min-width:110px">Remarks</th><th></th>
            </tr></thead>
            <tbody id="grid-body"></tbody>
            <tfoot><tr class="grid-total-row">
              <td colspan="8" class="text-end">TOTALS</td>
              <td class="text-money" id="tot-gross">0.00</td>
              <td colspan="3" class="text-money" id="tot-ded">0.00</td>
              <td class="text-money" id="tot-net">0.00</td><td colspan="2"></td>
            </tr></tfoot>
          </table>
        </div>

        <div class="text-end mt-3">
          <button class="btn btn-gov" id="pr-save">
            <span class="material-icons">save</span> Save Payroll</button>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- ==================== PAYROLL PERIODS ==================== -->
<section class="page" id="page-periods">
  <div class="card">
    <div class="card-body py-2 d-flex gap-2">
      <input class="form-control form-control-sm flex-grow-1" id="prd-search" placeholder="Live search periods...">
      <button class="btn btn-sm btn-gov" id="prd-add" style="display:none">
        <span class="material-icons">add</span> New Period</button>
    </div>
  </div>
  <div class="card mt-3"><div class="table-responsive">
    <table class="table table-hover">
      <thead><tr><th>Month</th><th>Year</th><th>Start</th><th>End</th><th>Status</th><th></th></tr></thead>
      <tbody id="prd-rows"></tbody>
    </table>
  </div></div>
</section>

<script>
/* ==================== Payroll Transactions module ==================== */
Pages.payroll = (function () {
  var editing = null;         // PayrollNo being edited, or null for new
  var employees = [];         // active employees available to the grid
  var maxRows = 15;
  var dirtyBound = false;     // editor input listener attached once

  /* ---------- list ---------- */

  function loadList() {
    api('apiListPayrolls', {
      search: document.getElementById('pr-search').value,
      PeriodID: document.getElementById('pr-f-period').value,
      OfficeCode: document.getElementById('pr-f-office').value,
      Status: document.getElementById('pr-f-status').value
    }).then(function (rows) {
      document.getElementById('pr-rows').innerHTML = rows.map(function (r) {
        var acts = actionBtn('visibility', 'Pages.payroll.view(\'' + r.PayrollNo + '\')');
        if (can('payroll.edit') && (r.Status === 'Draft' || r.Status === 'Pending'))
          acts += actionBtn('edit', 'Pages.payroll.open(\'' + r.PayrollNo + '\')');
        if (can('payroll.submit') && r.Status === 'Draft')
          acts += actionBtn('send', 'Pages.payroll.move(\'' + r.PayrollNo + '\',\'submit\')');
        if (can('payroll.approve') && r.Status === 'Pending')
          acts += actionBtn('check_circle', 'Pages.payroll.move(\'' + r.PayrollNo + '\',\'approve\')', 'text-success') +
            actionBtn('keyboard_return', 'Pages.payroll.move(\'' + r.PayrollNo + '\',\'return\')', 'text-warning');
        if (can('payroll.release') && r.Status === 'Approved')
          acts += actionBtn('paid', 'Pages.payroll.move(\'' + r.PayrollNo + '\',\'release\')', 'text-success');
        if (can('payroll.edit') && ['Draft', 'Pending', 'Approved'].indexOf(r.Status) >= 0)
          acts += actionBtn('cancel', 'Pages.payroll.move(\'' + r.PayrollNo + '\',\'cancel\')', 'text-danger');
        if (can('payroll.edit') && r.Status === 'Draft')
          acts += actionBtn('delete', 'Pages.payroll.remove(\'' + r.PayrollNo + '\')', 'text-danger');

        return '<tr><td class="fw-semibold">' + esc(r.PayrollNo) + '</td>' +
          '<td>' + esc(r.PeriodID) + '</td><td>' + esc(r.OfficeCode) + '</td>' +
          '<td>' + esc(r.Function) + '</td>' +
          '<td class="text-money">' + fmtMoney(r.TotalGross) + '</td>' +
          '<td class="text-money">' + fmtMoney(r.TotalNet) + '</td>' +
          '<td>' + badge(r.Status) + '</td>' +
          '<td class="text-end text-nowrap">' + acts + '</td></tr>';
      }).join('') || '<tr><td colspan="8" class="text-center text-muted py-4">No payroll transactions.</td></tr>';
    });
  }

  /* ---------- entry grid ---------- */

  /** Loads employees of the selected office for the grid dropdowns. */
  function loadGridEmployees() {
    var office = document.getElementById('pr-office').value;
    return api('apiListEmployees', { OfficeCode: office, Status: 'Active', pageSize: 1000 })
      .then(function (d) { employees = d.rows; });
  }

  /** Appends one grid row (optionally pre-filled from a detail record). */
  function addRow(d) {
    var body = document.getElementById('grid-body');
    if (body.children.length >= maxRows) {
      return toast('Maximum of ' + maxRows + ' employees per payroll transaction.', 'warning');
    }
    d = d || {};
    var tr = document.createElement('tr');
    tr.innerHTML =
      '<td><select class="form-select form-select-sm g-emp">' +
      options(employees, 'EmployeeID', 'FullName', d.EmployeeID, '-- select employee --') +
      '</select></td>' +
      '<td class="text-money g-rate">' + (d.SalaryRate ? fmtMoney(d.SalaryRate) : '') + '</td>' +
      numCell('DaysWorked', d.DaysWorked) + numCell('HoursWorked', d.HoursWorked) +
      numCell('OvertimeHours', d.OvertimeHours) + numCell('LateMinutes', d.LateMinutes) +
      numCell('UndertimeMinutes', d.UndertimeMinutes) + numCell('AbsentDays', d.AbsentDays) +
      '<td class="text-money g-gross">' + (d.GrossPay !== undefined ? fmtMoney(d.GrossPay) : '0.00') + '</td>' +
      numCell('Tax', d.Tax) + numCell('CashAdvance', d.CashAdvance) + numCell('OtherDeductions', d.OtherDeductions) +
      '<td class="text-money g-net fw-bold">' + (d.NetPay !== undefined ? fmtMoney(d.NetPay) : '0.00') + '</td>' +
      '<td><input class="form-control form-control-sm" data-f="Remarks" value="' + esc(d.Remarks || '') + '" style="text-align:left"></td>' +
      '<td>' + actionBtn('close', '', 'text-danger g-del') + '</td>';

    tr.querySelector('.g-emp').onchange = function () {
      var id = this.value;
      var emp = employees.filter(function (e) { return e.EmployeeID === id; })[0];
      tr.querySelector('.g-rate').textContent = emp ? fmtMoney(emp.DailyRate) : '';
      recompute();
    };
    tr.querySelectorAll('input').forEach(function (i) { i.oninput = debounce(recompute, 500); });
    tr.querySelector('.g-del').onclick = function () { tr.remove(); recompute(); };
    body.appendChild(tr);
    updateCount();
  }

  /** Numeric grid cell helper. */
  function numCell(field, value) {
    return '<td><input class="form-control form-control-sm" type="number" min="0" step="0.01" ' +
      'data-f="' + field + '" value="' + (value === undefined || value === '' ? '' : esc(value)) + '"></td>';
  }

  function updateCount() {
    document.getElementById('pr-grid-count').textContent =
      '(' + document.getElementById('grid-body').children.length + ' / ' + maxRows + ')';
  }

  /** Reads the grid into an array of line payloads. */
  function readLines() {
    return Array.prototype.map.call(document.getElementById('grid-body').children, function (tr) {
      var line = { EmployeeID: tr.querySelector('.g-emp').value };
      tr.querySelectorAll('input[data-f]').forEach(function (i) { line[i.dataset.f] = i.value; });
      return line;
    }).filter(function (l) { return l.EmployeeID; });
  }

  /** Server-side recompute keeps every figure authoritative. */
  var recompute = debounce(function () {
    var lines = readLines();
    if (!lines.length) return renderTotals({ gross: 0, deductions: 0, net: 0 }, []);
    api('apiComputePayroll', { lines: lines }, true).then(function (d) {
      var trs = document.getElementById('grid-body').children;
      var li = 0;
      Array.prototype.forEach.call(trs, function (tr) {
        if (!tr.querySelector('.g-emp').value) return;
        var c = d.lines[li++];
        if (!c) return;
        tr.querySelector('.g-rate').textContent = fmtMoney(c.SalaryRate);
        tr.querySelector('.g-gross').textContent = fmtMoney(c.GrossPay);
        tr.querySelector('.g-net').textContent = fmtMoney(c.NetPay);
        var taxInput = tr.querySelector('input[data-f="Tax"]');
        if (taxInput.value === '') taxInput.placeholder = fmtMoney(c.Tax);
      });
      renderTotals(d.totals);
    }).catch(function () { });
  }, 600);

  function renderTotals(t) {
    document.getElementById('tot-gross').textContent = fmtMoney(t.gross);
    document.getElementById('tot-ded').textContent = fmtMoney(t.deductions);
    document.getElementById('tot-net').textContent = fmtMoney(t.net);
  }

  /* ---------- editor open/save ---------- */

  function showEditor(show) {
    document.getElementById('pr-list-view').style.display = show ? 'none' : '';
    document.getElementById('pr-edit-view').style.display = show ? '' : 'none';
  }

  /** Opens a blank editor or loads an existing payroll into it. */
  function openEditor(payrollNo) {
    editing = payrollNo || null;
    var lk = App.lookups;
    maxRows = (App.session.settings && App.session.settings.maxEmployeesPerPayroll) || 15;

    document.getElementById('pr-period').innerHTML =
      '<option value="">-- select period --</option>' +
      lk.periods.filter(function (p) { return p.Status === 'Open'; }).map(function (p) {
        return '<option value="' + esc(p.PeriodID) + '">' +
          esc(p.PayrollMonth + ' ' + p.PayrollYear + ' (' +
            String(p.StartDate).slice(0, 10) + ' to ' + String(p.EndDate).slice(0, 10) + ')') +
          '</option>';
      }).join('');
    document.getElementById('pr-office').innerHTML =
      options(lk.offices, 'OfficeCode', 'OfficeName', '', '-- select office --');
    document.getElementById('pr-timekeeper').innerHTML =
      options(lk.timekeepers, 'TimekeeperID', 'EmployeeName', '', '-- none --');
    document.getElementById('pr-remarks').value = '';
    document.getElementById('grid-body').innerHTML = '';
    document.getElementById('pr-allow-dup').checked = false;
    renderTotals({ gross: 0, deductions: 0, net: 0 });
    updateCount();
    App.editorDirty = false;

    document.getElementById('pr-edit-title').textContent =
      payrollNo ? 'Edit Payroll ' + payrollNo : 'New Payroll Transaction';
    showEditor(true);

    if (!payrollNo) { employees = []; return; }

    busy(api('apiGetPayroll', { PayrollNo: payrollNo })).then(function (d) {
      document.getElementById('pr-period').value = d.header.PeriodID;
      document.getElementById('pr-office').value = d.header.OfficeCode;
      document.getElementById('pr-timekeeper').value = d.header.TimekeeperID || '';
      document.getElementById('pr-remarks').value = d.header.Remarks || '';
      return loadGridEmployees().then(function () {
        d.details.forEach(addRow);
        renderTotals({ gross: d.header.TotalGross, deductions: d.header.TotalDeductions,
          net: d.header.TotalNet });
      });
    });
  }

  function save() {
    var p = {
      PayrollNo: editing || '',
      PeriodID: document.getElementById('pr-period').value,
      OfficeCode: document.getElementById('pr-office').value,
      TimekeeperID: document.getElementById('pr-timekeeper').value,
      Remarks: document.getElementById('pr-remarks').value,
      allowDuplicates: document.getElementById('pr-allow-dup').checked,
      lines: readLines()
    };
    busy(api('apiSavePayroll', p)).then(function (d) {
      toast('Payroll ' + d.PayrollNo + ' saved. Net: ' + fmtMoney(d.totals.net));
      App.editorDirty = false;
      showEditor(false);
      loadList();
    });
  }

  /* ---------- read-only viewer ---------- */

  function viewPayroll(no) {
    busy(api('apiGetPayroll', { PayrollNo: no })).then(function (d) {
      var rows = d.details.map(function (l) {
        return '<tr><td>' + l.LineNo + '</td><td>' + esc(l.EmployeeName) + '</td>' +
          '<td>' + esc(l.Position) + '</td>' +
          '<td class="text-money">' + fmtMoney(l.SalaryRate) + '</td>' +
          '<td class="text-center">' + esc(l.DaysWorked) + '</td>' +
          '<td class="text-money">' + fmtMoney(l.GrossPay) + '</td>' +
          '<td class="text-money">' + fmtMoney(l.TotalDeductions) + '</td>' +
          '<td class="text-money fw-bold">' + fmtMoney(l.NetPay) + '</td></tr>';
      }).join('');
      openModal('Payroll ' + no + ' - ' + d.header.Status,
        '<div class="table-responsive"><table class="table table-sm">' +
        '<thead><tr><th>#</th><th>Employee</th><th>Position</th><th>Rate</th><th>Days</th>' +
        '<th>Gross</th><th>Deductions</th><th>Net</th></tr></thead><tbody>' + rows +
        '</tbody><tfoot><tr class="grid-total-row"><td colspan="5" class="text-end">TOTAL</td>' +
        '<td class="text-money">' + fmtMoney(d.header.TotalGross) + '</td>' +
        '<td class="text-money">' + fmtMoney(d.header.TotalDeductions) + '</td>' +
        '<td class="text-money">' + fmtMoney(d.header.TotalNet) + '</td></tr></tfoot></table></div>',
        [{ label: 'Close', cls: 'btn-outline-secondary', onclick: closeModal }]);
    });
  }

  /* ---------- workflow ---------- */

  var MOVES = {
    submit: ['apiSubmitPayroll', 'submitted for approval'],
    approve: ['apiApprovePayroll', 'approved'],
    return: ['apiReturnPayroll', 'returned to Draft'],
    release: ['apiReleasePayroll', 'released'],
    cancel: ['apiCancelPayroll', 'cancelled']
  };

  return {
    init: function () {
      var lk = App.lookups;
      document.getElementById('pr-f-period').innerHTML =
        options(lk.periods, 'PeriodID', 'PeriodID', '', 'All Periods');
      document.getElementById('pr-f-office').innerHTML =
        options(lk.offices, 'OfficeCode', 'OfficeCode', '', 'All Offices');
      document.getElementById('pr-f-status').innerHTML =
        options(lk.payrollStatuses, null, null, '', 'All Status');

      document.getElementById('pr-search').oninput = debounce(loadList);
      ['pr-f-period', 'pr-f-office', 'pr-f-status'].forEach(function (id) {
        document.getElementById(id).onchange = loadList;
      });

      var add = document.getElementById('pr-add');
      add.style.display = can('payroll.edit') ? '' : 'none';
      add.onclick = function () { openEditor(null); };

      var undo = document.getElementById('pr-undo');
      undo.style.display = can('payroll.edit') ? '' : 'none';
      undo.onclick = function () {
        confirmDlg('Undo your last payroll change?', function () {
          busy(api('apiUndoLast')).then(function (d) {
            toast('Undone: ' + d.undone + ' on ' + d.PayrollNo);
            loadList();
          });
        });
      };

      // Leaving the entry grid with unsaved edits asks for confirmation.
      if (!dirtyBound) {
        dirtyBound = true;
        document.getElementById('pr-edit-view').addEventListener('input', function () {
          App.editorDirty = true;
        });
      }
      document.getElementById('pr-back').onclick = function () {
        if (App.editorDirty &&
            !window.confirm('You have unsaved changes on this payroll. Leave and discard them?')) {
          return;
        }
        App.editorDirty = false;
        showEditor(false);
        loadList();
      };
      document.getElementById('pr-add-row').onclick = function () {
        if (!document.getElementById('pr-office').value) {
          return toast('Select an office first.', 'warning');
        }
        if (employees.length) addRow();
        else busy(loadGridEmployees()).then(function () { addRow(); });
      };
      document.getElementById('pr-office').onchange = function () {
        document.getElementById('grid-body').innerHTML = '';
        updateCount();
        loadGridEmployees();
      };
      document.getElementById('pr-save').onclick = save;

      showEditor(false);
      loadList();
    },
    open: openEditor,
    view: viewPayroll,
    move: function (no, action) {
      var m = MOVES[action];
      confirmDlg('Payroll ' + no + ' will be ' + m[1] + '. Continue?', function () {
        busy(api(m[0], { PayrollNo: no })).then(function () {
          toast('Payroll ' + no + ' ' + m[1] + '.');
          loadList();
        });
      });
    },
    remove: function (no) {
      confirmDlg('Delete draft payroll ' + no + '?', function () {
        busy(api('apiDeletePayroll', { PayrollNo: no })).then(function () {
          toast('Deleted.'); loadList();
        });
      });
    }
  };
})();

/* ==================== Payroll Periods module ==================== */
Pages.periods = (function () {
  function load() {
    api('apiListPeriods', { search: document.getElementById('prd-search').value })
      .then(function (rows) {
        document.getElementById('prd-rows').innerHTML = rows.map(function (r) {
          return '<tr><td class="fw-semibold">' + esc(r.PayrollMonth) + '</td>' +
            '<td>' + esc(r.PayrollYear) + '</td>' +
            '<td>' + fmtDate(r.StartDate) + '</td><td>' + fmtDate(r.EndDate) + '</td>' +
            '<td>' + badge(r.Status) + '</td>' +
            '<td class="text-end text-nowrap">' +
            (can('period.edit') ?
              actionBtn('edit', 'Pages.periods.edit(\'' + r.PeriodID + '\')') +
              actionBtn('delete', 'Pages.periods.remove(\'' + r.PeriodID + '\')', 'text-danger') : '') +
            '</td></tr>';
        }).join('') || '<tr><td colspan="6" class="text-center text-muted py-4">No payroll periods.</td></tr>';
      });
  }

  function openEditor(r) {
    r = r || {};
    var months = ['January', 'February', 'March', 'April', 'May', 'June', 'July',
      'August', 'September', 'October', 'November', 'December'];
    openModal(r.PeriodID ? 'Edit Payroll Period' : 'New Payroll Period',
      '<form id="prd-form" class="row g-2">' +
      '<input type="hidden" name="PeriodID" value="' + esc(r.PeriodID || '') + '">' +
      '<div class="col-md-6"><label class="form-label">Payroll Month *</label>' +
      '<select class="form-select form-select-sm" name="PayrollMonth">' +
      options(months, null, null, r.PayrollMonth, '-- select --') + '</select></div>' +
      '<div class="col-md-6"><label class="form-label">Payroll Year *</label>' +
      '<input class="form-control form-control-sm" type="number" name="PayrollYear" value="' +
      esc(r.PayrollYear || new Date().getFullYear()) + '"></div>' +
      '<div class="col-md-6"><label class="form-label">Start Date *</label>' +
      '<input class="form-control form-control-sm" type="date" name="StartDate" value="' +
      esc(String(r.StartDate || '').slice(0, 10)) + '"></div>' +
      '<div class="col-md-6"><label class="form-label">End Date *</label>' +
      '<input class="form-control form-control-sm" type="date" name="EndDate" value="' +
      esc(String(r.EndDate || '').slice(0, 10)) + '"></div>' +
      '<div class="col-md-6"><label class="form-label">Status</label>' +
      '<select class="form-select form-select-sm" name="Status">' +
      options(['Open', 'Closed', 'Locked'], null, null, r.Status || 'Open') + '</select></div></form>',
      [
        { label: 'Cancel', cls: 'btn-outline-secondary', onclick: closeModal },
        {
          label: 'Save Period', onclick: function () {
            busy(api('apiSavePeriod', formData(document.getElementById('prd-form'))))
              .then(function () { toast('Period saved.'); closeModalSaved(); load(); loadLookups(); });
          }
        }
      ]);
  }

  return {
    init: function () {
      document.getElementById('prd-search').oninput = debounce(load);
      var add = document.getElementById('prd-add');
      add.style.display = can('period.edit') ? '' : 'none';
      add.onclick = function () { openEditor(null); };
      load();
    },
    edit: function (id) {
      api('apiListPeriods', {}).then(function (rows) {
        openEditor(rows.filter(function (r) { return r.PeriodID === id; })[0]);
      });
    },
    remove: function (id) {
      confirmDlg('Delete this payroll period?', function () {
        busy(api('apiDeletePeriod', { PeriodID: id })).then(function () {
          toast('Period deleted.'); load(); loadLookups();
        });
      });
    }
  };
})();
</script>
