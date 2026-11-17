<?php

namespace App\Enums;

enum TeamRole: string
{
    case Owner = 'owner';
    case Manager = 'manager';
    case Bookkeeper = 'bookkeeper';

    /**
     * Deprecated starter-kit aliases retained for existing callers.
     */
    public const Admin = self::Manager;

    public const Member = self::Bookkeeper;

    /**
     * Get the display label for the role.
     */
    public function label(): string
    {
        return match ($this) {
            self::Owner => 'Business Owner',
            self::Manager => 'Manager',
            self::Bookkeeper => 'Bookkeeper',
        };
    }

    /**
     * Get all the permissions for this role.
     *
     * @return array<TeamPermission>
     */
    public function permissions(): array
    {
        return match ($this) {
            self::Owner => TeamPermission::cases(),
            self::Manager => [
                TeamPermission::UpdateTeam,
                TeamPermission::CreateInvitation,
                TeamPermission::CancelInvitation,
            ],
            self::Bookkeeper => [],
        };
    }

    /**
     * Determine if the role has the given permission.
     */
    public function hasPermission(TeamPermission $permission): bool
    {
        return in_array($permission, $this->permissions());
    }

    /**
     * Get the hierarchy level for this role.
     * Higher numbers indicate higher privileges.
     */
    public function level(): int
    {
        return match ($this) {
            self::Owner => 3,
            self::Manager => 2,
            self::Bookkeeper => 1,
        };
    }

    /**
     * Check if this role is at least as privileged as another role.
     */
    public function isAtLeast(TeamRole $role): bool
    {
        return $this->level() >= $role->level();
    }

    /**
     * Get the roles that can be assigned to team members (excludes Owner).
     *
     * @return array<array{value: string, label: string}>
     */
    public static function assignable(): array
    {
        return collect(self::cases())
            ->filter(fn (self $role) => $role !== self::Owner)
            ->map(fn (self $role) => ['value' => $role->value, 'label' => $role->label()])
            ->values()
            ->toArray();
    }
}
