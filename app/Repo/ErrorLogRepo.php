<?php
/**
 * ============================================================================
 * ErrorLogRepo - PHP and browser errors that never became a RuntimeException.
 *
 * The audit `Logs` table (app/Auth.php, writeLog()) records business events -
 * who approved what. This records the other kind: a TypeError, a PDOException,
 * a JS exception a screen threw at a user - the ones CLAUDE.md's own
 * convention says a RuntimeException message is safe to show verbatim and
 * these are not, because nobody wrote them for a timekeeper to read.
 *
 * record() is deliberately forgiving: it is called from inside the one catch
 * block api.php has left once something has already gone wrong, so it must
 * never itself throw and turn one bug into two. If the write fails - most
 * plausibly because the database connection is what broke - it falls back to
 * the PHP error log rather than losing the event.
 * ============================================================================
 */

declare(strict_types=1);

namespace Digos\Repo;

use DB;
use Throwable;

final class ErrorLogRepo
{
    /**
     * Every field is read with a default. This is called from inside the one
     * catch block api.php has left once something has already gone wrong, so
     * a missing key must degrade to "unknown", never to a second warning -
     * that would surface as a fresh, uncaught error from inside the code that
     * exists specifically to catch one.
     *
     * @param array{Source?: string, Message?: string, File?: string, Line?: int, Trace?: string, Url?: string, UserEmail?: string, UserAgent?: string} $entry
     */
    public static function record(array $entry): void
    {
        $source = (string) ($entry['Source'] ?? 'unknown');
        // Capped defensively - TEXT has no practical limit, but a recursive
        // JS error or an infinite loop's stack trace should not be free to
        // grow one row without bound.
        $message = mb_substr((string) ($entry['Message'] ?? '(no message)'), 0, 4000);

        try {
            DB::insert('ErrorLog', [
                'Source' => $source,
                'Message' => $message,
                'File' => mb_substr((string) ($entry['File'] ?? ''), 0, 255),
                'Line' => (int) ($entry['Line'] ?? 0),
                'Trace' => mb_substr((string) ($entry['Trace'] ?? ''), 0, 8000),
                'Url' => mb_substr((string) ($entry['Url'] ?? ''), 0, 255),
                'UserEmail' => mb_substr((string) ($entry['UserEmail'] ?? ''), 0, 120),
                'UserAgent' => mb_substr((string) ($entry['UserAgent'] ?? ''), 0, 255),
            ]);
        } catch (Throwable $e) {
            error_log('ErrorLogRepo::record() itself failed: ' . $e->getMessage()
                . ' | original: ' . $source . ': ' . $message);
        }
    }

    /**
     * Newest first, optionally filtered - the read side of the Audit Logs
     * screen's new companion table.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function recent(?string $source, ?string $search, int $limit = 300): array
    {
        $clauses = ['1=1'];
        $params = [];

        if ($source !== null && $source !== '') {
            $clauses[] = 'Source = ?';
            $params[] = $source;
        }
        if ($search !== null && $search !== '') {
            $clauses[] = '(Message LIKE ? OR File LIKE ? OR Url LIKE ? OR UserEmail LIKE ?)';
            $like = '%' . $search . '%';
            array_push($params, $like, $like, $like, $like);
        }

        return DB::rows(
            'SELECT * FROM ErrorLog WHERE ' . implode(' AND ', $clauses)
            . ' ORDER BY CreatedAt DESC, ErrorLogID DESC LIMIT ' . min(max($limit, 1), 1000),
            $params
        );
    }
}
