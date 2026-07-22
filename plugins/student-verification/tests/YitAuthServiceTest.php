<?php

namespace StudentVerification\Tests;

use PHPUnit\Framework\TestCase;
use StudentVerification\Services\YitAuthService;

class YitAuthServiceTest extends TestCase
{
    public function testGetSchoolReturnsYit(): void
    {
        $service = new YitAuthService();
        $this->assertEquals('yit', $service->getSchool());
    }

    public function testVerifyWithEmptyCredentials(): void
    {
        $service = $this->getMockBuilder(YitAuthService::class)
            ->onlyMethods(['httpPost'])
            ->getMock();

        $result = $service->verify('', '');
        $this->assertIsArray($result);
    }
}
