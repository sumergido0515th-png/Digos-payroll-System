<?php
/**
 * ============================================================================
 * AttachmentRepo - Evidence files and the dates they justify.
 *
 * Scoped through AttachmentCoverage's employees, not through a column on the
 * attachment. An attachment is evidence ABOUT people, so the people decide who
 * may see it - and a memo covering two offices is legitimately visible to
 * both, each seeing their own coverage rows. Copying an office onto the file
 * would have to pick one.
 *
 * An attachment covering nobody is visible to nobody except through
 * findByHash(), which is the dedup check and deliberately unscoped: the point
 * of refusing a duplicate is that it is refused whoever uploads it, and
 * scoping that would let the same file in twice through two accounts.
 * ============================================================================
 */

declare(strict_types=1);

namespace Digos\Repo;

use DB;

final class AttachmentRepo
{
    /**
     * Attachments touching employees the caller may see.
     *
     * DISTINCT because one attachment covering a fortnight for four people has
     * sixty coverage rows and is still one attachment.
     *
     * @param array<string, mixed> $filters DocumentType, ControlNo, search
     * @return array<int, array<string, mixed>>
     */
    public static function listScoped(array $user, array $filters = []): array
    {
        $scope = ScopeGateway::where($user, 'Employees', 'e.');

        $sql = 'SELECT DISTINCT a.AttachmentID, a.FileName, a.StoredName, a.MimeType,
                       a.SizeBytes, a.Sha256, a.ControlNo, a.DocumentType, a.DocumentID,
                       a.CoversFrom, a.CoversTo, a.Status, a.Remarks,
                       a.UploadedBy, a.UploadedAt
                  FROM Attachments a
                  JOIN AttachmentCoverage c ON c.AttachmentID = a.AttachmentID
                  JOIN Employees e ON e.EmployeeID = c.EmployeeID
                 WHERE ' . $scope['sql'];
        $params = $scope['params'];

        foreach (['DocumentType', 'ControlNo', 'Status'] as $f) {
            if (!empty($filters[$f])) { $sql .= " AND a.`$f` = ?"; $params[] = $filters[$f]; }
        }
        if (!empty($filters['search'])) {
            $sql .= ' AND (a.FileName LIKE ? OR a.ControlNo LIKE ? OR a.Remarks LIKE ?)';
            $like = '%' . $filters['search'] . '%';
            array_push($params, $like, $like, $like);
        }

        return DB::rows($sql . ' ORDER BY a.UploadedAt DESC', $params);
    }

    /** One attachment, or null when absent or covering nobody in scope. */
    public static function findScoped(array $user, string $attachmentId): ?array
    {
        $scope = ScopeGateway::where($user, 'Employees', 'e.');

        return DB::row(
            'SELECT a.* FROM Attachments a
               JOIN AttachmentCoverage c ON c.AttachmentID = a.AttachmentID
               JOIN Employees e ON e.EmployeeID = c.EmployeeID
              WHERE ' . $scope['sql'] . ' AND a.AttachmentID = ?
              LIMIT 1',
            array_merge($scope['params'], [$attachmentId]));
    }

    /**
     * An attachment with these exact bytes, whoever uploaded it.
     *
     * UNSCOPED on purpose. The refusal has to be absolute: a duplicate that
     * one account cannot upload but another can is not a dedup guarantee. The
     * caller returns the existing control number so the person can see what
     * they are duplicating, which is why this returns the row and not a bool.
     */
    public static function findByHash(string $sha256): ?array
    {
        return DB::row(
            'SELECT AttachmentID, FileName, ControlNo, UploadedBy, UploadedAt
               FROM Attachments WHERE Sha256 = ?',
            [$sha256]);
    }

    /**
     * Stores the record and its coverage in one transaction.
     *
     * Both or neither: an attachment with no coverage rows justifies nothing
     * and is invisible to every scoped read, so a half-applied save would put
     * a file on disk that nobody can see and nothing can use.
     *
     * @param array<string, mixed> $record
     * @param array<int, array{EmployeeID: string, CoveredDate: string}> $coverage
     */
    public static function save(string $attachmentId, array $record, array $coverage): void
    {
        DB::tx(function () use ($attachmentId, $record, $coverage) {
            DB::insert('Attachments', array_merge(['AttachmentID' => $attachmentId], $record));

            foreach ($coverage as $row) {
                DB::insert('AttachmentCoverage', [
                    'AttachmentID' => $attachmentId,
                    'EmployeeID' => $row['EmployeeID'],
                    'CoveredDate' => $row['CoveredDate'],
                ]);
            }
        });
    }

    /** Removes an attachment; its coverage cascades. */
    public static function delete(string $attachmentId): int
    {
        return DB::exec('DELETE FROM Attachments WHERE AttachmentID = ?', [$attachmentId]);
    }

    /**
     * Coverage rows for a set of employees over a date range.
     *
     * The shape CoverageMatrix wants: everything at once, so a fortnight for
     * twenty people is one query rather than three hundred.
     *
     * @param string[] $employeeIds
     * @return array<int, array<string, mixed>>
     */
    public static function coverageFor(array $employeeIds, string $from, string $to): array
    {
        if (!$employeeIds) return [];

        $placeholders = implode(',', array_fill(0, count($employeeIds), '?'));

        return DB::rows(
            "SELECT c.AttachmentID, c.EmployeeID, c.CoveredDate
               FROM AttachmentCoverage c
               JOIN Attachments a ON a.AttachmentID = c.AttachmentID
              WHERE a.Status = 'Active'
                AND c.EmployeeID IN ($placeholders)
                AND c.CoveredDate BETWEEN ? AND ?",
            array_merge(array_values($employeeIds), [$from, $to]));
    }

    /** The employees and dates one attachment covers, for its detail view. */
    public static function coverageOf(string $attachmentId): array
    {
        return DB::rows(
            'SELECT EmployeeID, CoveredDate FROM AttachmentCoverage
              WHERE AttachmentID = ? ORDER BY EmployeeID, CoveredDate',
            [$attachmentId]);
    }
}
