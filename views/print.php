        <!-- ==========================================================================
     print.html - Print Payroll screen: preview, one-click print, PDF export
     to Drive, and payslip email for approved/released payrolls.
     ========================================================================== -->
<section class="page" id="page-print">
  <div class="card">
    <div class="card-body py-2">
      <div class="row g-2 align-items-end">
        <div class="col-md-4"><label class="form-label">Live Search</label>
          <input class="form-control form-control-sm" id="pp-search" placeholder="Payroll number, office..."></div>
        <div class="col-md-3"><label class="form-label">Period</label>
          <select class="form-select form-select-sm" id="pp-period"></select></div>
        <div class="col-md-3"><label class="form-label">Status</label>
          <select class="form-select form-select-sm" id="pp-status">
            <option value="">Approved &amp; Released</option>
            <option>Draft</option><option>Pending</option>
            <option>Approved</option><option>Released</option>
          </select></div>
      </div>
    </div>
  </div>

  <div class="card mt-3"><div class="table-responsive">
    <table class="table table-hover">
      <thead><tr>
        <th>Payroll No.</th><th>Period</th><th>Office</th>
        <th class="text-end">Net Amount</th><th>Status</th><th>PDF Archive</th>
        <th class="text-end">Actions</th>
      </tr></thead>
      <tbody id="pp-rows"></tbody>
    </table>
  </div></div>
</section>

<script>
/** Print Payroll page module. */
Pages.print = (function () {

  /** Loads printable payrolls (Approved/Released by default). */
  function load() {
    var status = document.getElementById('pp-status').value;
    api('apiListPayrolls', {
      search: document.getElementById('pp-search').value,
      PeriodID: document.getElementById('pp-period').value,
      Status: status
    }).then(function (rows) {
      if (!status) {
        rows = rows.filter(function (r) {
          return r.Status === 'Approved' || r.Status === 'Released';
        });
      }
      document.getElementById('pp-rows').innerHTML = rows.map(function (r) {
        return '<tr><td class="fw-semibold">' + esc(r.PayrollNo) + '</td>' +
          '<td>' + esc(r.PeriodID) + '</td><td>' + esc(r.OfficeCode) + '</td>' +
          '<td class="text-money">' + fmtMoney(r.TotalNet) + '</td>' +
          '<td>' + badge(r.Status) + '</td>' +
          '<td><a target="_blank" class="small" href="print.php?no=' +
            encodeURIComponent(r.PayrollNo) + '">Open print view</a></td>' +
          '<td class="text-end text-nowrap">' +
          actionBtn('print', 'Pages.print.preview(\'' + r.PayrollNo + '\')') +
          actionBtn('picture_as_pdf', 'Pages.print.pdf(\'' + r.PayrollNo + '\')') +
          actionBtn('savings', 'Pages.print.form(\'' + r.PayrollNo + '\',\'pagibig\')') +
          actionBtn('summarize', 'Pages.print.form(\'' + r.PayrollNo + '\',\'summary\')') +
          actionBtn('fact_check', 'Pages.print.form(\'' + r.PayrollNo + '\',\'cafoa\')') +
          (can('payroll.release') && (r.Status === 'Approved' || r.Status === 'Released') ?
            actionBtn('forward_to_inbox', 'Pages.print.email(\'' + r.PayrollNo + '\')') : '') +
          '</td></tr>';
      }).join('') || '<tr><td colspan="7" class="text-center text-muted py-4">No printable payrolls found.</td></tr>';
    });
  }

  return {
    init: function () {
      document.getElementById('pp-period').innerHTML =
        options(App.lookups.periods, 'PeriodID', 'PeriodID', '', 'All Periods');
      document.getElementById('pp-search').oninput = debounce(load);
      document.getElementById('pp-period').onchange = load;
      document.getElementById('pp-status').onchange = load;
      load();
    },

    /** Opens the print preview (blank-template layout filled with data). */
    preview: function (no) {
      window.open('print.php?no=' + encodeURIComponent(no), '_blank');
    },

    /** Opens a companion form: pagibig | summary (GF 30-A) | cafoa. */
    form: function (no, form) {
      window.open('print.php?no=' + encodeURIComponent(no) +
        '&form=' + encodeURIComponent(form), '_blank');
    },

    /** PDF: the same print view; use the browser dialog's "Save as PDF". */
    pdf: function (no) {
      toast('Opening print view - choose "Save as PDF" in the print dialog.', 'info');
      window.open('print.php?no=' + encodeURIComponent(no), '_blank');
    },

    /** Emails payslips to every employee on the payroll. */
    email: function (no) {
      confirmDlg('Email payslips to all employees on ' + no + ' with an email address?',
        function () {
          busy(api('apiEmailPayslips', { PayrollNo: no })).then(function (d) {
            toast(d.sent + ' payslip(s) sent, ' + d.skipped + ' skipped (no email).');
          });
        });
    }
  };
})();
</script>
