<!-- ==========================================================================
     holidays.php - The holiday calendar, and the pay rules it feeds.

     Migration 0019 shipped Holidays and HolidayPayRules in Phase 4, and the
     four endpoints below were routed and permissioned on calendar.view /
     calendar.edit at the same time. Nothing ever called them. Until this
     screen the only trace of a holiday anywhere in the UI was a legend label
     in views/coverage.php and a day-type option in views/dtr.php - so the
     table stayed empty, and a pay rule that keys off a declaration cannot
     fire on a declaration nobody can enter. Found by auditing ROUTES against
     its call sites; see tests/Architecture/RouteTableTest.php.

     TWO SECTIONS BECAUSE THEY ARE TWO KINDS OF FACT, which is 0019's own
     reasoning: a declaration is a proclamation, new every year and editable
     here; a pay rule is policy, versioned by effectivity so that a payroll
     prepared last year is still re-checkable under last year's rule.

     THE PAY RULES ARE READ-ONLY, and deliberately so rather than for want of
     effort: there is no apiSavePayRule. HolidayRepo::savePayRule() exists and
     has no caller, so wiring an editor here would mean inventing an endpoint
     for versioned policy carrying legal citations - a design decision, not a
     screen. They are shown because the legal basis is the point: a timekeeper
     looking at a 200% holiday should be able to see what says so.
     ========================================================================== -->

<section class="page" id="page-holidays">
  <ul class="nav nav-tabs" id="hol-tabs">
    <li class="nav-item"><a class="nav-link active" data-hol="declarations" href="#">Declarations</a></li>
    <li class="nav-item"><a class="nav-link" data-hol="rules" href="#">Pay Rules</a></li>
  </ul>

  <!-- ------------------------------------------------------ declarations -->
  <div id="hol-declarations-view">
    <div class="card mt-3">
      <div class="card-body py-2">
        <div class="row g-2 align-items-end">
          <div class="col-md-2"><label class="form-label">Year</label>
            <select class="form-select form-select-sm" id="hol-f-year"></select></div>
          <div class="col-md-3"><label class="form-label">Day type</label>
            <select class="form-select form-select-sm" id="hol-f-daytype"></select></div>
          <div class="col-md-2"><label class="form-label">Scope</label>
            <select class="form-select form-select-sm" id="hol-f-scope"></select></div>
          <div class="col-md-2"><label class="form-label">Status</label>
            <select class="form-select form-select-sm" id="hol-f-status">
              <option value="">All</option><option>Active</option><option>Inactive</option>
            </select></div>
          <div class="col-md-3 text-end">
            <button class="btn btn-sm btn-gov" id="hol-add" style="display:none">
              <span class="material-icons" style="font-size:18px;vertical-align:-4px">add</span>
              New declaration
            </button>
          </div>
        </div>
      </div>
    </div>

    <div class="card mt-3"><div class="table-responsive">
      <table class="table table-hover">
        <thead><tr><th>Date</th><th>Name</th><th>Day type</th><th>Scope</th><th>Covers</th>
          <th>Legal basis</th><th>Status</th><th class="text-end">Actions</th></tr></thead>
        <tbody id="hol-rows"></tbody>
      </table>
    </div></div>
  </div>

  <!-- ---------------------------------------------------------- pay rules -->
  <div id="hol-rules-view" style="display:none">
    <div class="card mt-3"><div class="card-body py-2">
      <p class="small text-muted mb-0">
        What a day type is worth, by employment type, and whether the employee worked it.
        <b>Versioned by effectivity</b> - a payroll prepared under an older policy is still
        re-checked under that policy, so superseded versions stay listed. Read-only here:
        changing a multiplier that carries a legal citation is an issuance, not an edit.
      </p>
    </div></div>

    <div class="card mt-3"><div class="table-responsive">
      <table class="table table-sm table-hover">
        <thead><tr><th>Day type</th><th>Employment type</th><th>Worked</th><th>Paid</th>
          <th class="text-end">Multiplier</th><th>Effective</th><th>Legal basis</th></tr></thead>
        <tbody id="hol-rule-rows"></tbody>
      </table>
    </div></div>
  </div>
</section>

<script>
/* ==================== Holiday calendar module ==================== */
Pages.holidays = (function () {

  /**
   * HolidayResolver::DAY_TYPES, in the order that file lists them.
   *
   * RegularDay is offered even though it is the resolver's default for an
   * undeclared date: declaring one is how a date that would otherwise be
   * covered by a wider holiday is pulled back to ordinary, and the server
   * accepts it, so withholding it here would be the screen disagreeing with
   * the endpoint.
   */
  var DAY_TYPES = [
    ['RegularDay', 'Regular day'],
    ['RegularHoliday', 'Regular holiday'],
    ['SpecialNonWorking', 'Special (non-working)'],
    ['SpecialWorking', 'Special (working)'],
    ['LocalHoliday', 'Local holiday'],
    ['WorkSuspension', 'Work suspension']
  ];

  /** HolidayResolver::SCOPE_LEVELS, most general first - the resolver's own order. */
  var SCOPE_LEVELS = ['National', 'Region', 'Province', 'City', 'Barangay'];

  var tab = 'declarations';
  var rows = [];

  function label(pairs, value) {
    var hit = pairs.filter(function (p) { return p[0] === value; })[0];
    return hit ? hit[1] : value;
  }

  /** "National", or "City - Digos". */
  function scopeText(r) {
    return r.ScopeLevel === 'National' ? 'National'
      : r.ScopeLevel + ' - ' + (r.ScopeCode || '?');
  }

  /**
   * "Whole day", or the window a partial suspension covered.
   *
   * 0019 stores both times NULL for a whole day, and the ordinary form of a
   * typhoon suspension is "work is suspended from 12:00" - a table that could
   * only say the date would pay a full day to somebody who worked the morning.
   */
  function coversText(r) {
    if (!r.StartTime || !r.EndTime) return 'Whole day';
    return String(r.StartTime).slice(0, 5) + ' - ' + String(r.EndTime).slice(0, 5);
  }

  function filters() {
    return {
      Year: document.getElementById('hol-f-year').value,
      DayType: document.getElementById('hol-f-daytype').value,
      ScopeLevel: document.getElementById('hol-f-scope').value,
      Status: document.getElementById('hol-f-status').value
    };
  }

  function load() {
    var f = filters();
    syncUrl('holidays', Object.assign({ tab: tab }, f));

    busy(api('apiListHolidays', f)).then(function (d) {
      rows = d;
      document.getElementById('hol-rows').innerHTML = rows.map(function (r) {
        var acts = can('calendar.edit')
          ? actionBtn('edit', 'Pages.holidays.edit', [r.HolidayID]) +
            actionBtn('delete', 'Pages.holidays.remove', [r.HolidayID], 'text-danger')
          : '';
        return '<tr>' +
          '<td class="text-nowrap">' + fmtDate(r.HolidayDate) + '</td>' +
          '<td>' + esc(r.HolidayName || '-') + '</td>' +
          '<td>' + esc(label(DAY_TYPES, r.DayType)) + '</td>' +
          '<td class="small">' + esc(scopeText(r)) + '</td>' +
          '<td class="small text-nowrap">' + esc(coversText(r)) + '</td>' +
          '<td class="small text-muted" style="max-width:320px;overflow:hidden;text-overflow:ellipsis">' +
          esc(r.LegalBasis) + '</td>' +
          '<td>' + badge(r.Status) + '</td>' +
          '<td class="text-end text-nowrap">' + acts + '</td></tr>';
      }).join('') ||
        '<tr><td colspan="8" class="text-center text-muted py-4">' +
        'No declarations match these filters.</td></tr>';
    });
  }

  function loadRules() {
    busy(api('apiListHolidayPayRules', {})).then(function (d) {
      document.getElementById('hol-rule-rows').innerHTML = d.map(function (r) {
        var effective = fmtDate(r.EffectiveFrom) +
          (r.EffectiveTo ? ' - ' + fmtDate(r.EffectiveTo) : ' onwards');
        return '<tr>' +
          '<td>' + esc(label(DAY_TYPES, r.DayType)) + '</td>' +
          // NULL is the fallback row 0019 describes: "applies to any
          // employment type without a more specific rule". Rendering it blank
          // would read as missing data.
          '<td>' + (r.EmploymentTypeCode ? esc(r.EmploymentTypeCode)
            : '<span class="text-muted">any other</span>') + '</td>' +
          '<td>' + (Number(r.Worked) ? 'Worked' : 'Not worked') + '</td>' +
          '<td>' + (Number(r.Paid)
            ? '<span class="badge text-bg-success">Paid</span>'
            : '<span class="badge text-bg-secondary">Unpaid</span>') + '</td>' +
          '<td class="text-end text-money">' + Number(r.Multiplier).toFixed(3) + '</td>' +
          '<td class="small text-nowrap">' + esc(effective) + '</td>' +
          '<td class="small text-muted">' + esc(r.LegalBasis) +
          (r.Notes ? '<br><i>' + esc(r.Notes) + '</i>' : '') + '</td></tr>';
      }).join('') ||
        '<tr><td colspan="7" class="text-center text-muted py-4">No pay rules.</td></tr>';
    });
  }

  /* ------------------------------------------------------------- the form */

  function form(r) {
    r = r || {};
    var isNew = !r.HolidayID;

    openModal((isNew ? 'New' : 'Edit') + ' holiday declaration',
      '<div class="row g-2">' +
        '<div class="col-md-4"><label class="form-label">Date</label>' +
          '<input type="date" class="form-control form-control-sm" id="hol-date" value="' +
          esc(String(r.HolidayDate || '').slice(0, 10)) + '"></div>' +
        '<div class="col-md-8"><label class="form-label">Name</label>' +
          '<input class="form-control form-control-sm" id="hol-name" value="' +
          esc(r.HolidayName || '') + '" placeholder="Araw ng Kagitingan, Kadayawan, ..."></div>' +

        '<div class="col-md-6"><label class="form-label">Day type</label>' +
          '<select class="form-select form-select-sm" id="hol-daytype">' +
          DAY_TYPES.map(function (t) {
            return '<option value="' + esc(t[0]) + '"' +
              (t[0] === (r.DayType || 'RegularHoliday') ? ' selected' : '') +
              '>' + esc(t[1]) + '</option>';
          }).join('') + '</select></div>' +
        '<div class="col-md-3"><label class="form-label">Scope</label>' +
          '<select class="form-select form-select-sm" id="hol-scope">' +
          options(SCOPE_LEVELS, null, null, r.ScopeLevel || 'National') + '</select></div>' +
        '<div class="col-md-3"><label class="form-label">Which one</label>' +
          '<input class="form-control form-control-sm" id="hol-scopecode" value="' +
          esc(r.ScopeCode || '') + '" placeholder="Digos"></div>' +

        '<div class="col-12"><div class="form-check">' +
          '<input type="checkbox" class="form-check-input" id="hol-partial"' +
          (r.StartTime ? ' checked' : '') + '>' +
          '<label class="form-check-label small" for="hol-partial">Part of the day only ' +
          '- a suspension from midday is not a whole day off</label></div></div>' +
        '<div class="col-md-3"><label class="form-label">From</label>' +
          '<input type="time" class="form-control form-control-sm" id="hol-start" value="' +
          esc(String(r.StartTime || '').slice(0, 5)) + '"></div>' +
        '<div class="col-md-3"><label class="form-label">To</label>' +
          '<input type="time" class="form-control form-control-sm" id="hol-end" value="' +
          esc(String(r.EndTime || '').slice(0, 5)) + '"></div>' +
        '<div class="col-md-6"><label class="form-label">Status</label>' +
          '<select class="form-select form-select-sm" id="hol-status">' +
          options(['Active', 'Inactive'], null, null, r.Status || 'Active') + '</select></div>' +

        '<div class="col-12"><label class="form-label">Legal basis</label>' +
          '<input class="form-control form-control-sm" id="hol-basis" value="' +
          esc(r.LegalBasis || '') + '" placeholder="Proclamation No. 368, s. 2025"></div>' +
        '<div class="col-12"><div class="form-text">Required. A pre-audit finding that says ' +
          '"this should have been paid 2x" is worth little; one that cites the issuance is ' +
          'auditable, which is why 0019 made the column NOT NULL.</div></div>' +

        '<div class="col-12"><label class="form-label">Remarks</label>' +
          '<input class="form-control form-control-sm" id="hol-remarks" value="' +
          esc(r.Remarks || '') + '"></div>' +
      '</div>',
      [
        { label: 'Cancel', cls: 'btn-outline-secondary', onclick: closeModal },
        { label: 'Save', cls: 'btn-gov', onclick: function () { save(r.HolidayID); } }
      ]);

    // A scope code means nothing for a national declaration and is required
    // for every other level, so the control follows the level rather than
    // sitting there inviting a value the server would reject.
    var scope = document.getElementById('hol-scope');
    var partial = document.getElementById('hol-partial');
    scope.onchange = syncScope;
    partial.onchange = syncPartial;
    syncScope();
    syncPartial();
  }

  function syncScope() {
    var national = document.getElementById('hol-scope').value === 'National';
    var code = document.getElementById('hol-scopecode');
    code.disabled = national;
    code.placeholder = national ? 'Not needed' : 'Digos';
    if (national) code.value = '';
  }

  function syncPartial() {
    var on = document.getElementById('hol-partial').checked;
    ['hol-start', 'hol-end'].forEach(function (id) {
      var el = document.getElementById(id);
      el.disabled = !on;
      if (!on) el.value = '';
    });
  }

  function save(holidayId) {
    var partial = document.getElementById('hol-partial').checked;
    var payload = {
      HolidayID: holidayId || '',
      HolidayDate: document.getElementById('hol-date').value,
      HolidayName: document.getElementById('hol-name').value,
      DayType: document.getElementById('hol-daytype').value,
      ScopeLevel: document.getElementById('hol-scope').value,
      ScopeCode: document.getElementById('hol-scopecode').value,
      StartTime: partial ? document.getElementById('hol-start').value : '',
      EndTime: partial ? document.getElementById('hol-end').value : '',
      LegalBasis: document.getElementById('hol-basis').value,
      Status: document.getElementById('hol-status').value,
      Remarks: document.getElementById('hol-remarks').value
    };

    // The three the endpoint calls requireFields on. Checked here so the
    // refusal names the box rather than the field name: "Missing field:
    // LegalBasis" is true and unhelpful to somebody looking at a form.
    if (!payload.HolidayDate) { toast('A date is needed.', 'warning'); return; }
    if (!payload.LegalBasis.trim()) {
      toast('A legal basis is needed - the proclamation or ordinance that declared it.', 'warning');
      return;
    }

    busy(api('apiSaveHoliday', payload)).then(function (d) {
      closeModalSaved();
      toast('Declaration saved for ' + fmtDate(d.HolidayDate) + '.');
      refreshYears(d.HolidayDate);
      load();
    });
  }

  /* ------------------------------------------------------------------ tabs */

  function selectTab(name) {
    tab = name === 'rules' ? 'rules' : 'declarations';
    document.querySelectorAll('#hol-tabs .nav-link').forEach(function (a) {
      a.classList.toggle('active', a.dataset.hol === tab);
    });
    document.getElementById('hol-declarations-view').style.display =
      tab === 'declarations' ? '' : 'none';
    document.getElementById('hol-rules-view').style.display = tab === 'rules' ? '' : 'none';
  }

  /**
   * The Year filter's options.
   *
   * Built from the current year outward rather than from the rows, because an
   * empty table would otherwise offer no year at all and there would be no way
   * to file next year's proclamations. "All years" is first so the screen
   * opens showing everything there is, which on an empty table is the honest
   * answer rather than "nothing in 2026".
   *
   * `alsoOffer` names a date whose year must appear in the list - a
   * declaration saved for 2031 needs an option to be found under later. It
   * does NOT select it: the first version of this did, and saving a 2027
   * declaration while filtered to 2026 silently moved the filter, so the list
   * changed under the person without their asking. The toast already says
   * which date was saved; moving their filter to prove it is the screen
   * deciding what they wanted to look at.
   */
  function refreshYears(alsoOffer) {
    var now = new Date().getFullYear();
    var years = [];
    for (var y = now + 2; y >= now - 5; y--) years.push(String(y));

    var sel = document.getElementById('hol-f-year');
    var selected = sel.value;
    var extra = alsoOffer ? String(alsoOffer).slice(0, 4) : '';
    if (extra && years.indexOf(extra) < 0) years.push(extra);
    if (selected && years.indexOf(selected) < 0) years.push(selected);

    sel.innerHTML = options(years, null, null, selected, 'All years');
  }

  return {
    init: function (params) {
      params = params || {};

      document.getElementById('hol-f-daytype').innerHTML =
        '<option value="">All day types</option>' +
        DAY_TYPES.map(function (t) {
          return '<option value="' + esc(t[0]) + '"' +
            (t[0] === params.DayType ? ' selected' : '') + '>' + esc(t[1]) + '</option>';
        }).join('');
      document.getElementById('hol-f-scope').innerHTML =
        options(SCOPE_LEVELS, null, null, params.ScopeLevel || '', 'All scopes');
      document.getElementById('hol-f-status').value = params.Status || '';
      // The shared link's year is applied before the options are built, so
      // refreshYears() preserves it rather than being handed it separately -
      // one path that sets the selection, not two.
      document.getElementById('hol-f-year').value = params.Year || '';
      refreshYears(params.Year ? params.Year + '-01-01' : '');

      ['hol-f-year', 'hol-f-daytype', 'hol-f-scope', 'hol-f-status'].forEach(function (id) {
        document.getElementById(id).onchange = load;
      });

      document.getElementById('hol-add').style.display = can('calendar.edit') ? '' : 'none';
      document.getElementById('hol-add').onclick = function () { form(null); };

      document.querySelectorAll('#hol-tabs .nav-link').forEach(function (a) {
        a.onclick = function (e) {
          e.preventDefault();
          selectTab(a.dataset.hol);
          if (tab === 'rules') loadRules(); else load();
        };
      });

      selectTab(params.tab || 'declarations');
      if (tab === 'rules') loadRules(); else load();
    },

    edit: function (id) {
      form(rows.filter(function (r) { return r.HolidayID === id; })[0]);
    },

    remove: function (id) {
      var r = rows.filter(function (x) { return x.HolidayID === id; })[0] || {};
      confirmDlg('Delete the declaration for ' + fmtDate(r.HolidayDate) + '?', function () {
        busy(api('apiDeleteHoliday', { HolidayID: id })).then(function () {
          toast('Declaration deleted.');
          load();
        });
      });
    }
  };
})();
</script>
