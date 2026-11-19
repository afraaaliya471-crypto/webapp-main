<?php

use App\Models\MenuPermissionMatch;
use App\Models\Permission;
use App\Services\Rbac\MenuAccessService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;

beforeEach(function () {
    Cache::flush();

    Config::set('rbac_menu_match.modules', [
        ['key' => 'modules.procurement.purch-req', 'label' => 'Purchase Requisition', 'route_name' => null],
        ['key' => 'modules.procurement.grn',       'label' => 'Goods Receipt Note',  'route_name' => null],
    ]);
});

it('returns the permission slug mapped to a menu key', function () {
    $permission = Permission::create([
        'slug' => 'procurement.purch-req.view',
        'name' => 'View Purchase Requisitions',
    ]);

    MenuPermissionMatch::create([
        'menu_key'      => 'modules.procurement.purch-req',
        'menu_label'    => 'Purchase Requisition',
        'permission_id' => $permission->id,
    ]);

    $service = new MenuAccessService;

    expect($service->permissionSlugForMenuKey('modules.procurement.purch-req'))
        ->toBe('procurement.purch-req.view');
});

it('returns null when no permission is mapped to a menu key', function () {
    $service = new MenuAccessService;

    expect($service->permissionSlugForMenuKey('modules.procurement.purch-req'))->toBeNull();
});

it('caches the permission slug lookup', function () {
    $permission = Permission::create([
        'slug' => 'procurement.grn.view',
        'name' => 'View GRN',
    ]);

    $mapping = MenuPermissionMatch::create([
        'menu_key'      => 'modules.procurement.grn',
        'menu_label'    => 'Goods Receipt Note',
        'permission_id' => $permission->id,
    ]);

    $service = new MenuAccessService;
    expect($service->permissionSlugForMenuKey('modules.procurement.grn'))->toBe('procurement.grn.view');

    // Change the DB directly so cache is the only source of truth.
    $mapping->update(['permission_id' => null]);

    expect($service->permissionSlugForMenuKey('modules.procurement.grn'))
        ->toBe('procurement.grn.view', 'should return cached value not freshly-queried null');
});

it('busts the cache when saveMappings() is called', function () {
    $oldPermission = Permission::create(['slug' => 'old.permission', 'name' => 'Old']);
    $newPermission = Permission::create(['slug' => 'new.permission', 'name' => 'New']);

    $mapping = MenuPermissionMatch::create([
        'menu_key'      => 'modules.procurement.purch-req',
        'menu_label'    => 'Purchase Requisition',
        'permission_id' => $oldPermission->id,
    ]);

    $service = new MenuAccessService;
    expect($service->permissionSlugForMenuKey('modules.procurement.purch-req'))->toBe('old.permission');

    $service->saveMappings([
        ['id' => $mapping->id, 'permission_id' => $newPermission->id],
    ]);

    expect($service->permissionSlugForMenuKey('modules.procurement.purch-req'))
        ->toBe('new.permission', 'cache should be busted by saveMappings');
});

it('busts the cache when saveMappingByMenuKey() is called', function () {
    $newPermission = Permission::create(['slug' => 'new.permission', 'name' => 'New']);

    $service = new MenuAccessService;

    expect($service->permissionSlugForMenuKey('modules.procurement.purch-req'))->toBeNull();

    $service->saveMappingByMenuKey([
        'menu_key'      => 'modules.procurement.purch-req',
        'permission_id' => $newPermission->id,
    ]);

    expect($service->permissionSlugForMenuKey('modules.procurement.purch-req'))
        ->toBe('new.permission', 'cache should be busted by saveMappingByMenuKey');
});

it('rejects unknown menu keys when saving', function () {
    $service = new MenuAccessService;

    expect(fn () => $service->saveMappingByMenuKey([
        'menu_key'      => 'modules.totally.bogus',
        'permission_id' => null,
    ]))->toThrow(Illuminate\Validation\ValidationException::class);
});

it('returns normalized configured modules sorted by label', function () {
    Config::set('rbac_menu_match.modules', [
        ['key' => 'modules.b', 'label' => 'Zebra'],
        ['key' => 'modules.a', 'label' => 'Apple'],
        ['key' => 'modules.c', 'label' => 'Mango'],
    ]);

    $service = new MenuAccessService;
    $menus = $service->listAvailableMenus();

    expect($menus)->toHaveCount(3)
        ->and($menus[0]['key'])->toBe('modules.a')
        ->and($menus[1]['key'])->toBe('modules.c')
        ->and($menus[2]['key'])->toBe('modules.b');
});

it('ignores invalid module entries', function () {
    Config::set('rbac_menu_match.modules', [
        ['key' => 'valid.module', 'label' => 'Valid'],
        ['key' => '', 'label' => 'Empty key'],
        'not-an-array',
        ['no_key_at_all' => 'whatever'],
    ]);

    $service = new MenuAccessService;

    expect($service->listAvailableMenus())->toHaveCount(1)
        ->and($service->listAvailableMenus()[0]['key'])->toBe('valid.module');
});
