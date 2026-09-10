<!-- ==========================================================================
     backup.php - split out of what was a single views/settings.php.
     Backup and restore.
     See that file's own header for why.
     ========================================================================== -->

<!-- ==================== BACKUP & RESTORE ==================== -->
<section class="page" id="page-backup">
  <div class="card">
    <div class="card-body d-flex gap-2 align-items-center flex-wrap">
      <button class="btn btn-gov" id="bak-now">
        <span class="material-icons">backup</span> Backup Now</button>
      <div class="ms-3">
        <label class="form-label mb-0">Automatic Backup Schedule</label>
        <div class="d-flex gap-2">
          <select class="form-select form-select-sm w-auto" id="bak-schedule">
            <option value="off">Off</option>
            <option value="daily">Daily (2 AM)</option>
            <option value="weekly">Weekly (Sunday 2 AM)</option>
          </select>
          <button class="btn btn-sm btn-outline-secondary" id="bak-apply">Apply</button>
        </div>
      </div>
      <span class="text-muted small ms-auto">
        Backups are full copies of the database spreadsheet saved to Google Drive.
        Restoring replaces current data (a safety backup is taken first).</span>
    </div>
  </div>
  <div class="card mt-3"><div class="table-responsive">
    <table class="table table-hover">
      <thead><tr><th>Date</th><th>File Name</th><th>Type</th><th>By</th><th class="text-end">Actions</th></tr></thead>
      <tbody id="bak-rows"></tbody>
    </table>
  </div></div>
</section>

<script>
/* ==================== Backup & Restore module ==================== */
Pages.backup = (function () {
  function load() {
    api('apiListBackups').then(function (rows) {
      document.getElementById('bak-rows').innerHTML = rows.map(function (b) {
        return '<tr><td>' + esc(String(b.Timestamp).replace('T', ' ').slice(0, 19)) + '</td>' +
          '<td class="fw-semibold">' + esc(b.FileName) + '</td>' +
          '<td>' + esc(b.Type) + '</td><td>' + esc(b.User) + '</td>' +
          '<td class="text-end text-nowrap">' +
          '<a class="btn btn-sm btn-link p-1" target="_blank" href="' + esc(b.Url) +
          '" title="Open / download"><span class="material-icons" style="font-size:17px">open_in_new</span></a>' +
          actionBtn('settings_backup_restore', 'Pages.backup.restore', [b.FileID], 'text-danger') +
          '</td></tr>';
      }).join('') || '<tr><td colspan="5" class="text-center text-muted py-4">No backups yet.</td></tr>';
    });
  }
  return {
    init: function () {
      api('apiGetSettings').then(function (s) {
        document.getElementById('bak-schedule').value = s.BackupSchedule || 'weekly';
      });
      document.getElementById('bak-now').onclick = function () {
        busy(api('apiBackupNow')).then(function (d) {
          toast('Backup created: ' + d.fileName); load();
        });
      };
      document.getElementById('bak-apply').onclick = function () {
        var schedule = document.getElementById('bak-schedule').value;
        busy(api('apiSaveSettings', { settings: { BackupSchedule: schedule } })
          .then(function () { return api('apiApplyBackupSchedule'); }))
          .then(function () { toast('Backup schedule applied: ' + schedule); });
      };
      load();
    },
    restore: function (fileId) {
      confirmDlg('RESTORE will replace ALL current data with this backup. ' +
        'A safety backup of the current state is taken first. Continue?', function () {
          busy(api('apiRestoreBackup', { FileID: fileId })).then(function (d) {
            toast('Restored tables: ' + d.restored.join(', '));
            loadLookups();
            load();
          });
        });
    }
  };
})();
</script>
