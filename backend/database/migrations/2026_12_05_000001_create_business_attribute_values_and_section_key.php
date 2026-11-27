<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('category_attribute_definitions', function (Blueprint $table): void {
            $table->string('section_key', 60)->nullable()->after('attribute_key');
            $table->index(['category_id', 'section_key'], 'idx_cat_attr_defs_cat_sec');
        });

        Schema::create('business_attribute_values', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->char('public_id', 26)->unique();
            $table->foreignId('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->foreignId('attribute_definition_id')
                ->constrained('category_attribute_definitions')
                ->cascadeOnDelete();
            $table->string('value_string', 500)->nullable();
            $table->decimal('value_number', 14, 4)->nullable();
            $table->boolean('value_boolean')->nullable();
            $table->json('value_json')->nullable();
            $table->timestamps();

            $table->unique(['business_id', 'attribute_definition_id'], 'uniq_biz_attr_val');
            $table->index(['attribute_definition_id', 'value_boolean', 'business_id'], 'idx_bav_def_bool_biz');
            $table->index(['attribute_definition_id', 'value_number', 'business_id'], 'idx_bav_def_num_biz');
            $table->index(['attribute_definition_id', 'value_string', 'business_id'], 'idx_bav_def_str_biz');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_attribute_values');

        Schema::table('category_attribute_definitions', function (Blueprint $table): void {
            $table->dropIndex('idx_cat_attr_defs_cat_sec');
            $table->dropColumn('section_key');
        });
    }
};
