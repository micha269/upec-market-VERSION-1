<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Throwable;

class ProductController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'q' => ['nullable', 'string', 'max:255'],
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ]);

        $products = Product::query()
            ->with(['category:id,name', 'seller:id,name'])
            ->withAvg('reviews', 'rating')
            ->withCount('reviews')
            ->where('status', 'Aprobado')
            ->whereNull('sold_at')
            ->when($request->filled('q'), fn ($query) => $query->where(function ($query) use ($request) {
                $term = '%'.$request->string('q')->trim().'%';
                $query->whereRaw('LOWER(title) LIKE LOWER(?)', [$term])
                    ->orWhereRaw('LOWER(description) LIKE LOWER(?)', [$term]);
            }))
            ->when($request->filled('category_id'), fn ($query) => $query->where('category_id', $request->integer('category_id')))
            ->latest()
            ->paginate(min($request->integer('per_page', 24), 100));

        return response()->json([
            'data' => $products->getCollection()->map(fn (Product $product) => $this->present($product)),
            'meta' => [
                'current_page' => $products->currentPage(),
                'last_page' => $products->lastPage(),
                'total' => $products->total(),
            ],
        ]);
    }

    public function mine(Request $request): JsonResponse
    {
        $products = Product::with(['category:id,name', 'seller:id,name'])
            ->withAvg('reviews', 'rating')
            ->withCount('reviews')
            ->where('seller_id', $request->user()->id)
            ->latest()
            ->get();

        return response()->json($products->map(fn (Product $product) => $this->present($product)));
    }

    public function store(Request $request): JsonResponse
    {
        abort_unless(in_array($request->user()->role, ['vendedor', 'admin'], true), 403, 'Activa el modo vendedor para publicar.');

        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:10000'],
            'price' => ['required', 'numeric', 'min:0.01', 'max:99999999.99'],
            'category_id' => ['required', 'integer', 'exists:categories,id'],
            'type' => ['required', Rule::in(['En venta', 'En intercambio'])],
            'image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ]);

        $imagePath = null;
        try {
            $imagePath = $request->file('image')?->store('products', 'public');
            if ($request->hasFile('image') && ! $imagePath) {
                return response()->json(['message' => 'No se pudo guardar la imagen. Revisa el almacenamiento público del servidor.'], 503);
            }

            $product = DB::transaction(fn () => Product::create([
                ...$data,
                'image' => $imagePath,
                'seller_id' => $request->user()->id,
                'status' => 'Pendiente',
            ]))->load(['category:id,name', 'seller:id,name']);
        } catch (Throwable $exception) {
            if ($imagePath) {
                Storage::disk('public')->delete($imagePath);
            }
            throw $exception;
        }

        return response()->json($this->present($product), 201);
    }

    public function show(Product $product): JsonResponse
    {
        abort_unless($product->status === 'Aprobado' || auth()->id() === $product->seller_id || auth()->user()?->role === 'admin', 404);

        return response()->json($this->present(
            $product->load(['category:id,name', 'seller:id,name', 'reviews.user:id,name'])
                ->loadAvg('reviews', 'rating')
                ->loadCount('reviews')
        ));
    }

    public function approve(Product $product): JsonResponse
    {
        abort_unless(auth()->user()->role === 'admin', 403);
        abort_unless($product->status === 'Pendiente', 409, 'Solo se pueden aprobar productos pendientes.');

        $product->update(['status' => 'Aprobado']);

        return response()->json($this->present($product->load(['category:id,name', 'seller:id,name'])));
    }

    public function pending(): JsonResponse
    {
        abort_unless(auth()->user()->role === 'admin', 403);

        return response()->json(Product::with(['category:id,name', 'seller:id,name'])
            ->where('status', 'Pendiente')
            ->latest()
            ->get()
            ->map(fn (Product $product) => $this->present($product)));
    }

    public function reject(Request $request, Product $product): JsonResponse
    {
        abort_unless(auth()->user()->role === 'admin', 403);
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:1000']]);
        abort_unless($product->status === 'Pendiente', 409, 'Solo se pueden rechazar productos pendientes.');

        $product->update(['status' => 'Rechazado']);

        return response()->json(['message' => 'Producto rechazado.', 'reason' => $data['reason'] ?? null]);
    }

    private function present(Product $product): array
    {
        return [
            'id' => $product->id,
            'title' => $product->title,
            'description' => $product->description,
            'price' => (float) $product->price,
            'category_id' => $product->category_id,
            'category' => $product->category?->name,
            'seller_id' => $product->seller_id,
            'seller' => $product->seller?->name,
            'type' => $product->type,
            'status' => $product->status,
            'image' => $product->image
                ? (str_starts_with($product->image, 'http://') || str_starts_with($product->image, 'https://')
                    ? $product->image
                    : Storage::disk('public')->url($product->image))
                : null,
            'sold' => (bool) $product->sold_at,
            'rating' => $product->reviews_avg_rating ? round((float) $product->reviews_avg_rating, 1) : 0,
            'review_count' => $product->reviews_count ?? 0,
            'reviews' => $product->relationLoaded('reviews') ? $product->reviews->map(fn ($review) => [
                'id' => $review->id,
                'user' => $review->user?->name,
                'rating' => $review->rating,
                'comment' => $review->comment,
                'date' => $review->created_at?->format('d/m/Y'),
            ]) : null,
        ];
    }
}
