<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SendOtpRequest;
use App\Http\Requests\VerifyOtpRequest;
use App\Http\Resources\CustomerProfileResource;
use App\Services\AuthService;
use App\Services\OtpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuthController extends Controller
{
    public function __construct(
        private readonly OtpService $otp,
        private readonly AuthService $auth,
    ) {}

    public function sendOtp(SendOtpRequest $request): JsonResponse
    {
        $challenge = $this->otp->issue(
            $request->string('mobile')->toString(),
            $request->ip(),
        );

        return response()->json(['data' => array_filter(
            $challenge,
            // dev_hint is dropped entirely once a real provider is configured,
            // rather than sent as null.
            fn ($value) => $value !== null,
        )]);
    }

    public function verifyOtp(VerifyOtpRequest $request): JsonResponse
    {
        $mobile = $request->string('mobile')->toString();

        // Throws OtpException, which renders itself as a 422 or 429.
        $this->otp->verify(
            $mobile,
            $request->string('reference')->toString(),
            $request->string('code')->toString(),
        );

        $result = $this->auth->signIn(
            $mobile,
            $request->string('device_name')->toString() ?: null,
        );

        return response()->json(['data' => [
            'token' => $result['token']->plainTextToken,
            'is_new_user' => $result['is_new_user'],
            'expires_at' => $result['token']->accessToken->expires_at
                ?->toIso8601String(),
            'profile' => (new CustomerProfileResource(
                $result['user']->fresh('customerProfile'),
            ))->toArray($request),
        ]]);
    }

    public function logout(Request $request): JsonResponse
    {
        $this->auth->signOut($request->user());

        return response()->json(['data' => ['ok' => true]]);
    }

    /** Lets the app confirm a persisted token is still good on launch. */
    public function me(Request $request): CustomerProfileResource
    {
        return new CustomerProfileResource($request->user()->fresh('customerProfile'));
    }
}
