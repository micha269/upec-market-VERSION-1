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

    public function test_vendor_can_upload_a_product_image_and_image_path_is_persisted(): void
    {
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

    public function test_review_requires_a_completed_order_and_stripe_is_not_simulated(): void
    {
        Config::set('services.stripe.secret', null);
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

        $this->postJson("/api/v1/products/{$product->id}/checkout")
            ->assertStatus(503)
            ->assertJsonPath('message', 'Stripe no está configurado. Define STRIPE_SECRET en backend/.env antes de habilitar pagos.');

        MarketplaceOrder::create([
            'buyer_id' => $buyer->id,
            'product_id' => $product->id,
            'total' => 20,
            'commission' => 0.6,
            'seller_receives' => 19.4,
            'status' => 'Completado',
        ]);

        $this->postJson("/api/v1/products/{$product->id}/reviews", ['rating' => 5, 'comment' => 'Excelente'])
            ->assertCreated()
            ->assertJsonPath('user', $buyer->name);
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
