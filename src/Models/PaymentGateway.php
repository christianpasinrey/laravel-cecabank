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

    /**
     * Encryption key currently in use for this gateway, picked from its
     * declared `environment`. `test` -> test key, `production` -> prod key.
     */
    public function getEncryptionKeyAttribute(): ?string
    {
        return $this->getEncryptionKeyForEnvironment($this->environment);
    }

    public function getEncryptionKeyForEnvironment(?string $environment = null): ?string
    {
        $env = $environment ?: $this->environment ?: 'production';

        return $env === 'production'
            ? $this->encryption_key_prod
            : $this->encryption_key_test;
    }

    public function getGatewayUrlForEnvironment(?string $environment = null): string
    {
        $env = $environment ?: $this->environment ?: 'production';

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

    /**
     * Resolve the single active Cecabank gateway. Uses `sole()` so a
     * mis-seeded database with two active rows fails loud instead of
     * silently picking the first one (which would route money to the
     * wrong merchant and sign with the wrong key).
     */
    public static function active(): self
    {
        return self::where('provider', 'cecabank')
            ->where('is_active', true)
            ->sole();
    }

    /**
     * Live gateway URL — honors this gateway's `environment`.
     */
    public function getGatewayUrl(): string
    {
        return $this->getGatewayUrlForEnvironment($this->environment);
    }
}
