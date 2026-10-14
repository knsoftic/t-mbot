<?php

namespace App\Http\Controllers;

use App\Models\MemberImport;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Member import downloads: the per-person report and a sample list.
 */
class MemberImportFileController extends Controller
{
    /**
     * The import's result as CSV (opens in Excel).
     */
    public function export(MemberImport $import): StreamedResponse
    {
        return response()->streamDownload(function () use ($import) {
            $out = fopen('php://output', 'wb');
            fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM for Excel
            fputcsv($out, ['row', 'input', 'user_id', 'username', 'status', 'reason', 'processed_at'], ',', '"', '');

            $import->rows()->orderBy('row_number')->chunk(1000, function ($rows) use ($out) {
                foreach ($rows as $row) {
                    fputcsv($out, [
                        $row->row_number,
                        $this->safe($row->input),
                        $row->telegram_user_id,
                        $row->username ?? '',
                        $row->status->label(),
                        $this->safe((string) $row->reason),
                        $row->processed_at?->toIso8601String(),
                    ], ',', '"', '');
                }
            });

            fclose($out);
        }, "member-import-{$import->id}.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function sample(): Response
    {
        $csv = "username,user_id\n@example_user,\n,123456789\nt.me/another_user,\n";

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="member-list-sample.csv"',
        ]);
    }

    /**
     * Stop spreadsheet apps from running text that starts like a formula.
     */
    private function safe(string $value): string
    {
        return preg_match('/^[=+\-@\t\r]/', $value) ? "'".$value : $value;
    }
}
