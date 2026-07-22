<?php

namespace StudentVerification\Tests;

use PHPUnit\Framework\TestCase;
use StudentVerification\Models\StudentVerification;

class VerificationModelTest extends TestCase
{
    public function testFillableAttributes(): void
    {
        $model = new StudentVerification();
        $fillable = $model->getFillable();
        $this->assertContains('user_id', $fillable);
        $this->assertContains('school', $fillable);
        $this->assertContains('student_id', $fillable);
        $this->assertContains('student_name', $fillable);
        $this->assertContains('verified', $fillable);
    }

    public function testCasts(): void
    {
        $model = new StudentVerification();
        $casts = $model->getCasts();
        $this->assertEquals('bool', $casts['verified']);
        $this->assertEquals('datetime', $casts['verified_at']);
    }

    public function testTableName(): void
    {
        $model = new StudentVerification();
        $this->assertEquals('student_verifications', $model->getTable());
    }
}
