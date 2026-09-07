<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The shape Flutter's UserProfile.fromJson expects.
 *
 * Identity lives on users, the rest on customer_profiles; the app sees one
 * flat object. Keys here are contract — changing one breaks the app.
 *
 * @property-read User $resource
 */
class CustomerProfileResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $this->resource;
        $profile = $user->customerProfile;

        return [
            'id' => (string) $user->id,
            'mobile' => (string) $user->mobile,
            'full_name' => $profile?->full_name ?? '',
            'age' => $profile?->age,
            'city' => $profile?->city ?? '',
            'email' => $profile?->email ?? '',
            'intent' => $profile?->intent,
            'primary_goal' => $profile?->primary_goal,
            'onboarding_complete' => $profile?->onboarding_completed_at !== null,
        ];
    }
}
