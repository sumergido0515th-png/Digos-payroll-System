<!-- ==========================================================================
     timekeepers.php - split out of what was a single views/employees.php.
     See that file's own header for why.
     ========================================================================== -->

<!-- ==================== TIMEKEEPERS ==================== -->
<section class="page" id="page-timekeepers">
  <div class="card">
    <div class="card-body py-2 d-flex gap-2">
      <input class="form-control form-control-sm w-auto flex-grow-1" id="tk-search" placeholder="Live search timekeepers...">
      <button class="btn btn-sm btn-gov" id="tk-add" style="display:none">
        <span class="material-icons">add</span> New Timekeeper</button>
    </div>
  </div>
  <div class="card mt-3"><div class="table-responsive">
    <table class="table table-hover">
      <thead><tr><th>ID</th><th>Name</th><th>Office</th><th>Department</th>
        <th>Contact</th><th>Email</th><th>Status</th><th></th></tr></thead>
      <tbody id="tk-rows"></tbody>
    </table>
  </div></div>
</section>

<script>
/* ==================== Timekeepers module ==================== */
Pages.timekeepers = (function () {
  function load() {
    api('apiListTimekeepers', { search: document.getElementById('tk-search').value })
      .then(function (rows) {
        document.getElementById('tk-rows').innerHTML = rows.map(function (t) {
          return '<tr><td>' + esc(t.TimekeeperID) + '</td>' +
            '<td class="fw-semibold">' + esc(t.EmployeeName) + '</td>' +
            '<td>' + esc(t.OfficeCode) + '</td><td>' + esc(t.Department) + '</td>' +
            '<td>' + esc(t.Contact) + '</td><td>' + esc(t.Email) + '</td>' +
            '<td>' + badge(t.Status) + '</td>' +
            '<td class="text-end text-nowrap">' +
            (can('timekeeper.edit') ?
              actionBtn('edit', 'Pages.timekeepers.edit(\'' + t.TimekeeperID + '\')') +
              actionBtn('delete', 'Pages.timekeepers.remove(\'' + t.TimekeeperID + '\')', 'text-danger') : '') +
            '</td></tr>';
        }).join('') || '<tr><td colspan="8" class="text-center text-muted py-4">No timekeepers.</td></tr>';
      });
  }

  function openEditor(t) {
    t = t || {};
    openModal(t.TimekeeperID ? 'Edit Timekeeper' : 'New Timekeeper',
      '<form id="tk-form" class="row g-2">' +
      '<input type="hidden" name="TimekeeperID" value="' + esc(t.TimekeeperID || '') + '">' +
      '<div class="col-md-6"><label class="form-label">Employee Name *</label>' +
      '<input class="form-control form-control-sm" name="EmployeeName" value="' + esc(t.EmployeeName || '') + '"></div>' +
      '<div class="col-md-6"><label class="form-label">Office *</label>' +
      '<select class="form-select form-select-sm" name="OfficeCode">' +
      options(App.lookups.offices, 'OfficeCode', 'OfficeName', t.OfficeCode, '-- select --') + '</select></div>' +
      '<div class="col-md-6"><label class="form-label">Department</label>' +
      '<input class="form-control form-control-sm" name="Department" value="' + esc(t.Department || '') + '"></div>' +
      '<div class="col-md-6"><label class="form-label">Contact</label>' +
      '<input class="form-control form-control-sm" name="Contact" value="' + esc(t.Contact || '') + '"></div>' +
      '<div class="col-md-6"><label class="form-label">Email</label>' +
      '<input class="form-control form-control-sm" name="Email" value="' + esc(t.Email || '') + '"></div>' +
      '<div class="col-md-6"><label class="form-label">Status</label>' +
      '<select class="form-select form-select-sm" name="Status">' +
      options(['Active', 'Inactive'], null, null, t.Status || 'Active') + '</select></div></form>',
      [
        { label: 'Cancel', cls: 'btn-outline-secondary', onclick: closeModal },
        {
          label: 'Save', onclick: function () {
            busy(api('apiSaveTimekeeper', formData(document.getElementById('tk-form'))))
              .then(function () { toast('Timekeeper saved.'); closeModalSaved(); load(); loadLookups(); });
          }
        }
      ]);
  }

  return {
    init: function () {
      document.getElementById('tk-search').oninput = debounce(load);
      var add = document.getElementById('tk-add');
      add.style.display = can('timekeeper.edit') ? '' : 'none';
      add.onclick = function () { openEditor(null); };
      load();
    },
    edit: function (id) {
      api('apiListTimekeepers', {}).then(function (rows) {
        openEditor(rows.filter(function (r) { return r.TimekeeperID === id; })[0]);
      });
    },
    remove: function (id) {
      confirmDlg('Delete this timekeeper?', function () {
        busy(api('apiDeleteTimekeeper', { TimekeeperID: id })).then(function () {
          toast('Timekeeper deleted.'); load(); loadLookups();
        });
      });
    }
  };
})();
</script>
