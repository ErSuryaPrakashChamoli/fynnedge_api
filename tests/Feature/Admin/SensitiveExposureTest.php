<?php

namespace Tests\Feature\Admin;

use App\Domain\Admin\AdminRole;
use App\Models\AdminUser;
use App\Models\CreditReport;
use App\Models\KycVerification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * What the panel must never render, checked against what it actually renders.
 *
 * A field left out of a resource is a decision that lives in one file and
 * can be undone by anybody adding a column back "to help with debugging".
 * These tests plant a recognisable value in each withheld field, load the
 * page as the most privileged role there is, and search the HTML for it —
 * so the guarantee is about the response rather than about the resource
 * definition that produced it.
 *
 * Run as Super Admin on purpose. If the most privileged role cannot see
 * these, no role can.
 */
class SensitiveExposureTest extends TestCase
{
    use RefreshDatabase;

    private function superAdmin(): AdminUser
    {
        return AdminUser::create([
            'name' => 'Exposure Probe',
            'email' => 'exposure@fynnedge.test',
            'password' => 'CorrectHorseBattery1',
            'role' => AdminRole::SuperAdmin,
            'is_active' => true,
        ]);
    }

    private function customer(): User
    {
        $user = User::create(['mobile' => '9000000900']);
        $user->ensureProfiles();

        return $user;
    }

    public function test_a_documents_storage_location_is_never_rendered(): void
    {
        $customer = $this->customer();

        $document = $customer->documents()->create([
            'type' => 'identity',
            'display_name' => 'Probe document',
            'original_filename' => 'probe.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 1024,
            'storage_disk' => 'local',
            'storage_key' => 'documents/u1/SENTINEL-STORAGE-KEY.pdf',
            'status' => 'uploaded',
        ]);

        $this->actingAs($this->superAdmin(), 'admin');

        foreach (['/admin/documents', '/admin/documents/'.$document->getKey()] as $url) {
            $html = $this->get($url)->assertSuccessful()->getContent();

            $this->assertStringNotContainsString(
                'SENTINEL-STORAGE-KEY',
                $html,
                "{$url} rendered where a customer's file is stored",
            );
        }
    }

    public function test_a_credit_bureau_reference_is_never_rendered(): void
    {
        $customer = $this->customer();

        $report = CreditReport::query()->create([
            'user_id' => $customer->getKey(),
            'bureau' => 'fixture',
            'score' => 742,
            'score_model' => 'demo-v1',
            'score_min' => 300,
            'score_max' => 900,
            'obtained_at' => now(),
            'bureau_reference' => 'SENTINEL-BUREAU-REF',
        ]);

        $this->actingAs($this->superAdmin(), 'admin');

        foreach (['/admin/credit-reports', '/admin/credit-reports/'.$report->getKey()] as $url) {
            $html = $this->get($url)->assertSuccessful()->getContent();

            $this->assertStringNotContainsString(
                'SENTINEL-BUREAU-REF',
                $html,
                "{$url} rendered the bureau's own handle for the enquiry",
            );
        }
    }

    public function test_kyc_identity_detail_is_never_rendered(): void
    {
        $customer = $this->customer();

        $document = $customer->documents()->create([
            'type' => 'identity',
            'display_name' => 'Identity document',
            'original_filename' => 'id.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 2048,
            'storage_disk' => 'local',
            'storage_key' => 'documents/u1/id.pdf',
            'status' => 'uploaded',
        ]);

        $verification = KycVerification::query()->create([
            'user_id' => $customer->getKey(),
            'document_id' => $document->getKey(),
            'provider' => 'fixture',
            'is_sample' => true,
            'verification_type' => 'identity_document',
            'status' => 'verified',
            'masked_reference' => 'SENTINEL-MASKED-PAN',
            'provider_reference' => 'SENTINEL-PROVIDER-REF',
            'method_version' => 'v1',
            'file_digest' => 'SENTINEL-FILE-DIGEST',
            'checked_at' => now(),
        ]);

        $this->actingAs($this->superAdmin(), 'admin');

        foreach (['/admin/kyc-verifications', '/admin/kyc-verifications/'.$verification->getKey()] as $url) {
            $html = $this->get($url)->assertSuccessful()->getContent();

            foreach (['SENTINEL-MASKED-PAN', 'SENTINEL-PROVIDER-REF', 'SENTINEL-FILE-DIGEST'] as $secret) {
                $this->assertStringNotContainsString(
                    $secret,
                    $html,
                    "{$url} rendered identity detail ({$secret})",
                );
            }
        }
    }

    /** @return array<string, array{string}> */
    public static function everyResourceListPage(): array
    {
        $pages = [];

        // A relative path, not app_path(): a data provider runs before the
        // application is booted and the helper does not exist yet.
        foreach (glob(__DIR__.'/../../../app/Filament/Resources/*/*Resource.php') ?: [] as $file) {
            // Filament's own derivation when a resource does not override
            // it: drop the Resource suffix, pluralise, kebab.
            $slug = Str::kebab(Str::pluralStudly(
                Str::replaceLast('Resource', '', basename($file, '.php')),
            ));

            $pages[$slug] = ['/admin/'.$slug];
        }

        return $pages;
    }

    /**
     * No page anywhere in the panel renders an authentication secret.
     *
     * Broad on purpose. The specific tests above guard fields somebody
     * chose to withhold; this one guards against a category, so a resource
     * added later that happens to dump a model wholesale is caught without
     * anybody having predicted which field would leak.
     */
    #[DataProvider('everyResourceListPage')]
    public function test_no_page_renders_an_authentication_secret(string $url): void
    {
        $customer = $this->customer();
        $customer->createToken('probe-device');

        $this->actingAs($this->superAdmin(), 'admin');

        $html = $this->get($url)->assertSuccessful()->getContent();

        // The administrator's own bcrypt hash, and any Sanctum token hash.
        $this->assertStringNotContainsString('$2y$', $html, "{$url} rendered a password hash");

        foreach (['code_hash', 'remember_token', 'payload_hash'] as $column) {
            $this->assertStringNotContainsString(
                $column,
                $html,
                "{$url} names the {$column} column in its output",
            );
        }
    }
}
