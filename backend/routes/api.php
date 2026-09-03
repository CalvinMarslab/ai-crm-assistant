<?php

use App\Http\Controllers\Api\V1\AgentController;
use App\Http\Controllers\Api\V1\AuditLogController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CompanyController;
use App\Http\Controllers\Api\V1\ContactController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\OpportunityController;
use App\Http\Controllers\Api\V1\PipelineController;
use App\Http\Controllers\Api\V1\TaskController;
use App\Http\Controllers\Api\V1\UserController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->middleware('throttle:api')->group(function () {
    // Deliberately tighter than the rest of the API: this is the one unauthenticated
    // write endpoint, so it is the one worth brute-forcing.
    Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:login');

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('auth/me', [AuthController::class, 'me']);
        Route::post('auth/logout', [AuthController::class, 'logout']);

        // Dashboard — the execution view.
        Route::get('dashboard', [DashboardController::class, 'index']);
        Route::get('dashboard/metrics', [DashboardController::class, 'metrics']);

        // Pipeline configuration (read-only in Phase 1).
        Route::get('pipelines', [PipelineController::class, 'index']);
        Route::get('lead-sources', [PipelineController::class, 'leadSources']);

        // Companies and their unified timeline.
        Route::get('companies/{company}/contacts', [CompanyController::class, 'contacts']);
        Route::get('companies/{company}/opportunities', [CompanyController::class, 'opportunities']);
        Route::get('companies/{company}/timeline', [CompanyController::class, 'timeline']);
        Route::apiResource('companies', CompanyController::class);

        Route::apiResource('contacts', ContactController::class);

        // Agents.
        Route::get('agents/{agent}/stats', [AgentController::class, 'stats']);
        Route::get('agents/{agent}/opportunities', [AgentController::class, 'opportunities']);
        Route::apiResource('agents', AgentController::class);

        // Opportunities. Intent-specific endpoints keep the audit trail meaningful.
        Route::post('opportunities/{opportunity}/stage', [OpportunityController::class, 'changeStage']);
        Route::post('opportunities/{opportunity}/next-action', [OpportunityController::class, 'setNextAction']);
        Route::post('opportunities/{opportunity}/notes', [OpportunityController::class, 'addNote']);
        Route::post('opportunities/{opportunity}/owner', [OpportunityController::class, 'assignOwner']);
        Route::post('opportunities/{opportunity}/agent', [OpportunityController::class, 'assignAgent']);
        Route::get('opportunities/{opportunity}/timeline', [OpportunityController::class, 'timeline']);
        Route::get('opportunities/{opportunity}/stage-history', [OpportunityController::class, 'stageHistory']);
        Route::apiResource('opportunities', OpportunityController::class);

        // Tasks and follow-ups.
        Route::post('tasks/{task}/complete', [TaskController::class, 'complete']);
        Route::post('tasks/{task}/reopen', [TaskController::class, 'reopen']);
        Route::apiResource('tasks', TaskController::class);

        // In-app notification centre.
        Route::get('notifications', [NotificationController::class, 'index']);
        Route::get('notifications/unread-count', [NotificationController::class, 'unreadCount']);
        Route::post('notifications/read-all', [NotificationController::class, 'markAllRead']);
        Route::post('notifications/{uuid}/read', [NotificationController::class, 'markRead']);

        // Audit log (owner only, enforced by policy).
        Route::get('audit-logs', [AuditLogController::class, 'index']);
        Route::get('audit-logs/{subjectType}/{uuid}', [AuditLogController::class, 'forSubject']);

        // Users and roles.
        Route::get('roles', [UserController::class, 'roles']);
        Route::get('users', [UserController::class, 'index']);
        Route::post('users', [UserController::class, 'store']);
        Route::patch('users/{user}', [UserController::class, 'update']);
    });
});
