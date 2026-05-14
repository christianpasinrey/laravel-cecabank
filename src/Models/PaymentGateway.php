<?php

namespace Cpr\Cecabank\Models;

use Cpr\Cecabank\Database\Factories\PaymentGatewayFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PaymentGateway extends Model
{
    /** @use HasFactory<PaymentGatewayFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'provider',
        'merchant_id',
        'acquirer_bin',
        'terminal_id',
        'encryption_key_test',
        'encryption_key_prod',
        'environment',
        'currency',
        'language',
        'is_active',
    ];

    protected $hidden = [
        'encryption_key_test',
        'encryption_key_prod',
    ];

    protected function casts(): array
    {
        return [
            'encryption_key_test' => 'encrypted',
            'encryption_key_prod' => 'encrypted',
            'is_active' => 'boolean',
        ];
    }

    protected static function newFactory(): PaymentGatewayFactory
    {
        return PaymentGatewayFactory::new();
    }

    public function getEncryptionKeyAttribute(): ?string
    {
        return $this->encryption_key_prod ?: $this->encryption_key_test;
    }

    public function getEncryptionKeyForEnvironment(?string $environment = null): ?string
    {
        $env = $environment ?: 'production';

        return $env === 'production'
            ? $this->encryption_key_prod
            : $this->encryption_key_test;
    }

    public function getGatewayUrlForEnvironment(?string $environment = null): string
    {
        $env = $environment ?: 'production';

        return (string) config("cecabank.urls.{$env}");
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(PaymentTransaction::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public static function active(): self
    {
        return self::where('is_active', true)->firstOrFail();
    }

    public function getGatewayUrl(): string
    {
        return (string) config('cecabank.urls.production');
    }
}
