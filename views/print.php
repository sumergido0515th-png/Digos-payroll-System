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
          <!-- Options are filled from the live status list in init(), like the
               Payroll Transactions filter - a state added later appears here
               with no template edit. FOR_PRE_AUDIT is in the default so a
               payroll can be previewed while it is being corrected - re-scan,
               re-verify, re-preview - without changing a filter to find it.
               DRAFT is deliberately NOT in the default: it has not been
               submitted, and offering it as printable by default is how an
               unfinished payroll gets printed. It stays one dropdown away. -->
          <select class="form-select form-select-sm" id="pp-status"></select></div>
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

  /** Statuses shown when no explicit status filter is chosen. */
  var DEFAULT_STATUSES = ['FOR_PRE_AUDIT', 'PRE_AUDIT_APPROVED', 'FOR_PRINTING', 'PRINTED', 'SUBMITTED'];

  /** Statuses whose printed forms are the official document - see PrintDoc.php. */
  var OFFICIAL_STATUSES = ['PRE_AUDIT_APPROVED', 'FOR_PRINTING', 'PRINTED', 'SUBMITTED'];

  /** Loads printable payrolls (submitted and later, by default). */
  function load() {
    var status = document.getElementById('pp-status').value;
    api('apiListPayrolls', {
      search: document.getElementById('pp-search').value,
      PeriodID: document.getElementById('pp-period').value,
      Status: status
    }).then(function (rows) {
      if (!status) {
        rows = rows.filter(function (r) {
          return DEFAULT_STATUSES.indexOf(r.Status) !== -1;
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
          (OFFICIAL_STATUSES.indexOf(r.Status) !== -1 ?
            actionBtn('assignment_turned_in', 'Pages.print.form(\'' + r.PayrollNo + '\',\'certification\')') +
            actionBtn('account_balance_wallet', 'Pages.print.form(\'' + r.PayrollNo + '\',\'settlement\')') : '') +
          (can('print.run') && OFFICIAL_STATUSES.indexOf(r.Status) !== -1 ?
            actionBtn('verified', 'Pages.print.official(\'' + r.PayrollNo + '\')', 'text-success') : '') +
          (can('payroll.release') && OFFICIAL_STATUSES.indexOf(r.Status) !== -1 ?
            actionBtn('forward_to_inbox', 'Pages.print.email(\'' + r.PayrollNo + '\')') : '') +
          '</td></tr>';
      }).join('') || '<tr><td colspan="7" class="text-center text-muted py-4">' +
        (status ? 'No ' + esc(status) + ' payrolls found.'
                : 'No payrolls awaiting or past pre-audit sign-off were found. '
                  + 'Choose Draft above to see payrolls still being prepared.') +
        '</td></tr>';
    });
  }

  return {
    init: function () {
      document.getElementById('pp-period').innerHTML =
        options(App.lookups.periods, 'PeriodID', 'PeriodID', '', 'All Periods');
      document.getElementById('pp-status').innerHTML =
        options(App.lookups.payrollStatuses, null, null, '', 'Awaiting or past pre-audit');
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

    /**
     * The gated Official print: a mandatory preview first (this is "no
     * direct browser print" - window.print() is never reachable from here
     * before the server has actually assigned a serial), then an explicit
     * confirmation that calls apiGetPrintHtml with official:true. A second
     * Official print of the same form is refused server-side without a
     * Reprint Reason; the field is offered up front so the common case
     * (a genuine reprint) does not need a failed attempt first.
     */
    official: function (no) {
      busy(api('apiGetPrintHtml', { PayrollNo: no, form: 'payroll' })).then(function (preview) {
        openModal('Confirm Official Print - ' + no,
          '<div class="alert alert-warning py-2 small mb-2">' +
          'Printing Official assigns a permanent print serial and is logged. ' +
          'If this form has already been printed Official once for this payroll, ' +
          'a reprint reason is required.</div>' +
          '<div class="mb-2"><label class="form-label">Reprint Reason (if applicable)</label>' +
          '<input class="form-control form-control-sm" id="pp-official-reason"></div>' +
          '<iframe id="pp-official-frame" style="width:100%;height:45vh;border:1px solid #ccc"></iframe>',
          [
            { label: 'Cancel', cls: 'btn-outline-secondary', onclick: closeModal },
            {
              label: 'Confirm & Print Official', onclick: function () {
                var reason = document.getElementById('pp-official-reason').value;
                busy(api('apiGetPrintHtml',
                  { PayrollNo: no, form: 'payroll', official: true, ReprintReason: reason }))
                  .then(function (result) {
                    closeModalSaved();
                    var win = window.open('', '_blank');
                    win.document.write(result.html);
                    win.document.close();
                    toast('Printed Official. Use the new tab\'s Print button for paper or PDF.');
                  });
              }
            }
          ]);
        document.getElementById('pp-official-frame').srcdoc = preview.html;
      });
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
