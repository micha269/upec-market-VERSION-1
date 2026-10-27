<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('products', 'moderation_reason')) {
            Schema::table('products', function (Blueprint $table) {
                $table->text('moderation_reason')->nullable();
            });
        }

        if (! Schema::hasColumn('products', 'moderated_at')) {
            Schema::table('products', function (Blueprint $table) {
                $table->timestamp('moderated_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        // Moderation history is intentionally preserved.
    }
};
