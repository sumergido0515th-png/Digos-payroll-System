        <!-- ==========================================================================
     preaudit.php - Phase 7. The pre-auditor's worklist: the queue of payrolls
     awaiting review or already suspended, sorted by how long they have been
     waiting, with the findings, the coverage matrix and the decision (Approve
     or Suspend) in one screen - so nobody approves a payroll without having
     seen why it might not deserve it.
     ========================================================================== -->
<section class="page" id="page-preaudit">
  <div class="card">
    <div class="card-body py-2 d-flex align-items-center gap-3">
      <div>
        <span class="badge text-bg-secondary" id="pa-count-review">0 awaiting</span>
        <span class="badge text-bg-danger ms-1" id="pa-count-suspended">0 suspended</span>
      </div>
      <div class="ms-auto small text-muted">
        <span class="badge" style="background:#1e7e34">&lt; 24h</span>
        <span class="badge" style="background:#b8860b">24-72h</span>
        <span class="badge" style="background:#b00020">&gt; 72h</span>
        waiting
      </div>
    </div>
  </div>

  <div class="card mt-3"><div class="table-responsive">
    <table class="table table-hover">
      <thead><tr>
        <th>Payroll No.</th><th>Period</th><th>Office</th><th>Prepared By</th>
        <th class="text-end">Net</th><th>Status</th><th>Waiting</th>
        <th class="text-end">Actions</th>
      </tr></thead>
      <tbody id="pa-rows"></tbody>
    </table>
  </div></div>
</section>

<script>
/** Pre-auditor worklist: queue, findings, coverage, and the decision. */
Pages.preaudit = (function () {

  var rows = [];

  function load() {
    busy(api('apiGetWorklist', {})).then(function (d) {
      rows = d.rows;
      document.getElementById('pa-count-review').textContent = d.counts.ForPreAudit + ' awaiting';
      document.getElementById('pa-count-suspended').textContent = d.counts.Suspended + ' suspended';
      draw();
    });
  }

  function agingHours(since) {
    if (!since) return 0;
    return (Date.now() - new Date(since.replace(' ', 'T')).getTime()) / 3600000;
  }

  function agingBadge(since) {
    var h = agingHours(since);
    var color = h < 24 ? '#1e7e34' : (h < 72 ? '#b8860b' : '#b00020');
    var label = h < 1 ? '<1h' : Math.round(h) + 'h';
    return '<span class="badge" style="background:' + color + '">' + esc(label) + '</span>';
  }

  function draw() {
    document.getElementById('pa-rows').innerHTML = rows.map(function (r) {
      var acts = actionBtn('visibility', 'Pages.preaudit.open(\'' + r.PayrollNo + '\')');
      return '<tr>' +
        '<td class="fw-semibold">' + esc(r.PayrollNo) + '</td>' +
        '<td>' + esc(r.PeriodID) + '</td><td>' + esc(r.OfficeCode) + '</td>' +
        '<td class="small">' + esc(r.PreparedBy) + '</td>' +
        '<td class="text-money">' + fmtMoney(r.TotalNet) + '</td>' +
        '<td>' + badge(r.Status) +
          (r.OpenSuspensions.length ? ' <span class="small text-danger">(' +
            r.OpenSuspensions.length + ' open)</span>' : '') + '</td>' +
        '<td>' + agingBadge(r.AgingSince) + '</td>' +
        '<td class="text-end text-nowrap">' + acts + '</td></tr>';
    }).join('') || '<tr><td colspan="8" class="text-center text-muted py-4">' +
      'Nothing awaiting pre-audit or suspended within your access.</td></tr>';
  }

  /* --------------------------------------------------------- detail panel */

  function open(payrollNo) {
    var row = rows.filter(function (r) { return r.PayrollNo === payrollNo; })[0];

    busy(Promise.all([
      api('apiRunPreAudit', { PayrollNo: payrollNo }),
      api('apiGetCoverageMatrix', { PeriodID: row.PeriodID, OfficeCode: row.OfficeCode }, true)
        .catch(function () { return null; }),
      api('apiListSuspensions', { PayrollNo: payrollNo, Status: 'Open' })
    ])).then(function (results) {
      renderDetail(row, results[0], results[1], results[2]);
    });
  }

  var SEVERITY_ORDER = ['BLOCKER', 'WARNING', 'INFO'];
  var SEVERITY_CLASS = { BLOCKER: 'text-bg-danger', WARNING: 'text-bg-warning', INFO: 'text-bg-info' };

  function renderDetail(row, audit, matrix, openSuspensions) {
    var findingsHtml = SEVERITY_ORDER.map(function (sev) {
      var group = audit.findings.filter(function (f) { return f.Severity === sev; });
      if (!group.length) return '';
      return '<div class="mb-2"><span class="badge ' + SEVERITY_CLASS[sev] + '">' + sev +
        ' (' + group.length + ')</span><ul class="small mb-1 mt-1">' +
        group.map(function (f) {
          return '<li><b>' + esc(f.RuleID) + '</b> ' + esc(f.Message) +
            (f.EmployeeID ? ' <span class="text-muted">[' + esc(f.EmployeeID) + ']</span>' : '') +
            '</li>';
        }).join('') + '</ul></div>';
    }).join('') || '<p class="text-success small mb-2">No findings.</p>';

    var matrixHtml = '<p class="small text-muted">Coverage matrix unavailable.</p>';
    if (matrix) {
      var gaps = matrix.gaps.length;
      matrixHtml = '<div class="small mb-2">' +
        (gaps ? '<span class="badge text-bg-danger">' + gaps + ' unjustified day(s) this period</span>'
              : '<span class="badge text-bg-success">No unjustified days this period</span>') +
        '</div>';
    }

    var suspensionsHtml = openSuspensions.length
      ? '<div class="mb-2"><b class="small">Open suspensions</b><ul class="small mb-1">' +
        openSuspensions.map(function (s) {
          return '<li>' + esc(s.NsNo) + ' - ' + esc(s.GroundCode) +
            (s.EmployeeID ? ' [' + esc(s.EmployeeID) + ']' : ' [whole batch]') +
            ': ' + esc(s.Particulars) +
            ' <a class="small" target="_blank" href="print.php?no=' + encodeURIComponent(row.PayrollNo) +
            '&form=ns&ns=' + encodeURIComponent(s.NsNo) + '">Print NS</a>' +
            ' <button class="btn btn-sm btn-outline-secondary py-0 px-1" ' +
            'onclick="Pages.preaudit.settleForm(\'' + s.NsNo + '\')">Settle</button></li>';
        }).join('') + '</ul></div>'
      : '';

    var canAct = can('payroll.approve') && row.Status === 'FOR_PRE_AUDIT';
    var canSuspend = can('payroll.suspend') && (row.Status === 'FOR_PRE_AUDIT' || row.Status === 'PRE_AUDIT_APPROVED');

    openModal('Payroll ' + row.PayrollNo + ' - ' + row.Status,
      '<p class="small text-muted mb-1">' + esc(row.OfficeCode) + ' &middot; ' + esc(row.PeriodID) +
      ' &middot; prepared by ' + esc(row.PreparedBy) + '</p>' +
      '<h6 class="mt-2">Findings</h6>' + findingsHtml +
      '<h6 class="mt-2">Coverage</h6>' + matrixHtml +
      suspensionsHtml,
      [
        { label: 'Close', cls: 'btn-outline-secondary', onclick: closeModal },
        canSuspend ? { label: 'Suspend', cls: 'btn-warning',
          onclick: function () { closeModal(); suspendForm(row.PayrollNo); } } : null,
        canAct ? { label: 'Approve', cls: 'btn-success',
          onclick: function () { closeModal(); approveForm(row.PayrollNo, audit); } } : null,
      ].filter(Boolean));
  }

  /* ------------------------------------------------------------- approve */

  /**
   * Re-authentication before approval.
   *
   * Confirming who is clicking, at the moment of the click - not merely that
   * a session cookie is still valid. The server checks the password again
   * regardless; this is what makes the prompt honest rather than decorative.
   */
  function approveForm(payrollNo, audit) {
    var blockers = audit.findings.filter(function (f) { return f.Severity === 'BLOCKER'; });
    var warning = blockers.length
      ? '<p class="small text-danger">' + blockers.length + ' BLOCKER finding(s) are present. '
        + 'Approving will raise a Notice of Suspension instead of approving outright - clean '
        + 'employees proceed, the rest are held on a supplemental payroll.</p>'
      : '';

    openModal('Confirm approval', warning +
      '<div class="mb-2"><label class="form-label">Your password</label>' +
      '<input type="password" class="form-control form-control-sm" id="pa-approve-password"></div>',
      [
        { label: 'Cancel', cls: 'btn-outline-secondary', onclick: closeModal },
        { label: 'Approve', cls: 'btn-success', onclick: function () {
          var password = document.getElementById('pa-approve-password').value;
          busy(api('apiApprovePayroll', { PayrollNo: payrollNo, Password: password })).then(function (d) {
            closeModalSaved();
            if (!d.approved) {
              toast('Payroll suspended: ' + d.suspensions + ' notice(s) of suspension raised.', 'warning');
            } else if (d.split) {
              toast('Approved. ' + d.suspensions + ' employee(s) split to supplemental payroll '
                + d.supplementalPayrollNo + '.', 'warning');
            } else {
              toast('Payroll ' + payrollNo + ' approved.');
            }
            load();
          });
        } }
      ]);
  }

  /* ------------------------------------------------------------- suspend */

  var GROUND_CODES = ['DOCUMENT_INTEGRITY', 'CONFLICT', 'COMPUTATION', 'FORM_COMPLETENESS',
    'CALENDAR', 'SCOPE', 'OTHER'];

  function suspendForm(payrollNo) {
    openModal('Suspend payroll ' + payrollNo,
      '<div class="row g-2">' +
        '<div class="col-md-6"><label class="form-label">Ground</label>' +
          '<select class="form-select form-select-sm" id="pa-ground">' +
          options(GROUND_CODES, null, null, 'OTHER') + '</select></div>' +
        '<div class="col-md-6"><label class="form-label">Employee (optional)</label>' +
          '<input class="form-control form-control-sm" id="pa-employee" ' +
          'placeholder="Leave blank to hold the whole batch"></div>' +
        '<div class="col-md-6"><label class="form-label">Deadline (optional)</label>' +
          '<input type="date" class="form-control form-control-sm" id="pa-deadline"></div>' +
        '<div class="col-12"><label class="form-label">Particulars</label>' +
          '<textarea class="form-control form-control-sm" id="pa-particulars" rows="2"></textarea></div>' +
        '<div class="col-12"><label class="form-label">Required action</label>' +
          '<textarea class="form-control form-control-sm" id="pa-required" rows="2"></textarea></div>' +
      '</div>',
      [
        { label: 'Cancel', cls: 'btn-outline-secondary', onclick: closeModal },
        { label: 'Raise suspension', cls: 'btn-warning', onclick: function () {
          var particulars = document.getElementById('pa-particulars').value;
          var required = document.getElementById('pa-required').value;
          if (!particulars || !required) {
            toast('Particulars and required action are both needed.', 'warning');
            return;
          }
          busy(api('apiSuspendPayroll', {
            PayrollNo: payrollNo,
            GroundCode: document.getElementById('pa-ground').value,
            EmployeeID: document.getElementById('pa-employee').value,
            Deadline: document.getElementById('pa-deadline').value,
            Particulars: particulars,
            RequiredAction: required
          })).then(function (d) {
            closeModalSaved();
            toast('Suspension ' + d.NsNo + ' raised.', 'warning');
            load();
          });
        } }
      ]);
  }

  /* -------------------------------------------------------------- settle */

  function settleForm(nsNo) {
    openModal('Settle suspension ' + nsNo,
      '<div class="mb-2"><label class="form-label">Settlement reference</label>' +
        '<input class="form-control form-control-sm" id="pa-settlement-ref" ' +
        'placeholder="Document filed, corrected figure, etc."></div>' +
      '<div class="form-check"><input type="checkbox" class="form-check-input" id="pa-waive">' +
        '<label class="form-check-label small" for="pa-waive">Waive instead of settle - the ' +
        'finding stands but is not being corrected</label></div>',
      [
        { label: 'Cancel', cls: 'btn-outline-secondary', onclick: closeModal },
        { label: 'Confirm', cls: 'btn-success', onclick: function () {
          var ref = document.getElementById('pa-settlement-ref').value;
          if (!ref) { toast('A settlement reference is needed.', 'warning'); return; }
          busy(api('apiSettleSuspension', {
            NsNo: nsNo, SettlementRef: ref,
            Waive: document.getElementById('pa-waive').checked
          })).then(function (d) {
            closeModalSaved();
            toast(d.payrollReopened ? 'Settled - payroll returned to pre-audit.' : 'Settled.');
            load();
          });
        } }
      ]);
  }

  return {
    init: function () { load(); },
    open: open,
    settleForm: settleForm
  };
})();
</script>
