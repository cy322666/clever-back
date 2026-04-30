<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('source_connections', function (Blueprint $table) {
            $table->id();
            $table->string('source_key');
            $table->string('name');
            $table->string('driver');
            $table->string('status')->default('inactive');
            $table->boolean('is_enabled')->default(true);
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamp('last_error_at')->nullable();
            $table->text('last_error_message')->nullable();
            $table->jsonb('settings')->nullable();
            $table->timestamps();

        });

        Schema::create('source_sync_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('source_connection_id')->nullable()->constrained()->nullOnDelete();
            $table->string('source_key');
            $table->string('source_type');
            $table->string('status')->default('running');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->unsignedInteger('pulled_count')->default(0);
            $table->unsignedInteger('created_count')->default(0);
            $table->unsignedInteger('updated_count')->default(0);
            $table->unsignedInteger('error_count')->default(0);
            $table->text('error_message')->nullable();
            $table->jsonb('payload')->nullable();
            $table->timestamps();
        });

        Schema::create('source_mappings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('source_connection_id')->nullable()->constrained()->nullOnDelete();
            $table->string('source_key');
            $table->string('external_type');
            $table->string('external_id');
            $table->string('internal_type');
            $table->unsignedBigInteger('internal_id');
            $table->string('label')->nullable();
            $table->boolean('is_primary')->default(false);
            $table->jsonb('metadata')->nullable();
            $table->timestamps();

        });

        Schema::create('alerts', function (Blueprint $table) {
            $table->id();
            $table->string('source_key')->default('system');
            $table->string('type');
            $table->string('severity')->default('warning');
            $table->string('status')->default('open');
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('entity_type')->nullable();
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->decimal('score', 6, 4)->default(0);
            $table->timestamp('detected_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamps();

        });

        Schema::create('metric_snapshots', function (Blueprint $table) {
            $table->id();
            $table->date('snapshot_date');
            $table->string('period_key');
            $table->string('metric_group');
            $table->string('metric_key');
            $table->decimal('value_numeric', 18, 4)->nullable();
            $table->text('value_text')->nullable();
            $table->jsonb('payload')->nullable();
            $table->timestamps();

        });
    }

    public function down(): void
    {
        Schema::dropIfExists('metric_snapshots');
        Schema::dropIfExists('alerts');
        Schema::dropIfExists('source_mappings');
        Schema::dropIfExists('source_sync_logs');
        Schema::dropIfExists('source_connections');
    }
};
