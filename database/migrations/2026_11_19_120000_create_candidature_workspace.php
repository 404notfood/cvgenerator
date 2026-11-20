<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('role', 12)->default('user')->index();
            $table->text('openai_key')->nullable();
        });

        Schema::create('candidatures', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('status', 24);
            $table->string('provider', 16);
            $table->text('source_url');
            $table->longText('resume_text');
            $table->longText('offer_text')->nullable();
            $table->string('title')->nullable();
            $table->string('company')->nullable();
            $table->longText('result')->nullable();
            $table->string('error_code', 40)->nullable();
            $table->unsignedInteger('revision')->default(0);
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'created_at']);
        });

        Schema::create('site_settings', function (Blueprint $table): void {
            $table->string('name')->primary();
            $table->text('value');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_settings');
        Schema::dropIfExists('candidatures');
        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex(['role']);
            $table->dropColumn(['role', 'openai_key']);
        });
    }
};
