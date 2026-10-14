<?php

namespace App\Http\Controllers;

use App\Models\PaymentMethod;
use App\Models\PlanOrder;
use App\Support\Permissions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Private billing images: payment-method QR codes and payment screenshots.
 */
class BillingFileController extends Controller
{
    private const HEADERS = [
        'X-Content-Type-Options' => 'nosniff',
        'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'",
    ];

    public function qr(PaymentMethod $paymentMethod): StreamedResponse
    {
        return $this->stream($paymentMethod->qr_path, ['Cache-Control' => 'private, max-age=3600']);
    }

    /**
     * Only the buyer and staff who handle purchase requests can see a screenshot.
     */
    public function proof(Request $request, PlanOrder $order): StreamedResponse
    {
        $user = $request->user();
        abort_unless((int) $order->user_id === (int) $user->id || $user->can(Permissions::ORDERS_MANAGE), 403);

        return $this->stream($order->proof_path, ['Cache-Control' => 'private, no-store']);
    }

    /**
     * @param  array<string, string>  $headers
     */
    private function stream(?string $path, array $headers): StreamedResponse
    {
        $disk = Storage::disk('local');
        abort_unless($path && $disk->exists($path), 404);

        return $disk->response($path, null, $headers + self::HEADERS);
    }
}
