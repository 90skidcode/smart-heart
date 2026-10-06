<?php

declare(strict_types=1);

namespace SmartHeart\Auth;

use SmartHeart\Http\HttpError;

/** Who is calling: a staff user, a participant or a caregiver, from a verified access token. */
final readonly class Principal
{
    /**
     * @param 'user'|'participant'|'caregiver' $kind
     * @param list<string> $roles
     * @param list<string> $permissions
     */
    public function __construct(
        public string $kind,
        public int $id,
        public string $sessionId,
        public ?int $siteId = null,
        public array $roles = [],
        public array $permissions = [],
        public bool $blinded = false,
        public ?int $participantId = null,
        public ?int $credentialId = null,
    ) {
    }

    public function can(string $permission): bool
    {
        return in_array($permission, $this->permissions, true);
    }

    /** @throws HttpError 403 */
    public function require(string $permission): void
    {
        if (!$this->can($permission)) {
            throw HttpError::forbidden();
        }
    }

    /** @return array{type: string, id: int} the audit_log actor */
    public function actor(): array
    {
        return ['type' => $this->kind, 'id' => $this->id];
    }
}
