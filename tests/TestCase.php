<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Http::fake([
            '*' => Http::response([
                'id' => 'chg_test',
                'status' => 'PENDING',
                'qr_code' => '00020126ACMEPAY.FAKE.PIX.BRL.1500.0.synthetic',
                'copy_paste' => '00020126ACMEPAY.FAKE.PIX.BRL.1500.0.synthetic',
                'provider_tx_id' => 'pix_tx_test',
            ], 201),
        ]);
    }
}
