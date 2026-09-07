<?php

namespace App\Domain\Catalog;

/**
 * The product catalogue, as everything customer-facing sees it.
 *
 * FynnMatch, FynnTwin, the product endpoints and the application service
 * depend on this interface and nothing else, so a real provider feed can
 * replace the sample catalogue without any of them changing.
 *
 * WHAT SITS BEHIND IT
 *
 *   Provider          who offers a product; identified by code, never by
 *                     display name, so a rebrand orphans nothing
 *   CatalogueProduct  a stable identity plus a status and its versions
 *   ProductVersion    the terms that were in force over a period: pricing,
 *                     published eligibility, FynnTrust and the copy
 *   ProductSource     where the record came from, and therefore whether it
 *                     is sample or live
 *
 * A CatalogueProduct resolves to exactly one LoanProduct for a given day, or
 * to none. Two versions claiming the same day is a catalogue fault, not a
 * choice to be made quietly — see AmbiguousCatalogueException.
 *
 * WHAT THIS INTERFACE DELIBERATELY DOES NOT EXPOSE
 *
 * Versions, statuses, effective dates and source references. A caller
 * serving a customer wants this product's terms today; giving it the
 * machinery to pick a different answer is how two screens come to disagree.
 * Catalogue administration reads the richer shape directly from its own
 * implementation (see MockProductRepository::catalogueProducts) and is not
 * part of this contract.
 *
 * WHY HISTORICAL APPLICATIONS ARE SAFE
 *
 * They do not read this at all. An application records the pricing it was
 * created with, so republishing a product changes what new customers see and
 * nothing else. Proved by CatalogueVersioningTest.
 */
interface ProductRepository
{
    /**
     * @param  string|null  $category  Restrict to one product category.
     * @return list<LoanProduct>
     */
    public function all(?string $category = null): array;

    public function find(string $id): ?LoanProduct;

    /**
     * True while everything this catalogue serves is fictional sample data.
     *
     * A catalogue-wide answer for the screens that label a whole list. The
     * per-product answer lives on the product's own source, which is what a
     * mixed catalogue — some sample products, some live — would need. This
     * one stays because a list has to be labelled before its contents are
     * read.
     */
    public function isSampleData(): bool;
}
