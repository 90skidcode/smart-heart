<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Phase 3: participant app (intervention arm). */
return new class extends Migration
{
    public function up(): void
    {
        // People allowed to use the app for a participant: the participant, and caregivers (read-only).
        Schema::create('app_users', function (Blueprint $table) {
            $table->id();
            $table->foreignId('participant_id')->constrained();
            $table->string('role', 16);                      // participant | caregiver
            $table->string('name', 120)->nullable();         // caregiver name (participant name comes from the record)
            $table->string('relation', 60)->nullable();
            $table->string('phone', 15);                     // 10-digit Indian mobile, verified by Firebase OTP
            $table->string('firebase_uid', 128)->nullable();
            $table->string('status', 16)->default('invited'); // invited | active | revoked
            $table->string('lang', 5)->default('ta');
            $table->json('reminder_times')->nullable();       // {"Morning":"08:00", ...}
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->string('revoke_reason', 255)->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
            $table->index(['phone', 'status']);
        });

        Schema::create('app_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('app_user_id')->constrained()->cascadeOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->string('device_name', 120)->nullable();
            $table->timestamp('last_used_at');
            $table->timestamp('expires_at');
            $table->timestamps();
        });

        Schema::create('app_devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('app_user_id')->constrained()->cascadeOnDelete();
            $table->string('fcm_token', 512);
            $table->string('platform', 16)->default('android');
            $table->string('app_version', 32)->nullable();
            $table->timestamps();
            $table->unique(['app_user_id', 'fcm_token']);
        });

        // The medicine list shown in the app. Built from BL-M6, reviewed and published by staff. Versioned.
        Schema::create('med_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('participant_id')->constrained();
            $table->unsignedInteger('version');
            $table->json('items');                            // [{key, drug, dose, class, times[], instructions}]
            $table->string('source', 64);                     // e.g. "BL-M6 (form 12)"
            $table->string('note', 255)->nullable();
            $table->foreignId('published_by')->constrained('users');
            $table->timestamp('published_at');
            $table->timestamps();
            $table->unique(['participant_id', 'version']);
        });

        // One row per dose answered in the app. client_uuid makes offline retries idempotent.
        Schema::create('med_dose_logs', function (Blueprint $table) {
            $table->id();
            $table->uuid('client_uuid')->unique();
            $table->foreignId('participant_id')->constrained();
            $table->unsignedInteger('schedule_version');
            $table->string('med_key', 64);
            $table->date('dose_date');
            $table->string('slot', 16);                       // Morning | Afternoon | Evening | Night
            $table->string('status', 16);                     // taken | skipped
            $table->timestamp('answered_at');                 // on the phone
            $table->timestamp('received_at');                 // on the server
            $table->foreignId('app_user_id')->constrained();
            $table->timestamps();
            $table->unique(['participant_id', 'med_key', 'dose_date', 'slot']);
        });

        // BP, glucose and weight. Manual or from Health Connect; both times kept.
        Schema::create('readings', function (Blueprint $table) {
            $table->id();
            $table->uuid('client_uuid')->unique();
            $table->foreignId('participant_id')->constrained();
            $table->string('type', 16);                       // bp | glucose | weight
            $table->json('values');                           // bp: sbp,dbp,pulse · glucose: mg_dl,context · weight: kg
            $table->timestamp('measured_at');
            $table->timestamp('uploaded_at');
            $table->string('source', 24);                     // manual | health_connect
            $table->string('device', 80)->nullable();         // e.g. OMRON connect, Mi Fitness
            $table->foreignId('app_user_id')->nullable()->constrained();
            $table->timestamps();
            $table->index(['participant_id', 'type', 'measured_at']);
        });

        // Education articles and FAQ, English + Tamil. Tamil is served only once marked reviewed.
        Schema::create('content_items', function (Blueprint $table) {
            $table->id();
            $table->string('type', 16);                       // education | faq
            $table->string('category', 60)->nullable();
            $table->unsignedInteger('sort')->default(0);
            $table->string('status', 16)->default('draft');   // draft | published | archived
            $table->string('title_en', 200);
            $table->text('body_en');
            $table->string('title_ta', 200)->nullable();
            $table->text('body_ta')->nullable();
            $table->boolean('ta_reviewed')->default(false);
            $table->foreignId('ta_reviewed_by')->nullable()->constrained('users');
            $table->timestamp('published_at')->nullable();
            $table->foreignId('updated_by')->constrained('users');
            $table->timestamps();
        });

        // A caregiver tapping "Seen" on the participant's day.
        Schema::create('caregiver_seen', function (Blueprint $table) {
            $table->id();
            $table->foreignId('app_user_id')->constrained();
            $table->foreignId('participant_id')->constrained();
            $table->date('day');
            $table->timestamp('seen_at');
            $table->timestamps();
            $table->unique(['app_user_id', 'day']);
        });

        Schema::create('push_log', function (Blueprint $table) {
            $table->id();
            $table->foreignId('app_user_id')->constrained();
            $table->string('kind', 32);
            $table->string('title', 120);
            $table->string('status', 16);                     // sent | failed | skipped
            $table->string('error', 500)->nullable();
            $table->timestamps();
        });

        Schema::table('safety_alerts', function (Blueprint $table) {
            $table->foreignId('source_reading_id')->nullable()->after('source_form_id')->constrained('readings');
            $table->unique(['rule', 'source_reading_id']);
        });
    }

    public function down(): void
    {
        Schema::table('safety_alerts', function (Blueprint $table) {
            $table->dropUnique(['rule', 'source_reading_id']);
            $table->dropConstrainedForeignId('source_reading_id');
        });
        foreach (['push_log', 'caregiver_seen', 'content_items', 'readings', 'med_dose_logs', 'med_schedules', 'app_devices', 'app_tokens', 'app_users'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
