<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('participants', function (Blueprint $table) {
            $table->id();
            $table->string('study_id', 32)->unique();      // SMART-HEART-0001 (generated)
            $table->string('screening_id', 32)->unique();  // SCR-0001 (generated)
            // Identifiable data — removed from de-identified exports.
            $table->string('full_name');
            $table->string('phone', 20);
            $table->string('hospital_number', 50)->nullable();
            $table->text('address')->nullable();
            // Study status
            $table->string('status', 32)->default('registered');
            $table->string('arm', 16)->nullable();          // intervention | control (set by RAND-01, Phase 2)
            $table->string('age_stratum', 8)->nullable();
            $table->json('screen_fail_reasons')->nullable();
            $table->date('screen_failed_on')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
            $table->softDeletes();                           // never hard-deleted
            $table->index(['status', 'arm']);
        });

        Schema::create('forms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('participant_id')->constrained();
            $table->string('form_code', 16);
            $table->string('visit', 16)->default('BL');      // BL now; D14/D30/D60/D90/D180 for FU-01 later
            $table->string('status', 16)->default('in_progress'); // in_progress | complete | signed
            $table->json('data')->nullable();
            $table->json('computed')->nullable();
            $table->json('overrides')->nullable();           // field => reason for out-of-range values
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('users');
            $table->timestamp('signed_at')->nullable();
            $table->foreignId('signed_by')->nullable()->constrained('users');
            $table->string('signature_meaning')->nullable();
            $table->unsignedSmallInteger('unlock_count')->default(0);
            $table->foreignId('updated_by')->nullable()->constrained('users');
            $table->timestamps();
            $table->unique(['participant_id', 'form_code', 'visit']);
        });

        // Append-only. The AuditLog model refuses updates and deletes.
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->timestamp('created_at')->useCurrent()->index();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('user_name')->nullable();
            $table->string('action', 32)->index();
            $table->string('entity_type', 32)->nullable();
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->unsignedBigInteger('participant_id')->nullable()->index();
            $table->string('form_code', 16)->nullable();
            $table->string('field', 64)->nullable();
            $table->text('old_value')->nullable();
            $table->text('new_value')->nullable();
            $table->string('reason', 500)->nullable();
            $table->json('meta')->nullable();
            $table->string('ip_address', 45)->nullable();
        });

        Schema::create('id_sequences', function (Blueprint $table) {
            $table->string('name', 32)->primary();
            $table->unsignedInteger('value')->default(0);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('id_sequences');
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('forms');
        Schema::dropIfExists('participants');
    }
};
