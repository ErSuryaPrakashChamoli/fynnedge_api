<?php

namespace App\Domain\Scan\Fixture;

use App\Domain\Money;
use App\Domain\Scan\CanonicalExtraction;
use App\Domain\Scan\ConfidenceLevel;
use App\Domain\Scan\DocumentFamily;
use App\Domain\Scan\DocumentNormalizationFailed;
use App\Domain\Scan\DocumentNormalizer;
use App\Domain\Scan\ExtractedField;
use App\Domain\Scan\FieldEvidence;

/**
 * A salary slip, translated.
 *
 * Provider field           →  FynnEdge field
 *   employerName           →  employer_name
 *   employeeFullName       →  employee_name
 *   grossPay               →  gross_income   (money, paise)
 *   netPay                 →  net_income     (money, paise)
 *   payCycle               →  pay_period
 *   extractionScore        →  confidence     (high | medium | low)
 *   location.pageNumber    →  evidence.page
 *   location.section       →  evidence.section
 *   location.rect          →  evidence.box
 *   documentClass          →  classified_as
 *
 * Dropped entirely: consumer (name, PAN, Aadhaar, date of birth, address,
 * bank account, IFSC), deductions, rawText. None of it is needed to answer
 * whether a customer's stated income matches their payslip, so none of it is
 * read into a field, persisted, logged or returned.
 *
 * Gross and net are kept as two separate fields. Which of them a customer's
 * "monthly income" means is a business rule, and there isn't one — so the
 * document's own distinction survives to the customer, who decides.
 */
class IncomeDocumentNormalizer implements DocumentNormalizer
{
    /**
     * Confidence, mapped onto FynnEdge's scale.
     *
     * These boundaries are the fixture's, and are documented as such. A real
     * provider's scores mean whatever that provider's contract says they
     * mean; its normalizer maps them itself, and maps to Unavailable if the
     * contract does not define them.
     */
    private const HIGH = 0.90;

    private const MEDIUM = 0.70;

    public function family(): DocumentFamily
    {
        return DocumentFamily::Income;
    }

    public function normalize(array $response): CanonicalExtraction
    {
        $fields = $response['fields'] ?? null;

        if (! is_array($fields) || $fields === []) {
            throw new DocumentNormalizationFailed(
                'the response carried no readable fields',
            );
        }

        return new CanonicalExtraction(
            family: DocumentFamily::Income,
            schemaVersion: DocumentFamily::Income->schemaVersion(),
            fields: [
                $this->text($fields, 'employerName', 'employer_name', 'Employer'),
                $this->text($fields, 'employeeFullName', 'employee_name', 'Employee name'),
                $this->money($fields, 'grossPay', 'gross_income', 'Gross pay'),
                $this->money($fields, 'netPay', 'net_income', 'Net pay'),
                $this->period($fields),
            ],
            classifiedAs: $this->classification($response),
            documentPeriod: $this->periodValue($fields),
            modelVersion: isset($response['modelVersion'])
                ? (string) $response['modelVersion']
                : null,
        );
    }

    /**
     * A monetary field.
     *
     * Two candidates with nothing to choose between them is not a value —
     * it is a question for the customer, so the field is carried with both
     * named and marked for review rather than the first one being taken.
     *
     * @param  array<string, mixed>  $fields
     */
    private function money(
        array $fields,
        string $providerKey,
        string $key,
        string $label,
    ): ExtractedField {
        $raw = $fields[$providerKey] ?? null;

        if (! is_array($raw)) {
            return ExtractedField::missing(
                $key,
                $label,
                'money',
                'This document does not show a '.strtolower($label).'.',
            );
        }

        $amount = $this->parseAmount($raw['text'] ?? null);

        if ($amount === null) {
            // Read, and not understood. "75K" is only an amount if a
            // contract says it is, and none does.
            return ExtractedField::text(
                key: $key,
                label: $label,
                value: isset($raw['text']) ? (string) $raw['text'] : null,
                confidence: ConfidenceLevel::Unavailable,
                evidence: $this->evidence($raw),
                reviewReason: 'We could not read this as an amount. Please '
                    .'enter it yourself.',
                valueType: 'money',
            );
        }

        $alternatives = array_values(array_filter(
            (array) ($raw['alternatives'] ?? []),
            fn ($a) => $this->parseAmount($a) !== null,
        ));

        return ExtractedField::money(
            key: $key,
            label: $label,
            amount: $amount,
            confidence: $this->confidence($raw),
            evidence: $this->evidence($raw),
            reviewReason: $alternatives === []
                ? null
                : 'This document shows more than one figure here ('
                    .implode(', ', $alternatives).'). Please choose.',
        );
    }

    /** @param array<string, mixed> $fields */
    private function text(
        array $fields,
        string $providerKey,
        string $key,
        string $label,
    ): ExtractedField {
        $raw = $fields[$providerKey] ?? null;

        if (! is_array($raw) || ! isset($raw['text'])) {
            return ExtractedField::missing(
                $key,
                $label,
                'text',
                'This document does not show a '.strtolower($label).'.',
            );
        }

        return ExtractedField::text(
            key: $key,
            label: $label,
            value: (string) $raw['text'],
            confidence: $this->confidence($raw),
            evidence: $this->evidence($raw),
        );
    }

    /** @param array<string, mixed> $fields */
    private function period(array $fields): ExtractedField
    {
        $raw = $fields['payCycle'] ?? null;

        if (! is_array($raw) || ! isset($raw['text'])) {
            return ExtractedField::missing(
                'pay_period',
                'Pay period',
                'period',
                'This document does not show which period it covers.',
            );
        }

        $text = (string) $raw['text'];

        // Only an unambiguous period is a period. 01/02/26 is either
        // 1 February or 2 January depending on a convention the document
        // does not state, and guessing would put the wrong month on a
        // customer's record.
        if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $text)) {
            return ExtractedField::text(
                key: 'pay_period',
                label: 'Pay period',
                value: $text,
                confidence: ConfidenceLevel::Unavailable,
                evidence: $this->evidence($raw),
                reviewReason: 'We could not tell which period this covers. '
                    .'Please check it.',
                valueType: 'period',
            );
        }

        return ExtractedField::text(
            key: 'pay_period',
            label: 'Pay period',
            value: $text,
            confidence: $this->confidence($raw),
            evidence: $this->evidence($raw),
            valueType: 'period',
        );
    }

    /** @param array<string, mixed> $fields */
    private function periodValue(array $fields): ?string
    {
        $text = $fields['payCycle']['text'] ?? null;

        return is_string($text)
            && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $text) === 1
                ? $text
                : null;
    }

    /**
     * Rupees, exactly.
     *
     * Accepts what the contract defines: an optional rupee sign, Indian or
     * plain grouping, an optional two-decimal part. Refuses everything else
     * — including shorthand like "75K", which no contract defines and which
     * could as easily mean 75,000 as 75,000.00 in some other unit.
     */
    private function parseAmount(mixed $text): ?Money
    {
        if (! is_string($text)) {
            return null;
        }

        $cleaned = str_replace(['₹', ',', ' ', "\u{00A0}"], '', trim($text));

        if (preg_match('/^\d+(\.\d{1,2})?$/', $cleaned) !== 1) {
            return null;
        }

        $amount = Money::of($cleaned);

        return $amount->isNegative() ? null : $amount;
    }

    /** @param array<string, mixed> $raw */
    private function confidence(array $raw): ConfidenceLevel
    {
        $score = $raw['extractionScore'] ?? null;

        if (! is_numeric($score)) {
            return ConfidenceLevel::Unavailable;
        }

        return match (true) {
            $score >= self::HIGH => ConfidenceLevel::High,
            $score >= self::MEDIUM => ConfidenceLevel::Medium,
            default => ConfidenceLevel::Low,
        };
    }

    /** @param array<string, mixed> $raw */
    private function evidence(array $raw): ?FieldEvidence
    {
        $location = $raw['location'] ?? null;

        if (! is_array($location)) {
            return null;
        }

        return FieldEvidence::fromArray([
            'page' => $location['pageNumber'] ?? null,
            'section' => $location['section'] ?? null,
            'box' => $location['rect'] ?? null,

            // The words the figure was read from, so the customer can find
            // it on their own copy. Not the page, and not the whole text.
            'snippet' => isset($raw['text']) ? (string) $raw['text'] : null,
        ]);
    }

    /** @param array<string, mixed> $response */
    private function classification(array $response): ?string
    {
        $class = $response['documentClass'] ?? null;

        if (! is_string($class)) {
            return null;
        }

        // Provider classes map onto FynnEdge families. An unknown class is
        // reported as-is being unknown, never silently as the filed family.
        return match ($class) {
            'salary_slip', 'payslip' => DocumentFamily::Income->value,
            default => 'unknown:'.$class,
        };
    }
}
