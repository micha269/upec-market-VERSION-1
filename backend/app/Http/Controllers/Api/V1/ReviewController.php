<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\MarketplaceOrder;
use App\Models\Product;
use App\Models\Review;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReviewController extends Controller
{
    public function index(Product $product): JsonResponse
    {
        return response()->json($product->reviews()->with('user:id,name')->latest()->get()->map(fn (Review $review) => [
            'id' => $review->id,
            'user' => $review->user->name,
            'rating' => $review->rating,
            'comment' => $review->comment,
            'date' => $review->created_at->format('d/m/Y'),
        ]));
    }

    public function store(Request $request, Product $product): JsonResponse
    {
        $data = $request->validate([
            'rating' => ['required', 'integer', 'between:1,5'],
            'comment' => ['required', 'string', 'max:3000'],
        ]);

        abort_unless(MarketplaceOrder::where('buyer_id', $request->user()->id)
            ->where('product_id', $product->id)
            ->where('status', 'Completado')
            ->exists(), 403, 'Solo quienes completaron la compra pueden reseñar este producto.');

        $review = Review::updateOrCreate(
            ['user_id' => $request->user()->id, 'product_id' => $product->id],
            $data
        );

        return response()->json([
            'id' => $review->id,
            'user' => $request->user()->name,
            'rating' => $review->rating,
            'comment' => $review->comment,
            'date' => $review->created_at->format('d/m/Y'),
        ], 201);
    }
}
