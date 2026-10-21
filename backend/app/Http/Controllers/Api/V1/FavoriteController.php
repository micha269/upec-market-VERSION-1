<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FavoriteController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return response()->json($request->user()->belongsToMany(Product::class, 'favorites')->pluck('products.id'));
    }

    public function store(Request $request, Product $product): JsonResponse
    {
        abort_unless($product->status === 'Aprobado' && ! $product->sold_at, 404);
        $request->user()->belongsToMany(Product::class, 'favorites')->syncWithoutDetaching([$product->id]);

        return response()->json(['favorited' => true]);
    }

    public function destroy(Request $request, Product $product): JsonResponse
    {
        $request->user()->belongsToMany(Product::class, 'favorites')->detach($product->id);

        return response()->json(['favorited' => false]);
    }
}
