<?php

namespace Cpr\Cecabank\Tests\Unit;

use Cpr\Cecabank\CecabankService;
use PHPUnit\Framework\TestCase;

class CecabankSignatureTest extends TestCase
{
    private CecabankService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new CecabankService;
    }

    public function test_amount_to_cents_converts_correctly(): void
    {
        $this->assertSame('1050', $this->service->amountToCents(10.50));
        $this->assertSame('100', $this->service->amountToCents(1.00));
        $this->assertSame('999', $this->service->amountToCents(9.99));
        $this->assertSame('0', $this->service->amountToCents(0.00));
        $this->assertSame('10000', $this->service->amountToCents(100.00));
    }

    public function test_calculate_signature_returns_sha256_hex_lowercase(): void
    {
        $data = 'test_data_string';
        $this->assertSame(strtolower(hash('sha256', $data)), $this->service->calculateSignature($data));
    }

    public function test_signature_is_deterministic(): void
    {
        $this->assertSame(
            $this->service->calculateSignature('same'),
            $this->service->calculateSignature('same'),
        );
    }

    public function test_different_data_produces_different_signatures(): void
    {
        $this->assertNotSame(
            $this->service->calculateSignature('a'),
            $this->service->calculateSignature('b'),
        );
    }

    public function test_signature_format_is_lowercase_hex_64_chars(): void
    {
        $sig = $this->service->calculateSignature('x');
        $this->assertSame(64, strlen($sig));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $sig);
    }

    public function test_sandbox_operation_number_has_sbx_prefix_and_is_unique(): void
    {
        $a = $this->service->generateSandboxOperationNumber();
        $b = $this->service->generateSandboxOperationNumber();

        $this->assertStringStartsWith('SBX', $a);
        $this->assertNotSame($a, $b);
    }

    public function test_signature_reproduces_manual_example_p13(): void
    {
        // Cecabank manual 8.9 p.13.
        $chain = '99888888'.'111950028'.'0000554052'.'00000003'.'123'.'500'.'978'.'2'.'SHA2'.'http://www.ceca.es'.'http://www.ceca.es';

        $this->assertSame(
            '2b7f686593f1a424c510321e4bc354d21924e02e980a90f4d46c41f92a06f5a9',
            $this->service->calculateSignature($chain),
        );
    }
}
