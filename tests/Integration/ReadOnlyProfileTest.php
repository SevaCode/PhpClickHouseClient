<?php

declare(strict_types=1);

namespace SevaCode\ClickHouseClient\Tests\Integration;

use SevaCode\ClickHouseClient\ChcClient;
use SevaCode\ClickHouseClient\ChcException;
use SevaCode\ClickHouseClient\Exceptions\ChcServerException;

class ReadOnlyProfileTest extends IntegrationTestCase
{
    private ChcClient $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->client()->withReadOnly(0);

        try {
            $this->admin->execute('CREATE SETTINGS PROFILE IF NOT EXISTS chc_ro_profile SETTINGS readonly = 1');
            $this->admin->execute("CREATE USER IF NOT EXISTS chc_ro_user IDENTIFIED WITH no_password SETTINGS PROFILE 'chc_ro_profile'");
            $this->admin->execute('GRANT SELECT ON *.* TO chc_ro_user');
        } catch (ChcException $exception) {
            $this->cleanupAccessObjects();
            $this->markTestSkipped('ClickHouse access management is unavailable: ' . $exception->getMessage());
        }
    }

    protected function tearDown(): void
    {
        $this->cleanupAccessObjects();
        parent::tearDown();
    }

    public function testReadonlyProfileCanOverrideTheDefaultLevel(): void
    {
        $client = $this->client()->withCredentials('chc_ro_user');
        $exception = $this->caughtException(
            fn() => $client->fetchOne('SELECT 1'),
            ChcServerException::class,
        );
        $this->assertSame(164, $exception->getClickHouseErrorCode());

        $this->assertSame(1, $client->withReadOnly(1)->fetchOne('SELECT 1'));
        $this->assertSame(1, $client->withSettings(['readonly' => 1])->fetchOne('SELECT 1'));
    }

    private function cleanupAccessObjects(): void
    {
        if (!isset($this->admin)) {
            return;
        }

        try {
            $this->admin->execute('DROP USER IF EXISTS chc_ro_user');
        } catch (ChcException) {
        }
        try {
            $this->admin->execute('DROP SETTINGS PROFILE IF EXISTS chc_ro_profile');
        } catch (ChcException) {
        }
    }
}
