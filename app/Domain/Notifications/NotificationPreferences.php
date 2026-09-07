<?php

namespace App\Domain\Notifications;

/**
 * Which preferences a customer is allowed to have.
 *
 * The rule Module 29 turns on: a preference must never suppress a
 * notification somebody needs in order to understand or act on something
 * they started.
 *
 * So mutability is not a list somebody maintains. A category can be muted
 * only if every notification type in it is non-transactional, and
 * NotificationType decides transactionality from the event. Adding a
 * transactional type to a category makes that category immutable the moment
 * it is added, with nobody remembering to update anything — which is the
 * failure mode a hand-written list has.
 *
 * Today that leaves financial health and goals. Applications and documents
 * cannot be muted, and neither can system messages.
 *
 * Muting affects push only. Nothing here can keep a notification out of the
 * notification centre, because the centre is what a customer opens the app
 * to read.
 */
final class NotificationPreferences
{
    /**
     * Categories in which nothing is transactional.
     *
     * @return list<NotificationCategory>
     */
    public static function mutableCategories(): array
    {
        return array_values(array_filter(
            NotificationCategory::cases(),
            fn (NotificationCategory $category) => self::isMutable($category),
        ));
    }

    /** @return list<string> */
    public static function mutableCategoryIds(): array
    {
        return array_map(
            fn (NotificationCategory $c) => $c->value,
            self::mutableCategories(),
        );
    }

    /**
     * Whether a customer may switch this category's push off.
     *
     * A category with no types at all is not mutable. That is the
     * conservative reading of an empty set and it matters: System has no
     * types yet, and a category becoming mutable by virtue of being empty
     * would be exactly backwards for the one reserved for service
     * messages.
     */
    public static function isMutable(NotificationCategory $category): bool
    {
        $types = array_filter(
            NotificationType::cases(),
            fn (NotificationType $type) => $type->category() === $category,
        );

        if ($types === []) {
            return false;
        }

        foreach ($types as $type) {
            if ($type->isTransactional()) {
                return false;
            }
        }

        return true;
    }
}
