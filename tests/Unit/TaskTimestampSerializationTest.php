<?php

namespace Tests\Unit;

use App\Models\FactoryOrder;
use App\Models\Order;
use Tests\TestCase;

class TaskTimestampSerializationTest extends TestCase
{
    public function test_completion_timestamps_are_serialized_with_their_timezone(): void
    {
        $step = (new FactoryOrder())->setRawAttributes([
            'completed_at' => '2026-10-28 13:57:47',
            'engineer_confirmation_at' => null,
            'ready_at' => '2026-10-28 09:00:00',
            'finish_date' => '2026-11-05',
        ], true)->setAppends([]);
        $array = $step->attributesToArray();

        // Browsers convert an ISO 8601 UTC value to the viewer's local time.
        $this->assertSame('2026-10-28T13:57:47.000000Z', $array['completed_at']);
        $this->assertSame('2026-10-28T09:00:00.000000Z', $array['ready_at']);
        $this->assertNull($array['engineer_confirmation_at']);
        // Calendar date columns keep their stored format.
        $this->assertSame('2026-11-05', $array['finish_date']);
        $this->assertSame('2026-10-28 13:57:47', $step->getRawOriginal('completed_at'));

        $order = (new Order())->setRawAttributes(['completed_at' => '2026-10-28 13:57:47'])->setAppends([]);
        $this->assertSame('2026-10-28T13:57:47.000000Z', $order->attributesToArray()['completed_at']);
    }
}
