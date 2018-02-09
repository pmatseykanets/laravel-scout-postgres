<?php

namespace ScoutEngines\Postgres\Test;

use Mockery;
use PHPUnit\Framework\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    public function tearDown()
    {
        // Prevent PHPUnit complaining about risky tests
        // because Mockery expectations are not counted towards assertions
        if ($container = Mockery::getContainer()) {
            $this->addToAssertionCount($container->mockery_getExpectationCount());
        }

        Mockery::close();
    }
}
