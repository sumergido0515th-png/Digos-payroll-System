        <!-- ==========================================================================
     coverage.php - Phase 5. The coverage matrix and the attachment register.
     The matrix has one job: make an unjustified day impossible to miss.
     ========================================================================== -->
<section class="page" id="page-coverage">
  <ul class="nav nav-tabs" id="cov-tabs">
    <li class="nav-item"><a class="nav-link active" data-cov="matrix" href="#">Coverage Matrix</a></li>
    <li class="nav-item"><a class="nav-link" data-cov="files" href="#">Attachments</a></li>
  </ul>

  <!-- ---------------------------------------------------------- matrix -->
  <div id="cov-pane-matrix">
    <div class="card mt-3"><div class="card-body py-2">
      <div class="row g-2 align-items-end">
        <div class="col-md-3"><label class="form-label">Period</label>
          <select class="form-select form-select-sm" id="cov-period"></select></div>
        <div class="col-md-3"><label class="form-label">Office</label>
          <select class="form-select form-select-sm" id="cov-office"></select></div>
        <div class="col-md-3"><label class="form-label">Shift (for rest days)</label>
          <select class="form-select form-select-sm" id="cov-shift"></select></div>
        <div class="col-md-3 text-end"><div id="cov-summary" class="small"></div></div>
      </div>
      <div class="small mt-2" id="cov-legend"></div>
    </div></div>

    <div class="card mt-3"><div class="table-responsive">
      <table class="table table-sm table-bordered align-middle mb-0" id="cov-table">
        <thead id="cov-head"></thead>
        <tbody id="cov-rows"></tbody>
      </table>
    </div></div>
  </div>

  <!-- ----------------------------------------------------- attachments -->
  <div id="cov-pane-files" style="display:none">
    <div class="card mt-3"><div class="card-body py-2">
      <div class="row g-2 align-items-end">
        <div class="col-md-6"><label class="form-label">Live Search</label>
          <input class="form-control form-control-sm" id="att-search"
                 placeholder="File name, control number..."></div>
        <div class="col-md-6 text-end">
          <button class="btn btn-sm btn-gov" id="att-new">
            <span class="material-icons" style="font-size:18px;vertical-align:-4px">upload_file</span>
            Upload
          </button>
        </div>
      </div>
    </div></div>

    <div class="card mt-3"><div class="table-responsive">
      <table class="table table-hover">
        <thead><tr>
          <th>File</th><th>Control No.</th><th>Type</th><th>Covers</th>
          <th>Size</th><th>Uploaded</th><th class="text-end">Actions</th>
        </tr></thead>
        <tbody id="att-rows"></tbody>
      </table>
    </div></div>
  </div>
</section>

<script>
/** Coverage matrix and attachment register. */
Pages.coverage = (function () {

  var tab = 'matrix';
  var employees = [];

  /**
   * Cell state -> how it draws.
   *
   * Only one of these is loud. A matrix that shouts at every cell is exactly
   * as useless as one that shouts at none, and the whole point of the screen
   * is that the eye lands on the red.
   */
  var STATES = {
    biometric:    { cls: 'bg-success-subtle',  mark: '&check;', label: 'Biometric' },
    travel_order: { cls: 'bg-info-subtle',     mark: 'TO',      label: 'Travel Order' },
    bio_exemption:{ cls: 'bg-info-subtle',     mark: 'BE',      label: 'Bio Exemption' },
    attachment:   { cls: 'bg-info-subtle',     mark: '&#128206;', label: 'Attachment' },
    holiday:      { cls: 'bg-warning-subtle',  mark: 'H',       label: 'Holiday' },
    rest_day:     { cls: 'bg-light',           mark: '&middot;',label: 'Rest Day' },
    absent:       { cls: 'bg-secondary-subtle',mark: 'A',       label: 'Absent' },
    no_record:    { cls: '',                   mark: '',        label: 'No record' },
    unjustified:  { cls: 'bg-danger text-white fw-bold', mark: '!', label: 'UNJUSTIFIED' }
  };

  function showTab(name) {
    tab = name;
    document.getElementById('cov-pane-matrix').style.display = name === 'matrix' ? '' : 'none';
    document.getElementById('cov-pane-files').style.display = name === 'files' ? '' : 'none';
    name === 'matrix' ? loadMatrix() : loadFiles();
  }

  /* ------------------------------------------------------------- matrix */

  function loadMatrix() {
    var periodId = document.getElementById('cov-period').value;
    if (!periodId) return;

    busy(api('apiGetCoverageMatrix', {
      PeriodID: periodId,
      OfficeCode: document.getElementById('cov-office').value,
      ShiftCode: document.getElementById('cov-shift').value
    })).then(drawMatrix);
  }

  function drawMatrix(d) {
    document.getElementById('cov-head').innerHTML = '<tr><th style="min-width:180px">Employee</th>' +
      d.dates.map(function (date) {
        var day = new Date(date + 'T00:00:00')
          .toLocaleDateString(undefined, { weekday: 'narrow' });
        return '<th class="text-center small p-1" title="' + esc(date) + '">' +
          esc(date.substring(8)) + '<br><span class="text-muted">' + esc(day) + '</span></th>';
      }).join('') + '</tr>';

    document.getElementById('cov-rows').innerHTML = d.employees.map(function (e) {
      return '<tr><td class="small">' + esc(e.EmployeeName) +
        '<div class="text-muted">' + esc(e.OfficeCode) + '</div></td>' +
        d.dates.map(function (date) {
          var cell = (d.cells[e.EmployeeID] || {})[date] || { state: 'no_record', reason: '' };
          var s = STATES[cell.state] || STATES.no_record;
          return '<td class="text-center p-1 ' + s.cls + '" title="' + esc(cell.reason) + '">' +
            s.mark + '</td>';
        }).join('') + '</tr>';
    }).join('') || '<tr><td class="text-center text-muted py-4">' +
      'No employees within your access for this period.</td></tr>';

    var gaps = d.gaps.length;
    document.getElementById('cov-summary').innerHTML = gaps
      ? '<span class="badge text-bg-danger">' + gaps + ' unjustified day(s)</span>'
      : '<span class="badge text-bg-success">No unjustified days</span>';

    document.getElementById('cov-legend').innerHTML = Object.keys(STATES).map(function (k) {
      return '<span class="badge ' + (STATES[k].cls || 'bg-light text-dark') + ' me-1">' +
        STATES[k].mark + ' ' + esc(STATES[k].label) + '</span>';
    }).join('');
  }

  /* -------------------------------------------------------- attachments */

  function loadFiles() {
    api('apiListAttachments', { search: document.getElementById('att-search').value })
      .then(function (rows) {
        document.getElementById('att-rows').innerHTML = rows.map(function (a) {
          return '<tr>' +
            '<td><a href="' + esc(a.Url) + '" target="_blank">' + esc(a.FileName) + '</a></td>' +
            '<td>' + esc(a.ControlNo) + '</td>' +
            '<td>' + esc(a.DocumentType) + '</td>' +
            '<td class="small">' + esc(fmtDate(a.CoversFrom)) + ' - ' + esc(fmtDate(a.CoversTo)) + '</td>' +
            '<td class="small">' + esc(a.SizeKb) + ' KB</td>' +
            '<td class="small">' + esc(fmtDate(a.UploadedAt)) + '</td>' +
            '<td class="text-end">' +
              (can('attachment.edit')
                ? actionBtn('delete', 'Pages.coverage.remove(\'' + a.AttachmentID + '\')', 'text-danger')
                : '') +
            '</td></tr>';
        }).join('') || '<tr><td colspan="7" class="text-center text-muted py-4">' +
          'No attachments within your access.</td></tr>';
      });
  }

  /** The upload form. Coverage is asked for here, not deferred to print time. */
  function uploadForm() {
    openModal('Upload attachment',
      '<div class="row g-2">' +
        '<div class="col-md-6"><label class="form-label">File (PDF, JPG or PNG)</label>' +
          '<input type="file" class="form-control form-control-sm" id="att-file" ' +
          'accept=".pdf,.jpg,.jpeg,.png"></div>' +
        '<div class="col-md-6"><label class="form-label">Control No.</label>' +
          '<input class="form-control form-control-sm" name="ControlNo"></div>' +
        '<div class="col-md-6"><label class="form-label">Document Type</label>' +
          '<select class="form-select form-select-sm" name="DocumentType">' +
          options(['Memorandum', 'BioExemption', 'TravelOrder', 'Leave', 'Other'], null, null, 'Other') +
          '</select></div>' +
        '<div class="col-md-3"><label class="form-label">Covers From</label>' +
          '<input type="date" class="form-control form-control-sm" name="CoversFrom"></div>' +
        '<div class="col-md-3"><label class="form-label">Covers To</label>' +
          '<input type="date" class="form-control form-control-sm" name="CoversTo"></div>' +
        '<div class="col-md-6"><label class="form-label">Covered Employees</label>' +
          '<select class="form-select form-select-sm" id="att-employees" multiple size="6">' +
          options(employees, 'EmployeeID', 'EmployeeName', '', undefined) + '</select>' +
          '<div class="form-text">Asked now, not at print time - this binding is what the ' +
          'pre-audit checks a manual entry against.</div></div>' +
        '<div class="col-md-6"><label class="form-label">Remarks</label>' +
          '<textarea class="form-control form-control-sm" name="Remarks" rows="4"></textarea></div>' +
      '</div>', [
        { label: 'Cancel', cls: 'btn-outline-secondary', onclick: closeModal },
        { label: 'Upload', onclick: submitUpload }
      ]);
  }

  function submitUpload() {
    var body = document.getElementById('app-modal-body');
    var file = document.getElementById('att-file').files[0];
    if (!file) { toast('Choose a file first.', 'warning'); return; }

    var reader = new FileReader();
    reader.onload = function () {
      var payload = formData(body);
      payload.FileName = file.name;
      payload.data = reader.result;
      payload.EmployeeIDs = Array.prototype.slice
        .call(document.getElementById('att-employees').selectedOptions)
        .map(function (o) { return o.value; });

      busy(api('apiUploadAttachment', payload)).then(function (d) {
        closeModalSaved();
        toast('Uploaded, covering ' + d.covered + ' employee-day(s).');
        loadFiles();
      });
    };
    reader.readAsDataURL(file);
  }

  return {
    init: function () {
      document.getElementById('cov-period').innerHTML =
        options(App.lookups.periods, 'PeriodID', 'PeriodID', '', 'Select period...');
      document.getElementById('cov-office').innerHTML =
        options(App.lookups.offices, 'OfficeCode', 'OfficeName', '', 'All offices');

      document.querySelectorAll('#cov-tabs .nav-link').forEach(function (a) {
        a.onclick = function (e) {
          e.preventDefault();
          document.querySelectorAll('#cov-tabs .nav-link')
            .forEach(function (x) { x.classList.remove('active'); });
          a.classList.add('active');
          showTab(a.dataset.cov);
        };
      });

      document.getElementById('cov-period').onchange = loadMatrix;
      document.getElementById('cov-office').onchange = loadMatrix;
      document.getElementById('cov-shift').onchange = loadMatrix;
      document.getElementById('att-search').oninput = debounce(loadFiles);
      document.getElementById('att-new').onclick = uploadForm;
      document.getElementById('att-new').style.display =
        can('attachment.edit') ? '' : 'none';

      // Shifts and employees are both needed by the forms; loaded once here
      // rather than on every open.
      api('apiListWorkShifts', {}, true).then(function (shifts) {
        document.getElementById('cov-shift').innerHTML =
          options(shifts, 'ShiftCode', 'ShiftCode', '', 'No rest days');
      }, function () { /* the role may not hold shift.view */ });

      api('apiListEmployees', { Status: 'Active', pageSize: 5000 }).then(function (d) {
        employees = (d.rows || []).map(function (e) {
          return { EmployeeID: e.EmployeeID, EmployeeName: e.LastName + ', ' + e.FirstName };
        });
      });

      showTab('matrix');
    },

    remove: function (id) {
      confirmDlg('Delete this attachment and its file?', function () {
        busy(api('apiDeleteAttachment', { AttachmentID: id })).then(function () {
          toast('Attachment deleted.');
          loadFiles();
        });
      });
    }
  };
})();
</script>
