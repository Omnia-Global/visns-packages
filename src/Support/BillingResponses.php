<?php

namespace Visnsstudio\VisnsPackages\Support;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Stripe\Exception\ApiErrorException;
use Visnsstudio\VisnsPackages\Exceptions\Billing\BillingRefusal;
use Visnsstudio\VisnsPackages\Exceptions\Billing\BillingUnconfigured;

/**
 * The billing controllers' failure contract, in one place:
 *  - not configured            → 200 {ok:false, reason:'unconfigured'}
 *  - Stripe down or refusing   → 200 {ok:false, reason:'stripe_unavailable'}
 *  - a refusal with a sentence → its status (422, 409, 404) {ok:false, message}
 */
trait BillingResponses
{
    protected function answer(callable $action): JsonResponse
    {
        try {
            return $action();
        } catch (BillingRefusal $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage(), 'error' => $e->getMessage()], $e->status);
        } catch (BillingUnconfigured $e) {
            return response()->json(['ok' => false, 'reason' => 'unconfigured']);
        } catch (ApiErrorException $e) {
            Log::warning('billing.stripe_error', [
                'exception' => class_basename($e),
                'status' => $e->getHttpStatus(),
                'code' => $e->getStripeCode(),
                'request_id' => $e->getRequestId(),
            ]);

            return response()->json(['ok' => false, 'reason' => 'stripe_unavailable']);
        }
    }
}
