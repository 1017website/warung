<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Reservasi meja per cabang. DP diterima saat reservasi dibuat dan memotong
 * tagihan ketika reservasi dibuka di Kasir (pembayaran bermetode `dp`).
 */
class Reservation extends Model
{
    use SoftDeletes;

    public const STATUSES = [
        'booked' => 'Dipesan',
        'arrived' => 'Tamu datang',
        'completed' => 'Selesai',
        'cancelled' => 'Batal',
        'no_show' => 'Tidak datang',
    ];

    /** Status yang masih dapat dibuka di Kasir. */
    public const OPEN_STATUSES = ['booked', 'arrived'];

    public const DP_METHODS = ['cash' => 'Tunai', 'qris' => 'QRIS', 'transfer' => 'Transfer', 'debit' => 'Kartu debit'];

    protected $guarded = [];

    protected $casts = [
        'reserved_at' => 'datetime',
        'dp_paid_at' => 'datetime',
        'dp_refunded_at' => 'datetime',
        'arrived_at' => 'datetime',
        'closed_at' => 'datetime',
        'dp_amount' => 'decimal:2',
        'dp_used' => 'decimal:2',
        'dp_refunded' => 'boolean',
    ];

    public function store()
    {
        return $this->belongsTo(Store::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    public function transaction()
    {
        return $this->belongsTo(Transaction::class);
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN_STATUSES, true);
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }

    /** Data ringkas yang dipakai Kasir untuk memotong DP. */
    public function posData(): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->customer_name,
            'phone' => $this->phone,
            'time' => $this->reserved_at->format('H:i'),
            'date' => $this->reserved_at->format('d/m'),
            'guests' => $this->guests,
            'table' => $this->table_number,
            'notes' => $this->notes,
            'dp' => (float) $this->dp_amount,
            'status' => $this->status,
            'pending_id' => $this->transaction?->status === 'pending' ? $this->transaction_id : null,
        ];
    }
}
