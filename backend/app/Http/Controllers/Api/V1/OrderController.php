<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\MarketplaceOrder;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Stripe\Checkout\Session as StripeSession;
use Stripe\Exception\ApiErrorException;
use Stripe\Stripe;

class OrderController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return response()->json(MarketplaceOrder::query()
            ->with(['product.category:id,name', 'product.seller:id,name'])
            ->where('buyer_id', $request->user()->id)
            ->latest('created_at')
            ->get()
            ->map(fn (MarketplaceOrder $order) => $this->present($order)));
    }

    public function store(Request $request, Product $product): JsonResponse
    {
        if (! config('services.stripe.secret')) {
            return response()->json(['message' => 'Stripe no está configurado. Define STRIPE_SECRET en backend/.env antes de habilitar pagos.'], 503);
        }

        $order = DB::transaction(function () use ($request, $product) {
            $lockedProduct = Product::query()->lockForUpdate()->findOrFail($product->id);
            abort_unless($lockedProduct->status === 'Aprobado' && ! $lockedProduct->sold_at, 409, 'Este producto no está disponible.');
            abort_if($lockedProduct->seller_id === $request->user()->id, 422, 'No puedes comprar tu propio producto.');
            abort_if($lockedProduct->type !== 'En venta', 422, 'Los productos de intercambio no admiten pago online.');
            abort_if(
                MarketplaceOrder::where('product_id', $lockedProduct->id)->where('status', 'Pendiente')->exists(),
                409,
                'Este producto ya tiene un pago pendiente.'
            );

            $price = (float) $lockedProduct->price;
            $commission = round($price * 0.03, 2);

            return MarketplaceOrder::create([
                'buyer_id' => $request->user()->id,
                'product_id' => $lockedProduct->id,
                'total' => number_format($price, 2, '.', ''),
                'commission' => number_format($commission, 2, '.', ''),
                'seller_receives' => number_format($price - $commission, 2, '.', ''),
                'status' => 'Pendiente',
            ]);
        });

        Stripe::setApiKey(config('services.stripe.secret'));

        try {
            $session = StripeSession::create([
                'mode' => 'payment',
                'line_items' => [[
                    'quantity' => 1,
                    'price_data' => [
                        'currency' => config('services.stripe.currency', 'usd'),
                        'unit_amount' => $totalCents,
                        'product_data' => ['name' => $product->title],
                    ],
                ]],
                'metadata' => [
                    'order_id' => (string) $order->id,
                    'product_id' => (string) $product->id,
                ],
                        'success_url' => rtrim(config('services.frontend_url'), '/').'/?checkout=success&order='.$order->id.'&session_id={CHECKOUT_SESSION_ID}',
                        'cancel_url' => rtrim(config('services.frontend_url'), '/').'/?checkout=cancelled&order='.$order->id,
            ]);
        } catch (ApiErrorException $exception) {
            report($exception);
            $order->update(['status' => 'Fallido']);

            return response()->json(['message' => 'Stripe rechazó la solicitud de pago. Inténtalo más tarde.'], 502);
        }

        $order->update(['stripe_session_id' => $session->id]);

        return response()->json([
            'order_id' => $order->id,
            'checkout_url' => $session->url,
        ], 201);
    }

    public function show(Request $request, MarketplaceOrder $order): JsonResponse
    {
        abort_unless($order->buyer_id === $request->user()->id || $request->user()->role === 'admin', 403);

        return response()->json($this->present($order->load(['product.category:id,name', 'product.seller:id,name'])));
    }

    public function confirm(Request $request, MarketplaceOrder $order): JsonResponse
    {
        abort_unless($order->buyer_id === $request->user()->id, 403);
        $data = $request->validate(['session_id' => ['required', 'string', 'max:255']]);
        abort_unless($order->stripe_session_id && hash_equals($order->stripe_session_id, $data['session_id']), 422, 'La sesión de pago no corresponde a esta orden.');

        Stripe::setApiKey(config('services.stripe.secret'));
        try {
            $session = StripeSession::retrieve($data['session_id']);
        } catch (ApiErrorException $exception) {
            report($exception);

            return response()->json(['message' => 'No se pudo verificar el pago con Stripe.'], 502);
        }

        abort_unless(($session->metadata->order_id ?? null) === (string) $order->id, 422, 'Los datos del pago no coinciden.');
        if ($session->payment_status === 'paid') {
            abort_unless($this->matchesOrderAmount($session, $order), 422, 'El importe confirmado no coincide con la orden.');
            $this->completeOrder($order);
        }

        return response()->json($this->present($order->fresh()->load(['product.category:id,name', 'product.seller:id,name'])));
    }

    public function webhook(Request $request): JsonResponse
    {
        $secret = config('services.stripe.webhook_secret');
        if (! $secret) {
            return response()->json(['message' => 'Stripe webhook no está configurado.'], 503);
        }

        try {
            $event = \Stripe\Webhook::constructEvent(
                $request->getContent(),
                $request->header('Stripe-Signature', ''),
                $secret
            );
        } catch (\UnexpectedValueException|\Stripe\Exception\SignatureVerificationException $exception) {
            return response()->json(['message' => 'Firma de webhook inválida.'], 400);
        }

        if ($event->type === 'checkout.session.completed' || $event->type === 'checkout.session.async_payment_succeeded') {
            $session = $event->data->object;
            if (($session->payment_status ?? null) === 'paid') {
                $order = MarketplaceOrder::where('stripe_session_id', $session->id)->first();
                if ($order) {
                    if (! $this->matchesOrderAmount($session, $order)) {
                        Log::critical('Stripe payment amount does not match marketplace order.', ['order_id' => $order->id]);

                        return response()->json(['message' => 'El importe del pago no coincide con la orden.'], 422);
                    }
                    $this->completeOrder($order);
                }
            }
        }

        if ($event->type === 'checkout.session.expired') {
            $session = $event->data->object;
            MarketplaceOrder::where('stripe_session_id', $session->id)
                ->where('status', 'Pendiente')
                ->update(['status' => 'Cancelado']);
        }

        return response()->json(['received' => true]);
    }

    private function completeOrder(MarketplaceOrder $order): void
    {
        DB::transaction(function () use ($order) {
            $lockedOrder = MarketplaceOrder::query()->lockForUpdate()->findOrFail($order->id);
            if ($lockedOrder->status === 'Completado') {
                return;
            }

            $product = Product::query()->lockForUpdate()->findOrFail($lockedOrder->product_id);
            if ($product->sold_at) {
                $lockedOrder->update(['status' => 'Reembolsar']);
                Log::critical('Duplicate payment requires a manual Stripe refund.', ['order_id' => $lockedOrder->id]);

                return;
            }

            $product->update(['sold_at' => now()]);
            $lockedOrder->update(['status' => 'Completado']);
            DB::table('carts')->where('product_id', $product->id)->delete();
        });
    }

    private function matchesOrderAmount(object $session, MarketplaceOrder $order): bool
    {
        return (int) ($session->amount_total ?? -1) === (int) round((float) $order->total * 100)
            && strtolower((string) ($session->currency ?? '')) === strtolower(config('services.stripe.currency', 'usd'));
    }

    private function present(MarketplaceOrder $order): array
    {
        return [
            'id' => $order->id,
            'product_id' => $order->product_id,
            'product' => $order->product?->title,
            'image' => $order->product?->image,
            'category' => $order->product?->category?->name,
            'seller' => $order->product?->seller?->name,
            'total' => (float) $order->total,
            'commission' => (float) $order->commission,
            'seller_receives' => (float) $order->seller_receives,
            'status' => $order->status,
            'created_at' => $order->created_at?->toIso8601String(),
        ];
    }
}
