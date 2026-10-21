<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ExistingSchemaCompatibilityTest extends TestCase
{
    public function test_additive_migration_preserves_the_user_supplied_tables_and_rows(): void
    {
        Schema::create('users', function ($table) {
            $table->increments('id');
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->string('role', 50)->default('comprador');
            $table->timestamps();
        });
        Schema::create('categories', function ($table) {
            $table->increments('id');
            $table->string('name');
            $table->string('icon', 100)->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
        Schema::create('products', function ($table) {
            $table->increments('id');
            $table->string('title');
            $table->text('description')->nullable();
            $table->decimal('price', 10, 2);
            $table->integer('category_id')->nullable();
            $table->integer('seller_id')->nullable();
            $table->string('type', 50)->default('En venta');
            $table->string('status', 50)->default('Pendiente');
            $table->text('image')->nullable();
            $table->timestamps();
        });
        Schema::create('orders', function ($table) {
            $table->increments('id');
            $table->integer('buyer_id')->nullable();
            $table->integer('product_id')->nullable();
            $table->decimal('total', 10, 2);
            $table->decimal('commission', 10, 2);
            $table->decimal('seller_receives', 10, 2);
            $table->string('status', 50)->default('Completado');
            $table->timestamp('created_at')->useCurrent();
        });

        DB::table('users')->insert([
            'name' => 'Cuenta existente',
            'email' => 'existing@upec.edu.ec',
            'password' => 'already-hashed',
            'role' => 'vendedor',
        ]);

        $migration = require database_path('migrations/2026_10_21_000000_create_marketplace_tables.php');
        $migration->up();

        $this->assertSame('Cuenta existente', DB::table('users')->where('email', 'existing@upec.edu.ec')->value('name'));
        $this->assertTrue(Schema::hasColumn('products', 'sold_at'));
        $this->assertTrue(Schema::hasColumn('orders', 'stripe_session_id'));
        $this->assertTrue(Schema::hasTable('favorites'));
        $this->assertTrue(Schema::hasTable('reviews'));
        $this->assertTrue(Schema::hasTable('carts'));
        $this->assertTrue(Schema::hasTable('conversations'));
        $this->assertTrue(Schema::hasTable('messages'));
        $this->assertSame('integer', Schema::getColumnType('favorites', 'user_id'));
        $this->assertSame('integer', Schema::getColumnType('favorites', 'product_id'));
    }
}
