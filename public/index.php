<?php
/**
 * index.php - SPA shell. Requires a signed-in session (redirects to
 * login.php otherwise); the page then boots via apiGetSession.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';

if (empty($_SESSION['email'])) {
    header('Location: login.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Digos City Payroll Management System</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  <script src="https://www.gstatic.com/charts/loader.js"></script>
  <link href="assets/css/app.css" rel="stylesheet">
  <!-- Core must load before the view partials: they register on window.Pages -->
  <script src="assets/js/app.js"></script>
</head>
<body>

<div id="app">

  <!-- ==================== SIDEBAR ==================== -->
  <aside id="sidebar">
    <div class="brand">
      <!-- The icon is the fallback; app.js swaps in the uploaded office logo. -->
      <div class="seal" id="brand-seal"><span class="material-icons">account_balance</span></div>
      <h6 id="brand-name">DIGOS CITY GOVERNMENT</h6>
      <small>Employee Payroll Management System<br>Job Order &bull; Contract of Service</small>
    </div>
    <nav id="nav-menu">
      <div class="nav-section-label">Overview</div>
      <a class="nav-item-link" data-page="dashboard" data-perm="dashboard.view"><span class="material-icons">dashboard</span>Dashboard</a>

      <div class="nav-section-label">Payroll</div>
      <a class="nav-item-link" data-page="payroll" data-perm="payroll.view"><span class="material-icons">request_quote</span>Payroll Transactions</a>
      <a class="nav-item-link" data-page="dtr" data-perm="dtr.view"><span class="material-icons">event_note</span>Daily Time Records</a>
      <a class="nav-item-link" data-page="preaudit" data-perm="payroll.approve"><span class="material-icons">fact_check</span>Pre-Audit Worklist</a>
      <a class="nav-item-link" data-page="periods" data-perm="period.view"><span class="material-icons">date_range</span>Payroll Period</a>
      <a class="nav-item-link" data-page="reports" data-perm="report.view"><span class="material-icons">summarize</span>Payroll Reports</a>
      <a class="nav-item-link" data-page="print" data-perm="print.run"><span class="material-icons">print</span>Print Payroll</a>

      <div class="nav-section-label">People &amp; Records</div>
      <a class="nav-item-link" data-page="employees" data-perm="employee.view"><span class="material-icons">badge</span>Employees</a>
      <a class="nav-item-link" data-page="timekeepers" data-perm="timekeeper.view"><span class="material-icons">schedule</span>Timekeepers</a>
      <a class="nav-item-link" data-page="departments" data-perm="office.view"><span class="material-icons">apartment</span>Departments &amp; Offices</a>
      <a class="nav-item-link" data-page="documents" data-perm="document.view"><span class="material-icons">description</span>Authority Documents</a>
      <a class="nav-item-link" data-page="coverage" data-perm="attachment.view"><span class="material-icons">fact_check</span>Coverage &amp; Attachments</a>

      <div class="nav-section-label">Administration</div>
      <a class="nav-item-link" data-page="import" data-perm="data.import"><span class="material-icons">upload_file</span>Import Data</a>
      <a class="nav-item-link" data-page="users" data-perm="user.manage"><span class="material-icons">manage_accounts</span>Users</a>
      <a class="nav-item-link" data-page="logs" data-perm="log.view"><span class="material-icons">history</span>Audit Logs</a>
      <a class="nav-item-link" data-page="settings" data-perm="settings.edit"><span class="material-icons">settings</span>Settings</a>
      <a class="nav-item-link" data-page="backup" data-perm="backup.run"><span class="material-icons">cloud_sync</span>Backup &amp; Restore</a>
    </nav>
    <div class="user-box">
      <div class="d-flex align-items-center justify-content-between gap-2">
        <div class="flex-grow-1 min-w-0">
          <div id="user-name" class="fw-semibold text-truncate">&hellip;</div>
          <div class="d-flex align-items-center gap-2 mt-1">
            <span id="user-email" class="opacity-75 text-truncate"></span>
            <span id="user-role" class="role-badge flex-shrink-0"></span>
          </div>
        </div>
        <button class="btn-icon-logout flex-shrink-0" id="btn-logout" title="Sign out" aria-label="Sign out">
          <span class="material-icons">logout</span>
        </button>
      </div>
    </div>
  </aside>

  <!-- ==================== MAIN ==================== -->
  <div id="main">
    <!-- Watermark layer. Sits behind the content; app.js fills in the image
         and reveals it only on the pages that ask for it. -->
    <div id="watermark" aria-hidden="true"></div>

    <header id="topbar">
      <button class="btn btn-sm btn-outline-secondary" id="btn-sidebar">
        <span class="material-icons" style="font-size:18px;vertical-align:-4px">menu</span>
      </button>
      <h5 id="page-title">Dashboard</h5>
      <div class="ms-auto d-flex gap-2 align-items-center">
        <!-- Global control-number search (Phase 9E). One box: a payroll no.,
             a memo/travel-order control no., a contract ID or an employee no.
             jumps straight to the screen that record lives on. -->
        <form id="global-search-form" class="d-none d-sm-block" autocomplete="off">
          <div class="input-group input-group-sm" style="width:230px">
            <span class="input-group-text bg-transparent border-end-0">
              <span class="material-icons" style="font-size:16px">search</span></span>
            <input class="form-control border-start-0" id="global-search"
                   placeholder="Jump to control no....">
          </div>
        </form>
        <button class="btn btn-sm btn-outline-secondary" id="btn-theme" title="Toggle dark mode">
          <span class="material-icons" style="font-size:18px;vertical-align:-4px">dark_mode</span>
        </button>
      </div>
    </header>

    <main id="page-host">
      <?php
        include dirname(__DIR__) . '/views/dashboard.php';
        include dirname(__DIR__) . '/views/employees.php';
        include dirname(__DIR__) . '/views/payroll.php';
        include dirname(__DIR__) . '/views/preaudit.php';
        include dirname(__DIR__) . '/views/documents.php';
        include dirname(__DIR__) . '/views/dtr.php';
        include dirname(__DIR__) . '/views/coverage.php';
        include dirname(__DIR__) . '/views/reports.php';
        include dirname(__DIR__) . '/views/print.php';
        include dirname(__DIR__) . '/views/settings.php';
      ?>
    </main>
  </div>
</div>

<!-- global helpers -->
<div class="spinner-overlay" id="spinner">
  <div class="spinner-border text-light" style="width:3rem;height:3rem"></div>
</div>
<div id="toast-host"></div>

<!-- generic modal shell reused by every screen -->
<div class="modal fade" id="app-modal" tabindex="-1">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header py-2">
        <h6 class="modal-title" id="app-modal-title"></h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body" id="app-modal-body"></div>
      <div class="modal-footer py-2" id="app-modal-footer"></div>
    </div>
  </div>
</div>

</body>
</html>
