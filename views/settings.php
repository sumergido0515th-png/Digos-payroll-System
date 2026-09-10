<!-- ==========================================================================
     settings.php - the Settings screen: organisation details, branding
     uploads, payroll defaults and signatories.

     This file used to be five screens. It carried Settings, Users, Audit
     Logs, Backup/Restore and Import in one 956-line file with six Pages.*
     modules and no cross-references between them beyond Users reaching for
     the scope-grants panel it shows - which is to say five unrelated things
     kept together by nothing but the order they were written in. Its own
     header had already fallen behind, still naming four of the five after
     Import was added. Split into views/users.php, views/logs.php,
     views/backup.php and views/import.php, each its own <section> and
     Pages.* module; public/index.php includes all five.
     ========================================================================== -->

<!-- ==================== SETTINGS ==================== -->
<section class="page" id="page-settings">
  <div class="row g-3">
    <div class="col-lg-6">
      <div class="card h-100">
        <div class="card-header py-2">Organization &amp; Payroll</div>
        <div class="card-body row g-2" id="set-org"></div>
      </div>
    </div>
    <div class="col-lg-6">
      <div class="card h-100">
        <div class="card-header py-2">Authorized Signatories (printed payroll)</div>
        <div class="card-body row g-2" id="set-sign"></div>
      </div>
    </div>
    <div class="col-12 text-end">
      <button class="btn btn-gov" id="set-save">
        <span class="material-icons">save</span> Save Settings</button>
    </div>
  </div>
</section>

<script>
/* ==================== Settings module ==================== */
Pages.settings = (function () {
  /** [key, label, type, extra] descriptors per card. */
  var ORG = [
    ['GovernmentName', 'Government Name'], ['GovernmentSubtitle', 'Subtitle / City-Province'],
    ['GovernmentAddress', 'Office Address'], ['GovernmentContact', 'Contact Number'],
    ['GovernmentEmail', 'Email Address'], ['PagibigEmployerId', 'Pag-IBIG Employer ID No.'],
    ['CafoaExpenseCode', 'CAFOA Expense Code'],
    ['OfficeLogoUrl', 'Screen Logo', 'image',
      'Shown in the sidebar and on the sign-in page.'],
    ['PrintLogoUrl', 'Printed Seal', 'image',
      'Seal printed on the CAFOA header. Leave blank to print the screen logo.'],
    ['WatermarkUrl', 'Watermark Background', 'image',
      'Faint seal behind the dashboard and the sign-in page. Leave blank for none.'],
    ['WatermarkOpacity', 'Watermark Opacity (0.02 - 0.32)'],
    ['LoginBackgroundUrl', 'Sign-in Background Photo', 'image',
      'Photo behind the sign-in card. Auto-fits any size or aspect ratio without distorting it. Leave blank for the default look.'],
    ['LoginHeadline', 'Sign-in Headline',
      'text', 'Shown above the sign-in card. The last word is highlighted.'],
    ['LoginTagline', 'Sign-in Tagline',
      'text', 'Shown under the headline. Put "|" where the second half should be highlighted.'],
    ['PayrollPrefix', 'Payroll Number Prefix'],
    ['DefaultTaxRate', 'Default Tax Rate (%)', 'number'],
    ['OvertimeMultiplier', 'Overtime Multiplier', 'number'],
    ['WorkingDaysPerMonth', 'Working Days / Month', 'number'],
    ['WorkingHoursPerDay', 'Working Hours / Day', 'number'],
    ['MaxEmployeesPerPayroll', 'Max Employees / Payroll', 'number'],
    ['SessionTimeoutMinutes', 'Session Timeout (minutes)', 'number'],
    ['SystemTheme', 'Default Theme', 'select', ['light', 'dark']]
  ];
  var SIGN = [
    ['SignatoryPreparedBy', 'Prepared / Certified By (A)'],
    ['SignatoryPreparedByTitle', 'Title (A)'],
    ['SignatoryFundsAvailable', 'Funds Available By (B)'],
    ['SignatoryFundsAvailableTitle', 'Title (B)'],
    ['SignatoryApprovedBy', 'Approved By (C)'],
    ['SignatoryApprovedByTitle', 'Title (C)'],
    ['SignatoryCertifiedBy', 'Paid / Certified By (D)'],
    ['SignatoryCertifiedByTitle', 'Title (D)'],
    ['SignatoryBudgetOfficer', 'Budget Officer (CAFOA)'],
    ['SignatoryBudgetOfficerTitle', 'Title (Budget Officer)']
  ];

  /** Element ids for one image setting's controls, derived from its key. */
  function imageIds(key) {
    return { url: 'set-img-url-' + key, file: 'set-img-file-' + key,
      button: 'set-img-btn-' + key, preview: 'set-img-prev-' + key };
  }

  /**
   * The preview element: the image itself, or a placeholder gap.
   *
   * Sized to fill the field's width and shown against white, because that is
   * what the sidebar and the printed form put it on - a pale logo that will
   * vanish there should be visible as a problem here, not hidden by a preview
   * too small to judge.
   */
  function imagePreviewHtml(key, url) {
    var id = imageIds(key).preview;
    return url
      ? '<img id="' + id + '" src="' + esc(url) + '" alt="Preview" ' +
        'style="width:100%;max-height:180px;object-fit:contain;' +
        'border:1px solid #ddd;border-radius:6px;padding:6px;background:#fff;">'
      : '<div id="' + id + '" style="min-height:80px;"></div>';
  }

  /** Swaps one preview for the image at url, or for the placeholder gap. */
  function showImagePreview(key, url) {
    var preview = document.getElementById(imageIds(key).preview);
    if (preview) preview.outerHTML = imagePreviewHtml(key, url);
  }

  /**
   * Wires one image setting's Upload button. Called after render(), because
   * none of these elements exist until the settings come back from the server.
   *
   * api.php takes JSON only, so the file is read here and sent inline as a
   * data: URL. The server saves the setting itself and answers with the URL it
   * stored, which is what goes into the text field - the name the browser sent
   * is not the name it was saved under.
   */
  function bindImageUpload(key) {
    var id = imageIds(key);
    var fileInput = document.getElementById(id.file);
    var urlInput = document.getElementById(id.url);
    if (!fileInput || !urlInput) return;

    document.getElementById(id.button).onclick = function () { fileInput.click(); };
    urlInput.onchange = function () { showImagePreview(key, urlInput.value); };
    fileInput.onchange = function () {
      var file = fileInput.files && fileInput.files[0];
      if (!file) return;
      if (!/^image\//.test(file.type)) {
        toast('Please choose an image file (PNG, JPG, GIF or WEBP).', 'warning');
        fileInput.value = '';
        return;
      }
      var reader = new FileReader();
      reader.onerror = function () {
        toast('That file could not be read.', 'danger');
        fileInput.value = '';
      };
      reader.onload = function (evt) {
        busy(api('apiUploadImageSetting', { key: key, data: evt.target.result }))
          .then(function (d) {
            urlInput.value = d.url;
            showImagePreview(key, d.url);
            toast('Image uploaded and saved. Reload to see it applied.');
          }).catch(function () { /* message already shown by api() */ })
          .then(function () { fileInput.value = ''; });
      };
      reader.readAsDataURL(file);
    };
  }

  /** Renders one settings card from its descriptors. */
  function render(hostId, defs, values) {
    document.getElementById(hostId).innerHTML = defs.map(function (d) {
      var v = values[d[0]] === undefined ? '' : values[d[0]];
      if (d[2] === 'select') {
        return '<div class="col-md-6"><label class="form-label">' + d[1] + '</label>' +
          '<select class="form-select form-select-sm set-field" data-key="' + d[0] + '">' +
          options(d[3], null, null, v) + '</select></div>';
      }
      if (d[2] === 'image') {
        var id = imageIds(d[0]);
        return '<div class="col-md-6"><label class="form-label">' + d[1] + '</label>' +
          '<div class="input-group input-group-sm mb-1">' +
          '<input id="' + id.url + '" class="form-control set-field" type="text" ' +
          'data-key="' + d[0] + '" value="' + esc(v) + '">' +
          '<button type="button" class="btn btn-outline-secondary" id="' + id.button +
          '">Upload</button></div>' +
          '<input type="file" id="' + id.file + '" class="d-none" ' +
          'accept="image/png,image/jpeg,image/gif,image/webp">' +
          (d[3] ? '<div class="form-text small mb-1">' + esc(d[3]) + '</div>' : '') +
          '<div class="mb-2">' + imagePreviewHtml(d[0], v) + '</div></div>';
      }
      return '<div class="col-md-6"><label class="form-label">' + d[1] + '</label>' +
        '<input class="form-control form-control-sm set-field" type="' + (d[2] || 'text') +
        '" data-key="' + d[0] + '" value="' + esc(v) + '">' +
        (d[3] ? '<div class="form-text small">' + esc(d[3]) + '</div>' : '') + '</div>';
    }).join('');
  }

  var dirtyBound = false;   // page-level input listener attached once

  return {
    init: function () {
      // Editing settings marks the page dirty so navigating away confirms.
      if (!dirtyBound) {
        dirtyBound = true;
        document.getElementById('page-settings').addEventListener('input', function (e) {
          // Picking an image is not an unsaved edit - that upload saves itself.
          if (e.target.type !== 'file') App.editorDirty = true;
        });
      }
      busy(api('apiGetSettings')).then(function (values) {
        render('set-org', ORG, values);
        render('set-sign', SIGN, values);
        ORG.forEach(function (d) { if (d[2] === 'image') bindImageUpload(d[0]); });
      });
      document.getElementById('set-save').onclick = function () {
        var settings = {};
        document.querySelectorAll('.set-field').forEach(function (el) {
          settings[el.dataset.key] = el.value;
        });
        busy(api('apiSaveSettings', { settings: settings })).then(function () {
          App.editorDirty = false;
          toast('Settings saved.');
        });
      };
    }
  };
})();
</script>
