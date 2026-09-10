<!-- ==========================================================================
     departments.php - Departments/Offices/Functions. Split out of what was
     a single views/employees.php. See that file's own header for why.
     ========================================================================== -->

<!-- ==================== DEPARTMENTS / OFFICES / FUNCTIONS ==================== -->
<section class="page" id="page-departments">
  <ul class="nav nav-pills mb-3" id="dept-tabs">
    <li class="nav-item"><a class="nav-link active cursor-pointer" data-tab="offices">Offices</a></li>
    <li class="nav-item"><a class="nav-link cursor-pointer" data-tab="departments">Departments</a></li>
    <li class="nav-item"><a class="nav-link cursor-pointer" data-tab="functions">Functions / Funds</a></li>
  </ul>
  <div class="card">
    <div class="card-body py-2 d-flex gap-2">
      <input class="form-control form-control-sm flex-grow-1" id="org-search" placeholder="Live search...">
      <button class="btn btn-sm btn-gov" id="org-add" style="display:none">
        <span class="material-icons">add</span> New</button>
    </div>
  </div>
  <div class="card mt-3"><div class="table-responsive">
    <table class="table table-hover">
      <thead id="org-head"></thead><tbody id="org-rows"></tbody>
    </table>
  </div></div>
</section>

<script>
/* ==================== Departments / Offices / Functions module ============ */
Pages.departments = (function () {
  var tab = 'offices';

  /** Per-tab configuration: table layout, endpoints and editor fields. */
  var CFG = {
    offices: {
      head: ['Code', 'Office Name', 'Department', 'Division', 'Function', 'Office Head', 'Status', ''],
      list: 'apiListOffices', save: 'apiSaveOffice', del: 'apiDeleteOffice', key: 'OfficeCode',
      cols: ['OfficeCode', 'OfficeName', 'Department', 'Division', 'Function', 'OfficeHead'],
      fields: [['OfficeCode', 'Office Code *'], ['OfficeName', 'Office Name *'],
        ['Department', 'Department'], ['Division', 'Division'],
        ['Function', 'Function'], ['OfficeHead', 'Office Head'],
        ['FunctionCode', 'Function / PPA charged', 'functions', 'FunctionCode', 'FunctionName'],
        ['ParentOfficeCode', 'Parent Office', 'offices', 'OfficeCode', 'OfficeName']]
    },
    departments: {
      head: ['Code', 'Department Name', 'Office', 'Head', 'Status', ''],
      list: 'apiListDepartments', save: 'apiSaveDepartment', del: 'apiDeleteDepartment', key: 'DeptCode',
      cols: ['DeptCode', 'DeptName', 'OfficeCode', 'Head'],
      fields: [['DeptCode', 'Department Code *'], ['DeptName', 'Department Name *'],
        ['OfficeCode', 'Office Code'], ['Head', 'Department Head'],
        ['ParentDeptCode', 'Parent Department', 'departments', 'DeptCode', 'DeptName']]
    },
    functions: {
      head: ['Code', 'Function Name', 'Description', 'Status', ''],
      list: 'apiListFunctions', save: 'apiSaveFunction', del: 'apiDeleteFunction', key: 'FunctionCode',
      cols: ['FunctionCode', 'FunctionName', 'Description'],
      fields: [['FunctionCode', 'Function Code *'], ['FunctionName', 'Function Name *'],
        ['Description', 'Description'],
        ['OwningOfficeCode', 'Owning Office', 'offices', 'OfficeCode', 'OfficeName']]
    }
  };

  function load() {
    var c = CFG[tab];
    document.getElementById('org-head').innerHTML =
      '<tr>' + c.head.map(function (h) { return '<th>' + h + '</th>'; }).join('') + '</tr>';
    api(c.list, { search: document.getElementById('org-search').value }).then(function (rows) {
      document.getElementById('org-rows').innerHTML = rows.map(function (r) {
        return '<tr>' + c.cols.map(function (col, i) {
          return '<td class="' + (i === 1 ? 'fw-semibold' : '') + '">' + esc(r[col]) + '</td>';
        }).join('') +
          '<td>' + badge(r.Status) + '</td>' +
          '<td class="text-end text-nowrap">' +
          (can('office.edit') ?
            actionBtn('edit', 'Pages.departments.edit(\'' + esc(r[c.key]) + '\')') +
            actionBtn('delete', 'Pages.departments.remove(\'' + esc(r[c.key]) + '\')', 'text-danger') : '') +
          '</td></tr>';
      }).join('') || '<tr><td colspan="9" class="text-center text-muted py-4">No records.</td></tr>';
    });
  }

  function openEditor(rec) {
    var c = CFG[tab];
    rec = rec || {};
    openModal((rec[c.key] ? 'Edit ' : 'New ') + tab.replace(/s$/, ''),
      '<form id="org-form" class="row g-2">' +
      c.fields.map(function (f) {
        // f[2] names a lookup: render a picker instead of a text box. These
        // fields are foreign keys, and a typed code that matches no row is a
        // constraint violation the user cannot act on - "-- none --" is the
        // only way to clear one.
        if (f[2]) {
          var lk = App.lookups[f[2]] || [];
          return '<div class="col-md-6"><label class="form-label">' + f[1] + '</label>' +
            '<select class="form-select form-select-sm" name="' + f[0] + '">' +
            options(lk, f[3], f[4], rec[f[0]] || '', '-- none --') + '</select></div>';
        }
        return '<div class="col-md-6"><label class="form-label">' + f[1] + '</label>' +
          '<input class="form-control form-control-sm" name="' + f[0] +
          '" value="' + esc(rec[f[0]] || '') + '"></div>';
      }).join('') +
      '<div class="col-md-6"><label class="form-label">Status</label>' +
      '<select class="form-select form-select-sm" name="Status">' +
      options(['Active', 'Inactive'], null, null, rec.Status || 'Active') + '</select></div></form>',
      [
        { label: 'Cancel', cls: 'btn-outline-secondary', onclick: closeModal },
        {
          label: 'Save', onclick: function () {
            busy(api(c.save, formData(document.getElementById('org-form'))))
              .then(function () { toast('Saved.'); closeModalSaved(); load(); loadLookups(); });
          }
        }
      ]);
  }

  return {
    init: function () {
      document.querySelectorAll('#dept-tabs a').forEach(function (a) {
        a.onclick = function () {
          document.querySelectorAll('#dept-tabs a').forEach(function (x) {
            x.classList.toggle('active', x === a);
          });
          tab = a.dataset.tab;
          load();
        };
      });
      document.getElementById('org-search').oninput = debounce(load);
      var add = document.getElementById('org-add');
      add.style.display = can('office.edit') ? '' : 'none';
      add.onclick = function () { openEditor(null); };
      load();
    },
    edit: function (key) {
      var c = CFG[tab];
      api(c.list, {}).then(function (rows) {
        openEditor(rows.filter(function (r) { return String(r[c.key]) === key; })[0]);
      });
    },
    remove: function (key) {
      var c = CFG[tab];
      confirmDlg('Delete this record?', function () {
        var p = {}; p[c.key] = key;
        busy(api(c.del, p)).then(function () { toast('Deleted.'); load(); loadLookups(); });
      });
    }
  };
})();
</script>
