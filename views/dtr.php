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
        <div class="col-md-3"><label class="form-label">Office</label>
          <select class="form-select form-select-sm" id="dtr-office"></select></div>
        <div class="col-md-4"><label class="form-label">Employee</label>
          <select class="form-select form-select-sm" id="dtr-employee"></select></div>
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
      body.innerHTML = '<tr><td colspan="11" class="text-center text-muted py-4">' +
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

      draw();
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
