<?php

namespace App\Http\Controllers\Api;

use App\Domain\Notifications\NotificationPolicy;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreFinancialGoalRequest;
use App\Http\Resources\FinancialGoalResource;
use App\Models\FinancialGoal;
use App\Services\Intelligence\FinancialMoments;
use App\Services\Notifications\CustomerNotifications;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response;

class GoalController extends Controller
{
    public function __construct(
        private readonly CustomerNotifications $notifications,
        private readonly FinancialMoments $moments,
    ) {}

    /**
     * A milestone crossed, remembered once.
     *
     * The milestone is part of the moment's key, so refreshing the goals
     * screen five times records one "you are 50% of the way there" — and a
     * customer who dips below and returns is not congratulated twice.
     */
    private function recordMilestone(FinancialGoal $goal): void
    {
        $milestone = NotificationPolicy::milestoneFor($goal->progress());

        if ($milestone !== null) {
            $this->moments->goalMilestone($goal, $milestone);
        }
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $goals = $request->user()->goals()
            ->where('status', 'active')
            ->orderBy('target_date')
            ->get();

        return FinancialGoalResource::collection($goals);
    }

    public function store(StoreFinancialGoalRequest $request): JsonResponse
    {
        // Created through the relation, so ownership comes from the token.
        // A user_id in the payload has no rule and never reaches the model.
        $goal = $request->user()->goals()->create($request->validated());

        // A goal can be created already past a milestone — somebody
        // recording savings they already have. The milestone is part of the
        // notification's key, so this and every later edit together produce
        // one message per milestone.
        $this->notifications->goalProgressChanged($goal);
        $this->moments->goalCreated($goal);
        $this->recordMilestone($goal);

        return (new FinancialGoalResource($goal))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function show(Request $request, string $goal): FinancialGoalResource
    {
        return new FinancialGoalResource($this->ownedGoal($request, $goal));
    }

    public function update(
        StoreFinancialGoalRequest $request,
        string $goal,
    ): FinancialGoalResource {
        $model = $this->ownedGoal($request, $goal);
        $model->update($request->validated());

        $this->notifications->goalProgressChanged($model->fresh());
        $this->recordMilestone($model->fresh());

        return new FinancialGoalResource($model->fresh());
    }

    public function destroy(Request $request, string $goal): JsonResponse
    {
        $this->ownedGoal($request, $goal)->delete();

        return response()->json(null, Response::HTTP_NO_CONTENT);
    }

    /**
     * Resolves a goal that belongs to the caller, or fails.
     *
     * Deliberately not route-model binding: that resolves the row first and
     * only then checks ownership, which produced a different 404 body for
     * "exists but is not yours" than for "does not exist". The difference was
     * enough to confirm another customer's goal id. Looking the goal up
     * *through* the relation makes both cases identical.
     */
    private function ownedGoal(Request $request, string $id): FinancialGoal
    {
        $goal = $request->user()->goals()->find($id);

        abort_if(
            $goal === null,
            Response::HTTP_NOT_FOUND,
            'That goal could not be found.',
        );

        return $goal;
    }
}
