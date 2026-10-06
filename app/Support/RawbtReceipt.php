<?php

namespace App\Support;

use App\Models\Store;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

final class RawbtReceipt
{
    public static function logo(Store $store): ?string
    {
        if (! $store->receipt_show_logo || ! $store->logo_path) {
            return null;
        }
        if (! function_exists('imagecreatefromstring')) {
            throw new RuntimeException('Logo belum dapat disiapkan. Aktifkan ekstensi GD PHP di server.');
        }
        $disk = Storage::disk('public');
        $root = realpath($disk->path(''));
        $path = realpath($disk->path($store->logo_path));
        if (! $root || ! $path || ! str_starts_with($path, $root.DIRECTORY_SEPARATOR) || ! is_file($path)) {
            throw new RuntimeException('File logo tidak ditemukan. Unggah ulang logo di pengaturan branding.');
        }
        $info = @getimagesize($path);
        if (! $info || $info[0] * $info[1] > 16000000 || filesize($path) > 2097152) {
            throw new RuntimeException('Logo terlalu besar atau formatnya tidak didukung. Gunakan PNG atau JPG maksimal 2 MB.');
        }
        $source = @imagecreatefromstring(file_get_contents($path));
        if (! $source) {
            throw new RuntimeException('Format logo tidak didukung untuk RawBT. Gunakan PNG atau JPG.');
        }
        // A compact bitmap keeps Android intent URLs small and fits the 384-dot print head.
        $scale = min(128 / imagesx($source), 96 / imagesy($source), 1);
        $width = max(1, (int) round(imagesx($source) * $scale));
        $height = max(1, (int) round(imagesy($source) * $scale));
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, imagecolorallocate($image, 255, 255, 255));
        imagealphablending($image, true);
        imagecopyresampled($image, $source, 0, 0, 0, 0, $width, $height, imagesx($source), imagesy($source));
        $rowBytes = (int) ceil($width / 8);
        $raster = '';
        for ($y = 0; $y < $height; $y++) {
            for ($byte = 0; $byte < $rowBytes; $byte++) {
                $value = 0;
                for ($bit = 0; $bit < 8; $bit++) {
                    $x = $byte * 8 + $bit;
                    if ($x >= $width) {
                        continue;
                    }
                    $rgb = imagecolorat($image, $x, $y);
                    $luminance = (($rgb >> 16) & 255) * 0.299 + (($rgb >> 8) & 255) * 0.587 + ($rgb & 255) * 0.114;
                    if ($luminance < 180) {
                        $value |= 128 >> $bit;
                    }
                }
                $raster .= chr($value);
            }
        }
        imagedestroy($source);
        imagedestroy($image);

        return "\x1ba\x01\x1dv0\x00".pack('vv', $rowBytes, $height).$raster."\n\x1ba\x00";
    }

    public static function uri(array $jobs, ?string $logo = null): string
    {
        $bytes = "\x1b@\x1bM\x00".($logo ?? '');
        foreach ($jobs as $commands) {
            foreach ($commands as $command) {
                if ($command['type'] === 'text') {
                    $align = match ($command['align'] ?? 'left') {
                        'center' => 1,
                        'right' => 2,
                        default => 0,
                    };
                    // Double height keeps all 32 columns available on 58 mm paper.
                    $size = ! empty($command['double']) || ! empty($command['tall']) ? 1 : 0;
                    $text = preg_replace('/[^\x20-\x7e]/', '', $command['text'] ?? '');
                    $bytes .= "\x1ba".chr($align)."\x1bE".chr(! empty($command['bold']) ? 1 : 0)."\x1d!".chr($size).$text."\n";
                } elseif ($command['type'] === 'feed') {
                    $bytes .= str_repeat("\n", max(0, min(10, (int) ($command['lines'] ?? 1))));
                }
            }
        }
        // BT-58D uses a manual tear bar; omit cutter and drawer commands.
        $bytes .= "\x1b@";

        return 'intent:base64,'.base64_encode($bytes).'#Intent;scheme=rawbt;package=ru.a402d.rawbtprinter;end;';
    }
}
