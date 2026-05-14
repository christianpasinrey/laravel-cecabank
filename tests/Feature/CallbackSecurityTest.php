<?php

namespace Cpr\Cecabank\Tests\Feature;

use Cpr\Cecabank\CecabankService;
use Cpr\Cecabank\Events\PaymentCompleted;
use Cpr\Cecabank\Events\PaymentFailed;
use Cpr\Cecabank\Models\PaymentGateway;
use Cpr\Cecabank\Models\PaymentTransaction;
use Cpr\Cecabank\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

class CallbackSecurityTest extends TestCase
{
    use RefreshDatabase;

    // -------------------------------------------------------------------
    // C-1: /payment/callback must NOT be CSRF-protected (Cecabank can't
    // send a token; legitimate confirmations would all 419 otherwise).
    // -------------------------------------------------------------------

    public function test_callback_accepts_post_without_csrf_token(): void
    {
        // No session, no _token, no X-CSRF-TOKEN — exactly how Cecabank calls.
        $response = $this->postJson('/payment/callback', []);

        $response->assertStatus(200);
        $response->assertSee('$*$NOK$*$');
        $this->assertNotEquals(419, $response->getStatusCode(), 'callback must not return 419');
    }

    public function test_success_and_failure_accept_post_without_csrf_token(): void
    {
        $this->postJson('/payment/success', [])->assertStatus(302);
        $this->postJson('/payment/failure', [])->assertStatus(302);
    }

    // -------------------------------------------------------------------
    // C-2: race-safe state transitions. PaymentCompleted must fire exactly
    // once even when the callback is replayed back-to-back.
    // -------------------------------------------------------------------

    public function test_completed_callback_is_idempotent_and_fires_event_once(): void
    {
        Event::fake([PaymentCompleted::class]);

        [$gateway, $tx] = $this->seedPendingTransaction();

        $params = $this->validCallbackParams($gateway, $tx);

        $first = $this->postJson('/payment/callback', $params);
        $second = $this->postJson('/payment/callback', $params);

        $first->assertStatus(200)->assertSee('$*$OKY$*$');
        $second->assertStatus(200)->assertSee('$*$OKY$*$');

        Event::assertDispatchedTimes(PaymentCompleted::class, 1);
        $this->assertSame('completed', $tx->fresh()->status);
    }

    public function test_callback_with_invalid_signature_marks_failed(): void
    {
        Event::fake([PaymentFailed::class]);

        [$gateway, $tx] = $this->seedPendingTransaction();
        $params = $this->validCallbackParams($gateway, $tx);
        $params['Firma'] = 'definitely-not-the-correct-signature';

        $response = $this->postJson('/payment/callback', $params);

        $response->assertStatus(200)->assertSee('$*$NOK$*$');
        $this->assertSame('failed', $tx->fresh()->status);
        Event::assertDispatchedTimes(PaymentFailed::class, 1);
    }

    // -------------------------------------------------------------------
    // H-1: return token has TTL and is bound to the operation number.
    // -------------------------------------------------------------------

    public function test_success_with_valid_token_redirects_to_success_route(): void
    {
        [$gateway, $tx] = $this->seedPendingTransaction();

        $token = app(CecabankService::class)->returnToken($tx->operation_number);

        $response = $this->get('/payment/success?Num_operacion='.$tx->operation_number.'&token='.$token);

        $response->assertRedirect(route('test.success'));
    }

    public function test_success_with_forged_token_redirects_to_failure_not_success(): void
    {
        // H-2 regression: a bogus token must NOT show the user a "thanks" page.
        $response = $this->get('/payment/success?Num_operacion=OP-DOES-NOT-EXIST&token=garbage');

        $response->assertRedirect(route('test.failure'));
    }

    public function test_success_with_token_for_other_operation_is_rejected(): void
    {
        $service = app(CecabankService::class);
        $aToken = $service->returnToken('OP-AAA');

        // Token issued for OP-AAA must not validate against OP-BBB.
        $response = $this->get('/payment/success?Num_operacion=OP-BBB&token='.$aToken);

        $response->assertRedirect(route('test.failure'));
    }

    // -------------------------------------------------------------------
    // H-3: payable_type / payable_id are not mass-assignable.
    // -------------------------------------------------------------------

    public function test_payable_columns_are_not_mass_assignable(): void
    {
        [$gateway] = $this->seedPendingTransaction();

        $tx = PaymentTransaction::create([
            'payment_gateway_id' => $gateway->id,
            'operation_number' => 'OP-MASS-1',
            'amount' => 1.0,
            'status' => 'pending',
            // ↓ both must be silently ignored by Eloquent.
            'payable_type' => \App\Models\User::class,
            'payable_id' => 42,
        ]);

        $this->assertNull($tx->payable_type, 'payable_type must be guarded against mass-assignment');
        $this->assertNull($tx->payable_id, 'payable_id must be guarded against mass-assignment');
    }

    // -------------------------------------------------------------------
    // M-2/M-5: PaymentGateway::active() refuses to pick when ambiguous.
    // -------------------------------------------------------------------

    public function test_active_fails_loud_when_multiple_active_rows(): void
    {
        $this->makeGateway(['name' => 'one']);
        $this->makeGateway(['name' => 'two']);

        $this->expectException(\Illuminate\Database\MultipleRecordsFoundException::class);
        PaymentGateway::active();
    }

    public function test_active_ignores_rows_with_other_provider(): void
    {
        $stripeLike = $this->makeGateway(['provider' => 'redsys']);
        $cecabank = $this->makeGateway();

        $resolved = PaymentGateway::active();

        $this->assertTrue($resolved->is($cecabank));
        $this->assertNotSame($stripeLike->id, $resolved->id);
    }

    // -------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------

    /**
     * @return array{0: PaymentGateway, 1: PaymentTransaction}
     */
    private function seedPendingTransaction(): array
    {
        $gateway = $this->makeGateway();

        $service = app(CecabankService::class);
        $operationNumber = 'OP-SEED-'.bin2hex(random_bytes(4));
        $tx = PaymentTransaction::create([
            'payment_gateway_id' => $gateway->id,
            'operation_number' => $operationNumber,
            'amount' => 1.50,
            'status' => 'pending',
            'environment' => 'test',
        ]);

        return [$gateway, $tx];
    }

    private function makeGateway(array $overrides = []): PaymentGateway
    {
        return PaymentGateway::create(array_merge([
            'name' => 'Test Gateway',
            'provider' => 'cecabank',
            'merchant_id' => '111950028',
            'acquirer_bin' => '0000554052',
            'terminal_id' => '00000003',
            'encryption_key_test' => '99888888',
            'encryption_key_prod' => '99888888',
            'environment' => 'test',
            'currency' => '978',
            'language' => '1',
            'is_active' => true,
        ], $overrides));
    }

    /**
     * Build a fully-signed callback payload for a given transaction so the
     * controller's signature verification will pass.
     *
     * @return array<string, string>
     */
    private function validCallbackParams(PaymentGateway $gateway, PaymentTransaction $tx): array
    {
        $params = [
            'MerchantID' => $gateway->merchant_id,
            'AcquirerBIN' => $gateway->acquirer_bin,
            'TerminalID' => $gateway->terminal_id,
            'Num_operacion' => $tx->operation_number,
            'Importe' => app(CecabankService::class)->amountToCents((float) $tx->amount),
            'TipoMoneda' => $gateway->currency,
            'Referencia' => 'REF123',
            'Num_aut' => '999999',
        ];

        $signatureData = $gateway->encryption_key
            .$params['MerchantID']
            .$params['AcquirerBIN']
            .$params['TerminalID']
            .$params['Num_operacion']
            .$params['Importe']
            .$params['TipoMoneda']
            .config('cecabank.exponent')
            .$params['Referencia'];

        $params['Firma'] = strtolower(hash('sha256', $signatureData));

        return $params;
    }
}
