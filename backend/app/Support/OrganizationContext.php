<?php

namespace App\Support;

/**
 * Holds the organization the current request acts within. Set by
 * SetOrganizationContext middleware from the authenticated user, and settable
 * directly in console/queue/test contexts.
 */
final class OrganizationContext
{
    private static ?int $organizationId = null;

    private static bool $disabled = false;

    public static function set(?int $organizationId): void
    {
        self::$organizationId = $organizationId;
    }

    public static function id(): ?int
    {
        return self::$disabled ? null : self::$organizationId;
    }

    public static function clear(): void
    {
        self::$organizationId = null;
        self::$disabled = false;
    }

    /**
     * Run a callback without organization scoping. Reserved for seeding and
     * maintenance commands — never reachable from an HTTP request.
     */
    public static function withoutScope(callable $callback): mixed
    {
        $previous = self::$disabled;
        self::$disabled = true;

        try {
            return $callback();
        } finally {
            self::$disabled = $previous;
        }
    }
}
