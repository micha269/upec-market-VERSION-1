<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'role')) {
            Schema::table('users', function (Blueprint $table) {
                $table->string('role', 50)->default('comprador');
            });
        }

        if (! Schema::hasTable('categories')) {
            Schema::create('categories', function (Blueprint $table) {
                $table->increments('id');
                $table->string('name');
                $table->string('icon', 100)->nullable();
                $table->timestamp('created_at')->useCurrent();
            });
        }

        if (! Schema::hasTable('products')) {
            Schema::create('products', function (Blueprint $table) {
                $table->increments('id');
                $table->string('title');
                $table->text('description')->nullable();
                $table->decimal('price', 10, 2);
                $table->integer('category_id')->nullable();
                $table->foreign('category_id')->references('id')->on('categories')->nullOnDelete();
                $table->integer('seller_id')->nullable();
                $table->foreign('seller_id')->references('id')->on('users')->nullOnDelete();
                $table->string('type', 50)->default('En venta');
                $table->string('status', 50)->default('Pendiente');
                $table->text('image')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasColumn('products', 'sold_at')) {
            Schema::table('products', function (Blueprint $table) {
                $table->timestamp('sold_at')->nullable();
            });
        }

        if (! Schema::hasTable('orders')) {
            Schema::create('orders', function (Blueprint $table) {
                $table->increments('id');
                $table->integer('buyer_id')->nullable();
                $table->foreign('buyer_id')->references('id')->on('users')->nullOnDelete();
                $table->integer('product_id')->nullable();
                $table->foreign('product_id')->references('id')->on('products')->nullOnDelete();
                $table->decimal('total', 10, 2);
                $table->decimal('commission', 10, 2);
                $table->decimal('seller_receives', 10, 2);
                $table->string('status', 50)->default('Pendiente');
                $table->timestamp('created_at')->useCurrent();
            });
        }

        if (! Schema::hasColumn('orders', 'stripe_session_id')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->string('stripe_session_id')->nullable()->unique();
            });
        }

        if (! Schema::hasTable('personal_access_tokens')) {
            Schema::create('personal_access_tokens', function (Blueprint $table) {
                $table->id();
                $table->morphs('tokenable');
                $table->string('name');
                $table->string('token', 64)->unique();
                $table->text('abilities')->nullable();
                $table->timestamp('last_used_at')->nullable();
                $table->timestamp('expires_at')->nullable()->index();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('favorites')) {
            Schema::create('favorites', function (Blueprint $table) {
                $table->increments('id');
                $table->integer('user_id');
                $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
                $table->integer('product_id');
                $table->foreign('product_id')->references('id')->on('products')->cascadeOnDelete();
                $table->timestamps();
                $table->unique(['user_id', 'product_id']);
            });
        }

        if (! Schema::hasTable('reviews')) {
            Schema::create('reviews', function (Blueprint $table) {
                $table->increments('id');
                $table->integer('user_id');
                $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
                $table->integer('product_id');
                $table->foreign('product_id')->references('id')->on('products')->cascadeOnDelete();
                $table->unsignedTinyInteger('rating');
                $table->text('comment');
                $table->timestamps();
                $table->unique(['user_id', 'product_id']);
            });
        }

        if (! Schema::hasTable('carts')) {
            Schema::create('carts', function (Blueprint $table) {
                $table->increments('id');
                $table->integer('user_id');
                $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
                $table->integer('product_id');
                $table->foreign('product_id')->references('id')->on('products')->cascadeOnDelete();
                $table->timestamps();
                $table->unique(['user_id', 'product_id']);
            });
        }

        if (! Schema::hasTable('conversations')) {
            Schema::create('conversations', function (Blueprint $table) {
                $table->increments('id');
                $table->integer('product_id');
                $table->foreign('product_id')->references('id')->on('products')->cascadeOnDelete();
                $table->integer('buyer_id');
                $table->foreign('buyer_id')->references('id')->on('users')->cascadeOnDelete();
                $table->integer('seller_id');
                $table->foreign('seller_id')->references('id')->on('users')->cascadeOnDelete();
                $table->timestamps();
                $table->unique(['product_id', 'buyer_id']);
            });
        }

        if (! Schema::hasTable('messages')) {
            Schema::create('messages', function (Blueprint $table) {
                $table->increments('id');
                $table->integer('conversation_id');
                $table->foreign('conversation_id')->references('id')->on('conversations')->cascadeOnDelete();
                $table->integer('sender_id');
                $table->foreign('sender_id')->references('id')->on('users')->cascadeOnDelete();
                $table->text('body');
                $table->timestamp('read_at')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        // Existing marketplace tables and records are intentionally preserved.
    }
};
