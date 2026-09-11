<!-- ==========================================================================
     logs.php - split out of what was a single views/settings.php.
     The audit log and its application-error companion table.
     See that file's own header for why.
     ========================================================================== -->

<!-- ==================== AUDIT LOGS ==================== -->
<section class="page" id="page-logs">
  <div class="card">
    <div class="card-body py-2 d-flex gap-2">
      <input class="form-control form-control-sm" id="log-search" placeholder="Live search user, action, module...">
      <input class="form-control form-control-sm w-auto" type="date" id="log-from" title="From date">
      <input class="form-control form-control-sm w-auto" type="date" id="log-to" title="To date">
      <button class="btn btn-sm btn-outline-secondary" id="log-refresh">
        <span class="material-icons">refresh</span></button>
    </div>
  </div>
  <div class="card mt-3"><div class="table-responsive" style="max-height:70vh">
    <table class="table table-sm table-hover">
      <thead class="sticky-top"><tr><th>Timestamp</th><th>User</th><th>Action</th>
        <th>Module</th><th>Details</th></tr></thead>
      <tbody id="log-rows"></tbody>
    </table>
  </div></div>

  <!-- Application Error Log: the other kind of log (app/ErrorLog.php) -
       errors nobody anticipated, not business events. -->
  <div class="card mt-4">
    <div class="card-body py-2 d-flex gap-2 align-items-center">
      <span class="fw-semibold">Application Error Log</span>
      <span class="text-muted small">PHP and browser errors, not audit events.</span>
      <input class="form-control form-control-sm w-auto ms-auto" id="errlog-search"
        placeholder="Search message, file, url, user...">
      <select class="form-select form-select-sm w-auto" id="errlog-source">
        <option value="">All sources</option>
        <option value="php">PHP</option>
        <option value="js">Browser</option>
      </select>
      <button class="btn btn-sm btn-outline-secondary" id="errlog-refresh">
        <span class="material-icons">refresh</span></button>
    </div>
  </div>
  <div class="card mt-3"><div class="table-responsive" style="max-height:50vh">
    <table class="table table-sm table-hover">
      <thead class="sticky-top"><tr><th>Timestamp</th><th>Source</th><th>Message</th>
        <th>File:Line</th><th>User</th></tr></thead>
      <tbody id="errlog-rows"></tbody>
    </table>
  </div></div>
</section>

<script>
/* ==================== Audit Logs module ==================== */
Pages.logs = (function () {
  function load() {
    busy(api('apiGetLogs', {
      search: document.getElementById('log-search').value,
      dateFrom: document.getElementById('log-from').value,
      dateTo: document.getElementById('log-to').value
    })).then(function (rows) {
      document.getElementById('log-rows').innerHTML = rows.map(function (r) {
        return '<tr><td class="text-nowrap">' +
          esc(String(r.Timestamp).replace('T', ' ').slice(0, 19)) + '</td>' +
          '<td>' + esc(r.User) + '</td><td><b>' + esc(r.Action) + '</b></td>' +
          '<td>' + esc(r.Module) + '</td>' +
          '<td class="small text-muted" style="max-width:420px;overflow:hidden;text-overflow:ellipsis">' +
          esc(r.Details) + '</td></tr>';
      }).join('') || '<tr><td colspan="5" class="text-center text-muted py-4">No log entries.</td></tr>';
    });
  }
  function loadErrors() {
    busy(api('apiGetErrorLog', {
      search: document.getElementById('errlog-search').value,
      source: document.getElementById('errlog-source').value
    })).then(function (rows) {
      document.getElementById('errlog-rows').innerHTML = rows.map(function (r) {
        return '<tr><td class="text-nowrap">' +
          esc(String(r.CreatedAt).replace('T', ' ').slice(0, 19)) + '</td>' +
          '<td><span class="badge ' + (r.Source === 'js' ? 'text-bg-warning' : 'text-bg-secondary') +
          '">' + esc(r.Source) + '</span></td>' +
          '<td class="small" style="max-width:420px;overflow:hidden;text-overflow:ellipsis">' +
          esc(r.Message) + '</td>' +
          '<td class="small text-muted text-nowrap">' + esc(r.File) +
          (r.Line ? ':' + esc(r.Line) : '') + '</td>' +
          '<td class="small">' + esc(r.UserEmail) + '</td></tr>';
      }).join('') || '<tr><td colspan="5" class="text-center text-muted py-4">No errors logged.</td></tr>';
    });
  }
  return {
    init: function () {
      document.getElementById('log-search').oninput = debounce(load);
      document.getElementById('log-from').onchange = load;
      document.getElementById('log-to').onchange = load;
      document.getElementById('log-refresh').onclick = load;
      load();

      document.getElementById('errlog-search').oninput = debounce(loadErrors);
      document.getElementById('errlog-source').onchange = loadErrors;
      document.getElementById('errlog-refresh').onclick = loadErrors;
      loadErrors();
    }
  };
})();
</script>
