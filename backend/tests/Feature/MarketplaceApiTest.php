<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\MarketplaceOrder;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class MarketplaceApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_requires_institutional_email_and_returns_a_token(): void
    {
        $this->postJson('/api/v1/auth/register', [
            'name' => 'Estudiante',
            'email' => 'student@example.com',
            'password' => 'correct-horse-battery',
        ])->assertUnprocessable();

        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'Estudiante',
            'email' => 'student@upec.edu.ec',
            'password' => 'correct-horse-battery',
        ])->assertCreated()
            ->assertJsonPath('user.role', 'comprador')
            ->assertJsonStructure(['user' => ['id', 'name', 'email', 'role'], 'token']);

        $this->withToken($response->json('token'))
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('user.email', 'student@upec.edu.ec');
    }

    public function test_vendor_can_publish_but_only_admin_can_approve_products(): void
    {
        $category = Category::create(['name' => 'Tecnología', 'icon' => 'laptop']);
        $vendor = User::factory()->create(['role' => 'vendedor', 'email' => 'vendor@upec.edu.ec']);

        $productResponse = $this->actingAs($vendor, 'sanctum')->postJson('/api/v1/products', [
            'title' => 'Laptop usada',
            'price' => 200,
            'category_id' => $category->id,
            'type' => 'En venta',
        ])->assertCreated()->assertJsonPath('status', 'Pendiente');

        $productId = $productResponse->json('id');
        $this->getJson('/api/v1/products/mine')
            ->assertOk()
            ->assertJsonPath('0.id', $productId);
        $this->postJson("/api/v1/admin/products/{$productId}/approve")->assertForbidden();

        $admin = User::factory()->create(['role' => 'admin', 'email' => 'admin@upec.edu.ec']);
        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/admin/products/{$productId}/approve")
            ->assertOk()
            ->assertJsonPath('status', 'Aprobado');
    }

    public function test_rejected_product_remains_visible_to_its_seller_with_the_moderation_reason(): void
    {
        $category = Category::create(['name' => 'Tecnología']);
        $vendor = User::factory()->create(['role' => 'vendedor']);
        $admin = User::factory()->create(['role' => 'admin']);
        $product = Product::create([
            'title' => 'Producto con información incompleta',
            'price' => 20,
            'category_id' => $category->id,
            'seller_id' => $vendor->id,
            'status' => 'Pendiente',
            'type' => 'En venta',
        ]);

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/admin/products/{$product->id}/reject", ['reason' => 'Agrega fotos más claras.'])
            ->assertOk()
            ->assertJsonPath('status', 'Rechazado')
            ->assertJsonPath('moderation_reason', 'Agrega fotos más claras.');

        $this->actingAs($vendor, 'sanctum')
            ->getJson('/api/v1/products/mine')
            ->assertOk()
            ->assertJsonPath('0.id', $product->id)
            ->assertJsonPath('0.status', 'Rechazado')
            ->assertJsonPath('0.moderation_reason', 'Agrega fotos más claras.');

        $this->getJson('/api/v1/products')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_admin_removes_product_from_marketplace_without_erasing_seller_history(): void
    {
        $category = Category::create(['name' => 'Tecnología']);
        $vendor = User::factory()->create(['role' => 'vendedor']);
        $admin = User::factory()->create(['role' => 'admin']);
        $product = Product::create([
            'title' => 'Producto publicado',
            'price' => 20,
            'category_id' => $category->id,
            'seller_id' => $vendor->id,
            'status' => 'Aprobado',
            'type' => 'En venta',
        ]);

        $this->actingAs($admin, 'sanctum')
            ->deleteJson("/api/v1/admin/products/{$product->id}", ['reason' => 'No cumple las reglas del mercado.'])
            ->assertOk()
            ->assertJsonPath('status', 'Eliminado')
            ->assertJsonPath('moderation_reason', 'No cumple las reglas del mercado.');

        $this->assertDatabaseHas('products', ['id' => $product->id, 'status' => 'Eliminado']);
        $this->actingAs($vendor, 'sanctum')
            ->getJson('/api/v1/products/mine')
            ->assertOk()
            ->assertJsonPath('0.id', $product->id)
            ->assertJsonPath('0.status', 'Eliminado')
            ->assertJsonPath('0.moderation_reason', 'No cumple las reglas del mercado.');
        $this->getJson('/api/v1/products')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_moderation_requires_a_reason_and_sold_products_cannot_be_removed(): void
    {
        $category = Category::create(['name' => 'Tecnología']);
        $vendor = User::factory()->create(['role' => 'vendedor']);
        $admin = User::factory()->create(['role' => 'admin']);
        $product = Product::create([
            'title' => 'Producto vendido',
            'price' => 20,
            'category_id' => $category->id,
            'seller_id' => $vendor->id,
            'status' => 'Aprobado',
            'type' => 'En venta',
            'sold_at' => now(),
        ]);

        $this->actingAs($admin, 'sanctum')
            ->deleteJson("/api/v1/admin/products/{$product->id}", ['reason' => ''])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('reason');

        $this->deleteJson("/api/v1/admin/products/{$product->id}", ['reason' => 'Moderación'])
            ->assertConflict();
    }

    public function test_vendor_can_upload_a_product_image_and_image_path_is_persisted(): void
    {
        Config::set('filesystems.product_images_disk', 'public');
        Storage::fake('public');
        $category = Category::create(['name' => 'Tecnología']);
        $vendor = User::factory()->create(['role' => 'vendedor']);

        $response = $this->actingAs($vendor, 'sanctum')->post('/api/v1/products', [
            'title' => 'Audífonos',
            'price' => 25,
            'category_id' => $category->id,
            'type' => 'En venta',
            'image' => UploadedFile::fake()->create('audifonos.png', 100, 'image/png'),
        ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('status', 'Pendiente');

        $product = Product::findOrFail($response->json('id'));
        $this->assertNotNull($product->image);
        Storage::disk('public')->assertExists($product->image);
        $this->assertSame(Storage::disk('public')->url($product->image), $response->json('image'));
    }

    public function test_vendor_image_upload_uses_the_configured_product_image_disk(): void
    {
        Config::set('filesystems.product_images_disk', 'supabase');
        Storage::fake('supabase');
        $category = Category::create(['name' => 'Tecnología']);
        $vendor = User::factory()->create(['role' => 'vendedor']);

        $response = $this->actingAs($vendor, 'sanctum')->post('/api/v1/products', [
            'title' => 'Cámara',
            'price' => 80,
            'category_id' => $category->id,
            'type' => 'En venta',
            'image' => UploadedFile::fake()->create('camara.png', 100, 'image/png'),
        ], ['Accept' => 'application/json'])
            ->assertCreated();

        $product = Product::findOrFail($response->json('id'));
        Storage::disk('supabase')->assertExists($product->image);
        $this->assertSame(Storage::disk('supabase')->url($product->image), $response->json('image'));
    }

    public function test_cart_returns_the_public_url_for_product_images(): void
    {
        Config::set('filesystems.product_images_disk', 'supabase');
        Storage::fake('supabase');
        $category = Category::create(['name' => 'Tecnología']);
        $seller = User::factory()->create(['role' => 'vendedor']);
        $buyer = User::factory()->create();
        $product = Product::create([
            'title' => 'Cámara',
            'price' => 80,
            'category_id' => $category->id,
            'seller_id' => $seller->id,
            'status' => 'Aprobado',
            'type' => 'En venta',
            'image' => 'products/camara.png',
        ]);

        $this->actingAs($buyer, 'sanctum')
            ->postJson("/api/v1/cart/{$product->id}")
            ->assertCreated();

        $this->getJson('/api/v1/cart')
            ->assertOk()
            ->assertJsonPath('0.image', Storage::disk('supabase')->url($product->image));
    }

    public function test_cart_favorites_and_product_messages_are_persisted(): void
    {
        $category = Category::create(['name' => 'Libros y apuntes']);
        $seller = User::factory()->create(['role' => 'vendedor']);
        $buyer = User::factory()->create();
        $product = Product::create([
            'title' => 'Libro',
            'price' => 12.5,
            'category_id' => $category->id,
            'seller_id' => $seller->id,
            'status' => 'Aprobado',
            'type' => 'En venta',
        ]);

        $this->actingAs($buyer, 'sanctum')
            ->postJson("/api/v1/products/{$product->id}/favorite")->assertOk();
        $this->getJson('/api/v1/favorites')->assertOk()->assertJsonFragment([$product->id]);
        $this->postJson("/api/v1/cart/{$product->id}")->assertCreated();
        $this->getJson('/api/v1/cart')->assertOk()->assertJsonPath('0.title', 'Libro');

        $conversationId = $this->postJson("/api/v1/products/{$product->id}/conversations")
            ->assertCreated()
            ->json('id');
        $this->postJson("/api/v1/conversations/{$conversationId}/messages", ['text' => '¿Sigue disponible?'])
            ->assertCreated()
            ->assertJsonPath('text', '¿Sigue disponible?');
        $this->getJson("/api/v1/conversations/{$conversationId}/messages")
            ->assertOk()
            ->assertJsonPath('0.text', '¿Sigue disponible?');
    }

    public function test_simulated_checkout_completes_purchase_and_enables_review_without_charging(): void
    {
        Config::set('services.stripe.secret', null);
        Config::set('services.payments.mode', 'simulated');
        $category = Category::create(['name' => 'Deportes']);
        $seller = User::factory()->create(['role' => 'vendedor']);
        $buyer = User::factory()->create();
        $product = Product::create([
            'title' => 'Balón',
            'price' => 20,
            'category_id' => $category->id,
            'seller_id' => $seller->id,
            'status' => 'Aprobado',
            'type' => 'En venta',
        ]);

        $this->actingAs($buyer, 'sanctum')
            ->postJson("/api/v1/products/{$product->id}/reviews", ['rating' => 5, 'comment' => 'Excelente'])
            ->assertForbidden();

        $checkout = $this->postJson("/api/v1/products/{$product->id}/checkout")
            ->assertCreated()
            ->assertJsonPath('checkout_mode', 'simulated')
            ->assertJsonPath('message', 'Compra de demostración: no se realizará ningún cobro.');

        $orderId = $checkout->json('order_id');
        $this->assertDatabaseHas('orders', ['id' => $orderId, 'status' => 'Pendiente']);

        $this->postJson("/api/v1/orders/{$orderId}/simulate-payment")
            ->assertOk()
            ->assertJsonPath('id', $orderId)
            ->assertJsonPath('status', 'Completado')
            ->assertJsonPath('checkout_mode', 'simulated');

        $this->assertDatabaseHas('orders', ['id' => $orderId, 'status' => 'Completado']);
        $this->assertNotNull($product->fresh()->sold_at);

        $this->postJson("/api/v1/products/{$product->id}/reviews", ['rating' => 5, 'comment' => 'Excelente'])
            ->assertCreated()
            ->assertJsonPath('user', $buyer->name);
    }

    public function test_simulated_order_completion_is_buyer_only_and_disabled_in_stripe_mode(): void
    {
        Config::set('services.payments.mode', 'simulated');
        $category = Category::create(['name' => 'Deportes']);
        $seller = User::factory()->create(['role' => 'vendedor']);
        $buyer = User::factory()->create();
        $otherBuyer = User::factory()->create();
        $product = Product::create([
            'title' => 'Pelota',
            'price' => 10,
            'category_id' => $category->id,
            'seller_id' => $seller->id,
            'status' => 'Aprobado',
            'type' => 'En venta',
        ]);
        $order = MarketplaceOrder::create([
            'buyer_id' => $buyer->id,
            'product_id' => $product->id,
            'total' => 10,
            'commission' => 0.30,
            'seller_receives' => 9.70,
            'status' => 'Pendiente',
        ]);

        $this->actingAs($otherBuyer, 'sanctum')
            ->postJson("/api/v1/orders/{$order->id}/simulate-payment")
            ->assertForbidden();

        Config::set('services.payments.mode', 'stripe');
        $this->actingAs($buyer, 'sanctum')
            ->postJson("/api/v1/orders/{$order->id}/simulate-payment")
            ->assertNotFound();

        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'Pendiente']);
        $this->assertNull($product->fresh()->sold_at);
    }

    public function test_signed_stripe_webhook_completes_order_and_marks_product_sold(): void
    {
        $secret = 'whsec_local-test-secret';
        Config::set('services.stripe.webhook_secret', $secret);
        $category = Category::create(['name' => 'Hogar y oficina']);
        $seller = User::factory()->create(['role' => 'vendedor']);
        $buyer = User::factory()->create();
        $product = Product::create([
            'title' => 'Lámpara',
            'price' => 15,
            'category_id' => $category->id,
            'seller_id' => $seller->id,
            'status' => 'Aprobado',
            'type' => 'En venta',
        ]);
        $order = MarketplaceOrder::create([
            'buyer_id' => $buyer->id,
            'product_id' => $product->id,
            'total' => 15,
            'commission' => 0.45,
            'seller_receives' => 14.55,
            'status' => 'Pendiente',
            'stripe_session_id' => 'cs_test_paid',
        ]);
        $payload = json_encode([
            'id' => 'evt_test_paid',
            'object' => 'event',
            'api_version' => '2024-06-20',
            'created' => time(),
            'data' => ['object' => [
                'id' => 'cs_test_paid',
                'object' => 'checkout.session',
                'amount_total' => 1500,
                'currency' => 'usd',
                'payment_status' => 'paid',
                'metadata' => ['order_id' => (string) $order->id],
            ]],
            'livemode' => false,
            'pending_webhooks' => 1,
            'type' => 'checkout.session.completed',
        ]);
        $timestamp = time();
        $signature = 't='.$timestamp.',v1='.hash_hmac('sha256', $timestamp.'.'.$payload, $secret);

        $this->call('POST', '/api/v1/stripe/webhook', [], [], [], [
            'HTTP_STRIPE_SIGNATURE' => $signature,
            'CONTENT_TYPE' => 'application/json',
        ], $payload)->assertOk()->assertJsonPath('received', true);

        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'Completado']);
        $this->assertNotNull($product->fresh()->sold_at);
    }
}
