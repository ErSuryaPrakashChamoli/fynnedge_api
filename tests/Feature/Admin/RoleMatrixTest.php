<?php

namespace Tests\Feature\Admin;

use App\Domain\Admin\AdminRole;
use App\Models\AdminUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * What each role may open, stated once and checked against every resource.
 *
 * Phase 9 sampled two resources. This is the whole matrix, and it is
 * written as an expectation table rather than as assertions scattered
 * through the file so that the intended policy is readable in one place —
 * if the table is wrong, the wrongness is visible; if a resource's policy
 * drifts from it, the test says which.
 *
 * A resource missing from the table fails deliberately. The failure mode
 * being guarded against is a resource added later whose access nobody
 * decided, which is exactly how a credit report ends up readable by an
 * advisor.
 */
class RoleMatrixTest extends TestCase
{
    use RefreshDatabase;

    /**
     * resource slug => roles that may open its list page.
     *
     * @var array<string, list<AdminRole>>
     */
    private const MATRIX = [
        // Everyone. Staff cannot answer for the product without seeing who
        // the customer is or what they agreed to.
        'users' => [
            AdminRole::SuperAdmin, AdminRole::BusinessHead, AdminRole::Manager,
            AdminRole::TeamLeader, AdminRole::Advisor, AdminRole::Viewer,
        ],
        'consents' => [
            AdminRole::SuperAdmin, AdminRole::BusinessHead, AdminRole::Manager,
            AdminRole::TeamLeader, AdminRole::Advisor, AdminRole::Viewer,
        ],
        'providers' => [
            AdminRole::SuperAdmin, AdminRole::BusinessHead, AdminRole::Manager,
            AdminRole::TeamLeader, AdminRole::Advisor, AdminRole::Viewer,
        ],
        'catalogue-products' => [
            AdminRole::SuperAdmin, AdminRole::BusinessHead, AdminRole::Manager,
            AdminRole::TeamLeader, AdminRole::Advisor, AdminRole::Viewer,
        ],

        // A customer's money.
        'loan-applications' => [AdminRole::SuperAdmin, AdminRole::BusinessHead, AdminRole::Manager],
        'financial-goals' => [AdminRole::SuperAdmin, AdminRole::BusinessHead, AdminRole::Manager],
        'provider-events' => [AdminRole::SuperAdmin, AdminRole::BusinessHead, AdminRole::Manager],

        // Identity and credit — the narrowest category in the panel.
        'kyc-verifications' => [AdminRole::SuperAdmin, AdminRole::BusinessHead, AdminRole::Manager],
        'credit-reports' => [AdminRole::SuperAdmin, AdminRole::BusinessHead, AdminRole::Manager],
        'document-scans' => [AdminRole::SuperAdmin, AdminRole::BusinessHead, AdminRole::Manager],

        // Support work, plus the roles that can already see finances.
        'support-cases' => [
            AdminRole::SuperAdmin, AdminRole::BusinessHead, AdminRole::Manager,
            AdminRole::TeamLeader, AdminRole::Advisor,
        ],
        'documents' => [
            AdminRole::SuperAdmin, AdminRole::BusinessHead, AdminRole::Manager,
            AdminRole::TeamLeader, AdminRole::Advisor,
        ],
        'notifications' => [
            AdminRole::SuperAdmin, AdminRole::BusinessHead, AdminRole::Manager,
            AdminRole::TeamLeader, AdminRole::Advisor,
        ],

        // Creating an account includes choosing its role, so this is the
        // same permission as "may grant any permission".
        'admin-users' => [AdminRole::SuperAdmin],
    ];

    private function admin(AdminRole $role): AdminUser
    {
        return AdminUser::create([
            'name' => 'Matrix Probe',
            'email' => Str::random(10).'@fynnedge.test',
            'password' => 'CorrectHorseBattery1',
            'role' => $role,
            'is_active' => true,
        ]);
    }

    /** @return array<string, array{string, AdminRole, bool}> */
    public static function matrix(): array
    {
        $cases = [];

        foreach (self::MATRIX as $slug => $allowedRoles) {
            foreach (AdminRole::cases() as $role) {
                $allowed = in_array($role, $allowedRoles, true);

                $cases[sprintf('%s / %s %s', $slug, $role->value, $allowed ? 'may' : 'may not')] = [
                    $slug,
                    $role,
                    $allowed,
                ];
            }
        }

        return $cases;
    }

    #[DataProvider('matrix')]
    public function test_the_role_matrix_is_enforced(string $slug, AdminRole $role, bool $allowed): void
    {
        $this->actingAs($this->admin($role), 'admin');

        $response = $this->get("/admin/{$slug}");

        $allowed
            ? $response->assertSuccessful()
            : $response->assertForbidden();
    }

    /**
     * Every resource in the panel appears in the table above.
     *
     * Without this, a resource added later is simply not covered and its
     * access is whatever the default happened to be.
     */
    public function test_every_resource_has_a_decided_access_rule(): void
    {
        $slugs = [];

        foreach (glob(app_path('Filament/Resources/*/*Resource.php')) ?: [] as $file) {
            $directory = basename(dirname($file));
            $class = 'App\\Filament\\Resources\\'.$directory.'\\'.basename($file, '.php');

            $slugs[] = $class::getSlug();
        }

        sort($slugs);
        $decided = array_keys(self::MATRIX);
        sort($decided);

        $this->assertSame(
            $decided,
            $slugs,
            'A Filament resource exists whose access nobody decided. Add it to '
            .'RoleMatrixTest::MATRIX with the roles that may open it.',
        );
    }
}
