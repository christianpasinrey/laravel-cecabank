<?php

namespace Cpr\Cecabank\Tests\Unit;

use Cpr\Cecabank\CecabankService;
use Cpr\Cecabank\Contracts\Payable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class CecabankSignatureTest extends TestCase
{
    private CecabankService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new CecabankService;
    }

    // ---------------------------------------------------------------------
    // Amount + signature primitives
    // ---------------------------------------------------------------------

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

    // ---------------------------------------------------------------------
    // M-1 regression: operation_number must not collide and must fit varchar(50)
    // ---------------------------------------------------------------------

    public function test_operation_numbers_are_unique_and_fit_column(): void
    {
        // Old format 'OP{id}T{epoch}' collided whenever the same payable was
        // checked-out twice in the same second; the new random suffix must
        // produce 1000 distinct numbers AND fit varchar(50).
        $payable = new TestablePayable(42);
        $seen = [];
        for ($i = 0; $i < 1000; $i++) {
            $op = $this->service->generateOperationNumber($payable);
            $this->assertLessThanOrEqual(50, strlen($op), 'operation_number must fit varchar(50)');
            $this->assertStringStartsWith('OP42-', $op);
            $seen[$op] = true;
        }
        $this->assertCount(1000, $seen, 'all operation numbers must be distinct');
    }

    // ---------------------------------------------------------------------
    // M-4 regression: sanitizeResponse drops everything outside the allow-list
    // ---------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $input
     * @param  array<string, string>  $expected
     */
    #[DataProvider('sensitiveFieldProvider')]
    public function test_sanitize_response_strips_unknown_keys(array $input, array $expected): void
    {
        $this->assertSame($expected, $this->service->sanitizeResponse($input));
    }

    public static function sensitiveFieldProvider(): array
    {
        return [
            'allow-listed Firma kept, PAN-like data stripped' => [
                ['Firma' => 'sig', 'Pan' => '4111111111111111', 'CVV2' => '123', 'Caducidad' => '1230'],
                ['Firma' => 'sig'],
            ],
            'arbitrary unknown fields dropped' => [
                ['hostInjected' => 'evil', 'X-Forwarded-For' => '1.2.3.4'],
                [],
            ],
            'non-scalar values for allow-listed keys are dropped' => [
                ['Firma' => ['nested'], 'Referencia' => (object) ['x' => 1]],
                [],
            ],
            'mixed valid + invalid' => [
                ['MerchantID' => '123', 'leaked' => 'data', 'Num_aut' => '999999'],
                ['MerchantID' => '123', 'Num_aut' => '999999'],
            ],
        ];
    }
}

// Minimal Payable used only for operation-number generation tests.
class TestablePayable implements Payable
{
    public function __construct(private int|string $key) {}

    public function getKey()
    {
        return $this->key;
    }

    public function paymentAmount(): float
    {
        return 1.0;
    }

    public function paymentReference(): string
    {
        return 'REF';
    }

    public function paymentDescription(): ?string
    {
        return null;
    }

    public function isPayable(): bool
    {
        return true;
    }

    public function paymentSuccessRoute(): string
    {
        return 'home';
    }

    public function paymentFailureRoute(): string
    {
        return 'home';
    }
}
