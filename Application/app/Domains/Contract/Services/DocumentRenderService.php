<?php

namespace App\Domains\Contract\Services;

use App\Domains\Contract\Support\ReservedReplaceCodes;
use App\Models\ContractItem;
use App\Models\ItemDocument;
use App\Support\TaxPrice;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Font;
use PhpOffice\PhpSpreadsheet\Style\Style;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use RuntimeException;

class DocumentRenderService
{
    /**
     * @return array{binary: string, html: string, values: array<string, string>}
     */
    public function renderPdf(ContractItem $line, ItemDocument $template): array
    {
        $values = $this->buildReplaceMap($line);
        $html = $this->templateToHtml($template, $values);
        $pdf = $this->htmlToPdf($html);

        return [
            'binary' => $pdf,
            'html' => $html,
            'values' => $values,
        ];
    }

    /**
     * @return array<string, string>
     */
    public function buildReplaceMap(ContractItem $line): array
    {
        $line->loadMissing([
            'item',
            'dataRows.dataFieldName',
            'contract.dataRows.dataFieldName',
            'contract.customer',
            'contract.site',
            'contract.owningBp',
        ]);

        $contract = $line->contract;
        $bp = $contract?->owningBp;
        $customer = $contract?->customer;
        $site = $contract?->site;
        $item = $line->item;

        $price = (int) ($line->unit_price ?? 0);
        $part = (int) ($line->partition_price ?? 0);
        $rate = (int) ($line->tax_rate ?? 10);
        $priceIn = TaxPrice::inclusive($price, $rate);
        $partIn = TaxPrice::inclusive($part, $rate);
        $tax = $priceIn - $price;
        $partTax = $partIn - $part;

        $map = [
            'bp_name' => (string) ($bp?->name ?? ''),
            'bp_code' => (string) ($bp?->code ?? ''),
            'bp_addr' => $this->formatAddress($bp?->postal_code, $bp?->address, $bp?->building_name),
            'bp_tel' => (string) ($bp?->phone ?? ''),
            'bp_mail' => (string) ($bp?->email ?? ''),
            'c_name' => (string) ($customer?->name ?? ''),
            'c_code' => (string) ($customer?->code ?? ''),
            'c_addr' => $this->formatAddress($customer?->postal_code, $customer?->address, $customer?->building_name),
            'c_tel' => (string) ($customer?->phone ?? ''),
            'c_mail' => (string) ($customer?->email ?? ''),
            's_name' => (string) ($site?->name ?? ''),
            's_addr' => $this->formatAddress($site?->postal_code, $site?->address, $site?->building_name),
            's_tel' => (string) ($site?->phone ?? ''),
            'sb_name' => trim(implode(' ', array_filter([(string) ($site?->billing_name ?? ''), (string) ($site?->billing_department ?? '')]))),
            'sb_addr' => $this->formatAddress($site?->billing_postal_code, $site?->billing_address, $site?->billing_building_name),
            'sb_tel' => (string) ($site?->billing_phone ?? ''),
            'ctr' => (string) ($contract?->code ?? ''),
            'icode' => (string) ($item?->code ?? ''),
            'iname' => (string) ($item?->name ?? ''),
            'price' => (string) $price,
            'price_in' => (string) $priceIn,
            'tax' => (string) $tax,
            'rate' => (string) $rate,
            'part' => (string) $part,
            'part_in' => (string) $partIn,
            'part_tax' => (string) $partTax,
        ];

        foreach ($contract?->dataRows ?? [] as $row) {
            $this->applyDataRowToMap($map, $row);
        }

        foreach ($line->dataRows as $row) {
            $this->applyDataRowToMap($map, $row);
        }

        return $map;
    }

    /**
     * @param  array<string, string>  $map
     */
    private function applyDataRowToMap(array &$map, object $row): void
    {
        $code = $row->replace_code ?: $row->dataFieldName?->replace_code;
        if (! is_string($code) || $code === '' || ReservedReplaceCodes::isReserved($code)) {
            return;
        }
        $map[$code] = (string) $row->value;
    }

    /**
     * @param  array<string, string>  $values
     */
    public function replacePlaceholders(string $content, array $values): string
    {
        return (string) preg_replace_callback(
            '/\{\{\s*([a-z][a-z0-9_]*)\s*\}\}/',
            function (array $matches) use ($values): string {
                return $values[$matches[1]] ?? '';
            },
            $content
        );
    }

    /**
     * @param  array<string, string>  $values
     */
    public function templateToHtml(ItemDocument $template, array $values): string
    {
        if (! Storage::disk('local')->exists($template->file_path)) {
            throw new RuntimeException('テンプレートファイルが見つかりません。');
        }

        $absolute = Storage::disk('local')->path($template->file_path);
        $extension = strtolower(pathinfo($template->original_name ?: $template->file_path, PATHINFO_EXTENSION));

        $body = match ($extension) {
            'html', 'htm' => $this->replacePlaceholders(
                Storage::disk('local')->get($template->file_path) ?? '',
                $values
            ),
            'xml' => $this->xmlToHtml(
                $this->replacePlaceholders(Storage::disk('local')->get($template->file_path) ?? '', $values)
            ),
            'xls', 'xlsx' => $this->excelToHtml($absolute, $values),
            default => throw new RuntimeException("未対応のテンプレート形式です: {$extension}"),
        };

        if (! str_contains(strtolower($body), '<html')) {
            $body = '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body>'.$body.'</body></html>';
        }

        return $body;
    }

    public function htmlToPdf(string $html): string
    {
        $fontFile = storage_path('fonts/NotoSansJP-Regular.ttf');
        $options = new Options;
        $options->set('isRemoteEnabled', true);
        $options->set('isHtml5ParserEnabled', true);
        $options->set('defaultFont', 'DejaVu Sans');
        $options->setChroot([base_path(), storage_path('fonts')]);
        $options->setFontDir(storage_path('fonts'));
        $options->setFontCache(storage_path('fonts'));

        $dompdf = new Dompdf($options);
        $styled = $this->injectDefaultFont($html, is_file($fontFile) ? $fontFile : null);
        $dompdf->loadHtml($styled, 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        return $dompdf->output() ?? '';
    }

    /**
     * @param  array<string, string>  $values
     */
    private function excelToHtml(string $absolutePath, array $values): string
    {
        $spreadsheet = IOFactory::load($absolutePath);
        $parts = [];
        $sheetCount = $spreadsheet->getSheetCount();

        foreach ($spreadsheet->getWorksheetIterator() as $sheet) {
            if ($sheetCount > 1) {
                $parts[] = '<h2 style="font-size:12pt;margin:0 0 8pt;">'.e($sheet->getTitle()).'</h2>';
            }

            [$maxRow, $maxCol] = $this->excelUsedBounds($sheet);
            if ($maxRow < 1 || $maxCol < 1) {
                continue;
            }

            $mergeMap = $this->excelMergeMap($sheet);
            $colWidths = $this->excelColumnWidthPercents($sheet, $maxCol);

            $parts[] = '<table style="border-collapse:separate;border-spacing:0;table-layout:fixed;width:100%;font-size:11pt;">';
            $parts[] = '<colgroup>';
            for ($col = 1; $col <= $maxCol; $col++) {
                $parts[] = '<col style="width:'.$colWidths[$col].'%;">';
            }
            $parts[] = '</colgroup>';

            for ($row = 1; $row <= $maxRow; $row++) {
                $rowHeight = $sheet->getRowDimension($row)->getRowHeight();
                $trStyle = '';
                if (is_numeric($rowHeight) && (float) $rowHeight > 0) {
                    $trStyle = ' style="height:'.((float) $rowHeight).'pt;"';
                }
                $parts[] = '<tr'.$trStyle.'>';

                for ($col = 1; $col <= $maxCol; $col++) {
                    $coord = Coordinate::stringFromColumnIndex($col).$row;
                    if (isset($mergeMap['covered'][$coord])) {
                        continue;
                    }

                    $cell = $sheet->getCell($coord);
                    $style = $sheet->getStyle($coord);
                    $colspan = 1;
                    $rowspan = 1;
                    $mergeEndCol = $col;
                    $mergeEndRow = $row;
                    if (isset($mergeMap['starts'][$coord])) {
                        $colspan = $mergeMap['starts'][$coord]['colspan'];
                        $rowspan = $mergeMap['starts'][$coord]['rowspan'];
                        $mergeEndCol = $col + $colspan - 1;
                        $mergeEndRow = $row + $rowspan - 1;
                    }

                    $raw = (string) ($cell->getCalculatedValue() ?? '');
                    $text = $this->replacePlaceholders($raw, $values);
                    $css = $this->excelCellCss(
                        $style,
                        $sheet,
                        $col,
                        $row,
                        $mergeEndCol,
                        $mergeEndRow,
                    );
                    $attr = '';
                    if ($colspan > 1) {
                        $attr .= ' colspan="'.$colspan.'"';
                    }
                    if ($rowspan > 1) {
                        $attr .= ' rowspan="'.$rowspan.'"';
                    }
                    if ($css !== '') {
                        $attr .= ' style="'.$css.'"';
                    }

                    $parts[] = '<td'.$attr.'>'.($text === '' ? '&nbsp;' : nl2br(e($text))).'</td>';
                }

                $parts[] = '</tr>';
            }

            $parts[] = '</table>';
        }

        $spreadsheet->disconnectWorksheets();

        return implode('', $parts);
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function excelUsedBounds(Worksheet $sheet): array
    {
        $maxRow = max(1, (int) $sheet->getHighestDataRow());
        $maxCol = max(1, Coordinate::columnIndexFromString($sheet->getHighestDataColumn() ?: 'A'));

        foreach ($sheet->getMergeCells() as $range) {
            [$start, $end] = Coordinate::rangeBoundaries($range);
            $maxCol = max($maxCol, (int) $end[0]);
            $maxRow = max($maxRow, (int) $end[1]);
        }

        return [$maxRow, $maxCol];
    }

    /**
     * @return array{starts: array<string, array{colspan: int, rowspan: int}>, covered: array<string, true>}
     */
    private function excelMergeMap(Worksheet $sheet): array
    {
        $starts = [];
        $covered = [];

        foreach ($sheet->getMergeCells() as $range) {
            [$rangeStart, $rangeEnd] = Coordinate::getRangeBoundaries($range);
            $startCol = Coordinate::columnIndexFromString($rangeStart[0]);
            $startRow = (int) $rangeStart[1];
            $endCol = Coordinate::columnIndexFromString($rangeEnd[0]);
            $endRow = (int) $rangeEnd[1];
            $startCoord = $rangeStart[0].$rangeStart[1];

            $starts[$startCoord] = [
                'colspan' => $endCol - $startCol + 1,
                'rowspan' => $endRow - $startRow + 1,
            ];

            for ($row = $startRow; $row <= $endRow; $row++) {
                for ($col = $startCol; $col <= $endCol; $col++) {
                    $coord = Coordinate::stringFromColumnIndex($col).$row;
                    if ($coord === $startCoord) {
                        continue;
                    }
                    $covered[$coord] = true;
                }
            }
        }

        return ['starts' => $starts, 'covered' => $covered];
    }

    /**
     * @return array<int, float>
     */
    private function excelColumnWidthPercents(Worksheet $sheet, int $maxCol): array
    {
        $widths = [];
        $total = 0.0;
        for ($col = 1; $col <= $maxCol; $col++) {
            $width = (float) $sheet->getColumnDimensionByColumn($col)->getWidth();
            if ($width <= 0) {
                $width = 8.43;
            }
            $widths[$col] = $width;
            $total += $width;
        }

        $percents = [];
        foreach ($widths as $col => $width) {
            $percents[$col] = round(($width / max($total, 0.001)) * 100, 3);
        }

        return $percents;
    }

    private function excelCellCss(
        Style $style,
        Worksheet $sheet,
        int $startCol,
        int $startRow,
        int $endCol,
        int $endRow,
    ): string {
        $rules = [
            'padding:2pt 3pt',
            'vertical-align:'.$this->excelVerticalAlign($style->getAlignment()->getVertical()),
            'text-align:'.$this->excelHorizontalAlign($style->getAlignment()->getHorizontal()),
            'overflow:hidden',
            'word-wrap:break-word',
        ];

        $font = $style->getFont();
        $size = $font->getSize();
        if (is_numeric($size) && (float) $size > 0) {
            $rules[] = 'font-size:'.((float) $size).'pt';
        }
        if ($font->getBold()) {
            $rules[] = 'font-weight:bold';
        }
        if ($font->getItalic()) {
            $rules[] = 'font-style:italic';
        }
        if ($font->getUnderline() && $font->getUnderline() !== Font::UNDERLINE_NONE) {
            $rules[] = 'text-decoration:underline';
        }
        $fontColor = $font->getColor()->getRGB();
        if (is_string($fontColor) && $fontColor !== '' && strtoupper($fontColor) !== '000000') {
            $rules[] = 'color:#'.$fontColor;
        }

        $fill = $style->getFill();
        if ($fill->getFillType() && $fill->getFillType() !== Fill::FILL_NONE) {
            $rgb = $fill->getStartColor()->getRGB();
            if (is_string($rgb) && $rgb !== '') {
                $rules[] = 'background-color:#'.$rgb;
            }
        }

        foreach ($this->excelPerimeterBorders($sheet, $startCol, $startRow, $endCol, $endRow) as $side => $borderCss) {
            $rules[] = 'border-'.$side.':'.$borderCss;
        }

        return implode(';', $rules);
    }

    /**
     * 結合セルでは罫線が外周の各セルに分散しているため、辺ごとに集約する。
     *
     * @return array<string, string>
     */
    private function excelPerimeterBorders(
        Worksheet $sheet,
        int $startCol,
        int $startRow,
        int $endCol,
        int $endRow,
    ): array {
        $sides = [
            'top' => null,
            'right' => null,
            'bottom' => null,
            'left' => null,
        ];

        for ($col = $startCol; $col <= $endCol; $col++) {
            $top = $this->excelBorderCss(
                $sheet->getStyle(Coordinate::stringFromColumnIndex($col).$startRow)->getBorders()->getTop()
            );
            $bottom = $this->excelBorderCss(
                $sheet->getStyle(Coordinate::stringFromColumnIndex($col).$endRow)->getBorders()->getBottom()
            );
            $sides['top'] = $this->preferBorderCss($sides['top'], $top);
            $sides['bottom'] = $this->preferBorderCss($sides['bottom'], $bottom);
        }

        for ($row = $startRow; $row <= $endRow; $row++) {
            $left = $this->excelBorderCss(
                $sheet->getStyle(Coordinate::stringFromColumnIndex($startCol).$row)->getBorders()->getLeft()
            );
            $right = $this->excelBorderCss(
                $sheet->getStyle(Coordinate::stringFromColumnIndex($endCol).$row)->getBorders()->getRight()
            );
            $sides['left'] = $this->preferBorderCss($sides['left'], $left);
            $sides['right'] = $this->preferBorderCss($sides['right'], $right);
        }

        return array_filter($sides, fn ($css) => is_string($css) && $css !== '');
    }

    private function preferBorderCss(?string $current, ?string $candidate): ?string
    {
        if ($candidate === null || $candidate === '') {
            return $current;
        }
        if ($current === null || $current === '') {
            return $candidate;
        }

        // より太い線を優先
        $currentWidth = (float) $current;
        $candidateWidth = (float) $candidate;

        return $candidateWidth > $currentWidth ? $candidate : $current;
    }

    private function excelBorderCss(Border $border): ?string
    {
        $style = $border->getBorderStyle();
        if ($style === Border::BORDER_NONE || $style === Border::BORDER_OMIT || $style === null || $style === '') {
            return null;
        }

        $width = match ($style) {
            Border::BORDER_HAIR, Border::BORDER_DOTTED, Border::BORDER_DASHED => '0.5pt',
            Border::BORDER_MEDIUM, Border::BORDER_MEDIUMDASHED, Border::BORDER_MEDIUMDASHDOT,
            Border::BORDER_SLANTDASHDOT => '1.5pt',
            Border::BORDER_THICK, Border::BORDER_MEDIUMDASHDOTDOT => '2pt',
            Border::BORDER_DOUBLE => '2.5pt',
            default => '1pt',
        };

        $line = match ($style) {
            Border::BORDER_DOTTED => 'dotted',
            Border::BORDER_DASHED, Border::BORDER_MEDIUMDASHED, Border::BORDER_DASHDOT,
            Border::BORDER_DASHDOTDOT, Border::BORDER_MEDIUMDASHDOT, Border::BORDER_MEDIUMDASHDOTDOT,
            Border::BORDER_SLANTDASHDOT => 'dashed',
            Border::BORDER_DOUBLE => 'double',
            default => 'solid',
        };

        $color = $border->getColor()->getRGB() ?: '000000';

        return $width.' '.$line.' #'.$color;
    }

    private function excelHorizontalAlign(?string $align): string
    {
        return match ($align) {
            Alignment::HORIZONTAL_CENTER,
            Alignment::HORIZONTAL_CENTER_CONTINUOUS => 'center',
            Alignment::HORIZONTAL_RIGHT => 'right',
            Alignment::HORIZONTAL_JUSTIFY, Alignment::HORIZONTAL_DISTRIBUTED => 'justify',
            default => 'left',
        };
    }

    private function excelVerticalAlign(?string $align): string
    {
        return match ($align) {
            Alignment::VERTICAL_TOP => 'top',
            Alignment::VERTICAL_BOTTOM => 'bottom',
            Alignment::VERTICAL_JUSTIFY, Alignment::VERTICAL_DISTRIBUTED => 'middle',
            default => 'middle',
        };
    }

    private function xmlToHtml(string $xml): string
    {
        return '<pre style="white-space:pre-wrap;font-family:NotoSansJP,sans-serif;font-size:10pt;">'.e($xml).'</pre>';
    }

    private function injectDefaultFont(string $html, ?string $fontFile): string
    {
        $face = '';
        if ($fontFile !== null) {
            $face = '@font-face{font-family:NotoSansJP;font-style:normal;font-weight:normal;src:url(\'file://'
                .str_replace('\\', '/', $fontFile)
                .'\') format(\'truetype\');}';
        }
        $style = '<style>'.$face
            .'html,body{margin:0;padding:0;font-family:NotoSansJP, DejaVu Sans, sans-serif;font-size:11pt;}'
            .'table{border-collapse:collapse;}'
            .'td,th{font-family:NotoSansJP, DejaVu Sans, sans-serif;}'
            .'</style>';
        if (stripos($html, '</head>') !== false) {
            return (string) preg_replace('/<\/head>/i', $style.'</head>', $html, 1);
        }

        return '<!DOCTYPE html><html><head><meta charset="UTF-8">'.$style.'</head><body>'.$html.'</body></html>';
    }

    private function formatAddress(?string $postal, ?string $address, ?string $building): string
    {
        $parts = [];
        if (filled($postal)) {
            $parts[] = '〒'.$postal;
        }
        if (filled($address)) {
            $parts[] = $address;
        }
        if (filled($building)) {
            $parts[] = $building;
        }

        return implode(' ', $parts);
    }
}
