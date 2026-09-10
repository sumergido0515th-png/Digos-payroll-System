<!-- ==========================================================================
     import.php - split out of what was a single views/settings.php.
     Bulk master-data import.
     See that file's own header for why.
     ========================================================================== -->

<!-- ==================== IMPORT DATA ====================
     Bulk master data from a spreadsheet, a table copied off a web page, or a
     JSON export. Two steps on purpose: the column mapping is worked out from
     whatever somebody typed in the heading row, and a guess nobody reviewed is
     a silent error - so the guess is always shown before anything is written.
     The stepper mirrors that: Choose, then Map & review, then Import - a real
     order the screen cannot be used out of, not decoration. -->
<section class="page" id="page-import">
  <div class="card import-hero">
    <div class="wisp a" aria-hidden="true">
      <svg viewBox="0 0 400 60" width="100%" height="100%" preserveAspectRatio="none">
        <path d="M0 40 Q100 0 200 30 T400 20" fill="none" stroke="#fff" stroke-width="2"/>
      </svg>
    </div>
    <div class="wisp b" aria-hidden="true">
      <svg viewBox="0 0 400 60" width="100%" height="100%" preserveAspectRatio="none">
        <path d="M0 20 Q100 55 200 25 T400 45" fill="none" stroke="#fff" stroke-width="2"/>
      </svg>
    </div>
    <div class="import-stepper" id="imp-stepper">
      <div class="import-step" data-step="1"><span class="n">1</span><span class="t">Choose file</span></div>
      <div class="import-step" data-step="2"><span class="n">2</span><span class="t">Map &amp; review</span></div>
      <div class="import-step" data-step="3"><span class="n">3</span><span class="t">Import</span></div>
    </div>
    <div class="card-body pt-2">
      <div class="import-kicker">Bulk master data</div>
      <h5 class="mb-3">Bring in a spreadsheet — mapped, checked, and shown back to you before anything is saved.</h5>
      <div class="row g-3 align-items-end">
        <div class="col-md-3">
          <label class="form-label" for="imp-entity">What are you importing?</label>
          <select class="form-select form-select-sm" id="imp-entity"></select>
          <div class="form-text small text-white-50" id="imp-limits"></div>
        </div>
        <div class="col-md-6">
          <label class="form-label" for="imp-file">File</label>
          <label class="import-dropzone" id="imp-dropzone">
            <span class="material-icons">upload_file</span>
            <span class="txt">
              <strong id="imp-filename">Choose a file</strong> or drag it here
              <small>.csv, .tsv, .xlsx, .html, .json</small>
            </span>
            <input type="file" class="d-none" id="imp-file"
                   accept=".csv,.tsv,.txt,.xlsx,.html,.htm,.json">
          </label>
        </div>
        <div class="col-md-3 text-md-end">
          <button class="btn btn-light btn-sm fw-semibold" id="imp-preview">
            <span class="material-icons">preview</span> Preview</button>
          <button class="btn btn-outline-light btn-sm" id="imp-paste-toggle"
                  title="Paste a table instead of choosing a file">
            <span class="material-icons">content_paste</span></button>
        </div>
        <div class="col-12 d-none" id="imp-paste-wrap">
          <label class="form-label" for="imp-paste">…or paste a table copied from a web page or a spreadsheet</label>
          <textarea class="form-control form-control-sm" id="imp-paste" rows="5"
                    placeholder="Paste here. A table copied from a browser keeps its columns; from a spreadsheet it arrives tab-separated. Both are read."></textarea>
        </div>
        <div class="col-12">
          <div class="form-text small text-white-50">
            Column headings do not have to match this system's field names — they are matched for
            you and shown for confirmation before anything is saved.
            <span class="d-block mt-1">PDF cannot be imported: it stores text as positioned
            characters with no columns, so a figure read back from one would be a guess. Save
            the source as .xlsx or .csv instead.</span>
          </div>
        </div>
      </div>
    </div>
  </div>
  <div id="imp-result" class="mt-3"></div>
</section>

<script>
/* ==================== Import module ====================
   Preview, then commit. The file is sent twice rather than parked on the
   server between the two calls: api.php is stateless, and an upload held
   somewhere waiting for a confirmation that may never come is its own cleanup
   problem. The server re-reads and re-validates on commit, so nothing here
   has to be trusted. */
Pages['import'] = (function () {
  var types = [];        // importable entities this role may write
  var preview = null;    // the last preview response
  var override = {};     // Field -> column index chosen by hand

  /**
   * The chosen file, or the pasted text, as a data: URL.
   *
   * Pasted text is encoded through TextEncoder rather than btoa() directly,
   * because btoa() throws on any character above U+00FF - which includes the
   * peso sign and the en dash Excel inserts into a copied heading.
   */
  function payload() {
    var file = document.getElementById('imp-file').files[0];
    if (file) {
      return new Promise(function (resolve, reject) {
        var reader = new FileReader();
        reader.onerror = function () { reject(new Error('That file could not be read.')); };
        reader.onload = function (e) { resolve({ data: e.target.result, fileName: file.name }); };
        reader.readAsDataURL(file);
      });
    }

    var text = document.getElementById('imp-paste').value;
    if (!text.trim()) return Promise.reject(new Error('Choose a file, or paste a table.'));

    var bytes = new TextEncoder().encode(text);
    var binary = '';
    bytes.forEach(function (b) { binary += String.fromCharCode(b); });
    // No file name: the pasted text has no extension to break a CSV/TSV tie,
    // and the sniffer works it out from the content instead.
    return Promise.resolve({ data: 'data:text/plain;base64,' + btoa(binary), fileName: '' });
  }

  function request(extra) {
    return payload().then(function (p) {
      var body = { entity: document.getElementById('imp-entity').value,
        data: p.data, fileName: p.fileName, mapping: override };
      Object.keys(extra || {}).forEach(function (k) { body[k] = extra[k]; });
      return body;
    });
  }

  function runPreview() {
    request().then(function (body) {
      return busy(api('apiPreviewImport', body));
    }).then(function (d) {
      preview = d;
      render();
    }).catch(function (e) {
      // A rejected payload() never reached api(), which shows its own errors.
      if (e && e.message) toast(e.message, 'warning');
    });
  }

  /** One row of the field-to-column table. */
  function mappingRow(field, m) {
    var required = preview.missingRequired.indexOf(field) >= 0;
    var options = ['<option value="">— not imported —</option>'].concat(
      preview.headers.map(function (h, i) {
        return '<option value="' + i + '"' + (m.column === i ? ' selected' : '') + '>' +
          esc(h || '(column ' + (i + 1) + ')') + '</option>';
      })).join('');

    var chip = m.column === null
      ? (required
          ? '<span class="imp-confidence missing"><span class="dot"></span>required — choose a column</span>'
          : '<span class="imp-confidence empty"><span class="dot"></span>not in this file</span>')
      : (m.confident
          ? '<span class="imp-confidence ok"><span class="dot"></span>matched</span>'
          : '<span class="imp-confidence low"><span class="dot"></span>low confidence — check this</span>');

    return '<tr' + (required && m.column === null ? ' class="table-danger"' : '') + '>' +
      '<td class="fw-semibold">' + esc(field) + '</td>' +
      '<td><select class="form-select form-select-sm imp-map" data-field="' + esc(field) + '">' +
      options + '</select></td>' +
      '<td class="small">' + chip + '</td></tr>';
  }

  function render() {
    var d = preview;
    var unmatched = Object.keys(d.unmatched).map(function (i) { return d.unmatched[i]; });

    var summary =
      '<div class="card"><div class="card-body py-2 d-flex flex-wrap gap-3 align-items-center">' +
      '<span><strong>' + esc(d.label) + '</strong></span>' +
      '<span class="badge text-bg-secondary">' + esc(d.format.toUpperCase()) +
      (d.sheet ? ' — ' + esc(d.sheet) : '') + '</span>' +
      '<span><span class="material-icons align-middle" style="font-size:15px">table_rows</span> ' +
      d.total + ' rows</span>' +
      '<span class="imp-confidence ok"><span class="dot"></span>' + d.validCount + ' ready</span>' +
      (d.invalidCount ? '<span class="imp-confidence missing"><span class="dot"></span>' +
        d.invalidCount + ' with problems</span>' : '') +
      '</div></div>';

    var warnings = '';
    if (d.missingRequired.length) {
      warnings += '<div class="alert alert-danger py-2 mt-3 mb-0">Required columns not mapped: <strong>' +
        esc(d.missingRequired.join(', ')) + '</strong>. Pick them below.</div>';
    }
    if (unmatched.length) {
      warnings += '<div class="alert alert-warning py-2 mt-3 mb-0">Columns in the file that nothing ' +
        'used: <strong>' + esc(unmatched.join(', ')) + '</strong>. Map one below if it was meant ' +
        'to be imported.</div>';
    }
    if (d.duplicateKeys.length) {
      warnings += '<div class="alert alert-warning py-2 mt-3 mb-0">The same record appears more ' +
        'than once in this file: <strong>' + esc(d.duplicateKeys.join(', ')) + '</strong>.</div>';
    }

    var mapping = '<div class="card mt-3"><div class="card-header py-2">Column mapping</div>' +
      '<div class="table-responsive" style="max-height:45vh"><table class="table table-sm mb-0">' +
      '<thead class="sticky-top"><tr><th>Field</th><th style="width:40%">Column in your file</th>' +
      '<th>Match</th></tr></thead><tbody>' +
      Object.keys(d.mapping).map(function (f) { return mappingRow(f, d.mapping[f]); }).join('') +
      '</tbody></table></div></div>';

    var rows = '<div class="card mt-3"><div class="card-header py-2">' +
      'Rows (problems first, first ' + d.rows.length + ' shown)</div>' +
      '<div class="table-responsive" style="max-height:45vh"><table class="table table-sm mb-0 import-rows-table">' +
      '<thead class="sticky-top"><tr><th>Row</th><th>Record</th><th>Values</th></tr></thead><tbody>' +
      d.rows.map(function (r) {
        var bad = r.errors.length > 0;
        return '<tr class="' + (bad ? 'row-bad' : 'row-ok') + '">' +
          '<td>' + r.line + '</td>' +
          '<td class="fw-semibold">' + esc(r.key || '—') + '</td>' +
          '<td class="small">' + (bad
            ? '<span class="text-danger">' + esc(r.errors.join('; ')) + '</span>'
            : esc(Object.keys(r.values).map(function (k) {
                return k + '=' + r.values[k];
              }).join('  '))) + '</td></tr>';
      }).join('') + '</tbody></table></div></div>';

    var canCommit = d.validCount > 0 && d.missingRequired.length === 0;
    var actions = '<div class="card mt-3"><div class="card-body py-2 d-flex gap-2 align-items-center">' +
      (d.invalidCount
        ? '<div class="form-check mb-0"><input class="form-check-input" type="checkbox" id="imp-skip">' +
          '<label class="form-check-label small" for="imp-skip">Import the ' + d.validCount +
          ' valid rows only and skip the ' + d.invalidCount + ' with problems</label></div>'
        : '<span class="text-muted small">Every row checks out.</span>') +
      '<button class="btn btn-gov btn-sm ms-auto" id="imp-commit"' + (canCommit ? '' : ' disabled') +
      '><span class="material-icons">upload</span> Import ' + d.validCount + ' rows</button>' +
      '</div></div>';

    document.getElementById('imp-result').innerHTML =
      summary + warnings + mapping + rows + actions;

    document.querySelectorAll('.imp-map').forEach(function (sel) {
      sel.onchange = function () {
        override[sel.dataset.field] = sel.value;
        runPreview();
      };
    });

    var commit = document.getElementById('imp-commit');
    if (commit) commit.onclick = doCommit;

    updateStepper();
  }

  function doCommit() {
    var skipBox = document.getElementById('imp-skip');
    var skip = !!(skipBox && skipBox.checked);

    confirmDlg('Import ' + preview.validCount + ' ' + preview.label + ' record(s)? ' +
      'Records whose code already exists will be updated.', function () {
        request({ skipInvalid: skip }).then(function (body) {
          return busy(api('apiCommitImport', body));
        }).then(function (d) {
          toast(d.created + ' created, ' + d.updated + ' updated' +
            (d.skipped ? ', ' + d.skipped + ' skipped' : '') + '.');
          document.getElementById('imp-result').innerHTML = '';
          document.getElementById('imp-file').value = '';
          document.getElementById('imp-paste').value = '';
          document.getElementById('imp-filename').textContent = 'Choose a file';
          preview = null;
          override = {};
          updateStepper();
          // Offices and periods feed dropdowns all over the SPA; without this
          // an imported office is missing from every picker until a reload.
          loadLookups();
        }).catch(function () { /* api() has already shown the message */ });
      });
  }

  /**
   * Reflects real state onto the stepper - not a fixed decoration. Choosing a
   * file or pasting text finishes step 1; loading a preview finishes step 2 and
   * hands the "current" mark to step 3, which is exactly the point at which the
   * screen has something committable.
   */
  function updateStepper() {
    var chosen = !!(document.getElementById('imp-file').files.length) ||
      !!document.getElementById('imp-paste').value.trim();
    var stage = preview ? 2 : (chosen ? 1 : 0);
    document.querySelectorAll('#imp-stepper .import-step').forEach(function (el, i) {
      el.classList.toggle('done', i < stage);
      el.classList.toggle('current', i === stage);
    });
  }

  /** "1.2 MB" / "480 KB" - however apiGetImportTypes reports the file cap. */
  function fmtBytes(n) {
    if (!n) return '';
    return n >= 1048576 ? (n / 1048576).toFixed(1) + ' MB' : Math.round(n / 1024) + ' KB';
  }

  return {
    init: function () {
      override = {};
      preview = null;
      document.getElementById('imp-result').innerHTML = '';
      document.getElementById('imp-filename').textContent = 'Choose a file';

      api('apiGetImportTypes').then(function (d) {
        types = d.types;
        document.getElementById('imp-entity').innerHTML = types.map(function (t) {
          return '<option value="' + esc(t.entity) + '">' + esc(t.label) + '</option>';
        }).join('') || '<option value="">Your role cannot import any record type</option>';
        document.getElementById('imp-limits').textContent =
          'Up to ' + fmtBytes(d.maxBytes) + ', ' + d.maxRows + ' rows per file.';
      });

      // Changing the target invalidates a mapping made against the old one.
      document.getElementById('imp-entity').onchange = function () {
        override = {};
        preview = null;
        document.getElementById('imp-result').innerHTML = '';
        updateStepper();
      };

      var fileInput = document.getElementById('imp-file');
      var dropzone = document.getElementById('imp-dropzone');
      fileInput.onchange = function () {
        override = {};
        var f = fileInput.files[0];
        document.getElementById('imp-filename').textContent = f ? f.name : 'Choose a file';
        preview = null;
        document.getElementById('imp-result').innerHTML = '';
        updateStepper();
      };
      // Native <label>+<input> already opens the file picker on click; only
      // the drag-and-drop path needs wiring by hand.
      ['dragenter', 'dragover'].forEach(function (ev) {
        dropzone.addEventListener(ev, function (e) {
          e.preventDefault();
          dropzone.classList.add('drag-over');
        });
      });
      ['dragleave', 'drop'].forEach(function (ev) {
        dropzone.addEventListener(ev, function (e) {
          e.preventDefault();
          dropzone.classList.remove('drag-over');
        });
      });
      dropzone.addEventListener('drop', function (e) {
        var files = e.dataTransfer && e.dataTransfer.files;
        if (files && files.length) {
          fileInput.files = files;
          fileInput.onchange();
        }
      });

      document.getElementById('imp-paste').oninput = function () {
        preview = null;
        updateStepper();
      };
      document.getElementById('imp-paste-toggle').onclick = function () {
        document.getElementById('imp-paste-wrap').classList.toggle('d-none');
      };
      document.getElementById('imp-preview').onclick = runPreview;

      updateStepper();
    }
  };
})();
</script>
