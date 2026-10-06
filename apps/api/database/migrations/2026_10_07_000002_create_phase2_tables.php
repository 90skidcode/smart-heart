<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Licensed / validated questionnaire wording, entered by the study team. Never invented in code.
        Schema::create('instrument_texts', function (Blueprint $table) {
            $table->id();
            $table->string('instrument', 16);
            $table->string('lang', 5);
            $table->string('key', 64);
            $table->text('text');
            $table->foreignId('updated_by')->nullable()->constrained('users');
            $table->timestamps();
            $table->unique(['instrument', 'lang', 'key']);
        });

        // EQ-5D-5L India value set (Jyani 2022), loaded under the EuroQol licence.
        Schema::create('eq5d_values', function (Blueprint $table) {
            $table->char('health_state', 5)->primary();
            $table->decimal('utility', 6, 3);
        });

        // One tablet self-entry session = one participant + one questionnaire. Locks on completion.
        Schema::create('self_entry_sessions', function (Blueprint $table) {
            $table->id();
            $table->string('token_hash', 64)->unique();
            $table->foreignId('participant_id')->constrained();
            $table->string('form_code', 16);
            $table->string('lang', 5);
            $table->foreignId('created_by')->constrained('users');
            $table->timestamp('expires_at');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();
        });

        // Safety alerts: PHQ-9 item 9 (critical) and PHQ-9 / GAD-7 ≥ 10 (clinical review).
        Schema::create('safety_alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('participant_id')->constrained();
            $table->string('rule', 32);
            $table->string('severity', 16);           // critical | warning
            $table->foreignId('source_form_id')->nullable()->constrained('forms');
            $table->string('summary', 500);
            $table->string('status', 16)->default('open'); // open | acknowledged | closed
            $table->timestamp('raised_at');
            $table->timestamp('notified_at')->nullable();
            $table->timestamp('escalated_at')->nullable();
            $table->timestamp('acknowledged_at')->nullable();
            $table->foreignId('acknowledged_by')->nullable()->constrained('users');
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users');
            $table->text('close_note')->nullable();
            $table->timestamps();
            $table->unique(['rule', 'source_form_id']);
            $table->index(['status', 'severity']);
        });

        // CCSPS domain thresholds, versioned. Only an approved version is ever used to score.
        Schema::create('scoring_configs', function (Blueprint $table) {
            $table->id();
            $table->string('engine', 32);              // CCSPS
            $table->string('domain', 32);
            $table->unsignedInteger('version');
            $table->json('rule');
            $table->string('status', 16)->default('draft'); // draft | approved | retired
            $table->text('note')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users');
            $table->timestamps();
            $table->unique(['engine', 'domain', 'version']);
        });

        // The statistician's allocation list. Arms are never shown to users; only remaining counts.
        Schema::create('randomisation_lists', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('file_sha256', 64);
            $table->string('status', 16)->default('active'); // active | retired
            $table->unsignedInteger('row_count');
            $table->foreignId('uploaded_by')->constrained('users');
            $table->timestamps();
        });
        Schema::create('randomisation_slots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('list_id')->constrained('randomisation_lists');
            $table->string('stratum', 8);              // lt60 | ge60
            $table->unsignedInteger('seq_no');
            $table->unsignedInteger('block_no');
            $table->string('arm', 16);
            $table->foreignId('participant_id')->nullable()->unique()->constrained();
            $table->timestamp('used_at')->nullable();
            $table->unique(['list_id', 'stratum', 'seq_no']);
        });

        Schema::table('participants', function (Blueprint $table) {
            $table->timestamp('randomised_at')->nullable()->after('arm');
            $table->date('randomisation_date')->nullable()->after('randomised_at');
            $table->foreignId('randomised_by')->nullable()->after('randomisation_date')->constrained('users');
        });
    }

    public function down(): void
    {
        Schema::table('participants', function (Blueprint $table) {
            $table->dropConstrainedForeignId('randomised_by');
            $table->dropColumn(['randomised_at', 'randomisation_date']);
        });
        Schema::dropIfExists('randomisation_slots');
        Schema::dropIfExists('randomisation_lists');
        Schema::dropIfExists('scoring_configs');
        Schema::dropIfExists('safety_alerts');
        Schema::dropIfExists('self_entry_sessions');
        Schema::dropIfExists('eq5d_values');
        Schema::dropIfExists('instrument_texts');
    }
};
