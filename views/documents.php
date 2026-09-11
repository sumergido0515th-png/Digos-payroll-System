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
        <div class="col-md-8"><label class="form-label">Live Search</label>
          <input class="form-control form-control-sm" id="doc-search" placeholder="Search..."></div>
        <div class="col-md-4 text-end">
          <!-- Same filters the active tab is showing - see public/export.php.
               Absent on the Work Shifts tab, which is unscoped reference
               data rather than a FilterSpec entity - see init() below. -->
          <a class="btn btn-sm btn-outline-secondary" id="doc-export" href="#" target="_blank">
            <span class="material-icons" style="font-size:16px;vertical-align:-3px">download</span> Export CSV</a>
          <button class="btn btn-sm btn-gov" id="doc-new">
            <span class="material-icons" style="font-size:18px;vertical-align:-4px">add</span>
            New
          </button>
        </div>
      </div>
      <!-- Hidden on the Work Shifts tab along with the export button, and for
           the same reason: shifts are unscoped reference data, not a
           FilterSpec entity, so there is no sort allowlist to offer. -->
      <div class="row g-2 align-items-end mt-1" id="doc-sort-row">
        <div class="col-md-3"><label class="form-label">Sort by</label>
          <select class="form-select form-select-sm" id="doc-sort"></select></div>
        <div class="col-md-3">
          <button class="btn btn-sm btn-outline-secondary w-100" id="doc-direction" type="button"
                  title="Reverse sort direction">
            <span class="material-icons" id="doc-direction-icon" style="font-size:16px;vertical-align:-3px">arrow_downward</span>
            <span id="doc-direction-label">Newest first</span></button></div>
      </div>
      <!-- Built per tab from that entity's own scoped facet endpoint, never
           from App.lookups: an office dropdown listing every office in the
           city gives up the org chart before a row is fetched, which is the
           disclosure 9E found on Payroll, Employees and Reports. The Status
           choices come from here too - they used to be one hardcoded list
           shared by all five tabs, which offered memoranda an "Inactive" they
           never have while hiding the "Revoked" they do. -->
      <div class="row g-2 align-items-end mt-1" id="doc-facet-row"></div>
      <!-- The "When" half. One range at a time, named by a dropdown, rather
           than a From/To pair per date: Memoranda alone carry three
           (issued, received, effectivity) and six date boxes is a filter bar
           nobody reads. FilterSpec refuses a malformed date rather than
           dropping it, and type="date" can only produce YYYY-MM-DD or
           nothing, so the refusal is unreachable from here by construction -
           it stays the guard for a hand-written URL. -->
      <div class="row g-2 align-items-end mt-1" id="doc-date-row"></div>
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

  /** Current sort direction - shared across tabs, unlike the sort key. */
  var direction = 'DESC';

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
      remove: 'apiDeleteMemorandum', key: 'MemoID', entity: 'Memorandum',
      perm: 'document.edit', delPerm: 'document.delete',
      sorts: [['issued', 'Date issued'], ['received', 'Date received'],
        ['effectivity', 'Effectivity start'], ['controlNo', 'Control no.'],
        ['office', 'Office'], ['status', 'Status']],
      // FunctionCode is a FilterSpec facet and is deliberately not offered
      // yet. 0004 backfilled it from FunctionName and left NULL whatever did
      // not resolve, which is still every row: FacetOptions skips NULL and
      // '', so the control would render as a permanently empty "All" - a
      // filter that looks broken rather than one that has nothing to say. It
      // belongs here once the Backlog's Function/PPA data entry is done.
      facetApi: 'apiGetMemorandumFacets',
      facets: [['Status', 'Status'], ['OfficeCode', 'Office'],
        ['AuthorityType', 'Authority type'], ['EffectivityType', 'Effectivity']],
      // "Effectivity (within)" is named differently because it means
      // something different: IssuedFrom/IssuedTo are both DateIssued, so the
      // range is a point falling inside it, while EffectiveFrom is
      // EffectivityStart and EffectiveTo is EffectivityEnd - a memo matches
      // when its whole span sits inside the window. Same two boxes, and a
      // reader who is not told will read the second as the first.
      //
      // It has a sharper edge than the label can carry, confirmed against
      // live rows rather than reasoned about: EffectivityEnd is NULL on every
      // OpenEnded and Recurring memo, so `EffectivityEnd <= to` is NULL and
      // filters them ALL out the moment a To date is set - the four open-ended
      // memoranda the dashboard keeps a watchlist for included. Arguably
      // right (a memo with no end is contained in no window) and arguably a
      // trap; either way it is FilterSpec's semantics since 9B and not
      // something to quietly re-decide in a view. Logged to the Backlog.
      dates: [['Issued', 'IssuedFrom', 'IssuedTo'],
        ['Received', 'ReceivedFrom', 'ReceivedTo'],
        ['Effectivity (within)', 'EffectiveFrom', 'EffectiveTo']],
      head: ['Control No.', 'Subject', 'Authority', 'Office', 'Effectivity', 'Covers', 'Status'],
      cells: function (r) {
        return [r.ControlNo, r.Subject, r.AuthorityType, r.OfficeCode || 'Citywide',
          effectivity(r), (r.CoveredCount || 0) + ' employee(s)', badge(r.Status)];
      }
    },
    bioex: {
      title: 'Bio Exemption', list: 'apiListBioExemptions', save: 'apiSaveBioExemption',
      remove: 'apiDeleteBioExemption', key: 'ExemptionID', entity: 'BioExemptions',
      perm: 'document.edit', delPerm: 'document.delete',
      sorts: [['validFrom', 'Valid from'], ['validTo', 'Valid to'],
        ['reason', 'Reason'], ['status', 'Status']],
      facetApi: 'apiGetBioExemptionFacets',
      facets: [['Status', 'Status'], ['OfficeCode', 'Office'],
        ['ReasonCode', 'Reason'], ['ProofType', 'Proof type']],
      // A span, like the memo's effectivity above: ValidFrom is the exemption's
      // own ValidFrom and ValidTo its ValidTo.
      dates: [['Validity (within)', 'ValidFrom', 'ValidTo']],
      head: ['Employee', 'Office', 'Reason', 'Valid From', 'Valid To', 'Proof', 'Status'],
      cells: function (r) {
        return [r.EmployeeName, r.OfficeCode, r.ReasonCode || r.Reason,
          fmtDate(r.ValidFrom), fmtDate(r.ValidTo), r.ProofType, badge(r.Status)];
      }
    },
    travel: {
      title: 'Travel Order', list: 'apiListTravelOrders', save: 'apiSaveTravelOrder',
      remove: 'apiDeleteTravelOrder', key: 'TravelOrderID', entity: 'TravelOrders',
      perm: 'document.edit', delPerm: 'document.delete',
      sorts: [['depart', 'Depart date'], ['return', 'Return date'],
        ['travelOrderNo', 'T.O. no.'], ['status', 'Status']],
      facetApi: 'apiGetTravelOrderFacets',
      facets: [['Status', 'Status'], ['OfficeCode', 'Office']],
      dates: [['Departure', 'DepartFrom', 'DepartTo'],
        ['Return', 'ReturnFrom', 'ReturnTo']],
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
      key: 'ContractID', entity: 'Contracts', perm: 'contract.edit',
      sorts: [['start', 'Start date'], ['end', 'End date'], ['status', 'Status']],
      facetApi: 'apiGetContractFacets',
      facets: [['Status', 'Status'], ['OfficeCode', 'Office'],
        ['TypeCode', 'Type'], ['RateBasis', 'Rate basis']],
      dates: [['Start date', 'StartFrom', 'StartTo'],
        ['End date', 'EndFrom', 'EndTo']],
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
    var filters = { search: document.getElementById('doc-search').value };

    (cfg.facets || []).forEach(function (f) {
      var sel = document.getElementById('doc-f-' + f[0]);
      if (sel && sel.value) filters[f[0]] = sel.value;
    });

    // Only the range the dropdown is naming. Switching the dropdown therefore
    // moves the dates to the new pair of keys rather than leaving the old
    // pair applied behind a label that no longer says so.
    var range = activeRange();
    if (range) {
      if (range.from) filters[range.fromKey] = range.from;
      if (range.to) filters[range.toKey] = range.to;
    }

    // Only for a FilterSpec entity: the Work Shifts tab's apiListWorkShifts
    // does not go through the query core and has no sort allowlist to name.
    if (cfg.sorts) {
      filters.sort = document.getElementById('doc-sort').value;
      filters.direction = direction;
    }

    // The tab is part of the shareable state too, not just the filters - a
    // link to the Bio Exemptions tab should reopen there, not on Memoranda.
    syncUrl('documents', Object.assign({ tab: tab }, filters));

    var exportBtn = document.getElementById('doc-export');
    exportBtn.style.display = cfg.entity ? '' : 'none';
    if (cfg.entity) exportBtn.href = 'export.php?entity=' + cfg.entity + '&' + queryString(filters);

    document.getElementById('doc-head').innerHTML = '<tr>' +
      cfg.head.map(function (h) { return '<th>' + esc(h) + '</th>'; }).join('') +
      '<th class="text-end">Actions</th></tr>';

    api(cfg.list, filters).then(function (rows) {
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
            'Pages.documents.edit', [id]) : '') +
          (cfg.remove && can(cfg.delPerm) ? actionBtn('delete',
            'Pages.documents.remove', [id], 'text-danger') : '') +
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

  /** Switches the active tab without loading - init(params) and the tab clicks both use this. */
  function selectTab(name) {
    if (!TABS[name]) return;
    tab = name;
    document.querySelectorAll('#doc-tabs .nav-link').forEach(function (a) {
      a.classList.toggle('active', a.dataset.doc === name);
    });
    document.getElementById('doc-new').style.display = can(TABS[tab].perm) ? '' : 'none';
    populateSorts();
  }

  /**
   * Rebuilds the sort dropdown for the active tab.
   *
   * Each entity has its own sort allowlist, so this has to be rebuilt per tab
   * rather than filled once: FilterSpec *refuses* a key it does not know
   * ("There is no 'controlNo' column to sort by") rather than ignoring it the
   * way it ignores an unknown filter, because sorting is the one place the
   * payload becomes an identifier. Rebuilding resets the selection to the
   * entity's own default, which is what keeps a tab switch from carrying the
   * previous tab's key into a query that would refuse it.
   */
  function populateSorts() {
    var cfg = TABS[tab];
    document.getElementById('doc-sort-row').style.display = cfg.sorts ? '' : 'none';
    if (!cfg.sorts) return;

    document.getElementById('doc-sort').innerHTML = cfg.sorts.map(function (s) {
      return '<option value="' + esc(s[0]) + '">' + esc(s[1]) + '</option>';
    }).join('');
  }

  /** True if the active tab offers this sort key - see populateSorts(). */
  function tabHasSort(key) {
    var cfg = TABS[tab];
    return !!cfg.sorts && cfg.sorts.some(function (s) { return s[0] === key; });
  }

  function updateDirectionButton() {
    document.getElementById('doc-direction-icon').textContent =
      direction === 'ASC' ? 'arrow_upward' : 'arrow_downward';
    document.getElementById('doc-direction-label').textContent =
      direction === 'ASC' ? 'Oldest first' : 'Newest first';
  }

  /** Facet choices per tab, fetched once per page visit. */
  var facetCache = {};

  /**
   * Builds the active tab's filter dropdowns.
   *
   * `choices` is the scoped facet response - entity key => the values that
   * actually occur in rows this caller may see - or null when the fetch
   * failed. A null renders the controls disabled and saying so rather than
   * as empty "All" dropdowns, which would look like an entity with nothing
   * to filter by instead of a filter bar that did not load.
   */
  function renderFacets(choices, selected) {
    var cfg = TABS[tab];
    var row = document.getElementById('doc-facet-row');
    selected = selected || {};

    if (!cfg.facets) { row.innerHTML = ''; return; }

    row.innerHTML = cfg.facets.map(function (f) {
      var body = choices
        ? options(choices[f[0]] || [], null, null, selected[f[0]] || '', 'All')
        : '<option value="">Unavailable</option>';
      return '<div class="col-md-3"><label class="form-label">' + esc(f[1]) + '</label>' +
        '<select class="form-select form-select-sm" id="doc-f-' + esc(f[0]) + '"' +
        (choices ? '' : ' disabled') + '>' + body + '</select></div>';
    }).join('');

    // The selects are rebuilt on every tab switch, so the handlers go on with
    // them rather than once at init.
    cfg.facets.forEach(function (f) {
      var sel = document.getElementById('doc-f-' + f[0]);
      if (sel) sel.onchange = load;
    });
  }

  /**
   * The date range the "When" row is currently naming, or null.
   *
   * @return {?{fromKey: string, toKey: string, from: string, to: string}}
   */
  function activeRange() {
    var cfg = TABS[tab];
    var which = document.getElementById('doc-date-field');
    if (!cfg.dates || !which) return null;

    var range = cfg.dates[Number(which.value) || 0];
    return {
      fromKey: range[1], toKey: range[2],
      from: document.getElementById('doc-date-from').value,
      to: document.getElementById('doc-date-to').value
    };
  }

  /**
   * Builds the "When" row for the active tab.
   *
   * The named range is carried by index rather than by its key, because the
   * two keys are what the payload spells and the index is what the control
   * is: naming the dropdown 'IssuedFrom' would leave the To key implicit and
   * invite the two to be read from different places.
   */
  function renderDates(selected) {
    var cfg = TABS[tab];
    var row = document.getElementById('doc-date-row');
    selected = selected || {};

    if (!cfg.dates) { row.innerHTML = ''; return; }

    // A shared link names its range by the keys it carries, so the dropdown
    // follows the link rather than the link being forced onto range 0 - a
    // ?DepartFrom=... that opened on "Return" would show a date the list is
    // not filtered by.
    var picked = 0;
    cfg.dates.forEach(function (d, i) {
      if (selected[d[1]] || selected[d[2]]) picked = i;
    });
    var range = cfg.dates[picked];

    row.innerHTML =
      '<div class="col-md-3"><label class="form-label">Date range</label>' +
      '<select class="form-select form-select-sm" id="doc-date-field">' +
      cfg.dates.map(function (d, i) {
        return '<option value="' + i + '"' + (i === picked ? ' selected' : '') +
          '>' + esc(d[0]) + '</option>';
      }).join('') + '</select></div>' +
      '<div class="col-md-3"><label class="form-label">From</label>' +
      '<input type="date" class="form-control form-control-sm" id="doc-date-from" value="' +
      esc(selected[range[1]] || '') + '"></div>' +
      '<div class="col-md-3"><label class="form-label">To</label>' +
      '<input type="date" class="form-control form-control-sm" id="doc-date-to" value="' +
      esc(selected[range[2]] || '') + '"></div>';

    ['doc-date-field', 'doc-date-from', 'doc-date-to'].forEach(function (id) {
      document.getElementById(id).onchange = load;
    });
  }

  /**
   * Fetches the active tab's facet choices and renders them, keeping any
   * values a shared link asked for.
   *
   * Resolves only once the controls exist, because load() builds its filters
   * by reading them. Let the two race and the first request goes out without
   * the link's facet values and nothing re-issues it: the dropdowns read
   * "Superseded, COS" over a list showing every contract. Verified by
   * sabotage - `loadFacets(params); load();` returns 3 rows where
   * `loadFacets(params).then(load)` returns 1.
   */
  function loadFacets(selected) {
    var cfg = TABS[tab];
    renderDates(selected);

    if (!cfg.facetApi) { renderFacets(null, selected); return Promise.resolve(); }
    if (facetCache[tab]) { renderFacets(facetCache[tab], selected); return Promise.resolve(); }

    var forTab = tab;
    return api(cfg.facetApi, {}, true).then(function (d) {
      facetCache[forTab] = d || {};
      if (tab === forTab) renderFacets(facetCache[forTab], selected);
    }, function () {
      // Silent like the watchlists: a role that cannot read this entity would
      // otherwise toast on every visit to its tab. renderFacets(null) is what
      // tells the person looking at it.
      if (tab === forTab) renderFacets(null, selected);
    });
  }

  return {
    _rows: [],

    init: function (params) {
      params = params || {};

      document.querySelectorAll('#doc-tabs .nav-link').forEach(function (a) {
        a.onclick = function (e) {
          e.preventDefault();
          selectTab(a.dataset.doc);
          // No selection carried over: each entity has its own facet keys, and
          // a Status the previous tab had would be an unknown filter here -
          // ignored rather than refused, so the list would quietly show more
          // rows than the bar said it was showing.
          loadFacets(null).then(load);
        };
      });
      selectTab(params.tab || 'memo');
      document.getElementById('doc-search').value = params.search || '';

      // A shared link can carry a sort key belonging to a different tab
      // (#documents?tab=bioex&sort=controlNo). Assigning an absent option
      // leaves the select blank rather than throwing, and a blank is sent as
      // sort='' which FilterSpec::scalar() maps to null and defaults - so what
      // this prevents is not a refused query but a dropdown reading blank
      // while the list is sorted by something the user cannot see named.
      // The refusal case is handled by populateSorts() resetting on tab switch.
      if (params.sort && tabHasSort(params.sort)) {
        document.getElementById('doc-sort').value = params.sort;
      }
      direction = params.direction === 'ASC' ? 'ASC' : 'DESC';
      updateDirectionButton();

      document.getElementById('doc-search').oninput = debounce(load);
      document.getElementById('doc-sort').onchange = load;
      document.getElementById('doc-direction').onclick = function () {
        direction = direction === 'ASC' ? 'DESC' : 'ASC';
        updateDirectionButton();
        load();
      };
      document.getElementById('doc-new').onclick = function () { openForm(null); };

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
      });

      // Sequenced, not raced - see loadFacets().
      loadFacets(params).then(load);
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
