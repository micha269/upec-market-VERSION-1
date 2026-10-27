<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CartController;
use App\Http\Controllers\Api\V1\CategoryController;
use App\Http\Controllers\Api\V1\ConversationController;
use App\Http\Controllers\Api\V1\FavoriteController;
use App\Http\Controllers\Api\V1\OrderController;
use App\Http\Controllers\Api\V1\ProductController;
use App\Http\Controllers\Api\V1\ReviewController;

Route::prefix('v1')->name('api.v1.')->group(function () {
    // Ruta de prueba de conexión a Supabase
    Route::get('/test-db', function () {
        try {
            DB::connection()->getPdo();
            return response()->json([
                'status' => 'success',
                'message' => '¡Conexión exitosa a Supabase desde Render!'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'No se pudo conectar: ' . $e->getMessage()
            ], 500);
        }
    });

    Route::get('/health', function () {
        return response()->json([
            'status' => 'ok',
            'version' => 'v1',
        ]);
    })->name('health');

    Route::get('/categories', [CategoryController::class, 'index'])->name('categories.index');
    Route::get('/products', [ProductController::class, 'index'])->name('products.index');
    Route::get('/products/{product}', [ProductController::class, 'show'])->whereNumber('product')->name('products.show');
    Route::get('/products/{product}/reviews', [ReviewController::class, 'index'])->whereNumber('product')->name('products.reviews.index');
    Route::post('/stripe/webhook', [OrderController::class, 'webhook'])->name('stripe.webhook');

    Route::post('/auth/register', [AuthController::class, 'register'])->middleware('throttle:5,1')->name('auth.register');
    Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:10,1')->name('auth.login');

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/auth/me', [AuthController::class, 'me'])->name('auth.me');
        Route::post('/auth/logout', [AuthController::class, 'logout'])->name('auth.logout');
        Route::put('/auth/role', [AuthController::class, 'updateRole'])->name('auth.role');

        Route::get('/products/mine', [ProductController::class, 'mine'])->name('products.mine');
        Route::post('/products', [ProductController::class, 'store'])->name('products.store');
        Route::get('/favorites', [FavoriteController::class, 'index'])->name('favorites.index');
        Route::post('/products/{product}/favorite', [FavoriteController::class, 'store'])->whereNumber('product')->name('favorites.store');
        Route::delete('/products/{product}/favorite', [FavoriteController::class, 'destroy'])->whereNumber('product')->name('favorites.destroy');

        Route::get('/cart', [CartController::class, 'index'])->name('cart.index');
        Route::post('/cart/{product}', [CartController::class, 'store'])->whereNumber('product')->name('cart.store');
        Route::delete('/cart/{product}', [CartController::class, 'destroy'])->whereNumber('product')->name('cart.destroy');

        Route::get('/orders', [OrderController::class, 'index'])->name('orders.index');
        Route::post('/products/{product}/checkout', [OrderController::class, 'store'])->whereNumber('product')->name('orders.store');
        Route::get('/orders/{order}', [OrderController::class, 'show'])->name('orders.show');
        Route::post('/orders/{order}/simulate-payment', [OrderController::class, 'simulatePayment'])->whereNumber('order')->name('orders.simulate-payment');
        Route::post('/orders/{order}/confirm', [OrderController::class, 'confirm'])->name('orders.confirm');

        Route::post('/products/{product}/reviews', [ReviewController::class, 'store'])->whereNumber('product')->name('products.reviews.store');
        Route::get('/conversations', [ConversationController::class, 'index'])->name('conversations.index');
        Route::post('/products/{product}/conversations', [ConversationController::class, 'start'])->whereNumber('product')->name('conversations.start');
        Route::get('/conversations/{conversation}/messages', [ConversationController::class, 'messages'])->name('conversations.messages');
        Route::post('/conversations/{conversation}/messages', [ConversationController::class, 'send'])->name('conversations.send');
        Route::get('/admin/products/pending', [ProductController::class, 'pending'])->name('admin.products.pending');
        Route::get('/admin/products', [ProductController::class, 'moderationIndex'])->name('admin.products.index');
        Route::post('/admin/products/{product}/approve', [ProductController::class, 'approve'])->whereNumber('product')->name('admin.products.approve');
        Route::post('/admin/products/{product}/reject', [ProductController::class, 'reject'])->whereNumber('product')->name('admin.products.reject');
        Route::delete('/admin/products/{product}', [ProductController::class, 'remove'])->whereNumber('product')->name('admin.products.remove');
    });
});
