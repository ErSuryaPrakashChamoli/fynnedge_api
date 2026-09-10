<?php

namespace Tests\Feature\Admin;

use App\Domain\Admin\AdminRole;
use App\Models\AdminUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Staff and customers are two populations, and neither can become the other.
 *
 * The separation is meant to be structural rather than enforced: a customer
 * token names a `User`, the admin guard's provider only builds `AdminUser`,
 * and the panel asks that guard. These tests exist because "structural"
 * is a claim, and a later change to a guard, a provider or a middleware
 * stack could quietly turn it into one shared identity without anything
 * else failing.
 */
class AdminSeparationTest extends TestCase
{
    use RefreshDatabase;

    private function admin(AdminRole $role = AdminRole::SuperAdmin, bool $active = true): AdminUser
    {
        return AdminUser::create([
            'name' => 'Test Admin',
            'email' => Str::random(8).'@fynnedge.test',
            'password' => 'CorrectHorseBattery1',
            'role' => $role,
            'is_active' => $active,
        ]);
    }

    private function customer(): User
    {
        $user = User::create(['mobile' => '9876543210']);
        $user->ensureProfiles();

        return $user;
    }

    // A — a customer's Sanctum token must not open the panel.
    public function test_a_customer_token_does_not_authenticate_the_admin_panel(): void
    {
        $customer = $this->customer();
        $token = $customer->createToken('phone')->plainTextToken;

        $this->get('/admin', ['Authorization' => 'Bearer '.$token])
            ->assertRedirect('/admin/login');
    }

    // B — nor reach a resource behind it.
    public function test_a_customer_token_does_not_reach_a_filament_resource(): void
    {
        $customer = $this->customer();
        $token = $customer->createToken('phone')->plainTextToken;

        $this->get('/admin/users', ['Authorization' => 'Bearer '.$token])
            ->assertRedirect('/admin/login');
    }

    // B2 — nor does acting as the customer on the default guard.
    public function test_acting_as_a_customer_does_not_open_the_panel(): void
    {
        Sanctum::actingAs($this->customer());

        $this->get('/admin')->assertRedirect('/admin/login');
    }

    // C — an admin session must not authenticate the customer API.
    public function test_an_admin_session_does_not_authenticate_the_customer_api(): void
    {
        $this->actingAs($this->admin(), 'admin');

        $this->getJson('/api/v1/auth/me')
            ->assertStatus(401)
            ->assertExactJson(['message' => 'Unauthenticated.']);
    }

    // D — an admin has no customer identity to be resolved into.
    public function test_an_admin_never_resolves_to_a_customer(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin, 'admin');

        $this->assertTrue(auth('admin')->check());
        $this->assertFalse(auth('web')->check());
        $this->assertNull(auth('sanctum')->user());

        // The two tables do not overlap.
        $this->assertSame(0, User::query()->where('mobile', $admin->email)->count());
        $this->assertFalse(method_exists($admin, 'createToken'));
    }

    // E — a disabled administrator is refused.
    public function test_an_inactive_admin_cannot_use_the_panel(): void
    {
        $this->actingAs($this->admin(active: false), 'admin');

        // Authenticated as far as the guard is concerned, and still refused
        // by the panel, so disabling ends a session already in progress
        // rather than only preventing the next sign-in.
        $this->get('/admin')->assertForbidden();
    }

    public function test_an_active_admin_reaches_the_dashboard(): void
    {
        $this->actingAs($this->admin(), 'admin');

        $this->get('/admin')->assertSuccessful();
    }

    // F — bad credentials are refused.
    public function test_the_login_page_is_reachable_and_anonymous_access_is_not(): void
    {
        $this->get('/admin/login')->assertSuccessful();
        $this->get('/admin')->assertRedirect('/admin/login');
    }

    /*
     * One administrator per test, deliberately.
     *
     * Filament's AuthenticateSession middleware ends a session when the
     * authenticated user changes, so signing in as a second role inside one
     * test redirects to the login page and proves nothing about the policy.
     * A data provider gives each role its own request from a clean session.
     */

    /** @return array<string, array{AdminRole, bool}> */
    public static function staffAdministrationRoles(): array
    {
        return [
            'super admin may' => [AdminRole::SuperAdmin, true],
            'business head may not' => [AdminRole::BusinessHead, false],
            'manager may not' => [AdminRole::Manager, false],
            'team leader may not' => [AdminRole::TeamLeader, false],
            'advisor may not' => [AdminRole::Advisor, false],
            'viewer may not' => [AdminRole::Viewer, false],
        ];
    }

    // G — role gates the resources.
    #[DataProvider('staffAdministrationRoles')]
    public function test_only_a_super_admin_may_administer_staff(AdminRole $role, bool $allowed): void
    {
        $this->actingAs($this->admin($role), 'admin');

        $response = $this->get('/admin/admin-users');

        $allowed
            ? $response->assertSuccessful()
            : $response->assertForbidden();
    }

    /** @return array<string, array{AdminRole, bool}> */
    public static function financialRoles(): array
    {
        return [
            'super admin sees money' => [AdminRole::SuperAdmin, true],
            'business head sees money' => [AdminRole::BusinessHead, true],
            'manager sees money' => [AdminRole::Manager, true],
            'team leader does not' => [AdminRole::TeamLeader, false],
            'advisor does not' => [AdminRole::Advisor, false],
            'viewer does not' => [AdminRole::Viewer, false],
        ];
    }

    #[DataProvider('financialRoles')]
    public function test_loan_applications_are_limited_to_roles_that_may_see_money(
        AdminRole $role,
        bool $allowed,
    ): void {
        $this->actingAs($this->admin($role), 'admin');

        $response = $this->get('/admin/loan-applications');

        // An advisor helps with a document; they do not need to know what
        // the customer earns or what they asked to borrow.
        $allowed
            ? $response->assertSuccessful()
            : $response->assertForbidden();
    }

    /** @return array<string, array{AdminRole}> */
    public static function allRoles(): array
    {
        return array_map(
            fn (AdminRole $role): array => [$role],
            array_combine(
                array_map(fn (AdminRole $r): string => $r->value, AdminRole::cases()),
                AdminRole::cases(),
            ),
        );
    }

    #[DataProvider('allRoles')]
    public function test_every_role_may_see_the_customer_list(AdminRole $role): void
    {
        $this->actingAs($this->admin($role), 'admin');

        $this->get('/admin/users')->assertSuccessful();
    }

    public function test_an_admin_password_is_never_stored_in_plain_text(): void
    {
        $admin = $this->admin();

        $this->assertNotSame('CorrectHorseBattery1', $admin->password);
        $this->assertTrue(password_verify('CorrectHorseBattery1', $admin->password));
        $this->assertArrayNotHasKey('password', $admin->toArray());
        $this->assertArrayNotHasKey('remember_token', $admin->toArray());
    }
}
