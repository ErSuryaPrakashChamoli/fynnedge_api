<?php

use App\Http\Controllers\Api\AiController;
use App\Http\Controllers\Api\AiMemoryController;
use App\Http\Controllers\Api\ApplicationController;
use App\Http\Controllers\Api\ApplicationDocumentController;
use App\Http\Controllers\Api\ApplicationSubmissionController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ConsentController;
use App\Http\Controllers\Api\CreditController;
use App\Http\Controllers\Api\DecisionController;
use App\Http\Controllers\Api\DocumentController;
use App\Http\Controllers\Api\DocumentScanController;
use App\Http\Controllers\Api\FinancialSummaryController;
use App\Http\Controllers\Api\FynnTwinController;
use App\Http\Controllers\Api\GoalController;
use App\Http\Controllers\Api\HomeController;
use App\Http\Controllers\Api\IntelligenceController;
use App\Http\Controllers\Api\KycController;
use App\Http\Controllers\Api\MatchController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\NotificationDeviceController;
use App\Http\Controllers\Api\NotificationPreferenceController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\ProviderEventController;
use App\Http\Controllers\Api\ScoreController;
use App\Http\Controllers\Api\SupportController;
use Illuminate\Support\Facades\Route;

/*
|---------------------------------------------------------------------------
| FynnEdge API v1
|---------------------------------------------------------------------------
| Prefixed with /api/v1 in bootstrap/app.php.
|
| Every endpoint answers for the authenticated customer, from their own
| stored records and the domain engines. The JSON shapes here are the
| contract the Flutter app parses — change the implementation freely, but
| keep the keys.
|
| Only the two OTP endpoints are public, and both are rate limited by mobile
| number and by IP. Everything else requires a Sanctum token.
*/

Route::post('/auth/send-otp', [AuthController::class, 'sendOtp'])
    ->middleware('throttle:otp-send');

Route::post('/auth/verify-otp', [AuthController::class, 'verifyOtp'])
    ->middleware('throttle:otp-verify');

/*
 * Provider events.
 *
 * Deliberately outside the customer session group. A provider callback
 * carries no customer token and must never be able to act as one; equally a
 * customer's token authenticates nothing here. Provider identity is proved
 * by the provider's own scheme, inside the controller.
 *
 * NO LIVE PROVIDER EVENT SOURCE IS CONNECTED: with the default driver the
 * allowlist is empty and every provider code is a 404.
 */
Route::post('/provider-events/{provider}', [ProviderEventController::class, 'store'])
    ->middleware('throttle:provider-events');

Route::middleware(['auth:sanctum', 'throttle:api'])->group(function () {
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/auth/me', [AuthController::class, 'me']);

    Route::get('/home', [HomeController::class, 'index']);

    Route::get('/profile', [ProfileController::class, 'show']);
    Route::put('/profile', [ProfileController::class, 'update']);

    Route::get('/financial-profile', [ProfileController::class, 'financial']);
    // PUT and PATCH behave identically: the FormRequest requires the core
    // figures either way, so a partial write cannot leave a half-defined
    // profile that every downstream calculation would inherit.
    Route::match(
        ['put', 'patch'],
        '/financial-profile',
        [ProfileController::class, 'updateFinancial'],
    );

    // The complete financial-engine result, including aggregate goal figures
    // and the formulas behind every number.
    Route::get('/financial-summary', [FinancialSummaryController::class, 'show']);

    Route::get('/fynn-score', [ScoreController::class, 'show']);

    Route::get('/goals', [GoalController::class, 'index']);
    Route::post('/goals', [GoalController::class, 'store']);
    Route::get('/goals/{goal}', [GoalController::class, 'show']);
    Route::put('/goals/{goal}', [GoalController::class, 'update']);
    Route::delete('/goals/{goal}', [GoalController::class, 'destroy']);

    Route::get('/products', [ProductController::class, 'index']);
    Route::get('/products/{id}', [ProductController::class, 'show']);
    Route::post('/compare', [ProductController::class, 'compare']);

    // Which catalogue products fit this customer's stated need.
    Route::get('/fynn-match', [MatchController::class, 'index']);

    // One product at a chosen amount and tenure — Loan Detail's simulation.
    // Read-only: a moved slider changes nothing the customer has saved.
    Route::get('/fynn-match/{product}', [MatchController::class, 'show']);

    // FynnVault. Files are streamed by the controller from a private disk;
    // there is no route that reaches one without a token.
    Route::get('/documents', [DocumentController::class, 'index']);
    Route::get('/documents/limits', [DocumentController::class, 'limits']);
    Route::get('/documents/{document}', [DocumentController::class, 'show']);
    Route::get('/documents/{document}/download', [DocumentController::class, 'download']);

    // Writing to the vault costs storage, so it carries its own limit on
    // top of the global one. Reads do not.
    Route::middleware('throttle:vault-write')->group(function () {
        Route::post('/documents', [DocumentController::class, 'store']);
        Route::delete('/documents/{document}', [DocumentController::class, 'destroy']);
    });

    /*
     * FynnScan.
     *
     * Reading the state never asks a provider, so it sits outside the
     * limiter. Asking for a document to be read may cost money, so it does
     * not. Reviewing is the customer's own answer and costs nothing.
     */
    Route::get('/documents/{document}/scan', [DocumentScanController::class, 'show']);
    Route::post('/documents/{document}/scan/review', [DocumentScanController::class, 'review']);

    Route::middleware('throttle:scan-write')->group(function () {
        Route::post('/documents/{document}/scan', [DocumentScanController::class, 'store']);
    });

    /*
     * Identity verification.
     *
     * A separate capability from FynnScan on the same document, with its own
     * consent: reading a document and having an identity checked are
     * different questions. Reading the state asks no provider and sits
     * outside the limiter; asking for a check may cost money and does not.
     */
    Route::get('/documents/{document}/kyc', [KycController::class, 'show']);
    Route::post('/documents/{document}/kyc/resolve', [KycController::class, 'resolve']);

    Route::middleware('throttle:kyc-write')->group(function () {
        Route::post('/documents/{document}/kyc', [KycController::class, 'store']);
    });

    // FynnTwin. Stateless: a simulation computes and returns, and writes
    // nothing about the customer.
    Route::get('/fynn-twin', [FynnTwinController::class, 'index']);
    Route::post('/fynn-twin/simulate', [FynnTwinController::class, 'simulate']);

    // Privacy and consent.
    Route::get('/consents', [ConsentController::class, 'index']);
    Route::post('/consents', [ConsentController::class, 'store']);
    Route::post('/consents/{type}/withdraw', [ConsentController::class, 'withdraw']);
    Route::get('/consents/history', [ConsentController::class, 'history']);

    Route::get('/applications', [ApplicationController::class, 'index']);
    Route::post('/applications', [ApplicationController::class, 'store']);
    Route::get('/applications/{application}', [ApplicationController::class, 'show']);
    Route::match(['put', 'patch'], '/applications/{application}', [ApplicationController::class, 'update']);
    Route::post('/applications/{application}/submit', [ApplicationController::class, 'submit']);
    Route::post('/applications/{application}/cancel', [ApplicationController::class, 'cancel']);

    /*
     * Application documents.
     *
     * Preparing an application inside FynnEdge: which documents it asks
     * for, which of the customer's own vault documents fill them, and
     * whether they have confirmed each one. Nothing here sends anything
     * anywhere — external submission is the routes below, with its own
     * consent.
     */
    Route::get(
        '/applications/{application}/documents',
        [ApplicationDocumentController::class, 'index'],
    );
    Route::post(
        '/applications/{application}/documents',
        [ApplicationDocumentController::class, 'store'],
    );
    Route::delete(
        '/applications/{application}/documents/{requirement}',
        [ApplicationDocumentController::class, 'destroy'],
    );
    Route::post(
        '/applications/{application}/documents/{requirement}/review',
        [ApplicationDocumentController::class, 'review'],
    );

    /*
     * External submission.
     *
     * Separate from POST /applications/{id}/submit, which is Module 9's
     * "the customer has completed their application in FynnEdge". This is
     * "send it to a provider", which is a different act with its own
     * consent and its own outcome. Reading the state asks no provider and
     * sits outside the limiter; sending may create an application at an
     * external party and does not.
     */
    Route::get(
        '/applications/{application}/submission',
        [ApplicationSubmissionController::class, 'show'],
    );

    Route::middleware('throttle:lender-submit')->group(function () {
        Route::post(
            '/applications/{application}/submission',
            [ApplicationSubmissionController::class, 'store'],
        );
    });

    // Credit information. Reading the status asks no bureau anything.
    Route::get('/credit/status', [CreditController::class, 'status']);

    // A check may cost money and be recorded as an enquiry against the
    // customer, so it carries its own limit well below the global one.
    Route::middleware('throttle:credit-check')->group(function () {
        Route::post('/credit/check', [CreditController::class, 'check']);
    });

    /*
     * The notification centre.
     *
     * Read and read-state only. Nothing here creates a notification —
     * notifications come from things that actually happened to the
     * customer's applications, documents and goals, and an endpoint that
     * could write one would let a client put words in FynnEdge's mouth.
     *
     * Literal segments before the wildcard, so 'read-all' and
     * 'unread-count' are never taken for an id.
     */
    /*
     * Support.
     *
     * NO ADVISOR SYSTEM OR SUPPORT CRM IS CONNECTED. These write to
     * FynnEdge's own store. There is deliberately no advisor endpoint here:
     * an advisor mutation reachable with a customer token would let a
     * customer author FynnEdge's side of their own case.
     *
     * Creation and messages are limited separately — creating a case is a
     * heavier act than adding a line to one that exists.
     */
    /*
     * Financial intelligence.
     *
     * One aggregate read, plus the timeline as its own question. Read-only
     * throughout: nothing here creates a moment, because a moment records
     * something that happened and an endpoint that could write one would
     * let a client write history.
     */
    /*
     * Decision history.
     *
     * Read-only. Nothing here creates a decision — the two that are
     * recorded are written from the customer's own action, in the endpoint
     * that action reaches, and an endpoint that could write one would let a
     * client write history.
     */
    Route::get('/decisions', [DecisionController::class, 'index']);
    Route::get('/decisions/{decision}', [DecisionController::class, 'show']);

    Route::get('/intelligence', [IntelligenceController::class, 'show']);
    Route::get('/intelligence/timeline', [IntelligenceController::class, 'timeline']);

    Route::get('/support/categories', [SupportController::class, 'categories']);
    Route::get('/support/cases', [SupportController::class, 'index']);
    Route::get('/support/cases/{case}', [SupportController::class, 'show']);
    Route::post('/support/cases/{case}/close', [SupportController::class, 'close']);

    Route::middleware('throttle:support-create')->group(function () {
        Route::post('/support/cases', [SupportController::class, 'store']);
    });

    Route::middleware('throttle:support-message')->group(function () {
        Route::post('/support/cases/{case}/messages', [SupportController::class, 'addMessage']);
    });

    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::get('/notifications/unread-count', [NotificationController::class, 'unreadCount']);
    Route::post('/notifications/read-all', [NotificationController::class, 'markAllRead']);

    Route::get('/notifications/preferences', [NotificationPreferenceController::class, 'show']);
    Route::put('/notifications/preferences', [NotificationPreferenceController::class, 'update']);

    Route::post('/notifications/devices', [NotificationDeviceController::class, 'store']);
    Route::delete('/notifications/devices/{device}', [NotificationDeviceController::class, 'destroy']);

    Route::get('/notifications/{notification}', [NotificationController::class, 'show']);
    Route::post('/notifications/{notification}/read', [NotificationController::class, 'markRead']);

    // FynnAI. Conversations and memory belong to the authenticated
    // customer; the assistant only ever sees context built from that user.
    Route::get('/ai/context', [AiController::class, 'context']);
    Route::get('/ai/conversations', [AiController::class, 'index']);
    Route::get('/ai/conversations/{conversation}', [AiController::class, 'show']);
    Route::delete('/ai/conversations/{conversation}', [AiController::class, 'destroy']);

    Route::get('/ai/memory', [AiMemoryController::class, 'index']);
    Route::delete('/ai/memory', [AiMemoryController::class, 'clear']);
    Route::delete('/ai/memory/{memory}', [AiMemoryController::class, 'destroy']);

    // Generating an answer and starting a conversation cost more than a
    // read, so they carry their own limit on top of the global one.
    Route::middleware('throttle:ai-write')->group(function () {
        Route::post('/ai/conversations', [AiController::class, 'store']);
        Route::post('/ai/conversations/{conversation}/messages', [AiController::class, 'send']);
    });
});
