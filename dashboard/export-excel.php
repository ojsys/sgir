<?php
require_once dirname(__DIR__) . '/config/app.php';
require_once ROOT . '/config/database.php';
require_once ROOT . '/includes/auth.php';
require_once ROOT . '/includes/helpers.php';

start_session();
require_login();

// ══════════════════════════════════════════════════════════════════════════
// Minimal XLSX writer using ZipArchive (no Composer dependencies)
// ══════════════════════════════════════════════════════════════════════════
class SimpleXLSX
{
    private array  $rows        = [];
    private array  $col_widths  = [];
    private string $sheet_title = 'Feedback';

    // Header style: dark green bg, white bold text
    private const HEADER_FILL  = '1B3A1B';
    private const HEADER_FONT  = 'FFFFFF';
    private const ALT_ROW_FILL = 'F1F5F9';

    public function setSheetTitle(string $title): void
    {
        $this->sheet_title = $title;
    }

    /**
     * Add a row. Pass true for $is_header to apply header styling.
     */
    public function addRow(array $cells, bool $is_header = false): void
    {
        $this->rows[] = ['cells' => $cells, 'header' => $is_header];
        // Track max col widths
        foreach ($cells as $i => $cell) {
            $len = mb_strlen((string)$cell);
            $this->col_widths[$i] = max($this->col_widths[$i] ?? 8, min($len + 2, 60));
        }
    }

    /**
     * Build the .xlsx bytes in memory and return them.
     */
    public function build(): string
    {
        // All XML parts
        $content_types = $this->buildContentTypes();
        $rels           = $this->buildRels();
        $workbook_xml   = $this->buildWorkbook();
        $workbook_rels  = $this->buildWorkbookRels();
        $styles_xml     = $this->buildStyles();
        $sheet_xml      = $this->buildSheet();
        $shared_strings = $this->buildSharedStrings();

        // Use a temp file for ZipArchive
        $tmp = tempnam(sys_get_temp_dir(), 'xlsx_');
        $zip = new ZipArchive();
        $zip->open($tmp, ZipArchive::OVERWRITE);

        $zip->addFromString('[Content_Types].xml',              $content_types);
        $zip->addFromString('_rels/.rels',                      $rels);
        $zip->addFromString('xl/workbook.xml',                  $workbook_xml);
        $zip->addFromString('xl/_rels/workbook.xml.rels',       $workbook_rels);
        $zip->addFromString('xl/styles.xml',                    $styles_xml);
        $zip->addFromString('xl/sharedStrings.xml',             $shared_strings);
        $zip->addFromString('xl/worksheets/sheet1.xml',         $sheet_xml);
        $zip->close();

        $bytes = file_get_contents($tmp);
        unlink($tmp);
        return $bytes;
    }

    /** Stream the file as a download. */
    public function download(string $filename): void
    {
        $bytes = $this->build();
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($bytes));
        header('Cache-Control: no-cache, no-store, must-revalidate');
        echo $bytes;
        exit;
    }

    // ── Private builders ──────────────────────────────────────────────────

    private function buildContentTypes(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . '<Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>'
            . '</Types>';
    }

    private function buildRels(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>';
    }

    private function buildWorkbook(): string
    {
        $title = htmlspecialchars($this->sheet_title, ENT_XML1);
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets><sheet name="' . $title . '" sheetId="1" r:id="rId1"/></sheets>'
            . '</workbook>';
    }

    private function buildWorkbookRels(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            . '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/>'
            . '</Relationships>';
    }

    /**
     * Build styles.xml
     * Style index 0 = default
     * Style index 1 = header (dark green fill, white bold font)
     * Style index 2 = alt row (light blue fill)
     */
    private function buildStyles(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<fonts count="3">'
            .   '<font><sz val="10"/><name val="Calibri"/></font>'              // 0: default
            .   '<font><b/><sz val="10"/><color rgb="FF' . self::HEADER_FONT . '"/><name val="Calibri"/></font>'  // 1: header
            .   '<font><sz val="10"/><name val="Calibri"/></font>'              // 2: data
            . '</fonts>'
            . '<fills count="4">'
            .   '<fill><patternFill patternType="none"/></fill>'
            .   '<fill><patternFill patternType="gray125"/></fill>'
            .   '<fill><patternFill patternType="solid"><fgColor rgb="FF' . self::HEADER_FILL . '"/></patternFill></fill>'  // 2: header
            .   '<fill><patternFill patternType="solid"><fgColor rgb="FF' . self::ALT_ROW_FILL . '"/></patternFill></fill>' // 3: alt
            . '</fills>'
            . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="3">'
            .   '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'         // 0: default
            .   '<xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/>' // 1: header
            .   '<xf numFmtId="0" fontId="2" fillId="3" borderId="0" xfId="0" applyFill="1"/>'               // 2: alt row
            . '</cellXfs>'
            . '</styleSheet>';
    }

    /** Build sheet1.xml — the actual worksheet data */
    private function buildSheet(): string
    {
        $xml  = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
        $xml .= '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">';

        // Column widths
        if ($this->col_widths) {
            $xml .= '<cols>';
            foreach ($this->col_widths as $i => $w) {
                $col = $i + 1;
                $xml .= '<col min="' . $col . '" max="' . $col . '" width="' . $w . '" customWidth="1"/>';
            }
            $xml .= '</cols>';
        }

        $xml .= '<sheetData>';
        $data_row = 0;
        foreach ($this->rows as $ri => $row_def) {
            $row_num   = $ri + 1;
            $is_header = $row_def['header'];
            if (!$is_header) {
                $data_row++;
            }
            // Alternate every 2nd data row
            $use_alt = !$is_header && ($data_row % 2 === 0);
            $s       = $is_header ? '1' : ($use_alt ? '2' : '0');

            $xml .= '<row r="' . $row_num . '">';
            foreach ($row_def['cells'] as $ci => $cell) {
                $col_letter = $this->colLetter($ci);
                $cell_ref   = $col_letter . $row_num;
                $val        = (string)$cell;

                // Determine if numeric
                if (is_numeric($val) && $val !== '') {
                    $xml .= '<c r="' . $cell_ref . '" s="' . $s . '" t="n"><v>' . htmlspecialchars($val, ENT_XML1) . '</v></c>';
                } else {
                    // Store as shared string
                    $ss_index = $this->ssIndex($val);
                    $xml .= '<c r="' . $cell_ref . '" s="' . $s . '" t="s"><v>' . $ss_index . '</v></c>';
                }
            }
            $xml .= '</row>';
        }
        $xml .= '</sheetData></worksheet>';
        return $xml;
    }

    // Shared strings table
    private array $ss = [];
    private function ssIndex(string $val): int
    {
        if (!isset($this->ss[$val])) {
            $this->ss[$val] = count($this->ss);
        }
        return $this->ss[$val];
    }

    private function buildSharedStrings(): string
    {
        $count = count($this->ss);
        $xml   = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
        $xml  .= '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="' . $count . '" uniqueCount="' . $count . '">';
        foreach (array_keys($this->ss) as $val) {
            $xml .= '<si><t xml:space="preserve">' . htmlspecialchars($val, ENT_XML1) . '</t></si>';
        }
        $xml .= '</sst>';
        return $xml;
    }

    /** Convert 0-based column index to Excel letter (A, B, … Z, AA …) */
    private function colLetter(int $index): string
    {
        $letter = '';
        $n      = $index;
        do {
            $letter = chr(65 + ($n % 26)) . $letter;
            $n      = (int)floor($n / 26) - 1;
        } while ($n >= 0);
        return $letter;
    }
}

// ══════════════════════════════════════════════════════════════════════════
// Main: query + build XLSX
// ══════════════════════════════════════════════════════════════════════════

// ── Build WHERE clause ────────────────────────────────────────────────────
$where  = [];
$params = [];

if (!empty($_GET['department_id'])) {
    $where[]  = 'f.department_id = :dept_id';
    $params[':dept_id'] = (int)$_GET['department_id'];
}
if (!empty($_GET['category']) && in_array($_GET['category'], ['compliment','suggestion','complaint'], true)) {
    $where[]  = 'f.category = :category';
    $params[':category'] = $_GET['category'];
}
if (!empty($_GET['date_from'])) {
    $where[]  = 'DATE(f.created_at) >= :date_from';
    $params[':date_from'] = $_GET['date_from'];
}
if (!empty($_GET['date_to'])) {
    $where[]  = 'DATE(f.created_at) <= :date_to';
    $params[':date_to'] = $_GET['date_to'];
}
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$sql  = "SELECT f.id, f.created_at, d.name AS dept_name, f.other_department,
                f.category, f.rating, f.message,
                f.is_anonymous, f.submitter_name, f.email, f.phone,
                f.status, f.admin_notes, f.reviewed_at
         FROM feedback f
         LEFT JOIN departments d ON d.id = f.department_id
         {$whereSql}
         ORDER BY f.created_at DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

// ── Build XLSX ────────────────────────────────────────────────────────────
$xlsx = new SimpleXLSX();
$xlsx->setSheetTitle('Feedback');

$xlsx->addRow([
    'ID', 'Date', 'Department', 'Category', 'Rating',
    'Message', 'Anonymous', 'Submitter Name', 'Email', 'Phone',
    'Status', 'Admin Notes', 'Reviewed At',
], true);  // is_header = true

foreach ($rows as $row) {
    $dept = $row['dept_name'] ?: ($row['other_department'] ?: 'General');
    $xlsx->addRow([
        $row['id'],
        $row['created_at'],
        $dept,
        $row['category'],
        $row['rating'],
        $row['message'],
        $row['is_anonymous'] ? 'Yes' : 'No',
        $row['submitter_name'] ?? '',
        $row['email']          ?? '',
        $row['phone']          ?? '',
        $row['status'],
        $row['admin_notes']    ?? '',
        $row['reviewed_at']    ?? '',
    ]);
}

$filename = 'sgir-feedback-' . date('Ymd-His') . '.xlsx';
$xlsx->download($filename);
