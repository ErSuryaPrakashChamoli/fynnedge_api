<?php

namespace Tests\Unit;

use App\Domain\Scan\ConfidenceLevel;
use App\Domain\Scan\DocumentFamily;
use App\Domain\Scan\DocumentNormalizationFailed;
use App\Domain\Scan\Fixture\IncomeDocumentNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Provider vocabulary in, FynnEdge vocabulary out.
 *
 * Every value here is synthetic. No real payslip, employer or person is
 * involved.
 */
class IncomeNormalizerTest extends TestCase
{
    private function normalizer(): IncomeDocumentNormalizer
    {
        return new IncomeDocumentNormalizer;
    }

    /** @return array<string, mixed> */
    private function response(array $fields = [], array $extra = []): array
    {
        return array_merge([
            'documentClass' => 'salary_slip',
            'modelVersion' => 'fixture-payslip-2026.09',
            'fields' => array_merge([
                'employerName' => ['text' => 'Northwind Synthetic', 'extractionScore' => 0.96],
                'employeeFullName' => ['text' => 'Sample Employee', 'extractionScore' => 0.95],
                'grossPay' => ['text' => '₹85,000.00', 'extractionScore' => 0.97],
                'netPay' => ['text' => '₹75,000.00', 'extractionScore' => 0.98],
                'payCycle' => ['text' => '2026-08', 'extractionScore' => 0.94],
            ], $fields),
        ], $extra);
    }

    public function test_provider_field_names_become_fynnedge_ones(): void
    {
        $extraction = $this->normalizer()->normalize($this->response());

        $keys = array_map(fn ($f) => $f->key, $extraction->fields);

        $this->assertSame([
            'employer_name', 'employee_name',
            'gross_income', 'net_income', 'pay_period',
        ], $keys);

        // And none of the provider's own names survive.
        foreach ($keys as $key) {
            $this->assertStringNotContainsString('Pay', $key);
            $this->assertStringNotContainsString('Cycle', $key);
        }
    }

    public function test_money_arrives_as_exact_paise(): void
    {
        $extraction = $this->normalizer()->normalize($this->response());

        $this->assertSame(7500000, $extraction->field('net_income')->paise);
        $this->assertSame(8500000, $extraction->field('gross_income')->paise);
        $this->assertSame('75000.00', $extraction->field('net_income')->value);
    }

    /**
     * Amounts a contract defines, and amounts nobody does.
     */
    #[DataProvider('amounts')]
    public function test_only_unambiguous_amounts_become_numbers(
        string $text,
        ?int $expectedPaise,
    ): void {
        $extraction = $this->normalizer()->normalize(
            $this->response(['netPay' => ['text' => $text, 'extractionScore' => 0.99]]),
        );

        $field = $extraction->field('net_income');

        $this->assertSame($expectedPaise, $field->paise, $text);

        if ($expectedPaise === null) {
            // Read, and not understood. Never silently dropped and never
            // guessed into a figure.
            $this->assertTrue($field->needsReview());
            $this->assertSame(ConfidenceLevel::Unavailable, $field->confidence);
        }
    }

    public static function amounts(): array
    {
        return [
            'rupee sign and grouping' => ['₹75,000.00', 7500000],
            'plain' => ['75000', 7500000],
            'plain with decimals' => ['75000.50', 7500050],
            'spaces' => ['₹ 75 000', 7500000],

            // Shorthand no contract defines.
            'thousands shorthand' => ['75K', null],
            'lakh shorthand' => ['0.75L', null],
            'words' => ['seventy five thousand', null],
            'negative' => ['-75000', null],
            'three decimals' => ['75000.123', null],
            'range' => ['70000-80000', null],
            'empty' => ['', null],
        ];
    }

    /**
     * Dates the document states, and dates it does not.
     */
    #[DataProvider('periods')]
    public function test_only_unambiguous_periods_are_accepted(
        string $text,
        bool $accepted,
    ): void {
        $extraction = $this->normalizer()->normalize(
            $this->response(['payCycle' => ['text' => $text, 'extractionScore' => 0.99]]),
        );

        $this->assertSame(
            $accepted,
            ! $extraction->field('pay_period')->needsReview(),
            $text,
        );

        $this->assertSame($accepted ? $text : null, $extraction->documentPeriod);
    }

    public static function periods(): array
    {
        return [
            'iso month' => ['2026-08', true],

            // 01/02/26 is either 1 February or 2 January depending on a
            // convention the document does not state.
            'slashes' => ['01/02/26', false],
            'dots' => ['01.02.26', false],
            'month name' => ['August 2026', false],
            'month 13' => ['2026-13', false],
            'year only' => ['2026', false],
        ];
    }

    #[DataProvider('scores')]
    public function test_provider_scores_map_onto_one_documented_scale(
        mixed $score,
        ConfidenceLevel $expected,
    ): void {
        $extraction = $this->normalizer()->normalize(
            $this->response(['netPay' => ['text' => '75000', 'extractionScore' => $score]]),
        );

        $this->assertSame($expected, $extraction->field('net_income')->confidence);
    }

    public static function scores(): array
    {
        return [
            'clearly sure' => [0.98, ConfidenceLevel::High],
            'at the boundary' => [0.90, ConfidenceLevel::High],
            'middling' => [0.75, ConfidenceLevel::Medium],
            'unsure' => [0.41, ConfidenceLevel::Low],

            // A provider that says nothing about how sure it was is not
            // low confidence — it is silent, and that is a different thing.
            'silent' => [null, ConfidenceLevel::Unavailable],
            'not a number' => ['very confident', ConfidenceLevel::Unavailable],
        ];
    }

    public function test_evidence_points_at_the_document_without_copying_it(): void
    {
        $extraction = $this->normalizer()->normalize($this->response([
            'netPay' => [
                'text' => '₹75,000.00',
                'extractionScore' => 0.98,
                'location' => [
                    'pageNumber' => 2,
                    'section' => 'Net Pay',
                    'rect' => [0.1, 0.7, 0.46, 0.74],
                ],
            ],
        ]));

        $evidence = $extraction->field('net_income')->evidence;

        $this->assertSame(2, $evidence->page);
        $this->assertSame('Net Pay', $evidence->section);
        $this->assertSame('₹75,000.00', $evidence->snippet);
        $this->assertSame([0.1, 0.7, 0.46, 0.74], $evidence->box);
    }

    public function test_a_response_with_no_fields_is_refused(): void
    {
        $this->expectException(DocumentNormalizationFailed::class);

        $this->normalizer()->normalize(['documentClass' => 'salary_slip']);
    }

    public function test_a_layout_change_that_drops_a_field_fails_safely(): void
    {
        // A provider reshuffles its output and a field disappears. The
        // reading reports what it could not find rather than omitting it.
        $response = $this->response();
        unset($response['fields']['netPay']);

        $extraction = $this->normalizer()->normalize($response);
        $field = $extraction->field('net_income');

        $this->assertNotNull($field);
        $this->assertNull($field->value);
        $this->assertTrue($field->needsReview());
        $this->assertFalse($extraction->isComplete());
    }

    public function test_an_unknown_document_class_is_never_read_as_the_filed_one(): void
    {
        $extraction = $this->normalizer()->normalize(
            $this->response([], ['documentClass' => 'form_16']),
        );

        $this->assertSame('unknown:form_16', $extraction->classifiedAs);
        $this->assertTrue($extraction->disagreesWith(
            DocumentFamily::Income,
        ));
    }

    public function test_surplus_provider_data_never_becomes_a_field(): void
    {
        $extraction = $this->normalizer()->normalize($this->response([], [
            'consumer' => [
                'panNumber' => 'AAAAA0000A',
                'aadhaarNumber' => '0000 0000 0000',
                'bankAccountNumber' => '00000000000',
            ],
            'rawText' => 'PAN AAAAA0000A NET PAY 75,000.00',
            'deductions' => [['label' => 'PF', 'amount' => '6000']],
        ]));

        $serialized = json_encode(array_map(
            fn ($f) => $f->toArray(),
            $extraction->fields,
        ));

        foreach ([
            'AAAAA0000A', '0000 0000 0000', '00000000000', 'PAN', 'PF',
        ] as $leak) {
            $this->assertStringNotContainsString($leak, $serialized, $leak);
        }

        $this->assertCount(5, $extraction->fields);
    }
}
