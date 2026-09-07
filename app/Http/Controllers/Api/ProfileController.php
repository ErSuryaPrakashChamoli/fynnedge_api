<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateCustomerProfileRequest;
use App\Http\Requests\UpdateFinancialProfileRequest;
use App\Http\Resources\CustomerProfileResource;
use App\Http\Resources\FinancialProfileResource;
use App\Services\CustomerService;
use Illuminate\Http\Request;

class ProfileController extends Controller
{
    public function __construct(private readonly CustomerService $customers) {}

    public function show(Request $request): CustomerProfileResource
    {
        $user = $request->user();
        $this->customers->profileFor($user);

        return new CustomerProfileResource($user->fresh('customerProfile'));
    }

    public function update(UpdateCustomerProfileRequest $request): CustomerProfileResource
    {
        $user = $request->user();
        $this->customers->updateProfile($user, $request->validated());

        return new CustomerProfileResource($user->fresh('customerProfile'));
    }

    public function financial(Request $request): FinancialProfileResource
    {
        return new FinancialProfileResource(
            $this->customers->financialProfileFor($request->user()),
        );
    }

    /**
     * Ownership comes from the token, never from the payload: only
     * $request->validated() reaches the model, and the FormRequest has no
     * user_id rule, so a client-supplied id is discarded.
     */
    public function updateFinancial(
        UpdateFinancialProfileRequest $request,
    ): FinancialProfileResource {
        return new FinancialProfileResource(
            $this->customers->updateFinancialProfile(
                $request->user(),
                $request->validated(),
            ),
        );
    }
}
