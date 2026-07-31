# Roles and permissions

**Generated** by `php tools/generate-roles-doc.php` from `PERMISSIONS` in
[app/Auth.php](../app/Auth.php) and the `ROUTES` table in
[public/api.php](../public/api.php). **Do not edit by hand** - regenerate it.
`php tools/generate-roles-doc.php --check` fails if this file has drifted.

Phase 2's second deliverable, alongside the scope enforcement layer itself.

## What this document is not

**These are actions, not scope.** Holding `payroll.view` says you may look at
payrolls; it never says *which*. That is `ScopeGrants`, applied by
`Digos\Repo\ScopeGateway`, and it is what lets one Pre-Auditor cover two offices
and another cover one without inventing a role per office. A user with no grant
reads nothing, whatever this table says.

`*` is every permission, and only Admin holds it.

## Matrix

| Permission | Admin | HRMO | Payroll In-Charge | Pre-Auditor | Encoder | Office Head | Internal Auditor |
|---|---|---|---|---|---|---|---|
| `backup.run` | yes | - | - | - | - | - | - |
| `dashboard.view` | yes | yes | yes | yes | yes | yes | yes |
| `employee.delete` | yes | - | - | - | - | - | - |
| `employee.edit` | yes | yes | - | - | - | - | - |
| `employee.sensitive` | yes | yes | yes | yes | - | - | - |
| `employee.view` | yes | yes | yes | yes | yes | yes | yes |
| `log.view` | yes | - | - | - | - | - | yes |
| `office.edit` | yes | yes | - | - | - | - | - |
| `office.view` | yes | yes | yes | yes | yes | yes | yes |
| `payroll.approve` | yes | - | - | yes | - | - | - |
| `payroll.edit` | yes | - | yes | - | yes | - | - |
| `payroll.release` | yes | - | - | yes | - | - | - |
| `payroll.submit` | yes | - | yes | - | yes | - | - |
| `payroll.view` | yes | yes | yes | yes | yes | yes | yes |
| `period.edit` | yes | - | yes | - | - | - | - |
| `period.view` | yes | yes | yes | yes | yes | yes | yes |
| `print.run` | yes | yes | yes | yes | yes | yes | - |
| `report.view` | yes | yes | yes | yes | - | yes | yes |
| `scope.manage` | yes | - | - | - | - | - | - |
| `settings.edit` | yes | - | - | - | - | - | - |
| `settings.view` | yes | - | - | - | - | - | - |
| `timekeeper.edit` | yes | yes | - | - | - | - | - |
| `timekeeper.view` | yes | yes | yes | - | yes | yes | yes |
| `user.manage` | yes | - | - | - | - | - | - |

## Administrator-only

No named role lists these; they are reachable only through Admin's `*`.
`RouteTableTest::testEveryRoutePermissionIsOneSomeRoleCanHold` fails if one
appears here by accident rather than by decision.

- `backup.run`
- `employee.delete`
- `scope.manage`
- `settings.edit`
- `settings.view`
- `user.manage`

## Endpoints by permission

- (any signed-in user) — apiGetSession, apiHeartbeat, apiLogout
- `backup.run` — apiBackupNow, apiListBackups, apiRestoreBackup
- `dashboard.view` — apiGetDashboard, apiGetLookups
- `employee.delete` — apiDeleteEmployee
- `employee.edit` — apiSaveEmployee
- `employee.view` — apiGetEmployee, apiListEmployees
- `log.view` — apiGetLogs
- `office.edit` — apiDeleteDepartment, apiDeleteFunction, apiDeleteOffice, apiSaveDepartment, apiSaveFunction, apiSaveOffice
- `office.view` — apiListDepartments, apiListFunctions, apiListOffices
- `payroll.approve` — apiApprovePayroll, apiReturnPayroll
- `payroll.edit` — apiCancelPayroll, apiDeletePayroll, apiSavePayroll, apiUndoLast
- `payroll.release` — apiEmailPayslips, apiReleasePayroll
- `payroll.submit` — apiSubmitPayroll
- `payroll.view` — apiComputePayroll, apiGetPayroll, apiListPayrolls
- `period.edit` — apiDeletePeriod, apiSavePeriod
- `period.view` — apiListPeriods
- `print.run` — apiGetPrintHtml
- `report.view` — apiRunReport
- `scope.manage` — apiDeleteScopeGrant, apiGetScopeDimensions, apiListScopeGrants, apiSaveScopeGrant
- `settings.edit` — apiApplyBackupSchedule, apiSaveSettings, apiUploadImageSetting
- `settings.view` — apiGetSettings
- `timekeeper.edit` — apiDeleteTimekeeper, apiSaveTimekeeper
- `timekeeper.view` — apiListTimekeepers
- `user.manage` — apiDeleteUser, apiGetRoles, apiListUsers, apiSaveUser
