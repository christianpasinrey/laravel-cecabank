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

/**
 * Public endpoints called by Cecabank itself:
 *   - success: browser landing on URL_OK
 *   - failure: browser landing on URL_NOK
 *   - callback: server-to-server confirmation
 *
 * No views are rendered: success/failure redirect to a host-controlled route
 * (resolved from the Payable), callback returns the literal acknowledgement
 * string Cecabank expects. This keeps the package frontend-agnostic.
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

        $route = $transaction?->payableRecord()?->paymentSuccessRoute()
            ?? config('cecabank.fallback_routes.success');

        if ($transaction && $transaction->status === 'completed') {
            return redirect()->route($route)->with('success', __('cecabank::messages.payment_completed'));
        }

        return redirect()->route($route)->with('info', __('cecabank::messages.payment_verifying'));
    }

    public function failure(Request $request): RedirectResponse
    {
        $transaction = $this->resolveTransaction(
            (string) $request->input('Num_operacion', ''),
            (string) $request->input('token', ''),
        );

        if ($transaction && $transaction->status === 'pending') {
            $transaction->update([
                'status' => 'canceled',
                'error_message' => __('cecabank::messages.payment_canceled'),
                'raw_response' => $this->cecabank->sanitizeResponse($request->all()),
            ]);

            PaymentCanceled::dispatch($transaction, $transaction->payableRecord());
        }

        $route = $transaction?->payableRecord()?->paymentFailureRoute()
            ?? config('cecabank.fallback_routes.failure');

        return redirect()->route($route)->with('error', __('cecabank::messages.payment_failed'));
    }

    public function callback(Request $request): Response
    {
        $operationNumber = (string) $request->input('Num_operacion', '');

        if ($operationNumber === '') {
            return response('$*$NOK$*$', 200);
        }

        $transaction = PaymentTransaction::with('paymentGateway')
            ->where('operation_number', $operationNumber)
            ->first();

        if (! $transaction) {
            return response('$*$NOK$*$', 200);
        }

        if ($transaction->status === 'completed') {
            return response('$*$OKY$*$', 200);
        }

        // Sandbox transactions are reconciled exclusively via sandbox return URLs.
        if ($transaction->is_sandbox) {
            return response('$*$OKY$*$', 200);
        }

        $gateway = $transaction->paymentGateway;
        $params = $this->cecabank->sanitizeResponse($request->all());

        if (! $this->cecabank->verifyCallbackSignature($params, $gateway, $transaction->environment)) {
            $transaction->update([
                'status' => 'failed',
                'error_message' => 'Invalid response signature.',
                'signature_response' => $params['Firma'] ?? null,
                'raw_response' => $params,
            ]);

            PaymentFailed::dispatch($transaction, $transaction->payableRecord(), 'invalid_signature');

            return response('$*$NOK$*$', 200);
        }

        $transaction->update([
            'status' => 'completed',
            'authorization_number' => $params['Num_aut'] ?? null,
            'reference' => $params['Referencia'] ?? null,
            'signature_response' => $params['Firma'] ?? null,
            'raw_response' => $params,
            'completed_at' => now(),
        ]);

        PaymentCompleted::dispatch($transaction, $transaction->payableRecord());

        return response('$*$OKY$*$', 200);
    }

    private function resolveTransaction(string $operationNumber, string $token): ?PaymentTransaction
    {
        if (! $this->cecabank->verifyReturnToken($operationNumber, $token)) {
            return null;
        }

        return PaymentTransaction::where('operation_number', $operationNumber)->first();
    }
}
