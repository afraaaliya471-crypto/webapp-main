<?php

use App\Models\Settings\AppSetting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

it('persists and retrieves a value', function () {
    AppSetting::set('test_key', 'hello');

    expect(AppSetting::get('test_key'))->toBe('hello');
});

it('returns the default when the key does not exist', function () {
    expect(AppSetting::get('missing_key', 'fallback'))->toBe('fallback');
});

it('returns null by default for missing keys', function () {
    expect(AppSetting::get('missing_key'))->toBeNull();
});

it('updates an existing key in-place rather than creating duplicates', function () {
    AppSetting::set('counter', 1);
    AppSetting::set('counter', 2);
    AppSetting::set('counter', 3);

    expect(AppSetting::get('counter'))->toBe('3')
        ->and(AppSetting::where('key', 'counter')->count())->toBe(1);
});

it('does NOT cache get() (critical for PR counter atomicity)', function () {
    // The PR number generator reads AppSetting::get inside a DB::transaction.
    // If get() were cached, two concurrent transactions could both read a stale
    // value and produce duplicate PR numbers. Guard against accidental re-caching.
    AppSetting::set('purch_req_no_sequence', 5);

    expect(AppSetting::get('purch_req_no_sequence'))->toBe('5');

    DB::table('app_settings')
        ->where('key', 'purch_req_no_sequence')
        ->update(['value' => '6']);

    expect(AppSetting::get('purch_req_no_sequence'))->toBe('6');
});

it('caches d365Creds() and busts the cache on any set()', function () {
    AppSetting::set('d365_base_url', 'https://api.example.com');
    AppSetting::set('d365_tenant_id', 'tenant-1');

    $first = AppSetting::d365Creds();
    expect($first['d365_base_url'])->toBe('https://api.example.com')
        ->and($first['d365_tenant_id'])->toBe('tenant-1');

    // Bypass the model to simulate stale data — cache should still return cached values.
    DB::table('app_settings')
        ->where('key', 'd365_base_url')
        ->update(['value' => 'https://stale.example.com']);

    expect(AppSetting::d365Creds()['d365_base_url'])
        ->toBe('https://api.example.com', 'cache should still hold the original value');

    // Any set() must bust the d365 cache, even for an unrelated key.
    AppSetting::set('other_key', 'irrelevant');

    expect(AppSetting::d365Creds()['d365_base_url'])
        ->toBe('https://stale.example.com', 'cache should be refreshed after set()');
});

it('simulates the PR counter increment pattern atomically', function () {
    // This mirrors PurchReqController::generatePRRequestId() exactly.
    $settingKey = 'purch_req_id_seq_TEST_2026';

    $first = DB::transaction(function () use ($settingKey) {
        $current = (int) AppSetting::get($settingKey, 0);
        $next = $current + 1;
        AppSetting::set($settingKey, $next);

        return $next;
    });

    $second = DB::transaction(function () use ($settingKey) {
        $current = (int) AppSetting::get($settingKey, 0);
        $next = $current + 1;
        AppSetting::set($settingKey, $next);

        return $next;
    });

    expect($first)->toBe(1)
        ->and($second)->toBe(2);
});

afterEach(function () {
    Cache::flush();
});
