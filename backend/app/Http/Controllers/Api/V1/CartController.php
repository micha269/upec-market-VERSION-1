<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CartController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return response()->json($request->user()->belongsToMany(Product::class, 'carts')
            ->with(['category:id,name', 'seller:id,name'])
            ->get()
            ->map(fn (Product $product) => [
                'id' => $product->id,
                'title' => $product->title,
                'price' => (float) $product->price,
                'image' => $product->image,
                'seller' => $product->seller?->name,
                'status' => $product->status,
                'type' => $product->type,
                'category' => $product->category?->name,
                'description' => $product->description,
            ]));
    }

    public function store(Request $request, Product $product): JsonResponse
    {
        abort_unless($product->status === 'Aprobado' && ! $product->sold_at, 409, 'Este producto no está disponible.');
        abort_if($product->seller_id === $request->user()->id, 422, 'No puedes agregar tu propio producto al carrito.');

        $request->user()->belongsToMany(Product::class, 'carts')->syncWithoutDetaching([$product->id]);

        return response()->json(['added' => true], 201);
    }

    public function destroy(Request $request, Product $product): JsonResponse
    {
        $request->user()->belongsToMany(Product::class, 'carts')->detach($product->id);

        return response()->json(['removed' => true]);
    }
}
