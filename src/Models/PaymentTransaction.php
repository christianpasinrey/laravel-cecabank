<?php

namespace Cpr\Cecabank\Models;

use Cpr\Cecabank\Contracts\Payable;
use Cpr\Cecabank\Database\Factories\PaymentTransactionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class PaymentTransaction extends Model
{
    /** @use HasFactory<PaymentTransactionFactory> */
    use HasFactory;

    protected $fillable = [
        'payable_type',
        'payable_id',
        'payment_gateway_id',
        'operation_number',
        'amount',
        'status',
        'environment',
        'is_sandbox',
        'authorization_number',
        'reference',
        'signature_sent',
        'signature_response',
        'raw_request',
        'raw_response',
        'error_message',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'raw_request' => 'array',
            'raw_response' => 'array',
            'completed_at' => 'datetime',
            'amount' => 'decimal:2',
            'is_sandbox' => 'boolean',
        ];
    }

    protected static function newFactory(): PaymentTransactionFactory
    {
        return PaymentTransactionFactory::new();
    }

    public function scopeSandbox($query)
    {
        return $query->where('is_sandbox', true);
    }

    /**
     * Polymorphic link to whatever the host calls "payable" (Order, Invoice…).
     */
    public function payable(): MorphTo
    {
        return $this->morphTo();
    }

    public function payableRecord(): ?Payable
    {
        $record = $this->payable;

        return $record instanceof Payable ? $record : null;
    }

    public function paymentGateway(): BelongsTo
    {
        return $this->belongsTo(PaymentGateway::class);
    }
}
