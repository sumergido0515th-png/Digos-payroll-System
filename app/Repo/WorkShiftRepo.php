<?php
/**
 * ============================================================================
 * WorkShiftRepo - Versioned shift definitions.
 *
 * UNSCOPED, deliberately, like ReferenceRepo. A shift definition is a rule
 * about hours - 8:00 to 17:00 with a one-hour break and Saturday and Sunday
 * off. It is not anybody's record, there is no office whose rows these are, and
 * scoping it would empty the picker that every DTR and payroll screen needs.
 *
 * EDITS CREATE A VERSION. save() never updates the times of an existing row: it
 * closes the current version's EffectiveTo and inserts the next one. This is
 * the same reasoning as Contracts in 0005 - a payroll prepared last quarter was
 * computed against the shift in force then, and overwriting the times destroys
 * the only record of what "late" meant on those days. Phase 4 resolves which
 * version was effective on a given historical date, and it can only do that if
 * the earlier versions still exist.
 * ============================================================================
 */

declare(strict_types=1);

namespace Digos\Repo;

use DB;
use RuntimeException;

final class WorkShiftRepo
{
    /**
     * Every shift version, newest version of each code first.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function listAll(array $filters = []): array
    {
        $sql = 'SELECT * FROM WorkShifts WHERE 1 = 1';
        $params = [];

        if (!empty($filters['ShiftCode'])) {
            $sql .= ' AND ShiftCode = ?'; $params[] = $filters['ShiftCode'];
        }
        if (!empty($filters['Status'])) {
            $sql .= ' AND Status = ?'; $params[] = $filters['Status'];
        }
        if (empty($filters['IncludeSuperseded'])) {
            // The current version of each code: the one nothing supersedes.
            $sql .= ' AND ShiftID NOT IN (SELECT SupersedesID FROM WorkShifts
                                           WHERE SupersedesID IS NOT NULL)';
        }

        return DB::rows($sql . ' ORDER BY ShiftCode, VersionNo DESC', $params);
    }

    /** One shift version by its id. */
    public static function find(string $shiftId): ?array
    {
        return DB::row('SELECT * FROM WorkShifts WHERE ShiftID = ?', [$shiftId]);
    }

    /** Every version of one shift code, oldest first - the history screen. */
    public static function versionsOf(string $shiftCode): array
    {
        return DB::rows(
            'SELECT * FROM WorkShifts WHERE ShiftCode = ? ORDER BY VersionNo',
            [$shiftCode]);
    }

    /** The version in force for a code, or null if the code is unknown. */
    public static function currentVersion(string $shiftCode): ?array
    {
        return DB::row(
            'SELECT * FROM WorkShifts WHERE ShiftCode = ? ORDER BY VersionNo DESC LIMIT 1',
            [$shiftCode]);
    }

    /**
     * Creates the first version of a shift code.
     *
     * @param array<string, mixed> $record
     */
    public static function createFirstVersion(string $shiftId, string $shiftCode, array $record): void
    {
        if (self::currentVersion($shiftCode) !== null) {
            throw new RuntimeException(
                "Shift code $shiftCode already exists. Edit it instead - editing "
                . 'creates a new version and keeps the old one.');
        }

        DB::insert('WorkShifts', array_merge([
            'ShiftID' => $shiftId,
            'ShiftCode' => $shiftCode,
            'VersionNo' => 1,
            'SupersedesID' => null,
        ], $record));
    }

    /**
     * Supersedes the current version with a new one.
     *
     * Both halves in one transaction. A closed old version with no new one is a
     * shift that stops existing on the date somebody edited it, and every DTR
     * from that day forward would resolve against nothing.
     *
     * @param array<string, mixed> $record the new version's columns
     * @return array{ShiftID: string, VersionNo: int} the version created
     */
    public static function supersede(
        string $shiftCode,
        string $newShiftId,
        array $record,
        string $effectiveFrom
    ): array {
        $current = self::currentVersion($shiftCode);
        if ($current === null) {
            throw new RuntimeException("Shift code $shiftCode does not exist yet.");
        }

        if ($effectiveFrom <= (string) $current['EffectiveFrom']) {
            throw new RuntimeException(sprintf(
                'The new version must start after the one it replaces (%s starts %s). '
                . 'Two versions in force on the same day cannot both be the answer to '
                . 'what the shift was.',
                $current['ShiftCode'], $current['EffectiveFrom']));
        }

        $versionNo = (int) $current['VersionNo'] + 1;

        DB::tx(function () use ($current, $newShiftId, $shiftCode, $record, $effectiveFrom, $versionNo) {
            // The old version ends the day before the new one starts, so the two
            // windows meet without overlapping and no date falls between them.
            DB::update('WorkShifts',
                ['EffectiveTo' => date('Y-m-d', strtotime($effectiveFrom . ' -1 day')),
                    'Status' => 'Superseded'],
                'ShiftID', (string) $current['ShiftID']);

            DB::insert('WorkShifts', array_merge([
                'ShiftID' => $newShiftId,
                'ShiftCode' => $shiftCode,
                'VersionNo' => $versionNo,
                'SupersedesID' => $current['ShiftID'],
                'EffectiveFrom' => $effectiveFrom,
            ], $record));
        });

        return ['ShiftID' => $newShiftId, 'VersionNo' => $versionNo];
    }
}
