<?php
/**
 * ============================================================================
 * SourceTable.php - Uploaded bytes in, a rectangle of strings out.
 * ============================================================================
 */

declare(strict_types=1);

namespace Digos\Domain\Import;

use RuntimeException;

/**
 * Reads a spreadsheet-shaped file into a header row plus data rows.
 *
 * Pure in the sense Phase 4 established: bytes and a file name in, arrays out.
 * No DB::, no $_SESSION, no clock, no file I/O - the caller has already read
 * the upload, which is what lets this be tested against fixture bytes rather
 * than against a live upload.
 *
 * The format is decided by the file's own leading bytes and never by the name
 * the browser sent, the same rule public/attachment.php applies to downloads:
 * a name is a claim, and here a wrong claim silently produces a wrong payroll
 * rather than a refusal. The extension is consulted only to break ties that
 * the content genuinely cannot (CSV vs TSV are both plain text).
 */
final class SourceTable
{
    /** Largest upload accepted, before base64 expansion. */
    public const MAX_BYTES = 5242880;

    /** Most data rows accepted in one file. */
    public const MAX_ROWS = 5000;

    /** Delimiters sniffed for in a plain-text file, best-scoring wins. */
    private const DELIMITERS = [',', ';', "\t", '|'];

    /**
     * Parses an upload.
     *
     * @return array{format:string, headers:string[], rows:array<int,string[]>, sheet:string}
     */
    public static function parse(string $bytes, string $fileName = ''): array
    {
        if ($bytes === '') {
            throw new RuntimeException('That file is empty.');
        }
        if (strlen($bytes) > self::MAX_BYTES) {
            throw new RuntimeException('That file is larger than 5 MB. Split it into smaller files and import them one at a time.');
        }

        $format = self::detect($bytes, $fileName);

        $grid = match ($format) {
            'xlsx' => self::readXlsx($bytes),
            'html' => self::readHtml($bytes),
            'json' => self::readJson($bytes),
            default => self::readDelimited(self::toUtf8($bytes), $fileName),
        };

        return self::rectangle($format, $grid);
    }

    /**
     * Decides the format, refusing the two that cannot be read back reliably
     * rather than guessing at them.
     *
     * PDF is refused on purpose. A PDF stores text as positioned glyphs with no
     * table structure at all, so recovering columns means inferring them from
     * x-coordinates - and a scanned one carries no text whatsoever. In a payroll
     * system the failure mode of guessing is not a blank field, it is a
     * plausible wrong number on a voucher somebody signs. The refusal names the
     * way out, because there always is one: the system that produced the PDF
     * can produce a spreadsheet.
     */
    private static function detect(string $bytes, string $fileName): string
    {
        $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

        if (str_starts_with($bytes, '%PDF-')) {
            throw new RuntimeException(
                'This is a PDF. A PDF stores text as positioned characters with no table structure, '
                . 'so its columns cannot be read back reliably - and a misread figure here becomes a '
                . 'wrong payment. Open it in Excel, or in the system that produced it, and save it as '
                . '.xlsx or .csv - then import that file.');
        }

        // D0 CF 11 E0 is the OLE2 compound-document header: Excel 97-2003 .xls,
        // and also .doc and .ppt. The row data inside is a binary record stream
        // (BIFF) that is a parser in its own right, and Excel converts it in one
        // click, so this asks for the conversion instead.
        if (str_starts_with($bytes, "\xD0\xCF\x11\xE0")) {
            throw new RuntimeException(
                'This is an Excel 97-2003 file (.xls). Open it in Excel and use File > Save As to save '
                . 'it as .xlsx or .csv, then import that file.');
        }

        // Both .xlsx and .ods are ZIP containers, so the signature alone does
        // not separate them - the entry names do.
        if (str_starts_with($bytes, "PK\x03\x04")) {
            if (str_contains($bytes, 'xl/workbook.xml')) return 'xlsx';
            if (str_contains($bytes, 'content.xml') && str_contains($bytes, 'opendocument')) {
                throw new RuntimeException(
                    'This is an OpenDocument spreadsheet (.ods). Open it and save it as .xlsx or .csv, '
                    . 'then import that file.');
            }
            throw new RuntimeException('That ZIP file is not a spreadsheet. Import a .xlsx, .csv or .html file.');
        }

        $head = ltrim(substr(self::toUtf8($bytes), 0, 4096));

        if ($head !== '' && ($head[0] === '[' || $head[0] === '{')) return 'json';

        // The "web" case: a page saved from a browser, or a table copied out of
        // one. Matched on content so that a table pasted into a .txt file still
        // reads as HTML.
        if (stripos($head, '<table') !== false || stripos($head, '<!doctype html') !== false
            || stripos($head, '<html') !== false) {
            return 'html';
        }

        if ($extension === 'json') return 'json';
        if ($extension === 'html' || $extension === 'htm') return 'html';

        return 'csv';
    }

    /* ======================================================================
     * Plain text
     * ==================================================================== */

    /**
     * Normalises an upload to UTF-8.
     *
     * Excel's "CSV" on a Philippine Windows install is Windows-1252, not UTF-8,
     * and its "Unicode Text" export is UTF-16LE. Left alone, the first produces
     * a mangled surname on any accented name and the second produces a file that
     * looks empty because every second byte is NUL.
     */
    private static function toUtf8(string $bytes): string
    {
        if (str_starts_with($bytes, "\xEF\xBB\xBF")) return substr($bytes, 3);

        if (str_starts_with($bytes, "\xFF\xFE")) {
            return (string) mb_convert_encoding(substr($bytes, 2), 'UTF-8', 'UTF-16LE');
        }
        if (str_starts_with($bytes, "\xFE\xFF")) {
            return (string) mb_convert_encoding(substr($bytes, 2), 'UTF-8', 'UTF-16BE');
        }

        if (mb_check_encoding($bytes, 'UTF-8')) return $bytes;

        return (string) mb_convert_encoding($bytes, 'UTF-8', 'Windows-1252');
    }

    /**
     * Splits delimited text, sniffing the separator.
     *
     * A hand-written reader rather than fgetcsv() because that one needs a
     * stream, and a value may legitimately contain a newline inside quotes -
     * splitting on lines first and parsing second gets those wrong.
     */
    private static function readDelimited(string $text, string $fileName): array
    {
        $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        $delimiter = $extension === 'tsv' ? "\t" : self::sniffDelimiter($text);

        $rows = [];
        $row = [];
        $field = '';
        $quoted = false;
        $length = strlen($text);

        for ($i = 0; $i < $length; $i++) {
            $c = $text[$i];

            if ($quoted) {
                if ($c === '"') {
                    // A doubled quote inside a quoted field is one literal quote.
                    if ($i + 1 < $length && $text[$i + 1] === '"') {
                        $field .= '"';
                        $i++;
                    } else {
                        $quoted = false;
                    }
                } else {
                    $field .= $c;
                }
                continue;
            }

            if ($c === '"' && $field === '') {
                $quoted = true;
            } elseif ($c === $delimiter) {
                $row[] = $field;
                $field = '';
            } elseif ($c === "\n" || $c === "\r") {
                if ($c === "\r" && $i + 1 < $length && $text[$i + 1] === "\n") $i++;
                $row[] = $field;
                $field = '';
                $rows[] = $row;
                $row = [];
            } else {
                $field .= $c;
            }
        }

        if ($field !== '' || $row !== []) {
            $row[] = $field;
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * Picks the delimiter that yields the most consistent column count.
     *
     * Counting occurrences alone picks the wrong one often: a file of addresses
     * has more commas inside its fields than a semicolon-separated file has
     * separators. Consistency across lines is what actually identifies a
     * separator, so each candidate is scored on how many of the first lines
     * agree on a column count above one.
     */
    private static function sniffDelimiter(string $text): string
    {
        $lines = array_slice(array_filter(preg_split('/\r\n|\r|\n/', $text) ?: [], 'strlen'), 0, 20);
        if (!$lines) return ',';

        $best = ',';
        $bestScore = -1;

        foreach (self::DELIMITERS as $candidate) {
            $counts = array_map(fn($line) => substr_count($line, $candidate), $lines);
            $first = $counts[0];
            if ($first < 1) continue;

            $agree = count(array_filter($counts, fn($n) => $n === $first));
            $score = $agree * 100 + $first;
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $candidate;
            }
        }

        return $best;
    }

    /* ======================================================================
     * HTML and JSON
     * ==================================================================== */

    /** Reads the first <table> on a page - a table copied out of a browser. */
    private static function readHtml(string $bytes): array
    {
        $document = new \DOMDocument();

        // A page copied from a browser is rarely well-formed, and libxml's
        // warnings would otherwise surface as PHP notices in the JSON envelope.
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8">' . self::toUtf8($bytes));
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $tables = $document->getElementsByTagName('table');
        if ($tables->length === 0) {
            throw new RuntimeException('No table was found on that page. Copy the table itself, or save the data as .csv or .xlsx.');
        }

        $rows = [];
        foreach ($tables->item(0)->getElementsByTagName('tr') as $tr) {
            $row = [];
            foreach ($tr->childNodes as $cell) {
                if (!($cell instanceof \DOMElement)) continue;
                $tag = strtolower($cell->tagName);
                if ($tag !== 'td' && $tag !== 'th') continue;
                // &nbsp; is U+00A0, which trim() does not touch - an empty cell
                // pasted from a web page would otherwise read as a value.
                $row[] = trim(str_replace("\xC2\xA0", ' ', $cell->textContent));
            }
            if ($row) $rows[] = $row;
        }

        return $rows;
    }

    /** Reads a JSON array of objects, or {rows:[...]} around one. */
    private static function readJson(string $bytes): array
    {
        $decoded = json_decode(self::toUtf8($bytes), true);
        if (!is_array($decoded)) {
            throw new RuntimeException('That file is not valid JSON.');
        }
        if (isset($decoded['rows']) && is_array($decoded['rows'])) $decoded = $decoded['rows'];

        $decoded = array_values(array_filter($decoded, 'is_array'));
        if (!$decoded) {
            throw new RuntimeException('That JSON file contains no rows. It should be a list of objects, one per record.');
        }

        // The union of every object's keys, in first-seen order, so a row that
        // omits an optional field does not shorten the header for everyone.
        $headers = [];
        foreach ($decoded as $row) {
            foreach (array_keys($row) as $key) {
                if (!in_array((string) $key, $headers, true)) $headers[] = (string) $key;
            }
        }

        $rows = [$headers];
        foreach ($decoded as $row) {
            $rows[] = array_map(
                fn($key) => self::scalar($row[$key] ?? ''),
                $headers);
        }

        return $rows;
    }

    /** JSON values are typed; the rest of the pipeline works in strings. */
    private static function scalar(mixed $value): string
    {
        if (is_bool($value)) return $value ? '1' : '0';
        if ($value === null) return '';
        if (is_scalar($value)) return (string) $value;
        return '';
    }

    /* ======================================================================
     * XLSX
     * ==================================================================== */

    /**
     * Reads the first worksheet of an .xlsx workbook.
     *
     * The ZIP is walked by hand rather than through ZipArchive because that
     * class opens a path on disk, and this class takes bytes - writing the
     * upload to a temp file purely to read it back would put file I/O in the
     * one place the architecture says must not have any.
     */
    private static function readXlsx(string $bytes): array
    {
        $entries = self::zipEntries($bytes);

        $workbook = $entries['xl/workbook.xml'] ?? null;
        if ($workbook === null) {
            throw new RuntimeException('That .xlsx file is damaged - it has no workbook. Open it in Excel, save it again and retry.');
        }

        $shared = self::sharedStrings($entries['xl/sharedStrings.xml'] ?? '');
        $dateStyles = self::dateStyles($entries['xl/styles.xml'] ?? '');

        // Excel for Mac 2008 and earlier count days from 1904 instead of 1900.
        // Ignoring this shifts every date in the file by four years and a day.
        $epoch1904 = (bool) preg_match('/date1904\s*=\s*"(1|true)"/i', $workbook);

        $path = self::firstSheetPath($workbook, $entries);
        $sheet = $entries[$path] ?? null;
        if ($sheet === null) {
            throw new RuntimeException('That .xlsx file has no readable worksheet. Open it in Excel, save it again and retry.');
        }

        return self::readSheetXml($sheet, $shared, $dateStyles, $epoch1904);
    }

    /**
     * Resolves the workbook's first sheet to its part name.
     *
     * Not simply xl/worksheets/sheet1.xml: the sheets in a workbook are ordered
     * by the <sheets> element and joined to their parts through relationship
     * ids, and a workbook whose first tab was created second does not have that
     * tab in sheet1.xml. Importing the wrong tab silently is exactly the class
     * of quiet wrongness this file exists to avoid.
     */
    private static function firstSheetPath(string $workbook, array $entries): string
    {
        $fallback = 'xl/worksheets/sheet1.xml';

        if (!preg_match('/<sheet\b[^>]*\br:id\s*=\s*"([^"]+)"/i', $workbook, $sheet)) {
            return $fallback;
        }
        $rels = $entries['xl/_rels/workbook.xml.rels'] ?? '';
        if ($rels === '') return $fallback;

        $pattern = '/<Relationship\b[^>]*\bId\s*=\s*"' . preg_quote($sheet[1], '/') . '"[^>]*\bTarget\s*=\s*"([^"]+)"/i';
        if (!preg_match($pattern, $rels, $target)) return $fallback;

        $path = ltrim(html_entity_decode($target[1], ENT_QUOTES, 'UTF-8'), '/');
        if (!str_starts_with($path, 'xl/')) $path = 'xl/' . $path;

        return isset($entries[$path]) ? $path : $fallback;
    }

    /**
     * Expands a ZIP container into name => contents.
     *
     * Only the stored (0) and deflated (8) methods are handled, which is
     * everything Excel and LibreOffice actually write.
     */
    private static function zipEntries(string $bytes): array
    {
        $eocd = strrpos($bytes, "PK\x05\x06");
        if ($eocd === false) {
            throw new RuntimeException('That .xlsx file is damaged and cannot be opened. Open it in Excel, save it again and retry.');
        }

        $header = unpack('vdisk/vcddisk/vcdcount/vtotal/Vsize/Voffset', substr($bytes, $eocd + 4, 16));
        if (!$header) {
            throw new RuntimeException('That .xlsx file is damaged and cannot be opened.');
        }

        $entries = [];
        $cursor = (int) $header['offset'];

        for ($i = 0; $i < (int) $header['total']; $i++) {
            if (substr($bytes, $cursor, 4) !== "PK\x01\x02") break;

            $central = unpack('vversion/vneeded/vflags/vmethod/vtime/vdate/Vcrc/Vcsize/Vusize/'
                . 'vnamelen/vextralen/vcommentlen/vdisk/vinternal/Vexternal/Vlocaloffset',
                substr($bytes, $cursor + 4, 42));
            if (!$central) break;

            $name = substr($bytes, $cursor + 46, (int) $central['namelen']);
            $local = (int) $central['localoffset'];

            // The local header repeats the name and carries its own extra field,
            // whose lengths need not match the central directory's - so the data
            // offset has to be read from the local header, not computed from it.
            $localHeader = unpack('vnamelen/vextralen', substr($bytes, $local + 26, 4));
            if ($localHeader) {
                $start = $local + 30 + (int) $localHeader['namelen'] + (int) $localHeader['extralen'];
                $raw = substr($bytes, $start, (int) $central['csize']);

                $entries[$name] = match ((int) $central['method']) {
                    0 => $raw,
                    8 => (string) @gzinflate($raw),
                    default => '',
                };
            }

            $cursor += 46 + (int) $central['namelen'] + (int) $central['extralen'] + (int) $central['commentlen'];
        }

        return $entries;
    }

    /** The workbook's shared string table, indexed as the cells reference it. */
    private static function sharedStrings(string $xml): array
    {
        if ($xml === '') return [];

        $strings = [];
        if (preg_match_all('/<si\b[^>]*>(.*?)<\/si>|<si\b[^>]*\/>/s', $xml, $items, PREG_SET_ORDER)) {
            foreach ($items as $item) {
                $inner = $item[1] ?? '';
                // Rich text splits one string across several <r><t> runs; joining
                // them is what keeps a part-bold cell from arriving truncated.
                $text = '';
                if (preg_match_all('/<t\b[^>]*>(.*?)<\/t>/s', $inner, $runs)) {
                    $text = implode('', $runs[1]);
                }
                $strings[] = self::decode($text);
            }
        }

        return $strings;
    }

    /**
     * The style indexes whose number format is a date.
     *
     * Without this every date in a workbook arrives as its serial number -
     * "45108" where the file showed "2023-07-01". The cell itself carries no
     * type saying so; only its format does.
     */
    private static function dateStyles(string $xml): array
    {
        if ($xml === '') return [];

        // Built-in formats Excel does not write out: 14-17 and 22 are dates,
        // 18-21 and 45-47 are times.
        $dateFormats = [14, 15, 16, 17, 18, 19, 20, 21, 22, 45, 46, 47];

        if (preg_match_all('/<numFmt\b[^>]*\bnumFmtId\s*=\s*"(\d+)"[^>]*\bformatCode\s*=\s*"([^"]*)"/i',
            $xml, $custom, PREG_SET_ORDER)) {
            foreach ($custom as $format) {
                $code = strtolower(html_entity_decode($format[2], ENT_QUOTES, 'UTF-8'));
                // Strip the literal text sections, so a currency format with a
                // "d" in its label is not read as a day placeholder.
                $code = preg_replace('/"[^"]*"|\[[^\]]*\]|\\\\./', '', $code) ?? '';
                if (preg_match('/[ymdhs]/', $code)) $dateFormats[] = (int) $format[1];
            }
        }

        $styles = [];
        if (preg_match('/<cellXfs\b[^>]*>(.*?)<\/cellXfs>/s', $xml, $block)
            && preg_match_all('/<xf\b[^>]*>/', $block[1], $formats)) {
            foreach ($formats[0] as $index => $xf) {
                if (preg_match('/\bnumFmtId\s*=\s*"(\d+)"/', $xf, $id)
                    && in_array((int) $id[1], $dateFormats, true)) {
                    $styles[$index] = true;
                }
            }
        }

        return $styles;
    }

    /** Turns one worksheet part into rows of strings. */
    private static function readSheetXml(string $xml, array $shared, array $dateStyles, bool $epoch1904): array
    {
        $rows = [];

        if (!preg_match_all('/<row\b[^>]*>(.*?)<\/row>/s', $xml, $rowMatches, PREG_SET_ORDER)) {
            return [];
        }

        foreach ($rowMatches as $rowMatch) {
            $row = [];
            if (preg_match_all('/<c\b([^>]*)(?:\/>|>(.*?)<\/c>)/s', $rowMatch[1], $cells, PREG_SET_ORDER)) {
                foreach ($cells as $cell) {
                    $attributes = $cell[1];
                    $inner = $cell[2] ?? '';

                    // Cells are only present when they hold something, so the
                    // column has to come from the reference - without it a row
                    // with an empty third cell shifts every later value left.
                    $index = count($row);
                    if (preg_match('/\br\s*=\s*"([A-Z]+)\d+"/i', $attributes, $ref)) {
                        $index = self::columnIndex($ref[1]);
                    }
                    while (count($row) < $index) $row[] = '';

                    $row[] = self::cellValue($attributes, $inner, $shared, $dateStyles, $epoch1904);
                }
            }
            $rows[] = $row;
        }

        return $rows;
    }

    /** One cell, resolved through the shared strings and the date styles. */
    private static function cellValue(string $attributes, string $inner, array $shared,
        array $dateStyles, bool $epoch1904): string
    {
        $type = preg_match('/\bt\s*=\s*"([^"]+)"/', $attributes, $t) ? $t[1] : 'n';

        if ($type === 'inlineStr') {
            return preg_match_all('/<t\b[^>]*>(.*?)<\/t>/s', $inner, $runs)
                ? self::decode(implode('', $runs[1]))
                : '';
        }

        $raw = preg_match('/<v\b[^>]*>(.*?)<\/v>/s', $inner, $v) ? self::decode($v[1]) : '';
        if ($raw === '') return '';

        if ($type === 's') return $shared[(int) $raw] ?? '';
        if ($type === 'b') return $raw === '1' ? 'TRUE' : 'FALSE';
        if ($type === 'str' || $type === 'e') return $raw;

        $style = preg_match('/\bs\s*=\s*"(\d+)"/', $attributes, $s) ? (int) $s[1] : -1;
        if (isset($dateStyles[$style]) && is_numeric($raw)) {
            return self::excelDate((float) $raw, $epoch1904);
        }

        return $raw;
    }

    /**
     * Converts an Excel serial number to Y-m-d, or Y-m-d H:i:s when it carries
     * a time.
     *
     * The 1900 system deliberately contains a 29 February 1900 that never
     * existed, for compatibility with Lotus 1-2-3. Serials at or above 61 are
     * therefore one day further from the epoch than arithmetic suggests, which
     * is why the base differs either side of it.
     */
    private static function excelDate(float $serial, bool $epoch1904): string
    {
        if ($epoch1904) {
            $base = '1904-01-01';
            $days = $serial;
        } elseif ($serial >= 61) {
            $base = '1899-12-30';
            $days = $serial;
        } else {
            $base = '1899-12-31';
            $days = $serial;
        }

        $whole = (int) floor($days);
        $seconds = (int) round(($days - $whole) * 86400);

        $date = new \DateTimeImmutable($base . ' 00:00:00', new \DateTimeZone('UTC'));
        $date = $date->modify('+' . $whole . ' days');
        if ($date === false) return (string) $serial;

        if ($seconds > 0) {
            $date = $date->modify('+' . $seconds . ' seconds');
            if ($date === false) return (string) $serial;
            return $date->format('Y-m-d H:i:s');
        }

        return $date->format('Y-m-d');
    }

    /** "AB" => 27. Spreadsheet column letters are base-26 without a zero. */
    private static function columnIndex(string $letters): int
    {
        $index = 0;
        foreach (str_split(strtoupper($letters)) as $letter) {
            $index = $index * 26 + (ord($letter) - 64);
        }
        return $index - 1;
    }

    /** XML text content back to a plain string. */
    private static function decode(string $text): string
    {
        return html_entity_decode($text, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    /* ======================================================================
     * Shaping
     * ==================================================================== */

    /**
     * Squares the grid off into a header row and data rows.
     *
     * Leading blank lines are skipped rather than taken as the header: a
     * spreadsheet exported with a title row above the table is the normal case,
     * not the exception. Fully blank rows are dropped, and short rows are padded
     * so every row has exactly as many cells as there are headers - the caller
     * indexes by position and a ragged row would otherwise read one field into
     * the next field's slot.
     */
    private static function rectangle(string $format, array $grid): array
    {
        $grid = array_values(array_filter($grid,
            fn($row) => implode('', array_map('trim', $row)) !== ''));

        if (!$grid) {
            throw new RuntimeException('That file has no rows in it.');
        }

        $headers = array_map(fn($h) => trim(preg_replace('/\s+/u', ' ', (string) $h) ?? ''), array_shift($grid));

        // Trailing empty header cells are an artefact of a spreadsheet whose
        // used range is wider than its data; a blank header in the middle is
        // kept as a positional placeholder so later columns stay aligned.
        while ($headers !== [] && end($headers) === '') array_pop($headers);

        if (!$headers) {
            throw new RuntimeException('The first row of that file is blank, so there are no column names to read.');
        }
        if (count($grid) > self::MAX_ROWS) {
            throw new RuntimeException('That file has ' . count($grid) . ' rows; the limit is '
                . self::MAX_ROWS . '. Split it into smaller files and import them one at a time.');
        }

        $width = count($headers);
        $rows = array_map(function ($row) use ($width) {
            $row = array_map(fn($cell) => trim((string) $cell), array_slice($row, 0, $width));
            return array_pad($row, $width, '');
        }, $grid);

        return [
            'format' => $format,
            'headers' => $headers,
            'rows' => array_values($rows),
            'sheet' => $format === 'xlsx' ? 'first worksheet' : '',
        ];
    }
}
