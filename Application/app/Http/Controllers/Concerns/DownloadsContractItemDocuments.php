<?php

namespace App\Http\Controllers\Concerns;

use App\Models\ContractItemDocument;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

trait DownloadsContractItemDocuments
{
    protected function streamContractItemDocument(ContractItemDocument $document): StreamedResponse
    {
        if (! $document->file_path || ! Storage::disk('local')->exists($document->file_path)) {
            throw new NotFoundHttpException('ドキュメントファイルが見つかりません。再生成するか、一覧から削除してください。');
        }

        $base = trim((string) $document->title);
        if ($base === '') {
            $base = 'document';
        }
        $base = preg_replace('/[\\\\\\/:*?"<>|]+/u', '_', $base) ?: 'document';
        $base = pathinfo($base, PATHINFO_FILENAME) ?: 'document';
        $filename = $base.'.pdf';

        return Storage::disk('local')->download($document->file_path, $filename);
    }

    /**
     * @param  iterable<int, \App\Models\ContractItem>  $items
     * @return array<int, bool>
     */
    protected function contractDocumentFileMissingMap(iterable $items): array
    {
        $missing = [];
        foreach ($items as $line) {
            foreach ($line->documents as $document) {
                $missing[$document->id] = ! $document->file_path
                    || ! Storage::disk('local')->exists($document->file_path);
            }
        }

        return $missing;
    }
}
