<!-- ==========================================================================
     reports.html - Report runner: pick a type + filters, view, print, CSV.
     ========================================================================== -->
<section class="page" id="page-reports">
  <div class="card">
    <div class="card-body py-2">
      <div class="row g-2 align-items-end">
        <div class="col-md-3"><label class="form-label">Report</label>
          <select class="form-select form-select-sm" id="rp-type">
            <option value="monthly">Monthly Payroll</option>
            <option value="office">Office Payroll</option>
            <option value="department">Department Payroll</option>
            <option value="summary">Payroll Summary</option>
            <option value="history">Employee Payroll History</option>
            <option value="register">Payroll Register</option>
            <option value="journal">Payroll Journal</option>
            <option value="gross">Gross Pay Report</option>
            <option value="deduction">Deduction Report</option>
            <option value="net">Net Pay Report</option>
          </select></div>
        <div class="col-md-2"><label class="form-label">Period</label>
          <select class="form-select form-select-sm" id="rp-period"></select></div>
        <div class="col-md-2"><label class="form-label">Office</label>
          <select class="form-select form-select-sm" id="rp-office"></select></div>
        <div class="col-md-3" id="rp-emp-wrap" style="display:none">
          <label class="form-label">Employee</label>
          <select class="form-select form-select-sm" id="rp-employee"></select></div>
        <div class="col-md-2 text-end">
          <button class="btn btn-sm btn-gov" id="rp-run">
            <span class="material-icons">play_arrow</span> Run</button>
        </div>
      </div>
    </div>
  </div>

  <div class="card mt-3" id="rp-result" style="display:none">
    <div class="card-header py-2 d-flex align-items-center">
      <div>
        <div id="rp-title" class="fw-bold"></div>
        <small class="text-muted" id="rp-filters"></small>
      </div>
      <div class="ms-auto">
        <button class="btn btn-sm btn-outline-secondary" id="rp-csv">
          <span class="material-icons">download</span> CSV</button>
        <button class="btn btn-sm btn-outline-secondary" id="rp-print">
          <span class="material-icons">print</span> Print</button>
      </div>
    </div>
    <div class="table-responsive" style="max-height:65vh">
      <table class="table table-hover table-sm">
        <thead id="rp-head" class="sticky-top"></thead>
        <tbody id="rp-body"></tbody>
        <tfoot id="rp-foot"></tfoot>
      </table>
    </div>
  </div>
</section>

<script>
/** Reports page module. */
Pages.reports = (function () {
  var current = null;   // last report payload for CSV / print

  /** Runs the selected report and renders the result table. */
  function run() {
    var type = document.getElementById('rp-type').value;
    var p = {
      type: type,
      PeriodID: document.getElementById('rp-period').value,
      OfficeCode: document.getElementById('rp-office').value,
      EmployeeID: document.getElementById('rp-employee').value
    };
    if (type === 'history' && !p.EmployeeID) {
      return toast('Select an employee for the history report.', 'warning');
    }
    busy(api('apiRunReport', p)).then(function (r) {
      current = r;
      document.getElementById('rp-result').style.display = '';
      document.getElementById('rp-title').textContent = r.title;
      document.getElementById('rp-filters').textContent =
        r.filters + '  |  Generated ' + r.generatedAt;

      document.getElementById('rp-head').innerHTML = '<tr>' + r.columns.map(function (c) {
        return '<th class="' + (c.money ? 'text-end' : '') + '">' + esc(c.label) + '</th>';
      }).join('') + '</tr>';

      document.getElementById('rp-body').innerHTML = r.rows.map(function (row) {
        return '<tr>' + r.columns.map(function (c) {
          return c.money ?
            '<td class="text-money">' + fmtMoney(row[c.key]) + '</td>' :
            '<td>' + esc(row[c.key]) + '</td>';
        }).join('') + '</tr>';
      }).join('') || '<tr><td colspan="' + r.columns.length +
        '" class="text-center text-muted py-4">No data for the selected filters.</td></tr>';

      document.getElementById('rp-foot').innerHTML = r.rows.length ?
        '<tr class="grid-total-row">' + r.columns.map(function (c, i) {
          if (i === 0) return '<td class="text-end">TOTAL</td>';
          return c.money ? '<td class="text-money">' + fmtMoney(r.totals[c.key]) + '</td>' : '<td></td>';
        }).join('') + '</tr>' : '';
    });
  }

  /** Downloads the current report as CSV. */
  function downloadCsv() {
    if (!current) return;
    var lines = [current.columns.map(function (c) { return '"' + c.label + '"'; }).join(',')];
    current.rows.forEach(function (row) {
      lines.push(current.columns.map(function (c) {
        return '"' + String(row[c.key] === undefined ? '' : row[c.key]).replace(/"/g, '""') + '"';
      }).join(','));
    });
    var blob = new Blob([lines.join('\n')], { type: 'text/csv' });
    var a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = current.title.replace(/[^\w]+/g, '_') + '.csv';
    a.click();
    URL.revokeObjectURL(a.href);
  }

  /** Opens a print-friendly window with the current report. */
  function printReport() {
    if (!current) return;
    var gov = (App.session.settings && App.session.settings.governmentName) || '';
    var w = window.open('', '_blank');
    w.document.write('<html><head><title>' + esc(current.title) + '</title><style>' +
      'body{font-family:Arial,sans-serif;font-size:11px;margin:24px}' +
      'h3,h5{text-align:center;margin:2px}table{width:100%;border-collapse:collapse;margin-top:12px}' +
      'th,td{border:1px solid #444;padding:3px 6px}th{background:#eee}' +
      '.r{text-align:right}.tot{font-weight:bold;background:#f5f5f5}</style></head><body>' +
      '<h3>' + esc(gov) + '</h3><h5>' + esc(current.title) + '</h5>' +
      '<p style="text-align:center;font-size:10px">' + esc(current.filters) +
      ' | Generated ' + esc(current.generatedAt) + '</p><table><thead><tr>' +
      current.columns.map(function (c) { return '<th>' + esc(c.label) + '</th>'; }).join('') +
      '</tr></thead><tbody>' +
      current.rows.map(function (row) {
        return '<tr>' + current.columns.map(function (c) {
          return c.money ? '<td class="r">' + fmtMoney(row[c.key]) + '</td>' :
            '<td>' + esc(row[c.key]) + '</td>';
        }).join('') + '</tr>';
      }).join('') +
      '<tr class="tot">' + current.columns.map(function (c, i) {
        if (i === 0) return '<td class="r">TOTAL</td>';
        return c.money ? '<td class="r">' + fmtMoney(current.totals[c.key]) + '</td>' : '<td></td>';
      }).join('') + '</tr></tbody></table>' +
      '<script>window.print()<\/script></body></html>');
    w.document.close();
  }

  return {
    init: function () {
      var lk = App.lookups;
      document.getElementById('rp-period').innerHTML =
        options(lk.periods, 'PeriodID', 'PeriodID', '', 'All Periods');
      document.getElementById('rp-office').innerHTML =
        options(lk.offices, 'OfficeCode', 'OfficeName', '', 'All Offices');

      // Employee dropdown only matters for the history report.
      document.getElementById('rp-type').onchange = function () {
        var isHistory = this.value === 'history';
        document.getElementById('rp-emp-wrap').style.display = isHistory ? '' : 'none';
        if (isHistory && !document.getElementById('rp-employee').options.length) {
          api('apiListEmployees', { pageSize: 2000 }).then(function (d) {
            document.getElementById('rp-employee').innerHTML =
              options(d.rows, 'EmployeeID', 'FullName', '', '-- select employee --');
          });
        }
      };
      document.getElementById('rp-run').onclick = run;
      document.getElementById('rp-csv').onclick = downloadCsv;
      document.getElementById('rp-print').onclick = printReport;
    }
  };
})();
</script>
