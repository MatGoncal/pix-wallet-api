<?php

use App\Models\Partner;
use Illuminate\Support\Facades\DB;

it('truncates instead of wrapping each test in a transaction', function () {
    Partner::factory()->create();

    expect(DB::transactionLevel())->toBe(0)
        ->and(Partner::query()->count())->toBe(1);
});

it('leaves no rows behind for the next test', function () {
    expect(Partner::query()->count())->toBe(0);
});

it('drives the real queue instead of the sync driver', function () {
    expect(config('queue.default'))->toBe('redis')
        ->and($this->queueSize())->toBe(0);
});

it('runs forked workers against committed state', function () {
    $partner = Partner::factory()->create(['name' => 'Concurrency Harness']);

    $this->runConcurrently([
        fn () => DB::table('partner_balances')->insert([
            'id' => (string) Str::uuid(),
            'partner_id' => $partner->id,
            'currency' => 'BRL',
            'available' => 0,
            'pending' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]),
        fn () => DB::table('partner_balances')->insert([
            'id' => (string) Str::uuid(),
            'partner_id' => $partner->id,
            'currency' => 'USD',
            'available' => 0,
            'pending' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]),
    ]);

    expect(DB::table('partner_balances')->count())->toBe(2);
});
