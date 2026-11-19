<?php

namespace App\Services\Rbac;

use App\Models\Company;
use App\Models\MenuPermissionMatch;
use App\Models\Permission;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Resolves which menu items a user can see in a given company context,
 * based on configurable menu→permission mappings.
 */
class MenuAccessService
{
    /** Cache key for the menu→permission slug mapping (shared across all users). */
    private const PERMISSION_SLUGS_CACHE_KEY = 'tanseeq:menu:permission_slugs';

    /** TTL (seconds) for the menu→permission mapping cache. */
    private const PERMISSION_SLUGS_CACHE_TTL = 3600;

    /**
     * Modules declared in config/rbac_menu_match.php.
     *
     * @return array<int, array{key: string, label?: string, route_name?: string|null}>
     */
    public function configuredModules(): array
    {
        $modules = config('rbac_menu_match.modules', []);

        return is_array($modules) ? $modules : [];
    }

    /**
     * Mirror configured modules into the menu_permission_matches table so the
     * admin UI always lists every module declared in config.
     */
    public function syncConfiguredModules(): void
    {
        if (! Schema::hasTable('menu_permission_matches')) {
            return;
        }

        foreach ($this->normalizedConfiguredModules() as $module) {
            MenuPermissionMatch::query()->updateOrCreate(
                ['menu_key' => $module['key']],
                [
                    'menu_label' => $module['label'],
                    'route_name' => $module['route_name'] ?? null,
                ],
            );
        }
    }

    /** List every configured menu item with its current permission mapping. */
    public function listMappingsWithPermissions(): Collection
    {
        if (! Schema::hasTable('menu_permission_matches')) {
            return new Collection;
        }

        $keys = collect($this->normalizedConfiguredModules())->pluck('key')->all();

        if ($keys === []) {
            return new Collection;
        }

        $this->syncConfiguredModules();

        return MenuPermissionMatch::query()
            ->with('permission:id,slug,name')
            ->whereIn('menu_key', $keys)
            ->orderBy('menu_label')
            ->get(['id', 'menu_key', 'menu_label', 'route_name', 'permission_id']);
    }

    /** All permissions available for assignment in the admin UI. */
    public function listPermissions(): Collection
    {
        if (! Schema::hasTable('permissions')) {
            return new Collection;
        }

        return Permission::query()
            ->orderBy('name')
            ->get(['id', 'slug', 'name']);
    }

    /**
     * Persist a batch of mapping updates from the admin UI.
     *
     * @param  array<int, array{id: int, permission_id?: int|null}>  $mappings
     */
    public function saveMappings(array $mappings): void
    {
        if (! Schema::hasTable('menu_permission_matches')) {
            return;
        }

        DB::transaction(function () use ($mappings): void {
            foreach ($mappings as $mapping) {
                MenuPermissionMatch::query()
                    ->whereKey((int) $mapping['id'])
                    ->update([
                        'permission_id' => array_key_exists('permission_id', $mapping)
                            ? $mapping['permission_id']
                            : null,
                    ]);
            }
        });

        Cache::forget(self::PERMISSION_SLUGS_CACHE_KEY);
    }

    /**
     * Return menu items along with metadata about which are currently mapped
     * to permissions. Used by the admin UI to render dropdowns.
     *
     * @return array<int, array{
     *     key: string,
     *     label: string,
     *     route_name: string|null,
     *     mapping_id: int|null,
     *     permission_id: int|null,
     *     mapped: bool
     * }>
     */
    public function listAvailableMenus(): array
    {
        $configured = collect($this->normalizedConfiguredModules());

        if ($configured->isEmpty()) {
            return [];
        }

        if (! Schema::hasTable('menu_permission_matches')) {
            return $configured
                ->map(fn (array $m) => [
                    'key' => $m['key'],
                    'label' => $m['label'],
                    'route_name' => $m['route_name'],
                    'mapping_id' => null,
                    'permission_id' => null,
                    'mapped' => false,
                ])
                ->values()
                ->all();
        }

        $byKey = MenuPermissionMatch::query()
            ->whereIn('menu_key', $configured->pluck('key')->all())
            ->get(['id', 'menu_key', 'permission_id'])
            ->keyBy('menu_key');

        return $configured
            ->map(function (array $m) use ($byKey): array {
                $match = $byKey->get($m['key']);
                $permissionId = $match?->permission_id !== null ? (int) $match->permission_id : null;

                return [
                    'key' => $m['key'],
                    'label' => $m['label'],
                    'route_name' => $m['route_name'],
                    'mapping_id' => $match?->id,
                    'permission_id' => $permissionId,
                    'mapped' => $permissionId !== null,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Upsert a single menu→permission mapping (used by the per-row admin form).
     *
     * @param  array{menu_key: string, permission_id?: int|null}  $payload
     */
    public function saveMappingByMenuKey(array $payload): MenuPermissionMatch
    {
        if (! Schema::hasTable('menu_permission_matches')) {
            throw ValidationException::withMessages([
                'menu_key' => ['Menu item mapping table is missing.'],
            ]);
        }

        $menuKey = trim((string) ($payload['menu_key'] ?? ''));
        $module = $this->configuredModuleByKey($menuKey);

        if (! $module) {
            throw ValidationException::withMessages([
                'menu_key' => ['Invalid menu item.'],
            ]);
        }

        $result = DB::transaction(fn () => MenuPermissionMatch::query()->updateOrCreate(
            ['menu_key' => $module['key']],
            [
                'menu_label' => $module['label'],
                'route_name' => $module['route_name'] ?? null,
                'permission_id' => array_key_exists('permission_id', $payload)
                    ? $payload['permission_id']
                    : null,
            ],
        ));

        Cache::forget(self::PERMISSION_SLUGS_CACHE_KEY);

        return $result;
    }

    /** Whether the given user can see the given menu in the given company. */
    public function canUserAccessMenuKey(?User $user, ?Company $company, string $menuKey): bool
    {
        return $this->canUserAccessPermissionSlug(
            $user,
            $company,
            $this->permissionSlugForMenuKey($menuKey),
        );
    }

    /**
     * Bulk visibility map for every configured menu, used by the view composer.
     *
     * @return array<string, bool> keyed by menu_key, value is whether the user can see it
     */
    public function menuVisibilityForUser(?User $user, ?Company $company): array
    {
        $visibility = [];
        $permissionByMenuKey = $this->permissionSlugByMenuKey();

        foreach ($this->configuredModules() as $module) {
            $key = trim((string) ($module['key'] ?? ''));

            if ($key === '') {
                continue;
            }

            $permissionSlug = $permissionByMenuKey[$key] ?? null;
            $visibility[$key] = $this->canUserAccessPermissionSlug($user, $company, $permissionSlug);
        }

        return $visibility;
    }

    /** Look up a Company by its D365 code (case-insensitive). */
    public function resolveCompanyFromCode(?string $companyCode): ?Company
    {
        $candidate = strtoupper(trim((string) $companyCode));

        if ($candidate === '') {
            return null;
        }

        return Company::query()
            ->whereRaw('UPPER(d365_id) = ?', [$candidate])
            ->first();
    }

    /** Return the permission slug mapped to the given menu key (or null if unmapped). */
    public function permissionSlugForMenuKey(string $menuKey): ?string
    {
        return $this->permissionSlugByMenuKey()[$menuKey] ?? null;
    }

    /**
     * Single-source-of-truth for "can this user access this permission in this company?".
     * Super admins and company admins bypass all checks.
     */
    private function canUserAccessPermissionSlug(?User $user, ?Company $company, ?string $permissionSlug): bool
    {
        if (! $user) {
            return false;
        }

        if ($user->isSuperAdmin() || $user->isCompanyAdmin()) {
            return true;
        }

        if (! $company || $permissionSlug === null || $permissionSlug === '') {
            return false;
        }

        return $user->hasPermissionForCompany($company, $permissionSlug);
    }

    /**
     * Cached mapping of menu_key → permission slug.
     * Invalidated by {@see saveMappings()} and {@see saveMappingByMenuKey()}.
     *
     * @return array<string, string>
     */
    private function permissionSlugByMenuKey(): array
    {
        if (! Schema::hasTable('menu_permission_matches')) {
            return [];
        }

        return Cache::remember(
            self::PERMISSION_SLUGS_CACHE_KEY,
            self::PERMISSION_SLUGS_CACHE_TTL,
            function (): array {
                return MenuPermissionMatch::query()
                    ->leftJoin('permissions', 'permissions.id', '=', 'menu_permission_matches.permission_id')
                    ->orderBy('menu_permission_matches.menu_key')
                    ->get(['menu_permission_matches.menu_key', 'permissions.slug'])
                    ->reduce(function (array $carry, MenuPermissionMatch $row): array {
                        $slug = $row->getAttribute('slug');

                        if (is_string($slug) && $slug !== '') {
                            $carry[(string) $row->menu_key] = $slug;
                        }

                        return $carry;
                    }, []);
            },
        );
    }

    /**
     * Configured modules normalised: keys trimmed, labels defaulted, sorted by label.
     *
     * @return array<int, array{key: string, label: string, route_name: string|null}>
     */
    private function normalizedConfiguredModules(): array
    {
        return collect($this->configuredModules())
            ->map(function (mixed $module): ?array {
                if (! is_array($module)) {
                    return null;
                }

                $key = trim((string) ($module['key'] ?? ''));

                if ($key === '') {
                    return null;
                }

                return [
                    'key' => $key,
                    'label' => trim((string) ($module['label'] ?? $key)) ?: $key,
                    'route_name' => isset($module['route_name']) ? (string) $module['route_name'] : null,
                ];
            })
            ->filter(fn (?array $module) => $module !== null)
            ->unique(fn (array $module) => $module['key'])
            ->sortBy(fn (array $module) => strtolower($module['label']))
            ->values()
            ->all();
    }

    /**
     * @return array{key: string, label: string, route_name: string|null}|null
     */
    private function configuredModuleByKey(string $menuKey): ?array
    {
        return collect($this->normalizedConfiguredModules())
            ->first(fn (array $module) => $module['key'] === $menuKey);
    }
}
