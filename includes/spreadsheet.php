<?php
declare(strict_types=1);

function is_spreadsheet_previewable(string $name): bool
{
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    return in_array($ext, ['xlsx', 'xls', 'ods'], true);
}

function spreadsheet_library_available(): bool
{
    return class_exists(\PhpOffice\PhpSpreadsheet\IOFactory::class);
}

/**
 * Build an HTML sheet preview (tabs + tables). Caps rows/cols for large workbooks.
 *
 * @return array{ok: bool, html: string, error: string}
 */
function build_spreadsheet_preview_html(string $path, int $maxRows = 150, int $maxCols = 40): array
{
    if (!is_file($path)) {
        return ['ok' => false, 'html' => '', 'error' => 'File not found.'];
    }
    if (!spreadsheet_library_available()) {
        return ['ok' => false, 'html' => '', 'error' => 'PhpSpreadsheet is not installed. Run composer install.'];
    }

    try {
        $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($path);
        if (method_exists($reader, 'setReadDataOnly')) {
            $reader->setReadDataOnly(true);
        }
        if (method_exists($reader, 'setReadEmptyCells')) {
            $reader->setReadEmptyCells(false);
        }
        $spreadsheet = $reader->load($path);
    } catch (Throwable $e) {
        return ['ok' => false, 'html' => '', 'error' => 'Could not read spreadsheet: ' . $e->getMessage()];
    }

    $sheetCount = $spreadsheet->getSheetCount();
    if ($sheetCount < 1) {
        $spreadsheet->disconnectWorksheets();
        return ['ok' => false, 'html' => '', 'error' => 'Workbook has no sheets.'];
    }

    $parts = [];
    $parts[] = '<div class="sheet-preview" data-sheet-preview>';
    $parts[] = '<div class="sheet-preview__tabs" role="tablist" aria-label="Workbook sheets">';

    for ($i = 0; $i < $sheetCount; $i++) {
        $title = $spreadsheet->getSheet($i)->getTitle();
        $active = $i === 0 ? ' is-active' : '';
        $selected = $i === 0 ? 'true' : 'false';
        $parts[] = '<button type="button" class="sheet-preview__tab' . $active . '" role="tab"';
        $parts[] = ' data-sheet-tab="' . e((string) $i) . '"';
        $parts[] = ' aria-selected="' . $selected . '"';
        $parts[] = ' title="' . e($title) . '">' . e($title) . '</button>';
    }
    $parts[] = '</div>';

    $parts[] = '<p class="sheet-preview__note muted">Preview capped at ' . (int) $maxRows . ' rows × ' . (int) $maxCols . ' columns per sheet. Download the original for the full workbook.</p>';
    $parts[] = '<div class="sheet-preview__panels">';

    for ($i = 0; $i < $sheetCount; $i++) {
        $sheet = $spreadsheet->getSheet($i);
        $hidden = $i === 0 ? '' : ' hidden';
        $parts[] = '<div class="sheet-preview__panel" data-sheet-panel="' . e((string) $i) . '"' . $hidden . ' role="tabpanel">';
        $parts[] = render_worksheet_table_html($sheet, $maxRows, $maxCols);
        $parts[] = '</div>';
    }

    $parts[] = '</div></div>';

    $spreadsheet->disconnectWorksheets();
    unset($spreadsheet);

    return ['ok' => true, 'html' => implode('', $parts), 'error' => ''];
}

/**
 * @param \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet
 */
function render_worksheet_table_html($sheet, int $maxRows, int $maxCols): string
{
    $highestRow = (int) $sheet->getHighestDataRow();
    $highestColumn = $sheet->getHighestDataColumn();
    $highestColIndex = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($highestColumn);

    if ($highestRow < 1 || $highestColIndex < 1) {
        return '<p class="muted">This sheet is empty.</p>';
    }

    $rowLimit = min($highestRow, $maxRows);
    $colLimit = min($highestColIndex, $maxCols);
    $truncated = $highestRow > $maxRows || $highestColIndex > $maxCols;

    $html = [];
    $html[] = '<div class="sheet-preview__scroll">';
    $html[] = '<table class="sheet-table">';
    $html[] = '<thead><tr><th class="sheet-table__corner" scope="col"></th>';
    for ($c = 1; $c <= $colLimit; $c++) {
        $label = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($c);
        $html[] = '<th scope="col">' . e($label) . '</th>';
    }
    $html[] = '</tr></thead><tbody>';

    for ($r = 1; $r <= $rowLimit; $r++) {
        $html[] = '<tr>';
        $html[] = '<th scope="row" class="sheet-table__rowhead">' . $r . '</th>';
        for ($c = 1; $c <= $colLimit; $c++) {
            $coord = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($c) . $r;
            try {
                $value = $sheet->getCell($coord)->getFormattedValue();
            } catch (Throwable $e) {
                $value = '';
            }
            $text = is_scalar($value) || $value === null ? (string) $value : '';
            $html[] = '<td>' . e($text) . '</td>';
        }
        $html[] = '</tr>';
    }

    $html[] = '</tbody></table></div>';
    if ($truncated) {
        $html[] = '<p class="sheet-preview__trunc muted">Sheet has ' . $highestRow . ' data rows × ' . $highestColIndex . ' columns; showing ' . $rowLimit . ' × ' . $colLimit . '.</p>';
    }

    return implode('', $html);
}
