<?php

namespace App\Services;

use DOMDocument;
use DOMElement;
use DOMNode;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;
use ZipArchive;

/**
 * Shared hardened document parser for Web Excel preview and HaloAPU AI attachment reading.
 */
class DocumentContentService
{
    public const MAX_FILE_BYTES = 10 * 1024 * 1024; // 10 MB

    public const MAX_ZIP_XML_BYTES = 8 * 1024 * 1024; // 8 MB per XML entry / cumulative Word budget

    public const MAX_ZIP_ENTRIES = 300;

    public const MAX_ZIP_TOTAL_BYTES = 24 * 1024 * 1024; // 24 MB cumulative uncompressed archive limit

    public const MAX_DOCX_HEADER_FOOTER_ENTRIES = 6;

    public const MAX_SHEETS = 10;

    public const MAX_ROWS_PER_SHEET = 300;

    public const MAX_COLS_PER_SHEET = 30;

    private const WORD_NS = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';

    private const SAFE_INLINE_MIMES = [
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/webp',
        'application/pdf',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    ];

    /**
     * Resolve a relative path inside the `public` storage disk with traversal protection.
     */
    public static function resolvePublicPath(string $relativePath): string
    {
        if ($relativePath === '' || str_contains($relativePath, "\0")) {
            throw new InvalidArgumentException('Path lampiran tidak valid.');
        }

        $normalized = ltrim(str_replace('\\', '/', $relativePath), '/');
        if ($normalized === '' || str_contains($normalized, '..')) {
            throw new InvalidArgumentException('Path lampiran tidak valid.');
        }

        if (! Storage::disk('public')->exists($normalized)) {
            throw new RuntimeException('File lampiran tidak ditemukan.');
        }

        return Storage::disk('public')->path($normalized);
    }

    public static function extensionFor(string $filename, ?string $mimeType = null): string
    {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if ($ext !== '') {
            return $ext;
        }

        return match (strtolower(trim((string) $mimeType))) {
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
            'application/msword' => 'doc',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
            'application/vnd.ms-excel' => 'xls',
            'text/csv', 'application/csv' => 'csv',
            'text/plain' => 'txt',
            default => '',
        };
    }

    public static function isSpreadsheet(string $filename, ?string $mimeType = null): bool
    {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if (in_array($ext, ['xlsx', 'xls', 'csv'], true)) {
            return true;
        }

        return in_array(strtolower(trim((string) $mimeType)), [
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/vnd.ms-excel',
            'text/csv',
            'application/csv',
        ], true);
    }

    public static function isReadableByAi(string $filename, ?string $mimeType = null): bool
    {
        $ext = self::extensionFor($filename, $mimeType);

        return in_array($ext, ['docx', 'xlsx', 'xls', 'csv', 'txt'], true);
    }

    /**
     * Parse `.xlsx`, `.xls`, or `.csv` safely into bounded sheet grids for frontend rendering.
     *
     * @return array{
     *     type: string,
     *     extension: string,
     *     sheets: array<int, array{
     *         name: string,
     *         columns: array<int, string>,
     *         rows: array<int, array<int, string>>,
     *         row_count: int,
     *         col_count: int,
     *         truncated_rows: bool,
     *         truncated_cols: bool
     *     }>,
     *     total_sheets: int,
     *     truncated_sheets: bool
     * }
     */
    public static function extractSpreadsheetPreview(
        string $fullPath,
        string $extension,
        int $maxSheets = self::MAX_SHEETS,
        int $maxRows = self::MAX_ROWS_PER_SHEET,
        int $maxCols = self::MAX_COLS_PER_SHEET,
    ): array {
        self::assertSafeReadableFile($fullPath);

        $ext = strtolower(ltrim($extension, '.'));
        if (! in_array($ext, ['xlsx', 'xls', 'csv'], true)) {
            throw new InvalidArgumentException('Format file bukan spreadsheet yang didukung (xlsx, xls, csv).');
        }

        $maxSheets = max(1, $maxSheets);
        $maxRows = max(1, $maxRows);
        $maxCols = max(1, $maxCols);

        try {
            if ($ext === 'csv') {
                $readerType = 'Csv';
            } else {
                $identified = IOFactory::identify($fullPath);
                if (! in_array($identified, ['Xlsx', 'Xls', 'Csv'], true)) {
                    throw new RuntimeException('Format isi file spreadsheet tidak valid atau tidak diizinkan.');
                }
                $readerType = $identified;
            }

            if ($readerType === 'Xlsx') {
                self::assertSafeZipArchive($fullPath);
            }

            $reader = IOFactory::createReader($readerType);
            $reader->setReadDataOnly(true);

            // Read 1 extra row/col beyond cap so truncation flags stay accurate without unbounded RAM usage.
            $rowLimit = $maxRows + 1;
            $colLimit = $maxCols + 1;
            $reader->setReadFilter(new class($rowLimit, $colLimit) implements IReadFilter
            {
                public function __construct(
                    private readonly int $maxRow,
                    private readonly int $maxCol,
                ) {}

                public function readCell(string $columnAddress, int $row, string $worksheetName = ''): bool
                {
                    if ($row > $this->maxRow) {
                        return false;
                    }

                    return Coordinate::columnIndexFromString($columnAddress) <= $this->maxCol;
                }
            });

            $spreadsheet = $reader->load($fullPath);
        } catch (RuntimeException|InvalidArgumentException $e) {
            throw $e;
        } catch (Throwable) {
            throw new RuntimeException('File spreadsheet tidak dapat dibaca atau rusak.');
        }

        try {
            $totalSheets = max(1, $spreadsheet->getSheetCount());
            $truncatedSheets = $spreadsheet->getSheetCount() > $maxSheets;
            $limitSheets = min($spreadsheet->getSheetCount(), $maxSheets);
            $sheets = [];

            for ($s = 0; $s < $limitSheets; $s++) {
                $worksheet = $spreadsheet->getSheet($s);
                $highestRow = (int) $worksheet->getHighestDataRow();
                $highestColIndex = Coordinate::columnIndexFromString($worksheet->getHighestDataColumn());

                $truncatedRows = $highestRow > $maxRows;
                $truncatedCols = $highestColIndex > $maxCols;
                $rowCount = min($highestRow, $maxRows);
                $colCount = min($highestColIndex, $maxCols);

                if ($highestRow === 1 && $highestColIndex === 1) {
                    $firstVal = trim((string) ($worksheet->getCell('A1')->getValue() ?? ''));
                    if ($firstVal === '') {
                        $rowCount = 0;
                        $colCount = 0;
                    }
                }

                $columns = [];
                for ($c = 1; $c <= $colCount; $c++) {
                    $columns[] = Coordinate::stringFromColumnIndex($c);
                }

                $rows = [];
                for ($r = 1; $r <= $rowCount; $r++) {
                    $rowValues = [];
                    for ($c = 1; $c <= $colCount; $c++) {
                        $cell = $worksheet->getCell(Coordinate::stringFromColumnIndex($c).$r);
                        $value = $cell->isFormula()
                            ? ($cell->getOldCalculatedValue() ?? $cell->getValue())
                            : $cell->getFormattedValue();

                        if (is_bool($value)) {
                            $str = $value ? 'TRUE' : 'FALSE';
                        } elseif ($value === null) {
                            $str = '';
                        } elseif (is_scalar($value)) {
                            $str = (string) $value;
                        } else {
                            $str = '';
                        }

                        $rowValues[] = $str;
                    }
                    $rows[] = $rowValues;
                }

                $sheets[] = [
                    'name' => (string) $worksheet->getTitle(),
                    'columns' => $columns,
                    'rows' => $rows,
                    'row_count' => $rowCount,
                    'col_count' => $colCount,
                    'truncated_rows' => $truncatedRows,
                    'truncated_cols' => $truncatedCols,
                ];
            }

            return [
                'type' => 'spreadsheet',
                'extension' => $ext,
                'sheets' => $sheets,
                'total_sheets' => $totalSheets,
                'truncated_sheets' => $truncatedSheets,
            ];
        } finally {
            $spreadsheet->disconnectWorksheets();
            unset($spreadsheet);
        }
    }

    /**
     * Extract paragraphs and tables from a `.docx` file in reading order with anti-XXE & ZIP-bomb limits.
     *
     * @return array{text: string, paragraphs: int, tables: int, truncated: bool}
     */
    public static function extractDocxText(string $fullPath, int $maxChars = 12000): array
    {
        self::assertSafeReadableFile($fullPath);
        self::assertSafeZipArchive($fullPath);

        $zip = new ZipArchive;
        if ($zip->open($fullPath) !== true) {
            throw new RuntimeException('File Word (.docx) tidak dapat dibuka atau rusak.');
        }

        try {
            if ($zip->locateName('word/document.xml') === false) {
                throw new RuntimeException('File Word (.docx) tidak memiliki konten dokumen yang valid.');
            }

            $headerEntries = [];
            $footerEntries = [];
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = $zip->getNameIndex($i);
                if (! is_string($name)) {
                    continue;
                }
                if (preg_match('/^word\/header\d*\.xml$/i', $name) === 1) {
                    $headerEntries[] = $name;
                } elseif (preg_match('/^word\/footer\d*\.xml$/i', $name) === 1) {
                    $footerEntries[] = $name;
                }
            }
            sort($headerEntries, SORT_NATURAL);
            sort($footerEntries, SORT_NATURAL);
            $headerEntries = array_slice($headerEntries, 0, self::MAX_DOCX_HEADER_FOOTER_ENTRIES);
            $footerEntries = array_slice($footerEntries, 0, self::MAX_DOCX_HEADER_FOOTER_ENTRIES);

            $blocks = [];
            $paragraphs = 0;
            $tables = 0;
            $remainingXmlBytes = self::MAX_ZIP_XML_BYTES;

            foreach (array_merge($headerEntries, ['word/document.xml'], $footerEntries) as $entry) {
                $xml = self::readZipEntryBounded($zip, $entry, $remainingXmlBytes);
                $remainingXmlBytes -= strlen($xml);
                $dom = self::loadSafeXml($xml);
                self::collectWordBlocks($dom, $blocks, $paragraphs, $tables);
            }
        } finally {
            $zip->close();
        }

        $text = trim(implode("\n", $blocks));
        $truncated = false;
        $maxChars = max(1, $maxChars);

        if (mb_strlen($text, 'UTF-8') > $maxChars) {
            $text = rtrim(mb_substr($text, 0, $maxChars, 'UTF-8'))."\n... [konten dokumen dipotong]";
            $truncated = true;
        }

        return [
            'text' => $text,
            'paragraphs' => $paragraphs,
            'tables' => $tables,
            'truncated' => $truncated,
        ];
    }

    /**
     * Extract AI-readable plain text from `.docx`, `.xlsx`, `.xls`, `.csv`, or `.txt`.
     *
     * @return array<string, mixed>
     */
    public static function extractTextForAi(
        string $fullPath,
        string $originalName,
        ?string $mimeType = null,
        int $maxChars = 12000,
    ): array {
        $ext = self::extensionFor($originalName, $mimeType);

        if ($ext === 'docx') {
            $parsed = self::extractDocxText($fullPath, $maxChars);

            return [
                'supported' => true,
                'format' => 'docx',
                'text' => $parsed['text'],
                'paragraphs' => $parsed['paragraphs'],
                'tables' => $parsed['tables'],
                'truncated' => $parsed['truncated'],
            ];
        }

        if (in_array($ext, ['xlsx', 'xls', 'csv'], true)) {
            $preview = self::extractSpreadsheetPreview(
                $fullPath,
                $ext,
                self::MAX_SHEETS,
                self::MAX_ROWS_PER_SHEET,
                self::MAX_COLS_PER_SHEET,
            );

            $lines = [];
            $truncated = $preview['truncated_sheets'];

            foreach ($preview['sheets'] as $sheet) {
                $lines[] = "=== Sheet: {$sheet['name']} ({$sheet['row_count']} baris x {$sheet['col_count']} kolom) ===";
                if ($sheet['row_count'] === 0) {
                    $lines[] = '(kosong)';

                    continue;
                }

                $lines[] = '| '.implode(' | ', $sheet['columns']).' |';
                foreach ($sheet['rows'] as $row) {
                    $safeCells = array_map(
                        static fn (string $val): string => str_replace(["\r\n", "\r", "\n", '|'], [' ', ' ', ' ', '/'], $val),
                        $row,
                    );
                    $lines[] = '| '.implode(' | ', $safeCells).' |';
                }

                if ($sheet['truncated_rows'] || $sheet['truncated_cols']) {
                    $truncated = true;
                    $lines[] = '[Sebagian baris/kolom sheet ini dipotong untuk efisiensi pembacaan]';
                }
            }

            $text = trim(implode("\n", $lines));
            $maxChars = max(1, $maxChars);
            if (mb_strlen($text, 'UTF-8') > $maxChars) {
                $text = rtrim(mb_substr($text, 0, $maxChars, 'UTF-8'))."\n... [konten spreadsheet dipotong]";
                $truncated = true;
            }

            return [
                'supported' => true,
                'format' => $ext,
                'text' => $text,
                'total_sheets' => $preview['total_sheets'],
                'truncated' => $truncated,
            ];
        }

        if ($ext === 'txt') {
            self::assertSafeReadableFile($fullPath);
            $raw = file_get_contents($fullPath);
            if ($raw === false) {
                throw new RuntimeException('File teks tidak dapat dibaca.');
            }

            $text = trim($raw);
            $truncated = false;
            $maxChars = max(1, $maxChars);
            if (mb_strlen($text, 'UTF-8') > $maxChars) {
                $text = rtrim(mb_substr($text, 0, $maxChars, 'UTF-8'))."\n... [konten teks dipotong]";
                $truncated = true;
            }

            return [
                'supported' => true,
                'format' => 'txt',
                'text' => $text,
                'truncated' => $truncated,
            ];
        }

        return [
            'supported' => false,
            'format' => $ext !== '' ? $ext : 'unknown',
            'reason' => $ext === 'doc'
                ? 'Format Word lama (.doc) belum didukung untuk pembacaan otomatis AI; mohon konversi ke .docx atau unduh file secara manual.'
                : 'Format file ini tidak dapat diekstrak sebagai teks oleh AI (format didukung: .docx, .xlsx, .xls, .csv, .txt).',
        ];
    }

    /**
     * Build a JSON preview response for an authorized spreadsheet attachment without leaking server errors.
     */
    public static function previewSpreadsheetResponse(string $relativePath, string $originalName, ?string $mimeType = null): JsonResponse
    {
        if (! self::isSpreadsheet($originalName, $mimeType)) {
            return response()->json([
                'message' => 'Pratinjau ini hanya mendukung berkas spreadsheet (.xlsx, .xls, .csv).',
            ], 422);
        }

        try {
            $fullPath = self::resolvePublicPath($relativePath);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 404);
        }

        $ext = self::extensionFor($originalName, $mimeType);
        if (! in_array($ext, ['xlsx', 'xls', 'csv'], true)) {
            $ext = 'xlsx';
        }

        try {
            return response()->json(self::extractSpreadsheetPreview($fullPath, $ext), 200);
        } catch (InvalidArgumentException|RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (Throwable) {
            return response()->json(['message' => 'File spreadsheet tidak dapat diproses atau rusak.'], 422);
        }
    }

    /**
     * Serve safe inline-allowlisted files with `nosniff`, and force download (`attachment`) for everything else.
     */
    public static function streamInlineOrDownload(
        string $relativePath,
        string $downloadName,
        ?string $storedMime = null,
    ): BinaryFileResponse|StreamedResponse {
        $normalized = ltrim(str_replace('\\', '/', $relativePath), '/');
        if ($normalized === '' || str_contains($normalized, "\0") || str_contains($normalized, '..')) {
            abort(404, 'File tidak ditemukan.');
        }

        if (! Storage::disk('public')->exists($normalized)) {
            abort(404, 'File tidak ditemukan.');
        }

        $detectedMime = strtolower(trim((string) (Storage::disk('public')->mimeType($normalized) ?: 'application/octet-stream')));
        $safeName = trim(str_replace(["\r", "\n", '"', '\\', '/'], '_', $downloadName));
        if ($safeName === '') {
            $safeName = basename($normalized) ?: 'lampiran';
        }

        if (in_array($detectedMime, self::SAFE_INLINE_MIMES, true)) {
            return response()->file(Storage::disk('public')->path($normalized), [
                'Content-Type' => $detectedMime,
                'Content-Disposition' => 'inline; filename="'.$safeName.'"',
                'X-Content-Type-Options' => 'nosniff',
            ]);
        }

        return Storage::disk('public')->download($normalized, $safeName, [
            'Content-Type' => $detectedMime,
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * Pre-flight ZIP archive check across all entries to block multi-entry / spoofed-size ZIP bombs.
     */
    public static function assertSafeZipArchive(
        string $fullPath,
        int $maxEntries = self::MAX_ZIP_ENTRIES,
        int $maxTotalUncompressedBytes = self::MAX_ZIP_TOTAL_BYTES,
    ): void {
        self::assertSafeReadableFile($fullPath);

        $zip = new ZipArchive;
        if ($zip->open($fullPath) !== true) {
            throw new RuntimeException('Arsip dokumen tidak dapat dibuka atau rusak.');
        }

        try {
            if ($zip->numFiles > $maxEntries) {
                throw new RuntimeException("Arsip dokumen memiliki terlalu banyak entri (maksimal {$maxEntries}).");
            }

            $declaredTotal = 0;
            $streamedTotal = 0;

            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                $entryName = is_array($stat) ? (string) ($stat['name'] ?? '') : '';
                if ($entryName !== '' && str_ends_with($entryName, '/')) {
                    continue;
                }

                if (is_array($stat) && isset($stat['size'])) {
                    $declaredTotal += max(0, (int) $stat['size']);
                    if ($declaredTotal > $maxTotalUncompressedBytes) {
                        throw new RuntimeException('Ukuran hasil dekompresi arsip dokumen melebihi batas aman.');
                    }
                }

                $stream = $zip->getStreamIndex($i);
                if (! is_resource($stream)) {
                    throw new RuntimeException('Entri di dalam arsip dokumen tidak dapat dibaca.');
                }

                try {
                    while (! feof($stream)) {
                        $chunk = fread($stream, 8192);
                        if ($chunk === false) {
                            throw new RuntimeException('Gagal membaca stream entri arsip dokumen.');
                        }
                        $streamedTotal += strlen($chunk);
                        if ($streamedTotal > $maxTotalUncompressedBytes) {
                            throw new RuntimeException('Ukuran hasil dekompresi arsip dokumen melebihi batas aman.');
                        }
                    }
                } finally {
                    fclose($stream);
                }
            }
        } finally {
            $zip->close();
        }
    }

    private static function assertSafeReadableFile(string $fullPath): void
    {
        if (! is_file($fullPath) || ! is_readable($fullPath)) {
            throw new RuntimeException('File lampiran tidak ditemukan atau tidak dapat dibaca.');
        }

        $size = filesize($fullPath);
        if ($size === false || $size > self::MAX_FILE_BYTES) {
            throw new RuntimeException('Ukuran file melebihi batas maksimal pemrosesan (10 MB).');
        }
    }

    private static function readZipEntryBounded(
        ZipArchive $zip,
        string $entryName,
        int $maxBytes = self::MAX_ZIP_XML_BYTES,
    ): string {
        $maxBytes = max(0, $maxBytes);
        $stat = $zip->statName($entryName);
        if ($stat !== false && isset($stat['size']) && (int) $stat['size'] > $maxBytes) {
            throw new RuntimeException('Ukuran XML di dalam dokumen Word melebihi batas aman.');
        }

        $stream = $zip->getStream($entryName);
        if (! is_resource($stream)) {
            throw new RuntimeException("Gagal membaca komponen dokumen '{$entryName}'.");
        }

        try {
            $buffer = '';
            $total = 0;
            while (! feof($stream)) {
                $chunk = fread($stream, 8192);
                if ($chunk === false) {
                    throw new RuntimeException("Gagal membaca stream komponen '{$entryName}'.");
                }
                $total += strlen($chunk);
                if ($total > $maxBytes) {
                    throw new RuntimeException('Ukuran XML hasil dekompresi melebihi batas aman (8 MB).');
                }
                $buffer .= $chunk;
            }

            return $buffer;
        } finally {
            fclose($stream);
        }
    }

    private static function loadSafeXml(string $xml): DOMDocument
    {
        if (preg_match('/<!\s*(DOCTYPE|ENTITY)\b/i', $xml) === 1) {
            throw new RuntimeException('Dokumen XML ditolak karena mengandung deklarasi DTD/ENTITY.');
        }

        $dom = new DOMDocument;
        $prev = libxml_use_internal_errors(true);

        try {
            $loaded = $dom->loadXML($xml, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
            if (! $loaded) {
                throw new RuntimeException('Struktur XML dokumen tidak valid atau rusak.');
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($prev);
        }

        return $dom;
    }

    /**
     * @param  array<int, string>  $blocks
     */
    private static function collectWordBlocks(DOMDocument $dom, array &$blocks, int &$paragraphs, int &$tables): void
    {
        $root = $dom->documentElement;
        if (! $root instanceof DOMElement) {
            return;
        }

        $body = null;
        foreach ($root->childNodes as $child) {
            if ($child instanceof DOMElement && $child->localName === 'body') {
                $body = $child;
                break;
            }
        }

        $container = $body ?? $root;

        foreach ($container->childNodes as $node) {
            if (! $node instanceof DOMElement) {
                continue;
            }

            if ($node->localName === 'p') {
                $line = self::extractParagraphText($node);
                if ($line !== '') {
                    $blocks[] = $line;
                    $paragraphs++;
                }
            } elseif ($node->localName === 'tbl') {
                $tableRows = self::extractTableRows($node);
                if (! empty($tableRows)) {
                    $tables++;
                    foreach ($tableRows as $rowLine) {
                        $blocks[] = $rowLine;
                    }
                }
            }
        }
    }

    private static function extractParagraphText(DOMElement $paragraph): string
    {
        $buffer = '';
        $walk = static function (DOMNode $node) use (&$walk, &$buffer): void {
            if ($node instanceof DOMElement) {
                if ($node->namespaceURI === self::WORD_NS || $node->namespaceURI === null) {
                    if ($node->localName === 't' || $node->localName === 'delText' || $node->localName === 'instrText') {
                        $buffer .= $node->textContent;

                        return;
                    }
                    if ($node->localName === 'tab') {
                        $buffer .= ' ';

                        return;
                    }
                    if ($node->localName === 'br' || $node->localName === 'cr') {
                        $buffer .= "\n";

                        return;
                    }
                }
            }

            foreach ($node->childNodes as $child) {
                $walk($child);
            }
        };

        $walk($paragraph);

        return trim((string) preg_replace("/[ \t]+/", ' ', $buffer));
    }

    /**
     * @return array<int, string>
     */
    private static function extractTableRows(DOMElement $table): array
    {
        $rows = [];
        foreach ($table->childNodes as $tr) {
            if (! $tr instanceof DOMElement || $tr->localName !== 'tr') {
                continue;
            }

            $cells = [];
            foreach ($tr->childNodes as $tc) {
                if (! $tc instanceof DOMElement || $tc->localName !== 'tc') {
                    continue;
                }

                $cellParts = [];
                foreach ($tc->childNodes as $child) {
                    if (! $child instanceof DOMElement) {
                        continue;
                    }
                    if ($child->localName === 'p') {
                        $text = self::extractParagraphText($child);
                        if ($text !== '') {
                            $cellParts[] = $text;
                        }
                    } elseif ($child->localName === 'tbl') {
                        foreach (self::extractTableRows($child) as $nestedRow) {
                            $cellParts[] = $nestedRow;
                        }
                    }
                }

                $cellText = str_replace(["\r\n", "\r", "\n", '|'], [' ', ' ', ' ', '/'], implode(' ', $cellParts));
                $cells[] = trim($cellText);
            }

            if (! empty($cells)) {
                $rows[] = '| '.implode(' | ', $cells).' |';
            }
        }

        return $rows;
    }
}
