<?php

namespace App\Domain\Intelligence;

/**
 * Which authoritative part of FynnEdge an insight came from.
 *
 * There is no `ai_guess` and no `model`. Every insight in Module 31 is a
 * comparison against a figure one of these produced, and the source is
 * recorded so "where did this come from?" has an answer.
 */
enum InsightSource: string
{
    case FinancialEngine = 'financial_engine';
    case FynnScore = 'fynnscore';
    case Goal = 'goal';
    case Application = 'application';
    case ProviderEvent = 'provider_event';
}
