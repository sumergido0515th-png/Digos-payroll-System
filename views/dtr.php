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
          <!-- Hidden rather than disabled for a role without dtr.import - Encoder
               holds dtr.edit (can key days by hand) but not dtr.import (docs/ROLES.md),
               and a visible-but-refused button would say the wrong thing about why. -->
          <button class="btn btn-sm btn-outline-secondary" id="dtr-import" style="display:none"
                  title="Import biometric logs for this period">
            <span class="material-icons" style="font-size:16px;vertical-align:-3px">upload_file</span>
          </button>
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
    // Import needs a period to attribute rows to - apiImportBiometricLogs
    // requires PeriodID - so the button only ever shows once one is picked,
    // same reasoning as the Encoder-role gate above it.
    document.getElementById('dtr-import').style.display =
      (periodId && can('dtr.import')) ? '' : 'none';
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

  /* ---------------------------------------------------- biometric import */

  /**
   * The one header row this screen accepts, exactly as apiImportBiometricLogs
   * names the fields it reads. apiImportBiometricLogs itself takes any
   * already-parsed punch record - "which device the city buys is not settled,
   * and hardcoding one vendor's CSV layout here would have to be undone when
   * it is" (see its own header comment) - so THIS is where that choice gets
   * made, once, for whatever a timekeeper pastes or uploads. EmployeeID and
   * WorkDate are the only two the server requires to keep a row at all; the
   * rest default the way an empty box does on the grid above.
   */
  var PUNCH_COLUMNS = ['EmployeeID', 'WorkDate', 'TimeIn1', 'TimeOut1', 'TimeIn2', 'TimeOut2',
    'HoursWorked', 'OvertimeHours', 'LateMinutes', 'UndertimeMinutes', 'Remarks'];

  var TIME_RE = /^([01]\d|2[0-3]):([0-5]\d)(:[0-5]\d)?$/;
  var DATE_RE = /^\d{4}-\d{2}-\d{2}$/;

  /**
   * Splits one delimited line into cells, aware of double-quoted fields -
   * Remarks is free text and the one column likely to carry the delimiter
   * itself. Not a full RFC 4180 parser (no multi-line quoted fields); enough
   * for a device export or a spreadsheet paste, which is what this is for.
   */
  function splitLine(line, delim) {
    var cells = [];
    var cur = '';
    var inQuotes = false;
    for (var i = 0; i < line.length; i++) {
      var c = line[i];
      if (inQuotes) {
        if (c === '"' && line[i + 1] === '"') { cur += '"'; i++; }
        else if (c === '"') { inQuotes = false; }
        else { cur += c; }
      } else if (c === '"') {
        inQuotes = true;
      } else if (c === delim) {
        cells.push(cur); cur = '';
      } else {
        cur += c;
      }
    }
    cells.push(cur);
    return cells.map(function (c) { return c.trim(); });
  }

  /**
   * Text (pasted or read from a file) -> punch records, matched by header
   * name rather than position - a device export that reorders its columns
   * still works, and one that renames them fails at the header line instead
   * of silently misreading every row after it.
   *
   * @return {{rows: Array<Object>, unknownHeaders: string[]}}
   */
  function parsePunches(text) {
    var lines = text.replace(/\r\n?/g, '\n').split('\n').filter(function (l) { return l.trim() !== ''; });
    if (!lines.length) return { rows: [], unknownHeaders: [] };

    var delim = lines[0].indexOf('\t') >= 0 ? '\t' : ',';
    var headers = splitLine(lines[0], delim).map(function (h) { return h.toLowerCase(); });
    var known = PUNCH_COLUMNS.map(function (c) { return c.toLowerCase(); });

    var unknownHeaders = [];
    var colFor = headers.map(function (h, i) {
      var idx = known.indexOf(h);
      if (idx < 0) { unknownHeaders.push(lines[0] === '' ? '' : splitLine(lines[0], delim)[i]); return null; }
      return PUNCH_COLUMNS[idx];
    });

    var rows = lines.slice(1).map(function (line) {
      var cells = splitLine(line, delim);
      var row = {};
      colFor.forEach(function (field, i) {
        if (field) row[field] = cells[i] !== undefined ? cells[i] : '';
      });
      return row;
    });

    return { rows: rows, unknownHeaders: unknownHeaders.filter(Boolean) };
  }

  /**
   * One parsed row, checked against exactly what the server would refuse -
   * TIME_RE and DATE_RE are nullableTime()/nullableDate()'s own patterns,
   * copied rather than re-derived, so "looks fine here" and "the server
   * accepts it" cannot drift apart.
   *
   * Validating before anything is sent is the point, not a nicety:
   * apiImportBiometricLogs now runs the whole batch in one transaction (see
   * DtrRepo::withTransaction()), so a single bad row still fails the entire
   * import rather than half-committing it - but discovering that after
   * pasting three hundred rows is still a wasted round trip this catches
   * first, row by row, with every problem shown at once instead of one
   * exception naming only the first.
   *
   * @return {{status: 'ok'|'skip'|'invalid'|'outside', reason: string}}
   */
  function checkRow(row, period) {
    var emp = (row.EmployeeID || '').trim();
    var date = (row.WorkDate || '').trim();

    if (emp === '' || date === '') {
      return { status: 'skip', reason: 'No employee or no date - the server ignores this row too' };
    }
    if (!DATE_RE.test(date)) {
      return { status: 'invalid', reason: 'Date must be YYYY-MM-DD' };
    }
    if (period && (date < period.StartDate || date > period.EndDate)) {
      return { status: 'outside', reason: 'Outside ' + period.StartDate + ' to ' + period.EndDate };
    }
    var timeFields = ['TimeIn1', 'TimeOut1', 'TimeIn2', 'TimeOut2'];
    for (var i = 0; i < timeFields.length; i++) {
      var v = (row[timeFields[i]] || '').trim();
      if (v !== '' && !TIME_RE.test(v)) {
        return { status: 'invalid', reason: timeFields[i] + ' must be a time like 08:00 or 17:30' };
      }
    }
    var numFields = ['HoursWorked', 'OvertimeHours', 'LateMinutes', 'UndertimeMinutes'];
    for (var j = 0; j < numFields.length; j++) {
      var n = (row[numFields[j]] || '').trim();
      if (n !== '' && isNaN(Number(n))) {
        return { status: 'invalid', reason: numFields[j] + ' is not a number' };
      }
    }
    return { status: 'ok', reason: '' };
  }

  var STATUS_BADGE = {
    ok: '<span class="badge text-bg-success">OK</span>',
    skip: '<span class="badge text-bg-secondary">Skip</span>',
    outside: '<span class="badge text-bg-secondary">Outside period</span>',
    invalid: '<span class="badge text-bg-danger">Invalid</span>'
  };

  function previewHtml(parsed, checked) {
    var counts = { ok: 0, skip: 0, outside: 0, invalid: 0 };
    checked.forEach(function (c) { counts[c.status]++; });

    var warn = parsed.unknownHeaders.length
      ? '<div class="alert alert-warning py-2 small mb-2">Column(s) not recognised and ignored: ' +
        esc(parsed.unknownHeaders.join(', ')) + '. Expected: ' + esc(PUNCH_COLUMNS.join(', ')) + '.</div>'
      : '';

    var summary = '<div class="small mb-2">' +
      '<b>' + counts.ok + '</b> will be imported' +
      (counts.skip ? ', <b>' + counts.skip + '</b> skipped (no employee or date)' : '') +
      (counts.outside ? ', <b>' + counts.outside + '</b> outside the period' : '') +
      (counts.invalid ? ', <b class="text-danger">' + counts.invalid + ' invalid</b> - fix these ' +
        'before importing, or every row in this batch is refused' : '') +
      '.</div>';

    var rows = parsed.rows.map(function (row, i) {
      var c = checked[i];
      return '<tr' + (c.status === 'invalid' ? ' class="table-danger"' : '') + '>' +
        '<td>' + STATUS_BADGE[c.status] + '</td>' +
        '<td class="small">' + esc(row.EmployeeID || '') + '</td>' +
        '<td class="small">' + esc(row.WorkDate || '') + '</td>' +
        '<td class="small">' + esc(row.TimeIn1 || '') + '</td>' +
        '<td class="small">' + esc(row.TimeOut1 || '') + '</td>' +
        '<td class="small">' + esc(row.HoursWorked || '') + '</td>' +
        '<td class="small text-danger">' + esc(c.reason) + '</td></tr>';
    }).join('') || '<tr><td colspan="7" class="text-center text-muted py-3">Nothing parsed yet.</td></tr>';

    return warn + summary +
      '<div class="table-responsive" style="max-height:280px"><table class="table table-sm">' +
      '<thead><tr><th></th><th>Employee</th><th>Date</th><th>In</th><th>Out</th><th>Hours</th>' +
      '<th>Why</th></tr></thead><tbody>' + rows + '</tbody></table></div>';
  }

  function importForm() {
    var periodId = document.getElementById('dtr-period').value;
    var period = grid && grid.period ? grid.period : null;

    var parsed = { rows: [], unknownHeaders: [] };
    var checked = [];

    function redraw() {
      document.getElementById('imp-bio-preview').innerHTML = previewHtml(parsed, checked);
      var invalid = checked.some(function (c) { return c.status === 'invalid'; });
      var ok = checked.some(function (c) { return c.status === 'ok'; });
      var btn = document.getElementById('imp-bio-submit');
      if (btn) btn.disabled = invalid || !ok;
    }

    function reparse() {
      var text = document.getElementById('imp-bio-text').value;
      parsed = parsePunches(text);
      checked = parsed.rows.map(function (row) { return checkRow(row, period); });
      redraw();
    }

    openModal('Import biometric logs' + (periodId ? ' - ' + periodId : ''),
      '<p class="small text-muted">Paste rows copied from the device\'s export, or choose the ' +
        'file, with a header row naming the columns you have. Recognised headers: <code>' +
        esc(PUNCH_COLUMNS.join(', ')) + '</code> - only <code>EmployeeID</code> and ' +
        '<code>WorkDate</code> (YYYY-MM-DD) are required, and unrecognised columns are ignored ' +
        'rather than refused. Nothing is written until you confirm below.</p>' +
      '<div class="mb-2"><input type="file" class="form-control form-control-sm" ' +
        'id="imp-bio-file" accept=".csv,.tsv,.txt"></div>' +
      '<textarea class="form-control form-control-sm font-monospace" id="imp-bio-text" rows="5" ' +
        'placeholder="EmployeeID,WorkDate,TimeIn1,TimeOut1,HoursWorked\nEMP-000123,2026-08-04,08:00,17:00,8"></textarea>' +
      '<div id="imp-bio-preview" class="mt-2"></div>',
      [
        { label: 'Cancel', cls: 'btn-outline-secondary', onclick: closeModal },
        { label: 'Import', cls: 'btn-gov', onclick: function () {
          var punches = parsed.rows.filter(function (row, i) { return checked[i].status === 'ok'; });
          if (!punches.length) { toast('Nothing valid to import.', 'warning'); return; }

          busy(api('apiImportBiometricLogs', { PeriodID: periodId, punches: punches }))
            .then(function (d) {
              closeModalSaved();
              toast(d.message, d.conflicts.length ? 'warning' : 'success');
              load();
            });
        } }
      ]);

    // openModal() builds buttons generically and does not thread an id
    // through, so it is set here rather than widening that shared helper for
    // one caller's need to disable its own submit button.
    var submitBtn = document.querySelector('#app-modal-footer .btn:last-child');
    if (submitBtn) submitBtn.id = 'imp-bio-submit';

    document.getElementById('imp-bio-text').oninput = debounce(reparse);
    document.getElementById('imp-bio-file').onchange = function (e) {
      var file = e.target.files[0];
      if (!file) return;
      var reader = new FileReader();
      reader.onload = function (ev) {
        document.getElementById('imp-bio-text').value = ev.target.result;
        reparse();
      };
      reader.readAsText(file);
    };
    redraw();
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
      document.getElementById('dtr-import').onclick = importForm;

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
