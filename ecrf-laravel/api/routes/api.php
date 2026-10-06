<?php

use App\Http\Controllers\Api\AuditController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\ExportController;
use App\Http\Controllers\Api\FormController;
use App\Http\Controllers\Api\ParticipantController;
use App\Http\Controllers\Api\RoleController;
use App\Http\Controllers\Api\UserController;
use Illuminate\Support\Facades\Route;

// All routes here are prefixed with /api.

Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:10,1');

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
