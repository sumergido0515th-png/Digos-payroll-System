<!-- ==========================================================================
     suspensions.php - Notices of Suspension as a list of their own.

     Until now a suspension could only be read from inside the payroll it was
     raised against, which is the right default - a suspension is evidence
     about a payroll - but leaves nobody able to ask the question the deadline
     column exists for: "what is still open, and what is late." The dashboard
     card carried a `key !== 'suspension'` special case for exactly that, and
     this screen is what lets that case go.

     Every endpoint it uses has been in ROUTES since 9B and unused:
     apiListSuspensions, apiGetSuspensionFacets, and 'Suspensions' in
     export.php's EXPORTABLE table. No backend change was needed.
     ========================================================================== -->

<section class="page" id="page-suspensions">
  <div class="card">
    <div class="card-body py-2">
      <div class="row g-2 align-items-end">
        <div class="col-md-8"><label class="form-label">Live Search</label>
          <input class="form-control form-control-sm" id="ns-search"
            placeholder="NS number, ground, particulars, required action, settlement..."></div>
        <div class="col-md-4 text-end">
          <a class="btn btn-sm btn-outline-secondary" id="ns-export" href="#" target="_blank">
            <span class="material-icons" style="font-size:16px;vertical-align:-3px">download</span> Export CSV</a>
        </div>
      </div>
      <div class="row g-2 align-items-end mt-1">
        <div class="col-md-3"><label class="form-label">Sort by</label>
          <select class="form-select form-select-sm" id="ns-sort"></select></div>
        <div class="col-md-3">
          <button class="btn btn-sm btn-outline-secondary w-100" id="ns-direction" type="button"
                  title="Reverse sort direction">
            <span class="material-icons" id="ns-direction-icon" style="font-size:16px;vertical-align:-3px">arrow_downward</span>
            <span id="ns-direction-label">Newest first</span></button></div>
        <!-- A shortcut, not a filter of its own: it fills the controls
             beside it with Status = Open and a Deadline "To" of yesterday,
             which is what "past deadline" is, and then the ordinary filters
             do the work. Nothing narrows rows on the client, so the CSV
             export keeps running the same query the screen is showing - the
             9D invariant - and the bar always says what is applied. -->
        <div class="col-md-3">
          <button class="btn btn-sm btn-outline-danger w-100" id="ns-overdue" type="button">
            <span class="material-icons" style="font-size:16px;vertical-align:-3px">gavel</span>
            Open past deadline</button></div>
      </div>
      <!-- Built from apiGetSuspensionFacets, which derives its choices from
           rows already inside the caller's scope - an Office option can only
           appear because a payroll carrying it is readable. See
           views/documents.php for the disclosure this avoids. -->
      <div class="row g-2 align-items-end mt-1" id="ns-facet-row"></div>
      <div class="row g-2 align-items-end mt-1" id="ns-date-row"></div>
    </div>
  </div>

  <div class="card mt-3"><div class="table-responsive">
    <table class="table table-hover">
      <thead><tr><th>NS No.</th><th>Payroll</th><th>Employee</th><th>Ground</th>
        <th>Particulars</th><th>Raised</th><th>Deadline</th><th>Status</th>
        <th class="text-end">Actions</th></tr></thead>
      <tbody id="ns-rows"></tbody>
    </table>
  </div></div>
</section>

<script>
/* ==================== Suspensions module ==================== */
Pages.suspensions = (function () {

  /** FilterSpec's 'Suspensions' sort allowlist, in the words a reader uses. */
  var SORTS = [['raised', 'Date raised'], ['deadline', 'Deadline'],
    ['nsNo', 'NS number'], ['ground', 'Ground'], ['status', 'Status']];

  /** Its option facets. RuleID names the pre-audit rule that raised the row. */
  var FACETS = [['Status', 'Status'], ['GroundCode', 'Ground'],
    ['RuleID', 'Raised by rule'], ['OfficeCode', 'Office']];

  /**
   * Its date facets, both points rather than spans - RaisedFrom/RaisedTo are
   * both RaisedAt and DeadlineFrom/DeadlineTo both Deadline, so neither has
   * the open-ended-row edge documented on views/documents.php.
   *
   * RaisedAt is a datetime and its facets are datetimeFrom/datetimeTo, but
   * FilterSpec validates both against a calendar date, so type="date" is the
   * right control and the whole of the To day is included.
   */
  var DATES = [['Raised', 'RaisedFrom', 'RaisedTo'],
    ['Deadline', 'DeadlineFrom', 'DeadlineTo']];

  var direction = 'DESC';
  var employees = {};
  var facetChoices;
  var facetsLoaded = false;

  /** The date range the "When" row is naming. */
  function activeRange() {
    var which = document.getElementById('ns-date-field');
    if (!which) return null;
    var range = DATES[Number(which.value) || 0];
    return {
      fromKey: range[1], toKey: range[2],
      from: document.getElementById('ns-date-from').value,
      to: document.getElementById('ns-date-to').value
    };
  }

  function filters() {
    var f = {
      search: document.getElementById('ns-search').value,
      sort: document.getElementById('ns-sort').value,
      direction: direction
    };

    FACETS.forEach(function (x) {
      var sel = document.getElementById('ns-f-' + x[0]);
      if (sel && sel.value) f[x[0]] = sel.value;
    });

    var range = activeRange();
    if (range) {
      if (range.from) f[range.fromKey] = range.from;
      if (range.to) f[range.toKey] = range.to;
    }
    return f;
  }

  /**
   * The filter values that mean "open and past deadline".
   *
   * Watchlists::suspensionsPastDeadline() is `Status = 'Open' AND Deadline <
   * :today`, and FilterSpec's DeadlineTo is `Deadline <= :value` - so at date
   * granularity yesterday is exactly that boundary, expressed in facets the
   * server already has rather than in a new one or in a client-side filter.
   */
  function overdueParams() {
    return {
      Status: 'Open',
      DeadlineTo: new Date(Date.now() - 864e5).toISOString().slice(0, 10)
    };
  }

  /** Marks the rows overdueParams() would select, whether or not it is applied. */
  function overdue(r) {
    return r.Status === 'Open' && r.Deadline &&
      String(r.Deadline).slice(0, 10) < new Date().toISOString().slice(0, 10);
  }

  function load() {
    var f = filters();
    syncUrl('suspensions', f);

    document.getElementById('ns-export').href = 'export.php?entity=Suspensions&' + queryString(f);

    busy(api('apiListSuspensions', f)).then(function (rows) {
      document.getElementById('ns-rows').innerHTML = rows.map(function (r) {
        var settle = can('payroll.suspend') && r.Status === 'Open'
          ? actionBtn('task_alt', 'Pages.suspensions.settle', [r.NsNo], 'text-success')
          : '';
        return '<tr' + (overdue(r) ? ' class="table-danger"' : '') + '>' +
          '<td class="text-nowrap"><b>' + esc(r.NsNo) + '</b></td>' +
          '<td class="text-nowrap">' +
          '<a href="#" onclick="event.preventDefault();' +
          jsCall('goToPage', ['payroll', { search: r.PayrollNo }]) + '">' +
          esc(r.PayrollNo) + '</a></td>' +
          '<td>' + esc(employees[r.EmployeeID] || r.EmployeeID) + '</td>' +
          '<td>' + esc(r.GroundCode) + '</td>' +
          '<td class="small" style="max-width:340px;overflow:hidden;text-overflow:ellipsis">' +
          esc(r.Particulars) + '</td>' +
          '<td class="text-nowrap small">' + esc(String(r.RaisedAt || '').replace('T', ' ').slice(0, 16)) + '</td>' +
          '<td class="text-nowrap">' + fmtDate(r.Deadline) + '</td>' +
          '<td>' + badge(r.Status) + '</td>' +
          '<td class="text-end text-nowrap">' + settle + '</td></tr>';
      }).join('') ||
        '<tr><td colspan="9" class="text-center text-muted py-4">' +
        'No suspensions match these filters.</td></tr>';
    });
  }

  function renderFacets(selected) {
    selected = selected || {};
    document.getElementById('ns-facet-row').innerHTML = FACETS.map(function (x) {
      var body = facetChoices
        ? options(facetChoices[x[0]] || [], null, null, selected[x[0]] || '', 'All')
        : '<option value="">Unavailable</option>';
      return '<div class="col-md-3"><label class="form-label">' + esc(x[1]) + '</label>' +
        '<select class="form-select form-select-sm" id="ns-f-' + esc(x[0]) + '"' +
        (facetChoices ? '' : ' disabled') + '>' + body + '</select></div>';
    }).join('');

    FACETS.forEach(function (x) {
      var sel = document.getElementById('ns-f-' + x[0]);
      if (sel) sel.onchange = load;
    });

    // The shortcut sets a Status the dropdown has to be able to hold. With no
    // choices fetched it would apply half of itself - the deadline without
    // the status - and show a filter set nobody asked for, so it is offered
    // only when it can be honoured in full.
    document.getElementById('ns-overdue').disabled = !facetChoices;
  }

  function renderDates(selected) {
    selected = selected || {};
    var picked = 0;
    DATES.forEach(function (d, i) { if (selected[d[1]] || selected[d[2]]) picked = i; });
    var range = DATES[picked];

    document.getElementById('ns-date-row').innerHTML =
      '<div class="col-md-3"><label class="form-label">Date range</label>' +
      '<select class="form-select form-select-sm" id="ns-date-field">' +
      DATES.map(function (d, i) {
        return '<option value="' + i + '"' + (i === picked ? ' selected' : '') +
          '>' + esc(d[0]) + '</option>';
      }).join('') + '</select></div>' +
      '<div class="col-md-3"><label class="form-label">From</label>' +
      '<input type="date" class="form-control form-control-sm" id="ns-date-from" value="' +
      esc(selected[range[1]] || '') + '"></div>' +
      '<div class="col-md-3"><label class="form-label">To</label>' +
      '<input type="date" class="form-control form-control-sm" id="ns-date-to" value="' +
      esc(selected[range[2]] || '') + '"></div>';

    ['ns-date-field', 'ns-date-from', 'ns-date-to'].forEach(function (id) {
      document.getElementById(id).onchange = load;
    });
  }

  /** Fetched once per session; the choices are scoped, not per-filter. */
  function loadFacets(selected) {
    renderDates(selected);
    if (facetsLoaded) { renderFacets(selected); return Promise.resolve(); }

    return api('apiGetSuspensionFacets', {}, true).then(function (d) {
      facetChoices = d || {};
      facetsLoaded = true;
      renderFacets(selected);
    }, function () {
      // Silent, and said in the controls instead - see views/documents.php.
      renderFacets(selected);
    });
  }

  function updateDirectionButton() {
    document.getElementById('ns-direction-icon').textContent =
      direction === 'ASC' ? 'arrow_upward' : 'arrow_downward';
    document.getElementById('ns-direction-label').textContent =
      direction === 'ASC' ? 'Oldest first' : 'Newest first';
  }

  return {
    init: function (params) {
      params = params || {};

      document.getElementById('ns-sort').innerHTML = SORTS.map(function (s) {
        return '<option value="' + esc(s[0]) + '"' +
          (s[0] === (params.sort || 'raised') ? ' selected' : '') + '>' + esc(s[1]) + '</option>';
      }).join('');
      direction = params.direction === 'ASC' ? 'ASC' : 'DESC';
      updateDirectionButton();

      document.getElementById('ns-search').value = params.search || '';

      // A link may ask for "past deadline" as a shorthand - the dashboard's
      // watchlist card does. It expands here into the real facet values, so
      // what syncUrl() then writes back is the honest filter set and a reload
      // reproduces it without knowing the shorthand.
      if (params.overdue) params = Object.assign({}, params, overdueParams());

      document.getElementById('ns-search').oninput = debounce(load);
      document.getElementById('ns-sort').onchange = load;
      document.getElementById('ns-overdue').onclick = function () {
        var want = overdueParams();
        document.getElementById('ns-f-Status').value = want.Status;
        document.getElementById('ns-date-field').value =
          String(DATES.findIndex(function (d) { return d[2] === 'DeadlineTo'; }));
        document.getElementById('ns-date-from').value = '';
        document.getElementById('ns-date-to').value = want.DeadlineTo;
        load();
      };
      document.getElementById('ns-direction').onclick = function () {
        direction = direction === 'ASC' ? 'DESC' : 'ASC';
        updateDirectionButton();
        load();
      };

      // Every role holding payroll.view also holds employee.view (docs/ROLES.md),
      // so resolving the names cannot fail for someone who reached this screen.
      // Names only - apiListSuspensions returns EmployeeID, and joining a name
      // into the repository query would widen what the scope predicate has to
      // account for to render one column.
      api('apiListEmployees', { pageSize: 5000 }, true).then(function (d) {
        (d.rows || []).forEach(function (e) {
          employees[e.EmployeeID] = e.LastName + ', ' + e.FirstName;
        });
        load();
      }, function () { /* IDs stay as IDs; the list is not worth blocking on. */ });

      // Sequenced for the same reason as the Documents bar: load() builds its
      // payload by reading these controls, so racing them sends the first
      // request without the link's filter values and nothing re-issues it.
      loadFacets(params).then(load);
    },

    settle: function (nsNo) {
      openModal('Settle suspension ' + nsNo,
        '<div class="mb-2"><label class="form-label">Settlement reference</label>' +
          '<input class="form-control form-control-sm" id="ns-settlement-ref" ' +
          'placeholder="Document filed, corrected figure, etc."></div>' +
        '<div class="form-check"><input type="checkbox" class="form-check-input" id="ns-waive">' +
          '<label class="form-check-label small" for="ns-waive">Waive instead of settle - the ' +
          'finding stands but is not being corrected</label></div>',
        [
          { label: 'Cancel', cls: 'btn-outline-secondary', onclick: closeModal },
          { label: 'Confirm', cls: 'btn-success', onclick: function () {
            var ref = document.getElementById('ns-settlement-ref').value;
            if (!ref) { toast('A settlement reference is needed.', 'warning'); return; }
            busy(api('apiSettleSuspension', {
              NsNo: nsNo, SettlementRef: ref,
              Waive: document.getElementById('ns-waive').checked
            })).then(function (d) {
              closeModalSaved();
              toast(d.payrollReopened ? 'Settled - payroll returned to pre-audit.' : 'Settled.');
              load();
            });
          } }
        ]);
    }
  };
})();
</script>
