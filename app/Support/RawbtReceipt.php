<?php

namespace App\Support;

final class RawbtReceipt
{
    public static function uri(array $jobs): string
    {
        $bytes = "\x1b@\x1bM\x00";
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
