<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /*
     * FynnAI. 'deterministic' is the rules assistant FynnEdge ships; 'none'
     * reports that FynnAI is not connected; 'fastapi' answers with the
     * FynnAI Engine configured below.
     */
    'fynn_ai' => [
        'provider' => env('FYNN_AI_PROVIDER', 'deterministic'),
    ],

    /*
     * The FynnAI Engine: a FastAPI service in front of a self-hosted Ollama
     * model. Reached from this server only — the app is never given its
     * address. With no base URL, FynnAI reports itself not connected.
     *
     * `api_key` is sent as the X-FynnAI-Key header on every request, and
     * the engine refuses anything without it. It is a server-to-server
     * secret: never returned by an endpoint, logged, or given to the app.
     * With no key, FynnAI reports itself not connected, as with no address.
     *
     * `timeout` is the whole wait for a reply, in seconds, and stays below
     * the app's own 20-second request limit so a slow model reaches the
     * customer as a clean 504 rather than the app giving up first.
     *
     * `history_messages` is how many earlier messages of the conversation
     * travel with a new one, since the engine keeps no conversation of its
     * own. 0 sends only the new message.
     */
    'fastapi' => [
        'base_url' => env('FASTAPI_BASE_URL'),
        'api_key' => env('FASTAPI_API_KEY'),
        'timeout' => (int) env('FASTAPI_TIMEOUT', 15),
        'connect_timeout' => (int) env('FASTAPI_CONNECT_TIMEOUT', 3),
        'model' => env('FASTAPI_MODEL'),
        'history_messages' => (int) env('FASTAPI_HISTORY_MESSAGES', 10),
    ],

];
