<?php

/** Minimal dependency-free XLSX writer for small, server-generated exports. */
final class SimpleXlsxWriter
{
    private static function xml(string $value): string { return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8'); }

    /** @param array<int,array<int|string|float|null>> $rows */
    public static function download(string $filename, array $rows): void
    {
        if (!class_exists('ZipArchive')) throw new RuntimeException('The PHP ZipArchive extension is required for Excel export.');
        $tmp = @tempnam(sys_get_temp_dir(), 'sms2_xlsx_');
        if ($tmp === false) throw new RuntimeException('Unable to create the Excel temporary file.');
        $zipOpen = false;
        $zip = null;
        try {
            $zip = new ZipArchive();
            if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) throw new RuntimeException('Unable to create the Excel file.');
            $zipOpen = true;
            $sheetRows = '';
            foreach ($rows as $r => $row) {
                $cells = '';
                foreach (array_values($row) as $c => $value) {
                    $col = ''; $n = $c + 1; while ($n > 0) { $m = ($n - 1) % 26; $col = chr(65 + $m) . $col; $n = intdiv($n - 1, 26); }
                    $cells .= '<c r="' . $col . ($r + 1) . '" t="inlineStr"><is><t>' . self::xml((string) $value) . '</t></is></c>';
                }
                $sheetRows .= '<row r="' . ($r + 1) . '">' . $cells . '</row>';
            }
            if ($zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>') !== true) throw new RuntimeException('Unable to write an Excel package entry.');
            if ($zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>') !== true) throw new RuntimeException('Unable to write an Excel package entry.');
            if ($zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Cashier History" sheetId="1" r:id="rId1"/></sheets></workbook>') !== true) throw new RuntimeException('Unable to write an Excel package entry.');
            if ($zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>') !== true) throw new RuntimeException('Unable to write an Excel package entry.');
            if ($zip->addFromString('xl/worksheets/sheet1.xml', '<?xml version="1.0" encoding="UTF-8"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>' . $sheetRows . '</sheetData></worksheet>') !== true) throw new RuntimeException('Unable to write an Excel package entry.');
            if ($zip->close() !== true) throw new RuntimeException('Unable to finalize the Excel file.');
            $zipOpen = false;
            clearstatcache(true, $tmp);
            $size = @filesize($tmp);
            if (!is_file($tmp) || $size === false || $size <= 0) throw new RuntimeException('The Excel file is invalid.');
            header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '_', $filename) . '"');
            header('Content-Length: ' . $size); header('Cache-Control: no-store');
            try {
                if (@readfile($tmp) === false) error_log('XLSX export: DOWNLOAD TRANSPORT FAILURE');
            } catch (Throwable $e) {
                // Headers may already be committed; never append an endpoint JSON error.
                error_log('XLSX export: DOWNLOAD TRANSPORT FAILURE: ' . $e->getMessage());
            }
        } finally {
            if ($zipOpen) {
                try {
                    if (@$zip->close() !== true) error_log('XLSX export: ZIP cleanup failed.');
                } catch (Throwable $e) {
                    error_log('XLSX export: ZIP cleanup failed: ' . $e->getMessage());
                }
            }
            // Closing an empty archive can itself remove the temporary file.
            clearstatcache(true, $tmp);
            if (file_exists($tmp) && !@unlink($tmp)) error_log('XLSX export: temporary file cleanup failed.');
        }
        exit;
    }
}
