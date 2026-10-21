<?php

namespace Database\Seeders;

use App\Models\Category;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        foreach ([
            ['Libros y apuntes', 'book-open'],
            ['Tecnología', 'laptop'],
            ['Hogar y oficina', 'sofa'],
            ['Ropa y accesorios', 'shirt'],
            ['Deportes', 'dumbbell'],
            ['Instrumentos musicales', 'guitar'],
            ['Belleza y cuidado personal', 'sparkles'],
            ['Otros', 'more-horizontal'],
        ] as [$name, $icon]) {
            Category::firstOrCreate(['name' => $name], ['icon' => $icon]);
        }
    }
}
