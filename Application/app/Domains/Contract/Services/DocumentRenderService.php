<?php

namespace App\Domains\Contract\Services;

use App\Domains\Contract\Support\ReservedReplaceCodes;
use App\Models\ContractItem;
use App\Models\ItemDocument;
use App\Support\TaxPrice;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;
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

        foreach ($line->dataRows as $row) {
            $code = $row->replace_code ?: $row->dataFieldName?->replace_code;
            if (! is_string($code) || $code === '' || ReservedReplaceCodes::isReserved($code)) {
                continue;
            }
            $map[$code] = (string) $row->value;
        }

        return $map;
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
        $rows = [];
        foreach ($spreadsheet->getWorksheetIterator() as $sheet) {
            $rows[] = '<h2>'.e($sheet->getTitle()).'</h2><table border="1" cellpadding="4" cellspacing="0" width="100%">';
            foreach ($sheet->toArray(null, true, true, false) as $row) {
                $rows[] = '<tr>';
                foreach ($row as $cell) {
                    $text = $this->replacePlaceholders((string) ($cell ?? ''), $values);
                    $rows[] = '<td>'.nl2br(e($text)).'</td>';
                }
                $rows[] = '</tr>';
            }
            $rows[] = '</table>';
        }
        $spreadsheet->disconnectWorksheets();

        return implode('', $rows);
    }

    private function xmlToHtml(string $xml): string
    {
        return '<pre style="white-space:pre-wrap;font-family:NotoSansJP,sans-serif;">'.e($xml).'</pre>';
    }

    private function injectDefaultFont(string $html, ?string $fontFile): string
    {
        $face = '';
        if ($fontFile !== null) {
            $face = '@font-face{font-family:NotoSansJP;font-style:normal;font-weight:normal;src:url(\'file://'
                .str_replace('\\', '/', $fontFile)
                .'\') format(\'truetype\');}';
        }
        $style = '<style>'.$face.'body,table,td,th,p,div,span,h1,h2,h3,h4,h5,h6,pre{font-family:NotoSansJP, DejaVu Sans, sans-serif;}</style>';
        if (stripos($html, '</head>') !== false) {
            return (string) preg_replace('/<\/head>/i', $style.'</head>', $html, 1);
        }

        return $style.$html;
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
