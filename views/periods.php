<!-- ==========================================================================
     periods.php - Payroll Period register. Split out of what was a single
     views/payroll.php. See that file's own header for why.
     ========================================================================== -->

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
              actionBtn('edit', 'Pages.periods.edit', [r.PeriodID]) +
              actionBtn('delete', 'Pages.periods.remove', [r.PeriodID], 'text-danger') : '') +
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
