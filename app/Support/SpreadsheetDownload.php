<?php

namespace App\Support;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Workbook ditulis lengkap ke storage sebelum header dikirim. Bila penulisan
 * gagal, pengguna melihat pesan error, bukan file .xlsx rusak; ukuran file juga
 * dikirim lewat Content-Length agar unduhan di browser HP tidak terpotong.
 */
final class SpreadsheetDownload
{
    public static function response(Spreadsheet $spreadsheet, string $filename): StreamedResponse
    {
        $directory = storage_path('app/exports');
        File::ensureDirectoryExists($directory);
        self::pruneStaleFiles($directory);

        $path = $directory.DIRECTORY_SEPARATOR.Str::uuid().'.xlsx';
        try {
            (new Xlsx($spreadsheet))->save($path);
        } finally {
            $spreadsheet->disconnectWorksheets();
        }
        $size = (int) filesize($path);

        return response()->streamDownload(function () use ($path) {
            try {
                readfile($path);
            } finally {
                File::delete($path);
            }
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Length' => (string) $size,
            'Cache-Control' => 'no-store, no-cache',
        ]);
    }

    /** File yang tidak sempat terkirim (koneksi putus) dibersihkan pada ekspor berikutnya. */
    private static function pruneStaleFiles(string $directory): void
    {
        foreach (File::files($directory) as $file) {
            if ($file->getExtension() === 'xlsx' && $file->getMTime() < now()->subHour()->getTimestamp()) {
                File::delete($file->getPathname());
            }
        }
    }
}
