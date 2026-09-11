        <!-- ==========================================================================
     dtr.php - Phase 3B. The employee x date grid.
     One employee at a time: a fortnight is fifteen columns, and a grid of every
     employee against every date does not fit a screen anybody actually uses.
     ========================================================================== -->
<section class="page" id="page-dtr">
  <div class="card">
    <div class="card-body py-2">
      <div class="row g-2 align-items-end">
        <div class="col-md-3"><label class="form-label">Period</label>
          <select class="form-select form-select-sm" id="dtr-period"></select></div>
        <div class="col-md-2"><label class="form-label">Office</label>
          <select class="form-select form-select-sm" id="dtr-office"></select></div>
        <div class="col-md-3"><label class="form-label">Employee</label>
          <select class="form-select form-select-sm" id="dtr-employee"></select></div>
        <!-- Only the "What was this day?" panel reads this. Nothing in the
             schema links an employee to a shift - WorkShifts is a reference
             table and every caller passes a ShiftCode explicitly (see
             views/coverage.php, which has the same control for the same
             reason) - so a rest day cannot be answered without being told
             which shift to answer it against. -->
        <div class="col-md-2"><label class="form-label">Shift (for rest days)</label>
          <select class="form-select form-select-sm" id="dtr-shift"></select></div>
        <div class="col-md-2 text-end">
          <button class="btn btn-sm btn-gov" id="dtr-save" disabled>Save Days</button>
        </div>
      </div>
      <div class="small text-muted mt-2" id="dtr-summary"></div>
    </div>
  </div>

  <div class="card mt-3"><div class="table-responsive">
    <table class="table table-sm table-hover align-middle" id="dtr-table">
      <thead><tr>
        <th style="min-width:120px">Date</th>
        <th>Day</th>
        <th style="min-width:110px">In</th>
        <th style="min-width:110px">Out</th>
        <th style="min-width:90px">Hours</th>
        <th style="min-width:90px">OT</th>
        <th style="min-width:90px">Late (min)</th>
        <th style="min-width:100px">Undertime</th>
        <th>Absent</th>
        <th style="min-width:120px">Day Type</th>
        <th>Source</th>
        <th class="text-end">Day</th>
      </tr></thead>
      <tbody id="dtr-rows"></tbody>
    </table>
  </div></div>

  <div class="card mt-3"><div class="card-body py-2">
    <div class="row text-center g-2" id="dtr-totals"></div>
    <div class="small text-muted mt-2">
      Totals are <strong>derived</strong> from the days above, not typed. The payroll grid
      reads these, so a line's days worked is evidence rather than an assertion.
    </div>
  </div></div>
</section>

<script>
/** Daily Time Record page module. */
Pages.dtr = (function () {

  /** The loaded grid: period, dates, employees, days, totals. */
  var grid = null;

  /** Which employee's fortnight is on screen. */
  var employeeId = '';

  var DAY_TYPES = ['Regular', 'RestDay', 'Holiday', 'Suspension'];

  /** Loads the whole period in one call, then draws one employee from it. */
  function load() {
    var periodId = document.getElementById('dtr-period').value;
    if (!periodId) return;

    busy(api('apiGetDtrGrid', {
      PeriodID: periodId,
      OfficeCode: document.getElementById('dtr-office').value
    })).then(function (d) {
      grid = d;

      document.getElementById('dtr-employee').innerHTML =
        options(d.employees, 'EmployeeID', 'EmployeeName', employeeId, 'Select employee...');
      employeeId = document.getElementById('dtr-employee').value;

      document.getElementById('dtr-summary').textContent =
        d.summary.days + ' day row(s) for ' + d.summary.employees + ' employee(s) in this period, ' +
        d.summary.manual + ' keyed by hand.';

      draw();
    });
  }

  /** Draws the date rows for the selected employee. */
  function draw() {
    var body = document.getElementById('dtr-rows');
    document.getElementById('dtr-save').disabled = !employeeId;

    if (!grid || !employeeId) {
      body.innerHTML = '<tr><td colspan="12" class="text-center text-muted py-4">' +
        'Choose a period and an employee.</td></tr>';
      document.getElementById('dtr-totals').innerHTML = '';
      return;
    }

    // Existing rows by date, so a half-filled period draws what is stored and
    // leaves the rest blank rather than starting over.
    var stored = {};
    grid.days.forEach(function (d) {
      if (d.EmployeeID === employeeId) stored[d.WorkDate] = d;
    });

    body.innerHTML = grid.dates.map(function (date) {
      var d = stored[date] || {};
      var weekday = new Date(date + 'T00:00:00').toLocaleDateString(undefined, { weekday: 'short' });

      return '<tr data-date="' + date + '">' +
        '<td class="text-nowrap">' + esc(date) + '</td>' +
        '<td class="text-muted small">' + esc(weekday) + '</td>' +
        '<td>' + time('TimeIn1', d.TimeIn1) + '</td>' +
        '<td>' + time('TimeOut1', d.TimeOut1) + '</td>' +
        '<td>' + numeric('HoursWorked', d.HoursWorked, '0.25') + '</td>' +
        '<td>' + numeric('OvertimeHours', d.OvertimeHours, '0.25') + '</td>' +
        '<td>' + numeric('LateMinutes', d.LateMinutes, '1') + '</td>' +
        '<td>' + numeric('UndertimeMinutes', d.UndertimeMinutes, '1') + '</td>' +
        '<td class="text-center"><input type="checkbox" class="form-check-input" ' +
          'data-f="IsAbsent"' + (Number(d.IsAbsent) ? ' checked' : '') + '></td>' +
        '<td><select class="form-select form-select-sm" data-f="DayType">' +
          options(DAY_TYPES, null, null, d.DayType || 'Regular') + '</select></td>' +
        '<td class="small text-muted">' + esc(d.Source || 'new') + '</td>' +
        '<td class="text-end">' +
          actionBtn('help_outline', 'Pages.dtr.resolve', [date], 'text-primary') + '</td>' +
      '</tr>';
    }).join('');

    showTotals(grid.totals[employeeId]);
  }

  function time(field, value) {
    return '<input type="time" class="form-control form-control-sm" data-f="' + field +
      '" value="' + esc((value || '').substring(0, 5)) + '">';
  }

  function numeric(field, value, step) {
    return '<input type="number" step="' + step + '" min="0" class="form-control form-control-sm" ' +
      'data-f="' + field + '" value="' + esc(value === undefined || value === null ? '' : value) + '">';
  }

  function showTotals(t) {
    t = t || {};
    var cards = [
      ['Days Worked', t.DaysWorked], ['Hours', t.HoursWorked], ['Overtime', t.OvertimeHours],
      ['Late (min)', t.LateMinutes], ['Undertime (min)', t.UndertimeMinutes],
      ['Absent Days', t.AbsentDays]
    ];
    document.getElementById('dtr-totals').innerHTML = cards.map(function (c) {
      return '<div class="col"><div class="fw-semibold fs-5">' + esc(c[1] || 0) + '</div>' +
        '<div class="small text-muted">' + esc(c[0]) + '</div></div>';
    }).join('');
  }

  /**
   * Collects the rows that have anything on them.
   *
   * A blank row is not a zero-hour day - it is a day nobody has said anything
   * about yet, and saving fifteen empty rows would turn "not keyed" into
   * "keyed as nothing", which the totals cannot tell apart afterwards.
   */
  /* ------------------------------------------------- what was this day? */

  /**
   * Whether the row as currently keyed counts as worked.
   *
   * Read off the live inputs rather than the stored row, because the question
   * is "given what I have just typed, what is this day worth?" - and Worked is
   * the flag that picks between the two halves of every pay rule. Absent wins
   * over hours: a day marked absent with hours left in the boxes is a
   * half-finished edit, and the safer reading of it is not-worked.
   */
  function workedOn(date) {
    var tr = document.querySelector('#dtr-rows tr[data-date="' + date + '"]');
    if (!tr) return false;
    if (tr.querySelector('[data-f="IsAbsent"]').checked) return false;
    return Number(tr.querySelector('[data-f="HoursWorked"]').value || 0) > 0;
  }

  function line(label, value) {
    return '<div class="row g-1 small mb-1"><div class="col-4 text-muted">' + esc(label) +
      '</div><div class="col-8">' + value + '</div></div>';
  }

  /**
   * Renders apiResolveDay's answer.
   *
   * The legal basis is shown for both halves and is the reason the panel
   * exists: 0019 made LegalBasis NOT NULL on both tables because "a pre-audit
   * finding that says this should have been paid 2x is worth very little; one
   * that cites the issuance is auditable". A panel that showed the multiplier
   * and not the citation would throw away the half that makes it usable.
   */
  function resolvedHtml(d, worked) {
    var h = d.holiday || {};
    var a = d.authority || {};

    var paid = h.paid
      ? '<span class="badge text-bg-success">Paid</span> &times; ' + Number(h.multiplier).toFixed(3)
      : '<span class="badge text-bg-secondary">Unpaid</span> &times; ' +
        Number(h.multiplier || 0).toFixed(3);

    var html = line('Date', esc(d.Date) + ' &middot; ' +
      (worked ? 'counted as <b>worked</b>' : 'counted as <b>not worked</b>')) +
      line('Employment type', esc(d.EmploymentTypeCode || '&mdash; none recorded &mdash;')) +
      line('Day type', '<b>' + esc(h.day_type || '?') + '</b>' +
        (h.holiday_name ? ' &middot; ' + esc(h.holiday_name) : '') +
        (h.scope_level && h.scope_level !== 'National'
          ? ' <span class="text-muted">(' + esc(h.scope_level) +
            (h.scope_code ? ' - ' + esc(h.scope_code) : '') + ')</span>'
          : '')) +
      (h.holiday_basis ? line('Declared by', esc(h.holiday_basis)) : '') +
      (h.partial ? line('Covers', Math.round(Number(h.coverage_fraction) * 100) + '% of the day, ' +
        Number(h.hours_covered) + ' hour(s)') : '') +
      line('Pay', paid) +
      line('Pay rule', esc(h.rule_id || '?') +
        (h.legal_basis ? '<br><span class="text-muted">' + esc(h.legal_basis) + '</span>' : ''));

    // The resolver says so itself rather than guessing a rule; surfacing it is
    // the difference between "unpaid" and "nobody has written the rule yet".
    if (h.unresolved) {
      html += '<div class="alert alert-warning py-2 small mb-2">' +
        '<b>Not resolved.</b> ' + esc(h.unresolved_reason || '') + '</div>';
    }

    var shiftCode = document.getElementById('dtr-shift').value;
    html += line('Rest day', shiftCode
      ? (d.rest_day ? '<b>Yes</b>, under shift ' + esc(shiftCode)
                    : 'No, under shift ' + esc(shiftCode))
      : '<span class="text-muted">Not determined - a rest day comes from a shift, and ' +
        'none is selected above.</span>');

    html += '<hr class="my-2">' + line('Authority',
      a.authorised
        ? '<span class="badge text-bg-success">Covered</span> by ' + esc(a.control_no || a.memo_id)
        : '<span class="badge text-bg-secondary">None</span> <span class="text-muted">' +
          esc(a.reason || '') + '</span>');

    return html;
  }

  function collect() {
    var days = [];
    document.querySelectorAll('#dtr-rows tr[data-date]').forEach(function (tr) {
      var row = { EmployeeID: employeeId, WorkDate: tr.dataset.date, Source: 'Manual' };
      var touched = false;

      tr.querySelectorAll('[data-f]').forEach(function (el) {
        var v = el.type === 'checkbox' ? el.checked : el.value;
        row[el.dataset.f] = v;
        if (el.type === 'checkbox' ? v : (v !== '' && el.dataset.f !== 'DayType')) touched = true;
        if (el.dataset.f === 'DayType' && v !== 'Regular') touched = true;
      });

      if (touched) days.push(row);
    });
    return days;
  }

  return {
    init: function () {
      document.getElementById('dtr-period').innerHTML =
        options(App.lookups.periods, 'PeriodID', 'PeriodID', '', 'Select period...');
      document.getElementById('dtr-office').innerHTML =
        options(App.lookups.offices, 'OfficeCode', 'OfficeName', '', 'All offices');

      document.getElementById('dtr-period').onchange = function () { employeeId = ''; load(); };
      document.getElementById('dtr-office').onchange = function () { employeeId = ''; load(); };
      document.getElementById('dtr-employee').onchange = function (e) {
        employeeId = e.target.value;
        draw();
      };
      document.getElementById('dtr-save').onclick = Pages.dtr.save;

      // Silent on failure: a role may hold dtr.view without shift.view, and
      // the panel says "not determined" rather than toasting on every visit.
      api('apiListWorkShifts', {}, true).then(function (shifts) {
        document.getElementById('dtr-shift').innerHTML =
          options(shifts, 'ShiftCode', 'ShiftCode', '', 'Not against a shift');
      }, function () {
        document.getElementById('dtr-shift').innerHTML =
          '<option value="">Not against a shift</option>';
      });

      draw();
    },

    /**
     * apiResolveDay for one row - "what was this date, for this person, and
     * what is it worth?".
     *
     * app/Calendar.php's own header calls this "the endpoint a screen calls",
     * and until now no screen called it: it has been routed and unused since
     * Phase 4, alongside the holiday calendar it reads. Both resolvers answer
     * in one call because this is one question.
     */
    resolve: function (date) {
      var worked = workedOn(date);

      busy(api('apiResolveDay', {
        EmployeeID: employeeId,
        Date: date,
        Worked: worked,
        ShiftCode: document.getElementById('dtr-shift').value
      })).then(function (d) {
        openModal('What was ' + date + '?', resolvedHtml(d, worked),
          [{ label: 'Close', cls: 'btn-outline-secondary', onclick: closeModal }]);
      });
    },

    save: function () {
      var days = collect();
      if (!days.length) { toast('Nothing to save - no day has been filled in.', 'warning'); return; }

      busy(api('apiSaveDtrDays', {
        PeriodID: document.getElementById('dtr-period').value,
        days: days
      })).then(function (d) {
        toast(d.saved + ' day(s) saved.');
        load();
      });
    }
  };
})();
</script>
