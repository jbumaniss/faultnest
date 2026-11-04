<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Migration runs only when the host explicitly enables history and runs migrations. */
return new class extends Migration {
    public function __construct()
    {
        $this->connection = config('faultnest.storage.database.connection');
    }

    public function up(): void
    {
        Schema::connection($this->connection)->create('faultnest_groups', function (Blueprint $table): void {
            $table->char('id', 64)->primary();
            $table->string('project', 120);
            $table->string('environment', 120);
            $table->unsignedSmallInteger('fingerprint_version');
            $table->string('fingerprint', 128);
            $table->string('category', 80);
            $table->text('title');
            $table->string('max_level', 20);
            $table->unsignedBigInteger('occurrence_count');
            $table->dateTime('first_seen_at', 6);
            $table->dateTime('last_seen_at', 6);
            $table->timestamps(6);
            $table->index(['project', 'environment', 'last_seen_at'], 'faultnest_groups_scope_last');
            $table->index(['last_seen_at'], 'faultnest_groups_retention');
        });

        Schema::connection($this->connection)->create('faultnest_event_samples', function (Blueprint $table): void {
            $table->char('id', 32)->primary();
            $table->char('group_id', 64);
            $table->string('source', 40);
            $table->string('level', 20);
            $table->string('exception_type', 255)->nullable();
            $table->text('message');
            $table->string('file', 255)->nullable();
            $table->unsignedInteger('line')->nullable();
            $table->json('frames_json');
            $table->json('context_json');
            $table->json('tags_json');
            $table->json('breadcrumbs_json');
            $table->dateTime('occurred_at', 6);
            $table->timestamp('created_at', 6);
            $table->foreign('group_id', 'faultnest_event_group_fk')
                ->references('id')->on('faultnest_groups')->cascadeOnDelete();
            $table->index(['group_id', 'occurred_at'], 'faultnest_events_group_time');
            $table->index(['occurred_at'], 'faultnest_events_retention');
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('faultnest_event_samples');
        Schema::connection($this->connection)->dropIfExists('faultnest_groups');
    }
};
