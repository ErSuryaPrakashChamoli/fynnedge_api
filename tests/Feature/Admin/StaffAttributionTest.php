<?php

namespace Tests\Feature\Admin;

use App\Domain\Admin\AdminRole;
use App\Exceptions\OtpException;
use App\Models\AdminUser;
use App\Models\OtpChallenge;
use App\Models\User;
use App\Services\Catalogue\CatalogueAuditor;
use App\Services\OtpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Two things Phase 9 quietly changed, and one it did not.
 *
 * Adding an admin panel made two previously-true statements false: that
 * FynnEdge has no administrator accounts, and that a catalogue change comes
 * from either a customer or the console. Both were written into
 * CatalogueAuditor as comments and as behaviour.
 */
class StaffAttributionTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): AdminUser
    {
        return AdminUser::create([
            'name' => 'Audit Probe',
            'email' => 'audit@fynnedge.test',
            'password' => 'CorrectHorseBattery1',
            'role' => AdminRole::SuperAdmin,
            'is_active' => true,
        ]);
    }

    public function test_a_catalogue_change_by_staff_names_the_member_of_staff(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin, 'admin');

        $event = app(CatalogueAuditor::class)->record('product_updated', 'catalogue_product', 1);

        $this->assertStringContainsString(
            'admin:'.$admin->getKey(),
            $event->actor_label,
            'A catalogue change made by staff was not attributed to them.',
        );

        $this->assertStringContainsString($admin->email, $event->actor_label);

        /*
         * And it must not claim a customer made it. actor_user_id is a
         * foreign key into `users`; putting an administrator's id there
         * would resolve to whichever customer shares the number.
         */
        $this->assertNull(
            $event->actor_user_id,
            'A staff id was written into a column that resolves to customers.',
        );
    }

    public function test_a_catalogue_change_with_nobody_signed_in_still_says_console(): void
    {
        $auditor = app(CatalogueAuditor::class);
        $auditor->actingFromConsole('command:catalogue');

        $event = $auditor->record('product_created', 'catalogue_product', 2);

        $this->assertSame('command:catalogue', $event->actor_label);
        $this->assertNull($event->actor_user_id);
    }

    public function test_a_customer_is_still_attributed_as_a_customer(): void
    {
        $customer = User::create(['mobile' => '9000000801']);
        $this->actingAs($customer);

        $event = app(CatalogueAuditor::class)->record('product_viewed', 'catalogue_product', 3);

        $this->assertSame('user:'.$customer->getKey(), $event->actor_label);
        $this->assertSame($customer->getKey(), $event->actor_user_id);
    }

    /**
     * Only one code for a number works at a time.
     *
     * The retire step exists to guarantee this. Written as retire-then-
     * insert it only held when requests arrived one at a time: two together
     * both found nothing live to retire and both inserted.
     */
    public function test_issuing_a_second_code_retires_the_first(): void
    {
        $otp = app(OtpService::class);

        $first = $otp->issue('9000000802');
        $second = $otp->issue('9000000802');

        $this->assertNotSame($first['reference'], $second['reference']);

        $live = OtpChallenge::query()
            ->where('mobile', '9000000802')
            ->whereNull('consumed_at')
            ->get();

        $this->assertCount(1, $live, 'More than one code for this number still works.');

        $this->assertSame(
            $second['reference'],
            $live->first()->reference,
            'The surviving code is not the most recently issued one.',
        );
    }

    public function test_a_retired_code_cannot_be_verified(): void
    {
        $otp = app(OtpService::class);

        config(['fynnedge.otp.expose_dev_hint' => true]);

        $first = $otp->issue('9000000803');
        $firstCode = $first['dev_hint'];
        $otp->issue('9000000803');

        $this->expectException(OtpException::class);
        $otp->verify('9000000803', $first['reference'], $firstCode);
    }
}
