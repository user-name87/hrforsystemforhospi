<?php

namespace Tests\Unit;

use App\Support\ViolationPolicy;
use PHPUnit\Framework\TestCase;

class ViolationPolicyTest extends TestCase
{
    public function test_minutes_map_to_the_right_row_of_the_penalty_table(): void
    {
        $this->assertSame(1, ViolationPolicy::rowForMinutes(1));
        $this->assertSame(1, ViolationPolicy::rowForMinutes(15));
        $this->assertSame(2, ViolationPolicy::rowForMinutes(16));
        $this->assertSame(2, ViolationPolicy::rowForMinutes(30));
        $this->assertSame(3, ViolationPolicy::rowForMinutes(31));
        $this->assertSame(3, ViolationPolicy::rowForMinutes(60));
        $this->assertSame(4, ViolationPolicy::rowForMinutes(61));
    }

    public function test_penalty_escalates_with_each_occurrence(): void
    {
        $this->assertSame('تنبيه شفوي', ViolationPolicy::penalty(1, 1));
        $this->assertSame('إنذار كتابي', ViolationPolicy::penalty(1, 2));
        $this->assertSame('خصم مرتب ربع يوم عمل', ViolationPolicy::penalty(1, 3));
        $this->assertSame('خصم مرتب نصف يوم عمل', ViolationPolicy::penalty(1, 4));
    }

    public function test_occurrences_past_the_fourth_keep_the_harshest_penalty(): void
    {
        $this->assertSame(ViolationPolicy::penalty(4, 4), ViolationPolicy::penalty(4, 9));
    }

    public function test_absence_uses_its_own_ladder_and_article(): void
    {
        $this->assertSame('الغياب بدون إجازة', ViolationPolicy::article(null));
        $this->assertNotSame(ViolationPolicy::penalty(null, 1), ViolationPolicy::penalty(null, 3));
    }
}
