<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ConnectedDevice extends Model
{
    public const DRIVER_EPSON_EPOS = 'epson_epos';
    public const DRIVER_RAWBT = 'rawbt';

    public function isRawbtPrinter(): bool
    {
        return $this->type === 'receipt_printer' && $this->driver === self::DRIVER_RAWBT;
    }

    protected $guarded = [];

    protected $casts = ['last_tested_at' => 'datetime', 'settings' => 'array'];

    public function store()
    {
        return $this->belongsTo(Store::class);
    }

    public function isEpsonPrinter(): bool
    {
        return $this->type === 'receipt_printer' && $this->driver === self::DRIVER_EPSON_EPOS && filled($this->settings['host'] ?? null);
    }

    /** 42 karakter untuk kertas 80 mm (Font A TM-T82/TM-m30), 32 karakter untuk 58 mm. */
    public function eposColumns(): int
    {
        $columns = (int) ($this->settings['columns'] ?? 0);

        return $columns >= 24 && $columns <= 64 ? $columns : ((int) ($this->settings['paper'] ?? 80) === 58 ? 32 : 42);
    }

    /**
     * Alamat layanan ePOS-Print di printer. Browser mengirim XML langsung ke
     * printer, sehingga aplikasi HTTPS membutuhkan printer dengan HTTPS aktif.
     */
    public function eposUrl(): string
    {
        $settings = $this->settings ?? [];
        $scheme = ! empty($settings['https']) ? 'https' : 'http';
        $port = ! empty($settings['port']) ? ':'.(int) $settings['port'] : '';
        $deviceId = rawurlencode($settings['device_id'] ?? 'local_printer') ?: 'local_printer';

        return $scheme.'://'.$settings['host'].$port.'/cgi-bin/epos/service.cgi?devid='.$deviceId.'&timeout='.$this->eposTimeout();
    }

    public function eposTimeout(): int
    {
        return max(3000, min(60000, (int) ($this->settings['timeout'] ?? 10000)));
    }

    /** Konfigurasi yang dibaca epos-printer.js di layar kasir, struk, dan pengaturan. */
    public function eposConfig(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'url' => $this->eposUrl(),
            'timeout' => $this->eposTimeout(),
            'columns' => $this->eposColumns(),
            'auto_print' => (bool) ($this->settings['auto_print'] ?? true),
            'https' => ! empty($this->settings['https']),
        ];
    }
}
