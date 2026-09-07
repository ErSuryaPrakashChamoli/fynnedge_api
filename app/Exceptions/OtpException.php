<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * A failed OTP attempt the customer can act on.
 *
 * Messages here are deliberately vague about *why* verification failed —
 * telling an attacker whether a code was wrong, expired or already used is
 * free information. The log keeps the detail.
 */
class OtpException extends Exception
{
    public function __construct(
        string $message,
        private readonly int $status = Response::HTTP_UNPROCESSABLE_ENTITY,
    ) {
        parent::__construct($message);
    }

    public static function invalid(): self
    {
        return new self('That code is not correct. Please try again.');
    }

    public static function expired(): self
    {
        return new self('That code has expired. Request a new one.');
    }

    public static function tooManyAttempts(): self
    {
        return new self(
            'Too many incorrect attempts. Request a new code.',
            Response::HTTP_TOO_MANY_REQUESTS,
        );
    }

    public function render(): JsonResponse
    {
        return response()->json(['message' => $this->getMessage()], $this->status);
    }
}
