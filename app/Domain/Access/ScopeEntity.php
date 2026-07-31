<?php
/**
 * ============================================================================
 * ScopeEntity - Which column on a table carries each scope dimension.
 *
 * This registry is the ONLY place a scoped table's columns are named, and it
 * is hardcoded on purpose. ScopePredicate interpolates these names straight
 * into SQL, which is safe precisely because they can never come from a
 * payload - the convention the whole codebase follows: values are always
 * bound, and an interpolated column name comes from an allowlist.
 *
 * Adding a table here is the deliberate act of bringing it under scope. A
 * table absent from this list is not scoped at all, so anything holding
 * restricted rows belongs here before it gets a read path.
 *
 * Pure: no DB::, no session, no clock.
 * ============================================================================
 */

declare(strict_types=1);

namespace Digos\Domain\Access;

use InvalidArgumentException;

final class ScopeEntity
{
    /** The four dimensions a grant can restrict, in the order they are checked. */
    public const DIMENSIONS = ['OfficeCode', 'FunctionCode', 'EmploymentTypeCode', 'FiscalYear'];

    /**
     * entity name => scope dimension => the column on that entity carrying it.
     *
     * A dimension absent from an entity's map cannot be restricted for that
     * entity, and a grant narrowing it is treated as not applying rather than
     * as matching everything - see ScopePredicate::forGrant().
     *
     * PayrollDetails scopes on ChargedOfficeCode, NOT on the employee's home
     * office. Which appropriation pays for a line and where its employee is
     * assigned are different questions, and migration 0006 made the charge a
     * first-class column so that this one could be asked. Scoping on the home
     * office would answer the other one.
     *
     * @var array<string, array<string, string>>
     */
    private const MAP = [
        'Payroll' => [
            'OfficeCode' => 'OfficeCode',
            'FunctionCode' => 'FunctionCode',
        ],
        'PayrollDetails' => [
            'OfficeCode' => 'ChargedOfficeCode',
            'FunctionCode' => 'FunctionCode',
        ],
        'Employees' => [
            'OfficeCode' => 'OfficeCode',
            'EmploymentTypeCode' => 'EmploymentTypeCode',
        ],
        // A memorandum belongs to the office that issued it. NULL OfficeCode on
        // the row means citywide, which a memo from the Mayor's office is; the
        // predicate treats it the same way it treats any other value, so a
        // citywide memo is visible to a wildcard grant and to nobody else. That
        // is the conservative reading and it is the right one at this phase -
        // the alternative, "NULL is visible to everyone", would make the first
        // citywide memo a hole in the layer.
        //
        // BioExemptions and TravelOrders are deliberately absent. They are about
        // a person, so their scope is that person's, and they are read through a
        // join to Employees rather than by carrying a copy of an office code
        // that would then need keeping in step. Two answers to "whose row is
        // this?" eventually disagree.
        //
        // WorkShifts is absent too: a shift definition is a rule about hours,
        // not somebody's record. It is reference data, like Offices.
        'Memorandum' => [
            'OfficeCode' => 'OfficeCode',
            'FunctionCode' => 'FunctionCode',
        ],
    ];

    /** Every scoped entity name, for tests and diagnostics. */
    public static function names(): array
    {
        return array_keys(self::MAP);
    }

    /**
     * The dimension => column map for one entity.
     *
     * @throws InvalidArgumentException for an unregistered entity. Failing
     *         loudly matters more here than convenience: the alternative to a
     *         known entity is an unscoped query, and silently returning an
     *         empty map would produce exactly that.
     */
    public static function columns(string $entity): array
    {
        if (!isset(self::MAP[$entity])) {
            throw new InvalidArgumentException(
                "'$entity' is not a scoped entity. Add it to ScopeEntity::MAP before "
                . 'querying it through the gateway.');
        }
        return self::MAP[$entity];
    }
}
