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

  <!-- Operational metrics - Phase 10's baseline (docs/PHASE_PLAN.md: reprint
       rate, top suspension grounds, settlement turnaround, pages printed).
       Scoped to the caller like every other read here; a citywide figure
       follows from a citywide scope grant, not from a separate screen. -->
  <div class="card mt-3">
    <div class="card-header py-2">Operational Metrics <small class="text-muted">(Phase 10 baseline)</small></div>
    <div class="card-body py-2">
      <div class="row g-2 align-items-end">
        <div class="col-md-3"><label class="form-label">From</label>
          <input type="date" class="form-control form-control-sm" id="om-from"></div>
        <div class="col-md-3"><label class="form-label">To</label>
          <input type="date" class="form-control form-control-sm" id="om-to"></div>
        <div class="col-md-3">
          <button class="btn btn-sm btn-gov" id="om-run">
            <span class="material-icons" style="font-size:18px;vertical-align:-4px">insights</span>
            Compute</button>
        </div>
      </div>

      <div class="row g-3 mt-2" id="om-tiles" style="display:none">
        <div class="col-6 col-md-3">
          <div class="text-muted small">Official prints</div>
          <div class="fs-4 fw-bold" id="om-prints">-</div>
        </div>
        <div class="col-6 col-md-3">
          <div class="text-muted small">Reprint rate</div>
          <div class="fs-4 fw-bold" id="om-reprint-rate">-</div>
          <div class="text-muted small" id="om-reprint-count"></div>
        </div>
        <div class="col-6 col-md-3">
          <div class="text-muted small">Pages printed <span class="text-muted" title="Every Payroll form is exactly one page; other forms count as at least one - an undercount, never an overcount">(est.)</span></div>
          <div class="fs-4 fw-bold" id="om-pages">-</div>
        </div>
        <div class="col-6 col-md-3">
          <div class="text-muted small">Avg. settlement turnaround</div>
          <div class="fs-4 fw-bold" id="om-turnaround">-</div>
          <div class="text-muted small" id="om-settled-count"></div>
        </div>
      </div>

      <div class="mt-3" id="om-grounds-wrap" style="display:none">
        <div class="text-muted small mb-1">Top suspension grounds</div>
        <table class="table table-sm mb-0" style="max-width:420px">
          <tbody id="om-grounds"></tbody>
        </table>
      </div>
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

  /** Formats a duration in hours as "Xd Yh" (or "-" when there is nothing settled to average). */
  function fmtTurnaround(hours) {
    if (hours === null || hours === undefined) return '-';
    var days = Math.floor(hours / 24);
    var rem = Math.round(hours - days * 24);
    return (days ? days + 'd ' : '') + rem + 'h';
  }

  /** Runs apiGetOperationalMetrics() over the chosen date range and renders the tiles. */
  function runOperationalMetrics() {
    var payload = {
      From: document.getElementById('om-from').value,
      To: document.getElementById('om-to').value
    };
    busy(api('apiGetOperationalMetrics', payload)).then(function (m) {
      document.getElementById('om-tiles').style.display = '';
      document.getElementById('om-prints').textContent = m.officialPrints;
      document.getElementById('om-reprint-rate').textContent = (m.reprintRate * 100).toFixed(1) + '%';
      document.getElementById('om-reprint-count').textContent =
        m.reprints + ' of ' + m.officialPrints + ' print(s)';
      document.getElementById('om-pages').textContent = m.pagesPrinted;
      document.getElementById('om-turnaround').textContent = fmtTurnaround(m.averageTurnaroundHours);
      document.getElementById('om-settled-count').textContent = m.settledCount + ' settled suspension(s)';

      var groundsWrap = document.getElementById('om-grounds-wrap');
      groundsWrap.style.display = m.topGrounds.length ? '' : 'none';
      document.getElementById('om-grounds').innerHTML = m.topGrounds.map(function (g) {
        return '<tr><td>' + esc(g.GroundCode) + '</td><td class="text-end">' + g.Count + '</td></tr>';
      }).join('');
    });
  }

  return {
    init: function () {
      var lk = App.lookups;
      document.getElementById('rp-period').innerHTML =
        options(lk.periods, 'PeriodID', 'PeriodID', '', 'All Periods');

      // Scoped - apiGetPayrollFacets(), never the citywide App.lookups.offices
      // - so this dropdown can never offer an office the caller has no report
      // rows for. Every role holding report.view also holds payroll.view (the
      // permission this facet endpoint itself requires), so this never hits a
      // wall for anyone who can reach this screen. The reports engine already
      // scopes its rows through Payroll (see reportContext() in
      // app/Reports.php); this is the same scope, reused for the dropdown
      // rather than a second one for Reports alone.
      api('apiGetPayrollFacets').then(function (facets) {
        document.getElementById('rp-office').innerHTML =
          options(facets.OfficeCode || [], null, null, '', 'All Offices (in your scope)');
      });

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
      document.getElementById('om-run').onclick = runOperationalMetrics;
    }
  };
})();
</script>
