<?php

declare(strict_types=1);

namespace App\Application\User;

/**
 * DTO immuable de filtre pour la liste utilisateurs admin.
 *
 * Construit depuis la query string par `UserFilter::fromQuery()` qui
 * normalise les valeurs (trim, valeurs vides → null, statut limité aux
 * 3 constantes connues).
 */
final readonly class UserFilter
{
    public const string STATUS_ALL = 'all';

    public const string STATUS_ACTIVE = 'active';

    public const string STATUS_DISABLED = 'disabled';

    public function __construct(
        public ?string $search = null,
        public ?string $role = null,
        public ?string $group = null,
        public string $status = self::STATUS_ALL,
    ) {
    }

    /**
     * @param array<string, mixed> $query
     */
    public static function fromQuery(array $query): self
    {
        $search = self::normalizeString($query['q'] ?? null);
        $role = self::normalizeString($query['role'] ?? null);
        $group = self::normalizeString($query['group'] ?? null);

        $status = is_string($query['status'] ?? null) ? $query['status'] : self::STATUS_ALL;
        if (!in_array($status, [self::STATUS_ALL, self::STATUS_ACTIVE, self::STATUS_DISABLED], true)) {
            $status = self::STATUS_ALL;
        }

        return new self($search, $role, $group, $status);
    }

    public function isEmpty(): bool
    {
        return $this->search === null
            && $this->role === null
            && $this->group === null
            && $this->status === self::STATUS_ALL;
    }

    private static function normalizeString(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
