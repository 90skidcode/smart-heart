<?php

use App\Http\Controllers\Api\AlertController;
use App\Http\Controllers\Api\AuditController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\ExportController;
use App\Http\Controllers\Api\FormController;
use App\Http\Controllers\Api\InstrumentController;
use App\Http\Controllers\Api\ParticipantController;
use App\Http\Controllers\Api\RandomisationController;
use App\Http\Controllers\Api\RoleController;
use App\Http\Controllers\Api\ScoringConfigController;
use App\Http\Controllers\Api\SelfEntryController;
use App\Http\Controllers\Api\UserController;
use Illuminate\Support\Facades\Route;

// All routes here are prefixed with /api.

Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:10,1');

// Tablet self-entry: no staff login; the one-time session token is the credential.
Route::middleware('throttle:120,1')->group(function () {
    Route::get('self-entry/{token}', [SelfEntryController::class, 'show']);
    Route::put('self-entry/{token}', [SelfEntryController::class, 'save']);
    Route::post('self-entry/{token}/submit', [SelfEntryController::class, 'submit']);
});

Route::middleware('auth.token')->group(function () {
    Route::get('auth/me', [AuthController::class, 'me']);
    Route::post('auth/logout', [AuthController::class, 'logout']);
    Route::post('auth/change-password', [AuthController::class, 'changePassword']);

    Route::get('dashboard', [DashboardController::class, 'index'])->middleware('screen:dashboard');

    Route::get('forms/definitions', [FormController::class, 'definitions']);
    Route::post('forms/{code}/preview', [FormController::class, 'preview']);

    Route::get('participants', [ParticipantController::class, 'index'])->middleware('screen:participants');
    Route::post('participants', [ParticipantController::class, 'store'])->middleware(['screen:participants,write', 'screen:form_reg01,write']);
    Route::get('participants/{participant}', [ParticipantController::class, 'show'])->middleware('screen:participants');
    Route::put('participants/{participant}', [ParticipantController::class, 'update'])->middleware('screen:participants,write');

    Route::get('participants/{participant}/forms/{code}', [FormController::class, 'show']);
    Route::put('participants/{participant}/forms/{code}', [FormController::class, 'save']);
    Route::post('participants/{participant}/forms/{code}/complete', [FormController::class, 'complete']);
    Route::post('participants/{participant}/forms/{code}/sign', [FormController::class, 'sign'])->middleware('throttle:10,1');
    Route::post('participants/{participant}/forms/{code}/unlock', [FormController::class, 'unlock']);

    Route::post('participants/{participant}/forms/{code}/self-entry', [SelfEntryController::class, 'start'])->middleware('screen:form_pro,write');
    Route::get('participants/{participant}/ccsps', [RandomisationController::class, 'ccsps'])->middleware('screen:ccsps');
    Route::get('participants/{participant}/randomisation', [RandomisationController::class, 'check'])->middleware('screen:randomisation');
    Route::post('participants/{participant}/randomise', [RandomisationController::class, 'randomise'])->middleware(['screen:randomisation,write', 'throttle:10,1']);

    Route::get('randomisation', [RandomisationController::class, 'status'])->middleware('screen:randomisation');
    Route::post('randomisation/list', [RandomisationController::class, 'upload'])->middleware('screen:randomisation_list,write');

    Route::get('alerts', [AlertController::class, 'index'])->middleware('screen:alerts');
    Route::get('alerts/summary', [AlertController::class, 'summary'])->middleware('screen:alerts');
    Route::post('alerts/{alert}/acknowledge', [AlertController::class, 'acknowledge'])->middleware('screen:alerts,write');
    Route::post('alerts/{alert}/close', [AlertController::class, 'close'])->middleware('screen:alerts,write');

    Route::get('instruments', [InstrumentController::class, 'index'])->middleware('screen:instruments');
    Route::get('instruments/{key}/{lang}', [InstrumentController::class, 'show'])->middleware('screen:instruments');
    Route::put('instruments/{key}/{lang}', [InstrumentController::class, 'update'])->middleware('screen:instruments,write');
    Route::post('instruments/eq5d-value-set', [InstrumentController::class, 'uploadValueSet'])->middleware('screen:instruments,write');

    Route::get('scoring-configs', [ScoringConfigController::class, 'index'])->middleware('screen:scoring');
    Route::post('scoring-configs', [ScoringConfigController::class, 'store'])->middleware('screen:scoring,write');
    Route::post('scoring-configs/{config}/approve', [ScoringConfigController::class, 'approve'])->middleware(['screen:scoring,write', 'screen:sign_forms,write']);

    Route::get('audit', [AuditController::class, 'index'])->middleware('screen:audit');
    Route::get('audit/download', [AuditController::class, 'download'])->middleware('screen:audit');

    Route::get('export/data', [ExportController::class, 'data']);
    Route::get('export/dictionary', [ExportController::class, 'dictionary']);

    Route::get('users', [UserController::class, 'index'])->middleware('screen:users');
    Route::post('users', [UserController::class, 'store'])->middleware('screen:users,write');
    Route::put('users/{user}', [UserController::class, 'update'])->middleware('screen:users,write');
    Route::post('users/{user}/reset-password', [UserController::class, 'resetPassword'])->middleware('screen:users,write');

    Route::get('roles', [RoleController::class, 'index'])->middleware('screen:roles');
    Route::post('roles', [RoleController::class, 'store'])->middleware('screen:roles,write');
    Route::put('roles/{role}', [RoleController::class, 'update'])->middleware('screen:roles,write');
    Route::delete('roles/{role}', [RoleController::class, 'destroy'])->middleware('screen:roles,write');
});
