<!-- ==========================================================================
     dashboard.html - Stat cards, Google Charts and the recent-payroll feed.
     ========================================================================== -->
<section class="page" id="page-dashboard">

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

  return {
    init: function () {
      busy(api('apiGetDashboard')).then(function (d) {
        lastData = d;
        renderCards(d.stats);
        renderCharts(d);
        renderRecent(d.recent);
      });
    }
  };
})();
</script>
