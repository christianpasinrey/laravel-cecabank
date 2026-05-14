<?php

namespace Cpr\Cecabank\Http\Controllers;

use Cpr\Cecabank\CecabankService;
use Cpr\Cecabank\Events\PaymentCanceled;
use Cpr\Cecabank\Events\PaymentCompleted;
use Cpr\Cecabank\Events\PaymentFailed;
use Cpr\Cecabank\Models\PaymentTransaction;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Public endpoints called by Cecabank itself:
 *   - success: browser landing on URL_OK
 *   - failure: browser landing on URL_NOK
 *   - callback: server-to-server confirmation
 *
 * No views are rendered: success/failure redirect to a host-controlled route
 * (resolved from the Payable), callback returns the literal acknowledgement
 * string Cecabank expects. This keeps the package frontend-agnostic.
 *
 * Every state mutation goes through `DB::transaction { lockForUpdate; … }` so
 * concurrent callbacks (Cecabank retries) and a leaked URL token racing the
 * server-to-server callback cannot both transition the same row.
 */
class PaymentController extends Controller
{
    public function __construct(public CecabankService $cecabank) {}

    public function success(Request $request): RedirectResponse
    {
        $transaction = $this->resolveTransaction(
            (string) $request->input('Num_operacion', ''),
            (string) $request->input('token', ''),
        );

        // Bad / expired / unknown token → redirect to FAILURE, not success.
        // Refusing to confuse a user (or attacker) with a "thanks" page on
        // bogus input.
        if (! $transaction) {
            return redirect()->route(
                (string) config('cecabank.fallback_routes.failure')
            )->with('error', __('cecabank::messages.payment_failed'));
        }

        $route = $transaction->payableRecord()?->paymentSuccessRoute()
            ?? (string) config('cecabank.fallback_routes.success');

        if ($transaction->status === 'completed') {
            return redirect()->route($route)
                ->with('success', __('cecabank::messages.payment_completed'));
        }

        return redirect()->route($route)
            ->with('info', __('cecabank::messages.payment_verifying'));
    }

    public function failure(Request $request): RedirectResponse
    {
        $transaction = $this->resolveTransaction(
            (string) $request->input('Num_operacion', ''),
            (string) $request->input('token', ''),
        );

        $canceled = null;

        // Only mutate state for a valid token. The state transition is
        // serialised against the server-to-server callback via a row lock so
        // a leaked URL_NOK token can't overwrite an authoritative callback
        // result.
        if ($transaction) {
            $canceled = DB::transaction(function () use ($transaction, $request) {
                /** @var PaymentTransaction|null $locked */
                $locked = PaymentTransaction::lockForUpdate()->find($transaction->id);

                if (! $locked || $locked->status !== 'pending') {
                    return null;
                }

                $locked->update([
                    'status' => 'canceled',
                    'error_message' => __('cecabank::messages.payment_canceled'),
                    'raw_response' => $this->cecabank->sanitizeResponse($request->all()),
                ]);

                return $locked;
            });

            if ($canceled) {
                PaymentCanceled::dispatch($canceled, $canceled->payableRecord());
            }
        }

        $route = $transaction?->payableRecord()?->paymentFailureRoute()
            ?? (string) config('cecabank.fallback_routes.failure');

        return redirect()->route($route)
            ->with('error', __('cecabank::messages.payment_failed'));
    }

    public function callback(Request $request): Response
    {
        $operationNumber = (string) $request->input('Num_operacion', '');

        if ($operationNumber === '') {
            return $this->ack(false);
        }

        $existing = PaymentTransaction::with('paymentGateway')
            ->where('operation_number', $operationNumber)
            ->first();

        if (! $existing) {
            return $this->ack(false);
        }

        // Already finalised → idempotent OK for Cecabank's retry policy.
        if ($existing->status === 'completed') {
            return $this->ack(true);
        }

        // Sandbox rows are reconciled by the host's admin sandbox flow only.
        if ($existing->is_sandbox) {
            Log::warning('cpr/laravel-cecabank: callback received for sandbox transaction; ignored.', [
                'operation_number' => $operationNumber,
                'transaction_id' => $existing->id,
            ]);

            return $this->ack(true);
        }

        $gateway = $existing->paymentGateway;
        $params = $this->cecabank->sanitizeResponse($request->all());

        if (! $this->cecabank->verifyCallbackSignature($params, $gateway, $existing->environment)) {
            $failed = DB::transaction(function () use ($existing, $params) {
                /** @var PaymentTransaction|null $locked */
                $locked = PaymentTransaction::lockForUpdate()->find($existing->id);
                if (! $locked || $locked->status !== 'pending') {
                    return null;
                }
                $locked->update([
                    'status' => 'failed',
                    'error_message' => 'Invalid response signature.',
                    'signature_response' => $params['Firma'] ?? null,
                    'raw_response' => $params,
                ]);

                return $locked;
            });

            if ($failed) {
                PaymentFailed::dispatch($failed, $failed->payableRecord(), 'invalid_signature');
            }

            return $this->ack(false);
        }

        // Authoritative success transition. Event fires ONLY when the UPDATE
        // actually flipped pending → completed, so concurrent retries or
        // replays cannot double-fulfil.
        $completed = DB::transaction(function () use ($existing, $params) {
            /** @var PaymentTransaction|null $locked */
            $locked = PaymentTransaction::lockForUpdate()->find($existing->id);
            if (! $locked || $locked->status !== 'pending') {
                return null;
            }
            $locked->update([
                'status' => 'completed',
                'authorization_number' => $params['Num_aut'] ?? null,
                'reference' => $params['Referencia'] ?? null,
                'signature_response' => $params['Firma'] ?? null,
                'raw_response' => $params,
                'completed_at' => now(),
            ]);

            return $locked;
        });

        if ($completed) {
            PaymentCompleted::dispatch($completed, $completed->payableRecord());
        }

        return $this->ack(true);
    }

    private function resolveTransaction(string $operationNumber, string $token): ?PaymentTransaction
    {
        if (! $this->cecabank->verifyReturnToken($operationNumber, $token)) {
            return null;
        }

        return PaymentTransaction::where('operation_number', $operationNumber)->first();
    }

    private function ack(bool $ok): Response
    {
        return response($ok ? '$*$OKY$*$' : '$*$NOK$*$', 200);
    }
}
