<!-- ==========================================================================
     dashboard.html - Stat cards, Google Charts and the recent-payroll feed.
     ========================================================================== -->
<section class="page" id="page-dashboard">

  <div class="card dashboard-hero mb-3" id="dash-hero">
    <div class="card-body d-flex flex-wrap align-items-center justify-content-between gap-3">
      <div>
        <div class="import-kicker">Welcome back</div>
        <h5 class="mb-0" id="dash-greeting">Loading&hellip;</h5>
      </div>
      <div class="small" id="dash-today"></div>
    </div>
  </div>

  <div class="row g-3" id="dash-cards"></div>

  <div class="row g-3 mt-1">
    <div class="col-lg-8">
      <div class="card h-100">
        <div class="card-header py-2">Monthly Payroll Summary</div>
        <div class="card-body"><div id="chart-monthly" class="chart-box"></div></div>
      </div>
    </div>
    <div class="col-lg-4">
      <div class="card h-100">
        <div class="card-header py-2">Workforce Mix</div>
        <div class="card-body"><div id="chart-split" class="chart-box"></div></div>
      </div>
    </div>
  </div>

  <div class="card mt-3">
    <div class="card-header py-2">Recent Payroll Transactions</div>
    <div class="table-responsive">
      <table class="table table-hover">
        <thead><tr>
          <th>Payroll No.</th><th>Office</th><th>Department</th><th>Date</th>
          <th class="text-end">Net Amount</th><th>Status</th>
        </tr></thead>
        <tbody id="dash-recent"></tbody>
      </table>
    </div>
  </div>

  <!-- Standing watchlists (Phase 9C's four queries, surfaced). Each card is
       shown only to a role holding that entity's own view permission -
       exactly the permission its apiGet*Watchlist route already requires,
       so a card is never offered only to be refused when clicked. -->
  <div class="row g-3 mt-1" id="dash-watchlists"></div>

  <!-- Citywide totals - gated on aggregate.citywide, held by Internal
       Auditor and Admin only (Phase 9D). Absent entirely for every other
       role, not merely hidden, since the row is built only when can() says
       yes. -->
  <div class="card mt-3" id="dash-citywide" style="display:none">
    <div class="card-header py-2">Citywide Payroll Totals by Office</div>
    <div class="table-responsive">
      <table class="table table-hover mb-0">
        <thead><tr>
          <th>Office</th><th class="text-end">Payrolls</th>
          <th class="text-end">Gross</th><th class="text-end">Net</th>
        </tr></thead>
        <tbody id="dash-citywide-rows"></tbody>
      </table>
    </div>
  </div>
</section>

<script>
/** Dashboard page module. */
Pages.dashboard = (function () {
  var chartsReady = false;
  var lastData = null;
  google.charts.load('current', { packages: ['corechart'] });
  google.charts.setOnLoadCallback(function () { chartsReady = true; });

  // Google Charts bakes the label colour into the SVG it emits, so flipping the
  // theme while the dashboard is open leaves the axis and legend text in the
  // previous theme's grey. Redraw from the data we already have.
  window.addEventListener('themechange', function () {
    if (lastData) renderCharts(lastData);
  });

  /** One stat card definition: [label, valueKey, icon, color]. */
  var CARDS = [
    ['Total Employees', 'totalEmployees', 'groups', '#0b3d91'],
    ['Active Job Order', 'activeJO', 'engineering', '#1367d3'],
    ['Active COS', 'activeCOS', 'work_history', '#0a8ea0'],
    ['Departments', 'departments', 'apartment', '#6b4fd8'],
    ['Payroll Transactions', 'payrollCount', 'receipt_long', '#0a6c3c'],
    ['Pending Payroll', 'pendingPayroll', 'pending_actions', '#c07b00'],
    ['Processed Payroll', 'processedPayroll', 'task_alt', '#2e7d32'],
    ['Offices', 'offices', 'location_city', '#845400']
  ];

  /** Renders the stat card row. */
  function renderCards(stats) {
    document.getElementById('dash-cards').innerHTML = CARDS.map(function (c) {
      return '<div class="col-6 col-md-3"><div class="card stat-card">' +
        '<div class="ic" style="background:' + c[3] + '">' +
        '<span class="material-icons">' + c[2] + '</span></div>' +
        '<div><div class="val">' + (stats[c[1]] || 0).toLocaleString() + '</div>' +
        '<div class="lbl">' + c[0] + '</div></div></div></div>';
    }).join('');
  }

  /** Draws both Google Charts once the loader is ready. */
  function renderCharts(d) {
    if (!chartsReady) return setTimeout(function () { renderCharts(d); }, 250);
    var dark = document.documentElement.getAttribute('data-theme') === 'dark';
    var textStyle = { color: dark ? '#c7d0e2' : '#444' };
    var base = { backgroundColor: 'transparent', legend: { textStyle: textStyle },
      hAxis: { textStyle: textStyle }, vAxis: { textStyle: textStyle },
      chartArea: { width: '85%', height: '75%' } };

    var m = new google.visualization.DataTable();
    m.addColumn('string', 'Month');
    m.addColumn('number', 'Gross');
    m.addColumn('number', 'Net');
    (d.monthly || []).forEach(function (r) { m.addRow([r.label, r.gross, r.net]); });
    new google.visualization.ColumnChart(document.getElementById('chart-monthly'))
      .draw(m, Object.assign({}, base, { colors: ['#7fa4e8', '#0b3d91'] }));

    var p = new google.visualization.DataTable();
    p.addColumn('string', 'Type');
    p.addColumn('number', 'Employees');
    (d.employmentSplit || []).forEach(function (r) { p.addRow([r.label, r.value]); });
    new google.visualization.PieChart(document.getElementById('chart-split'))
      .draw(p, Object.assign({}, base, { colors: ['#0b3d91', '#f4b400'], pieHole: 0.45 }));
  }

  /** Renders the recent transactions table. */
  function renderRecent(rows) {
    document.getElementById('dash-recent').innerHTML = (rows || []).map(function (r) {
      return '<tr><td class="fw-semibold">' + esc(r.PayrollNo) + '</td>' +
        '<td>' + esc(r.OfficeCode) + '</td><td>' + esc(r.Department) + '</td>' +
        '<td>' + fmtDate(r.DateCreated) + '</td>' +
        '<td class="text-money">' + fmtMoney(r.TotalNet) + '</td>' +
        '<td>' + badge(r.Status) + '</td></tr>';
    }).join('') || '<tr><td colspan="6" class="text-center text-muted py-4">No payroll transactions yet.</td></tr>';
  }

  /** Greets by time of day and dates the banner - the one part of the page
      that changes without any data ever loading. */
  function renderGreeting() {
    var hour = new Date().getHours();
    var word = hour < 12 ? 'Good morning' : hour < 18 ? 'Good afternoon' : 'Good evening';
    var name = (App.session && App.session.fullName) ? ', ' + App.session.fullName.split(' ')[0] : '';
    document.getElementById('dash-greeting').textContent = word + name + '.';
    document.getElementById('dash-today').textContent = new Date()
      .toLocaleDateString('en-PH', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' });
  }

  /**
   * Phase 9C built the four standing watchlists as query/API endpoints only,
   * with no screen of their own - this is where they finally surface. Each
   * card's own permission is exactly the one its apiGet*Watchlist route
   * already requires, so a card is only ever offered to a role that could
   * also reach it directly, and hidden rather than shown-then-refused for
   * anyone else.
   */
  var WATCHLISTS = [
    { key: 'bioex', perm: 'document.view', title: 'Bio Exemptions Expiring Soon', icon: 'event_busy',
      api: 'apiGetBioExemptionWatchlist', page: 'documents', tab: 'bioex',
      row: function (r) { return esc(r.EmployeeName) + ' &ndash; valid to ' + fmtDate(r.ValidTo); } },
    { key: 'memo', perm: 'document.view', title: 'Open-Ended Memoranda, Stale 6+ Months', icon: 'history_edu',
      api: 'apiGetMemorandumWatchlist', page: 'documents', tab: 'memo',
      row: function (r) { return esc(r.ControlNo) + ' &ndash; ' + esc(r.Subject); } },
    // Needs the open period's end date - see loadWatchlist(). Contracts
    // ending BEFORE that date is the predicate Digos\Domain\Query\Watchlists
    // actually checks; "this period" names it for a reader here.
    { key: 'contract', perm: 'contract.view', title: 'Contracts Ending This Period', icon: 'assignment_late',
      api: 'apiGetContractWatchlist', page: 'documents', tab: 'contract', needsPeriod: true,
      row: function (r) { return esc(r.EmployeeName) + ' &ndash; ends ' + fmtDate(r.EndDate); } },
    // No standalone list screen to link to - a suspension is read from
    // within the payroll it was raised against, not as a list of its own -
    // so the "View all" link only ever appears for a role that can actually
    // reach the Worklist (payroll.approve), decided in loadWatchlist().
    { key: 'suspension', perm: 'payroll.view', title: 'Suspensions Past Deadline', icon: 'gavel',
      api: 'apiGetSuspensionWatchlist', page: 'preaudit', tab: null,
      row: function (r) { return esc(r.NsNo) + ' &ndash; ' + esc(r.GroundCode) + ', due ' + fmtDate(r.Deadline); } }
  ];

  function renderWatchlists() {
    var host = document.getElementById('dash-watchlists');
    var cards = WATCHLISTS.filter(function (w) { return can(w.perm); });
    if (!cards.length) { host.innerHTML = ''; return; }

    host.innerHTML = cards.map(function (w) {
      return '<div class="col-md-6 col-xl-3"><div class="card h-100">' +
        '<div class="card-header py-2 d-flex align-items-center gap-2">' +
        '<span class="material-icons" style="font-size:18px">' + w.icon + '</span>' +
        '<span class="small fw-semibold">' + esc(w.title) + '</span></div>' +
        '<div class="card-body py-2" id="dash-wl-' + w.key + '">' +
        '<div class="text-muted small">Loading&hellip;</div></div></div></div>';
    }).join('');

    cards.forEach(loadWatchlist);
  }

  function loadWatchlist(w) {
    var body = document.getElementById('dash-wl-' + w.key);
    var payload = {};

    if (w.needsPeriod) {
      var current = (App.lookups.periods || []).filter(function (p) { return p.Status === 'Open'; })[0];
      if (!current) { body.innerHTML = '<div class="text-muted small">No open period.</div>'; return; }
      payload.PeriodID = current.PeriodID;
    }

    api(w.api, payload, true).then(function (rows) {
      if (!rows.length) { body.innerHTML = '<div class="text-muted small">Nothing outstanding.</div>'; return; }

      var shown = rows.slice(0, 5);
      var html = '<ul class="list-unstyled small mb-1">' +
        shown.map(function (r) { return '<li class="text-truncate">' + w.row(r) + '</li>'; }).join('') +
        '</ul>';
      if (rows.length > shown.length) {
        html += '<div class="text-muted small mb-1">+' + (rows.length - shown.length) + ' more</div>';
      }
      if (w.key !== 'suspension' || can('payroll.approve')) {
        html += '<a href="#" class="small" onclick="event.preventDefault();goToPage(\'' + w.page + '\'' +
          (w.tab ? ',{tab:\'' + w.tab + '\'}' : '') + ')">View all &raquo;</a>';
      }
      body.innerHTML = html;
    }).catch(function () { body.innerHTML = '<div class="text-muted small">Unavailable.</div>'; });
  }

  /** Citywide totals - aggregate.citywide only (Phase 9D); absent, not merely hidden, otherwise. */
  function renderCitywide() {
    var panel = document.getElementById('dash-citywide');
    if (!can('aggregate.citywide')) { panel.style.display = 'none'; return; }
    panel.style.display = '';

    api('apiGetCitywidePayrollTotals', {}, true).then(function (rows) {
      document.getElementById('dash-citywide-rows').innerHTML = rows.map(function (r) {
        return '<tr><td class="fw-semibold">' + esc(r.OfficeCode) + '</td>' +
          '<td class="text-end">' + esc(r.PayrollCount) + '</td>' +
          '<td class="text-end text-money">' + fmtMoney(r.TotalGross) + '</td>' +
          '<td class="text-end text-money">' + fmtMoney(r.TotalNet) + '</td></tr>';
      }).join('') || '<tr><td colspan="4" class="text-center text-muted py-3">No payroll data yet.</td></tr>';
    });
  }

  return {
    init: function () {
      renderGreeting();
      busy(api('apiGetDashboard')).then(function (d) {
        lastData = d;
        renderCards(d.stats);
        renderCharts(d);
        renderRecent(d.recent);
      });
      renderWatchlists();
      renderCitywide();
    }
  };
})();
</script>
