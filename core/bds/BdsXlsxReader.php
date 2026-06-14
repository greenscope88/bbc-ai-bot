<?php
declare(strict_types=1);

/**
 * BDS Phase 7-1c-2a — Local Excel (.xlsx) reader for Upload Portal staging files.
 *
 * Reads five Required Tabs from a workbook and returns raw row arrays aligned with
 * BdsGoogleSheetReader output for downstream Parser / Validator.
 *
 * @see docs/BATS_DATA_CONTRACT.md §1.6.7
 * @see docs/BATS_DATA_SYNC_IMPLEMENTATION_PLAN.md §8.8.6
 */
final class BdsXlsxReader
{
    /** @var list<string> */
    public const REQUIRED_TABS = [
        'company_profile',
        'qa',
        'external_product_links',
        'service_items',
        'special_prices',
    ];

    /**
     * @return array{
     *   company_profile: list<array<string, mixed>>,
     *   qa: list<array<string, mixed>>,
     *   external_product_links: list<array<string, mixed>>,
     *   service_items: list<array<string, mixed>>,
     *   special_prices: list<array<string, mixed>>
     * }
     */
    public function readFile(string $filePath): array
    {
        $filePath = trim($filePath);
        if ($filePath === '' || !is_file($filePath)) {
            throw new \InvalidArgumentException('xlsx file does not exist.');
        }

        if (!class_exists('ZipArchive')) {
            throw new \RuntimeException('ZipArchive extension is required to read xlsx files.');
        }

        $zip = new \ZipArchive();
        if ($zip->open($filePath) !== true) {
            throw new \RuntimeException('Unable to open xlsx file.');
        }

        try {
            $sharedStrings = $this->readSharedStrings($zip);
            $sheetMap = $this->readWorksheetMap($zip);
            $output = $this->emptyTabPayload();
            $foundTabs = [];

            foreach (self::REQUIRED_TABS as $tabName) {
                if (!isset($sheetMap[$tabName])) {
                    continue;
                }

                $foundTabs[$tabName] = true;
                $sheetPath = $sheetMap[$tabName];
                $grid = $this->readWorksheetGrid($zip, $sheetPath, $sharedStrings);
                $output[$tabName] = $this->gridToRows($grid);
            }

            $missing = [];
            foreach (self::REQUIRED_TABS as $tabName) {
                if (!isset($foundTabs[$tabName])) {
                    $missing[] = $tabName;
                }
            }
            if ($missing !== []) {
                throw new \InvalidArgumentException(
                    'Missing required worksheet tabs: ' . implode(', ', $missing)
                );
            }

            return $output;
        } finally {
            $zip->close();
        }
    }

    /**
     * @return array{
     *   company_profile: list<array<string, mixed>>,
     *   qa: list<array<string, mixed>>,
     *   external_product_links: list<array<string, mixed>>,
     *   service_items: list<array<string, mixed>>,
     *   special_prices: list<array<string, mixed>>
     * }
     */
    private function emptyTabPayload(): array
    {
        return [
            'company_profile' => [],
            'qa' => [],
            'external_product_links' => [],
            'service_items' => [],
            'special_prices' => [],
        ];
    }

    /**
     * @return list<string>
     */
    private function readSharedStrings(\ZipArchive $zip): array
    {
        $xml = $zip->getFromName('xl/sharedStrings.xml');
        if ($xml === false || $xml === '') {
            return [];
        }

        $document = $this->loadXml($xml);
        if ($document === null) {
            return [];
        }

        $strings = [];
        $items = $document->xpath('//*[local-name()="si"]');
        if (!is_array($items)) {
            return [];
        }

        foreach ($items as $item) {
            $textNodes = $item->xpath('.//*[local-name()="t"]');
            $parts = [];
            if (is_array($textNodes)) {
                foreach ($textNodes as $textNode) {
                    $parts[] = (string) $textNode;
                }
            }
            $strings[] = implode('', $parts);
        }

        return $strings;
    }

    /**
     * @return array<string, string> tab name => worksheet xml path
     */
    private function readWorksheetMap(\ZipArchive $zip): array
    {
        $workbookXml = $zip->getFromName('xl/workbook.xml');
        $relsXml = $zip->getFromName('xl/_rels/workbook.xml.rels');
        if ($workbookXml === false || $relsXml === false) {
            throw new \RuntimeException('Invalid xlsx workbook structure.');
        }

        $workbook = $this->loadXml($workbookXml);
        $rels = $this->loadXml($relsXml);
        if ($workbook === null || $rels === null) {
            throw new \RuntimeException('Invalid xlsx workbook XML.');
        }

        $relTargets = [];
        $relationships = $rels->xpath('//*[local-name()="Relationship"]');
        if (is_array($relationships)) {
            foreach ($relationships as $relationship) {
                $id = (string) $relationship['Id'];
                $target = (string) $relationship['Target'];
                if ($id !== '' && $target !== '') {
                    $relTargets[$id] = 'xl/' . ltrim(str_replace('\\', '/', $target), '/');
                }
            }
        }

        $sheetMap = [];
        $sheets = $workbook->xpath('//*[local-name()="sheet"]');
        if (!is_array($sheets)) {
            return $sheetMap;
        }

        foreach ($sheets as $sheet) {
            $name = trim((string) $sheet['name']);
            $relId = (string) $sheet->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];
            if ($name === '' || $relId === '' || !isset($relTargets[$relId])) {
                continue;
            }
            $sheetMap[$name] = $relTargets[$relId];
        }

        return $sheetMap;
    }

    /**
     * @param list<string> $sharedStrings
     * @return array<int, array<int, string>>
     */
    private function readWorksheetGrid(\ZipArchive $zip, string $sheetPath, array $sharedStrings): array
    {
        $xml = $zip->getFromName($sheetPath);
        if ($xml === false || $xml === '') {
            return [];
        }

        $document = $this->loadXml($xml);
        if ($document === null) {
            return [];
        }

        $grid = [];
        $rows = $document->xpath('//*[local-name()="sheetData"]/*[local-name()="row"]');
        if (!is_array($rows)) {
            return [];
        }

        foreach ($rows as $row) {
            $rowIndex = isset($row['r']) ? max(1, (int) $row['r']) : count($grid) + 1;
            $cells = $row->xpath('./*[local-name()="c"]');
            if (!is_array($cells)) {
                continue;
            }

            foreach ($cells as $cell) {
                $ref = (string) $cell['r'];
                if ($ref === '') {
                    continue;
                }

                [$columnIndex] = $this->cellReferenceToColumnIndex($ref);
                $grid[$rowIndex][$columnIndex] = $this->extractCellValue($cell, $sharedStrings);
            }
        }

        if ($grid === []) {
            return [];
        }

        ksort($grid);
        $normalized = [];
        $maxColumn = 0;
        foreach ($grid as $rowCells) {
            foreach (array_keys($rowCells) as $columnIndex) {
                if ($columnIndex > $maxColumn) {
                    $maxColumn = $columnIndex;
                }
            }
        }

        foreach ($grid as $rowCells) {
            $line = [];
            for ($column = 1; $column <= $maxColumn; ++$column) {
                $line[] = isset($rowCells[$column]) ? $rowCells[$column] : '';
            }
            $normalized[] = $line;
        }

        return $normalized;
    }

    /**
     * @param array<int, array<int, string>> $grid
     * @return list<array<string, mixed>>
     */
    private function gridToRows(array $grid): array
    {
        if ($grid === []) {
            return [];
        }

        $headerRow = array_shift($grid);
        if (!is_array($headerRow) || $headerRow === []) {
            return [];
        }

        $headers = $this->normalizeHeaders($headerRow);
        if ($headers === []) {
            return [];
        }

        $rows = [];
        foreach ($grid as $rawRow) {
            if (!is_array($rawRow)) {
                continue;
            }
            $row = $this->combineRow($headers, $rawRow);
            if ($this->isNonEmptyRow($row)) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * @param list<mixed> $headerRow
     * @return list<string>
     */
    private function normalizeHeaders(array $headerRow): array
    {
        $headers = [];
        foreach ($headerRow as $index => $cell) {
            $name = trim((string) $cell);
            if ($name === '') {
                $name = 'column_' . ((int) $index + 1);
            }
            $headers[] = $name;
        }

        return $headers;
    }

    /**
     * @param list<string> $headers
     * @param list<mixed> $rawRow
     * @return array<string, mixed>
     */
    private function combineRow(array $headers, array $rawRow): array
    {
        $row = [];
        $columnCount = count($headers);
        for ($index = 0; $index < $columnCount; ++$index) {
            $row[$headers[$index]] = array_key_exists($index, $rawRow) ? $rawRow[$index] : '';
        }

        return $row;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function isNonEmptyRow(array $row): bool
    {
        foreach ($row as $value) {
            if ($value !== null && trim((string) $value) !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{0:int}
     */
    private function cellReferenceToColumnIndex(string $cellReference): array
    {
        if (!preg_match('/^([A-Z]+)(\d+)$/', strtoupper($cellReference), $matches)) {
            return [1];
        }

        $letters = $matches[1];
        $column = 0;
        $length = strlen($letters);
        for ($i = 0; $i < $length; ++$i) {
            $column = $column * 26 + (ord($letters[$i]) - 64);
        }

        return [$column];
    }

    /**
     * @param list<string> $sharedStrings
     */
    private function extractCellValue(\SimpleXMLElement $cell, array $sharedStrings): string
    {
        $type = (string) $cell['t'];
        if ($type === 's') {
            $valueNode = $cell->xpath('./*[local-name()="v"]');
            $index = is_array($valueNode) && isset($valueNode[0]) ? (int) $valueNode[0] : -1;
            return $index >= 0 && isset($sharedStrings[$index]) ? $sharedStrings[$index] : '';
        }

        if ($type === 'inlineStr') {
            $textNodes = $cell->xpath('.//*[local-name()="t"]');
            $parts = [];
            if (is_array($textNodes)) {
                foreach ($textNodes as $textNode) {
                    $parts[] = (string) $textNode;
                }
            }
            return implode('', $parts);
        }

        $valueNode = $cell->xpath('./*[local-name()="v"]');
        return is_array($valueNode) && isset($valueNode[0]) ? (string) $valueNode[0] : '';
    }

    private function loadXml(string $xml): ?\SimpleXMLElement
    {
        $previous = libxml_use_internal_errors(true);
        $document = simplexml_load_string($xml);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $document instanceof \SimpleXMLElement ? $document : null;
    }
}
