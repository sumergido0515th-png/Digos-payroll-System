        <!-- ==========================================================================
     documents.php - Phase 3. The four authority documents plus contracts.
     One page, five tabs, because they are consulted together: a pre-auditor
     checking a payroll line asks "what authorised this?" and the answer is a
     memo, an exemption, a travel order, a shift or the contract rate.
     ========================================================================== -->
<section class="page" id="page-documents">
  <ul class="nav nav-tabs" id="doc-tabs">
    <li class="nav-item"><a class="nav-link active" data-doc="memo" href="#">Memoranda</a></li>
    <li class="nav-item"><a class="nav-link" data-doc="bioex" href="#">Bio Exemptions</a></li>
    <li class="nav-item"><a class="nav-link" data-doc="travel" href="#">Travel Orders</a></li>
    <li class="nav-item"><a class="nav-link" data-doc="shift" href="#">Work Shifts</a></li>
    <li class="nav-item"><a class="nav-link" data-doc="contract" href="#">Contracts</a></li>
  </ul>

  <div class="card mt-3">
    <div class="card-body py-2">
      <div class="row g-2 align-items-end">
        <div class="col-md-5"><label class="form-label">Live Search</label>
          <input class="form-control form-control-sm" id="doc-search" placeholder="Search..."></div>
        <div class="col-md-3"><label class="form-label">Status</label>
          <select class="form-select form-select-sm" id="doc-status">
            <option value="">All</option><option>Active</option>
            <option>Superseded</option><option>Inactive</option>
          </select></div>
        <div class="col-md-4 text-end">
          <button class="btn btn-sm btn-gov" id="doc-new">
            <span class="material-icons" style="font-size:18px;vertical-align:-4px">add</span>
            New
          </button>
        </div>
      </div>
    </div>
  </div>

  <div class="card mt-3"><div class="table-responsive">
    <table class="table table-hover">
      <thead id="doc-head"></thead>
      <tbody id="doc-rows"></tbody>
    </table>
  </div></div>
</section>

<script>
/** Documents page module: memoranda, exemptions, travel orders, shifts, contracts. */
Pages.documents = (function () {

  /** Which tab is showing. */
  var tab = 'memo';

  /** Employees the caller may see, loaded once per page visit for the pickers. */
  var employees = [];

  /**
   * Per-tab configuration.
   *
   * The five documents differ enough in their columns that a shared renderer
   * would be mostly branches, and little enough in their shape that five
   * copies of the page would be five places to fix a bug. This table is the
   * middle: what to call, what to show, and what the form asks for.
   */
  var TABS = {
    memo: {
      title: 'Memorandum', list: 'apiListMemoranda', save: 'apiSaveMemorandum',
      remove: 'apiDeleteMemorandum', key: 'MemoID',
      perm: 'document.edit', delPerm: 'document.delete',
      head: ['Control No.', 'Subject', 'Authority', 'Office', 'Effectivity', 'Covers', 'Status'],
      cells: function (r) {
        return [r.ControlNo, r.Subject, r.AuthorityType, r.OfficeCode || 'Citywide',
          effectivity(r), (r.CoveredCount || 0) + ' employee(s)', badge(r.Status)];
      }
    },
    bioex: {
      title: 'Bio Exemption', list: 'apiListBioExemptions', save: 'apiSaveBioExemption',
      remove: 'apiDeleteBioExemption', key: 'ExemptionID',
      perm: 'document.edit', delPerm: 'document.delete',
      head: ['Employee', 'Office', 'Reason', 'Valid From', 'Valid To', 'Proof', 'Status'],
      cells: function (r) {
        return [r.EmployeeName, r.OfficeCode, r.ReasonCode || r.Reason,
          fmtDate(r.ValidFrom), fmtDate(r.ValidTo), r.ProofType, badge(r.Status)];
      }
    },
    travel: {
      title: 'Travel Order', list: 'apiListTravelOrders', save: 'apiSaveTravelOrder',
      remove: 'apiDeleteTravelOrder', key: 'TravelOrderID',
      perm: 'document.edit', delPerm: 'document.delete',
      head: ['T.O. No.', 'Employee', 'Destination', 'Depart', 'Return', 'Per Diem', 'Status'],
      cells: function (r) {
        return [r.TravelOrderNo, r.EmployeeName, r.Destination,
          fmtDate(r.DepartDate), fmtDate(r.ReturnDate),
          Number(r.PerDiem) ? 'Yes' : 'No', badge(r.Status)];
      }
    },
    shift: {
      title: 'Work Shift', list: 'apiListWorkShifts', save: 'apiSaveWorkShift',
      key: 'ShiftID', perm: 'shift.edit',
      head: ['Code', 'Name', 'Version', 'In', 'Out', 'Break', 'Rest Days', 'Effective From'],
      cells: function (r) {
        return [r.ShiftCode, r.ShiftName, 'v' + r.VersionNo, r.TimeIn, r.TimeOut,
          r.BreakMinutes + ' min', restDayNames(r.RestDays), fmtDate(r.EffectiveFrom)];
      }
    },
    contract: {
      title: 'Contract', list: 'apiListContracts', save: 'apiSaveContract',
      key: 'ContractID', perm: 'contract.edit',
      head: ['Employee', 'Office', 'Type', 'Basis', 'Rate', 'Start', 'End', 'Status'],
      cells: function (r) {
        return [r.EmployeeName, r.OfficeCode, r.TypeCode || '-', r.RateBasis,
          fmtMoney(r.Rate), fmtDate(r.StartDate), fmtDate(r.EndDate), badge(r.Status)];
      }
    }
  };

  var DAY_NAMES = ['', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

  /** '6,7' -> 'Sat, Sun'. */
  function restDayNames(csv) {
    if (!csv) return 'None';
    return String(csv).split(',').map(function (d) {
      return DAY_NAMES[Number(d)] || d;
    }).join(', ');
  }

  /** A one-line summary of however the memo's effectivity was entered. */
  function effectivity(r) {
    if (r.EffectivityType === 'Specific') return 'Specific: ' + (r.SpecificDates || '-');
    if (r.EffectivityType === 'Recurring') return 'Weekly: ' + restDayNames(r.RecurrenceDays);
    if (r.EffectivityType === 'OpenEnded') return fmtDate(r.EffectivityStart) + ' onwards';

    var span = fmtDate(r.EffectivityStart) + ' - ' + fmtDate(r.EffectivityEnd);
    return r.EffectivityType === 'Window' && r.TimeFrom ?
      span + ', ' + r.TimeFrom + '-' + r.TimeTo : span;
  }

  /** Loads and renders the active tab. */
  function load() {
    var cfg = TABS[tab];

    document.getElementById('doc-head').innerHTML = '<tr>' +
      cfg.head.map(function (h) { return '<th>' + esc(h) + '</th>'; }).join('') +
      '<th class="text-end">Actions</th></tr>';

    api(cfg.list, {
      search: document.getElementById('doc-search').value,
      Status: document.getElementById('doc-status').value
    }).then(function (rows) {
      document.getElementById('doc-rows').innerHTML = rows.map(function (r) {
        var id = r[cfg.key];
        return '<tr>' + cfg.cells(r).map(function (c, i) {
          // The status cell is already markup from badge(); everything else is
          // data and gets escaped.
          return '<td>' + (i === cfg.head.length - 1 && /^<span/.test(String(c))
            ? c : esc(c === null || c === undefined ? '' : c)) + '</td>';
        }).join('') +
        '<td class="text-end text-nowrap">' +
          (can(cfg.perm) ? actionBtn('edit',
            'Pages.documents.edit(\'' + id + '\')') : '') +
          (cfg.remove && can(cfg.delPerm) ? actionBtn('delete',
            'Pages.documents.remove(\'' + id + '\')', 'text-danger') : '') +
        '</td></tr>';
      }).join('') || '<tr><td colspan="' + (cfg.head.length + 1) +
        '" class="text-center text-muted py-4">No ' + esc(cfg.title.toLowerCase()) +
        ' records within your access.</td></tr>';

      Pages.documents._rows = rows;
    });
  }

  /** The employee <select> markup, from what the caller may actually see. */
  function employeeOptions(selected) {
    return options(employees, 'EmployeeID', 'EmployeeName', selected, 'Select employee...');
  }

  /** Builds the form body for the active tab. */
  function formHtml(r) {
    r = r || {};
    if (tab === 'memo') {
      return '<div class="row g-2">' +
        field('Control No.', '<input class="form-control form-control-sm" name="ControlNo" value="' + esc(r.ControlNo || '') + '">', 4) +
        field('Subject', '<input class="form-control form-control-sm" name="Subject" value="' + esc(r.Subject || '') + '">', 8) +
        field('Authority Type', select('AuthorityType', ['Overtime', 'Detail', 'Travel', 'FlexiTime', 'Suspension', 'Other'], r.AuthorityType), 4) +
        field('Office', '<select class="form-select form-select-sm" name="OfficeCode">' +
          options(App.lookups.offices, 'OfficeCode', 'OfficeName', r.OfficeCode, 'Citywide') + '</select>', 4) +
        field('Status', select('Status', ['Active', 'Superseded', 'Revoked'], r.Status), 4) +
        field('Date Issued', dateInput('DateIssued', r.DateIssued), 4) +
        field('Date Approved', dateInput('DateApproved', r.DateApproved), 4) +
        field('Date Received', dateInput('DateReceived', r.DateReceived), 4) +
        field('Effectivity', select('EffectivityType', ['Range', 'Specific', 'Recurring', 'Window', 'OpenEnded'], r.EffectivityType), 4) +
        field('From', dateInput('EffectivityStart', r.EffectivityStart), 4) +
        field('To', dateInput('EffectivityEnd', r.EffectivityEnd), 4) +
        field('Time From', timeInput('TimeFrom', r.TimeFrom), 3) +
        field('Time To', timeInput('TimeTo', r.TimeTo), 3) +
        field('Specific Dates', '<input class="form-control form-control-sm" name="SpecificDates" placeholder="2026-07-04,2026-07-11" value="' + esc(r.SpecificDates || '') + '">', 6) +
        field('Weekdays', '<input class="form-control form-control-sm" name="RecurrenceDays" placeholder="1,3 = Mon and Wed" value="' + esc(r.RecurrenceDays || '') + '">', 6) +
        field('Covered Employees', '<select class="form-select form-select-sm" name="EmployeeIDs" multiple size="6">' +
          options(employees, 'EmployeeID', 'EmployeeName', '', undefined) + '</select>' +
          '<div class="form-text">Only employees within your access are listed.</div>', 6) +
        field('Remarks', '<textarea class="form-control form-control-sm" name="Remarks" rows="3">' + esc(r.Remarks || '') + '</textarea>', 6) +
        '</div>';
    }
    if (tab === 'bioex') {
      return '<div class="row g-2">' +
        field('Employee', '<select class="form-select form-select-sm" name="EmployeeID">' + employeeOptions(r.EmployeeID) + '</select>', 6) +
        field('Reason Code', '<input class="form-control form-control-sm" name="ReasonCode" value="' + esc(r.ReasonCode || '') + '">', 6) +
        field('Reason', '<input class="form-control form-control-sm" name="Reason" value="' + esc(r.Reason || '') + '">', 12) +
        field('Valid From', dateInput('ValidFrom', r.ValidFrom), 4) +
        field('Valid To', dateInput('ValidTo', r.ValidTo), 4) +
        field('Status', select('Status', ['Active', 'Inactive'], r.Status), 4) +
        field('Proof Type', '<input class="form-control form-control-sm" name="ProofType" value="' + esc(r.ProofType || '') + '">', 6) +
        field('Proof Reference', '<input class="form-control form-control-sm" name="ProofRef" value="' + esc(r.ProofRef || '') + '">', 6) +
        field('Remarks', '<textarea class="form-control form-control-sm" name="Remarks" rows="2">' + esc(r.Remarks || '') + '</textarea>', 12) +
        '</div>';
    }
    if (tab === 'travel') {
      return '<div class="row g-2">' +
        field('T.O. No.', '<input class="form-control form-control-sm" name="TravelOrderNo" value="' + esc(r.TravelOrderNo || '') + '">', 6) +
        field('Employee', '<select class="form-select form-select-sm" name="EmployeeID">' + employeeOptions(r.EmployeeID) + '</select>', 6) +
        field('Destination', '<input class="form-control form-control-sm" name="Destination" value="' + esc(r.Destination || '') + '">', 6) +
        field('Purpose', '<input class="form-control form-control-sm" name="Purpose" value="' + esc(r.Purpose || '') + '">', 6) +
        field('Depart', dateInput('DepartDate', r.DepartDate), 4) +
        field('Return', dateInput('ReturnDate', r.ReturnDate), 4) +
        field('Status', select('Status', ['Active', 'Inactive'], r.Status), 4) +
        field('', '<div class="form-check"><input class="form-check-input" type="checkbox" name="PerDiem"' +
          (Number(r.PerDiem) ? ' checked' : '') + '><label class="form-check-label">Per diem claimed</label></div>', 6) +
        field('Remarks', '<textarea class="form-control form-control-sm" name="Remarks" rows="2">' + esc(r.Remarks || '') + '</textarea>', 6) +
        '</div>';
    }
    if (tab === 'shift') {
      return '<div class="alert alert-info py-2 small">Saving creates a new <strong>version</strong>. ' +
        'The version in force keeps its times and ends the day before this one starts, so a ' +
        'payroll prepared last quarter can still be reconciled against the shift that applied then.</div>' +
        '<div class="row g-2">' +
        field('Shift Code', '<input class="form-control form-control-sm" name="ShiftCode" value="' + esc(r.ShiftCode || '') + '">', 4) +
        field('Name', '<input class="form-control form-control-sm" name="ShiftName" value="' + esc(r.ShiftName || '') + '">', 8) +
        field('Time In', timeInput('TimeIn', r.TimeIn), 3) +
        field('Time Out', timeInput('TimeOut', r.TimeOut), 3) +
        field('Break (min)', '<input type="number" class="form-control form-control-sm" name="BreakMinutes" value="' + esc(r.BreakMinutes || 0) + '">', 3) +
        field('Effective From', dateInput('EffectiveFrom', ''), 3) +
        field('Rest Days', '<input class="form-control form-control-sm" name="RestDays" placeholder="6,7 = Sat and Sun" value="' + esc(r.RestDays || '') + '">', 4) +
        field('Night Diff. From', timeInput('NightDiffFrom', r.NightDiffFrom), 4) +
        field('Night Diff. To', timeInput('NightDiffTo', r.NightDiffTo), 4) +
        field('Remarks', '<textarea class="form-control form-control-sm" name="Remarks" rows="2">' + esc(r.Remarks || '') + '</textarea>', 12) +
        '</div>';
    }
    return '<div class="alert alert-info py-2 small">Saving records a <strong>new engagement</strong>. ' +
      'An existing contract is closed the day before this one starts rather than overwritten, ' +
      'because the pre-audit compares a payroll line against the rate that was in force on its dates.</div>' +
      '<div class="row g-2">' +
      field('Employee', '<select class="form-select form-select-sm" name="EmployeeID">' + employeeOptions(r.EmployeeID) + '</select>', 6) +
      field('Type', '<input class="form-control form-control-sm" name="TypeCode" value="' + esc(r.TypeCode || '') + '">', 3) +
      field('Basis', select('RateBasis', ['Daily', 'Monthly', 'Hourly'], r.RateBasis), 3) +
      field('Rate', '<input type="number" step="0.01" class="form-control form-control-sm" name="Rate" value="' + esc(r.Rate || '') + '">', 4) +
      field('Start Date', dateInput('StartDate', ''), 4) +
      field('End Date', dateInput('EndDate', ''), 4) +
      field('Remarks', '<textarea class="form-control form-control-sm" name="Remarks" rows="2">' + esc(r.Remarks || '') + '</textarea>', 12) +
      '</div>';
  }

  function field(label, control, cols) {
    return '<div class="col-md-' + cols + '"><label class="form-label">' +
      esc(label) + '</label>' + control + '</div>';
  }

  function select(name, values, selected) {
    return '<select class="form-select form-select-sm" name="' + name + '">' +
      options(values, null, null, selected) + '</select>';
  }

  function dateInput(name, value) {
    return '<input type="date" class="form-control form-control-sm" name="' + name +
      '" value="' + esc(value || '') + '">';
  }

  function timeInput(name, value) {
    return '<input type="time" class="form-control form-control-sm" name="' + name +
      '" value="' + esc((value || '').substring(0, 5)) + '">';
  }

  /** Opens the form, blank or filled from the row already loaded. */
  function openForm(row) {
    var cfg = TABS[tab];

    openModal((row ? 'Edit ' : 'New ') + cfg.title, formHtml(row), [
      { label: 'Cancel', cls: 'btn-outline-secondary', onclick: closeModal },
      { label: 'Save', onclick: function () {
        var body = document.getElementById('app-modal-body');
        var payload = formData(body);

        // A multi-select is not one value, so formData() cannot carry it.
        var covered = body.querySelector('[name="EmployeeIDs"]');
        if (covered) {
          payload.EmployeeIDs = Array.prototype.slice.call(covered.selectedOptions)
            .map(function (o) { return o.value; });
        }
        if (row) payload[cfg.key] = row[cfg.key];

        busy(api(cfg.save, payload)).then(function () {
          closeModalSaved();
          toast(cfg.title + ' saved.');
          load();
        });
      } }
    ]);
  }

  return {
    _rows: [],

    init: function () {
      document.querySelectorAll('#doc-tabs .nav-link').forEach(function (a) {
        a.onclick = function (e) {
          e.preventDefault();
          document.querySelectorAll('#doc-tabs .nav-link').forEach(function (x) {
            x.classList.remove('active');
          });
          a.classList.add('active');
          tab = a.dataset.doc;
          document.getElementById('doc-new').style.display =
            can(TABS[tab].perm) ? '' : 'none';
          load();
        };
      });

      document.getElementById('doc-search').oninput = debounce(load);
      document.getElementById('doc-status').onchange = load;
      document.getElementById('doc-new').onclick = function () { openForm(null); };
      document.getElementById('doc-new').style.display = can(TABS[tab].perm) ? '' : 'none';

      // The employee pickers must offer only what the caller may see, so they
      // come from the scoped endpoint rather than from a lookup table.
      //
      // apiListEmployees answers {total, page, pageSize, rows}, not a bare
      // array - and pageSize is 25, so a picker reading only the first page
      // would silently omit everybody after the twenty-fifth name. Asking for
      // one large page is right here: this is a <select>, not a table.
      api('apiListEmployees', { Status: 'Active', pageSize: 5000 }).then(function (d) {
        employees = (d.rows || []).map(function (e) {
          return { EmployeeID: e.EmployeeID, EmployeeName: e.LastName + ', ' + e.FirstName };
        });
        load();
      });
    },

    edit: function (id) {
      var cfg = TABS[tab];
      var row = Pages.documents._rows.filter(function (r) { return r[cfg.key] === id; })[0];
      openForm(row);
    },

    remove: function (id) {
      var cfg = TABS[tab];
      confirmDlg('Delete this ' + cfg.title.toLowerCase() + '?', function () {
        var payload = {};
        payload[cfg.key] = id;
        busy(api(cfg.remove, payload)).then(function () {
          toast(cfg.title + ' deleted.');
          load();
        });
      });
    }
  };
})();
</script>
