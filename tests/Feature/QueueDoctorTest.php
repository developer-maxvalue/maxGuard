<?php

namespace Tests\Feature;

use Tests\TestCase;

final class QueueDoctorTest extends TestCase
{
    public function test_queue_doctor_accepts_the_sync_driver_without_a_worker(): void
    {
        config()->set('queue.default', 'sync');

        $this->artisan('maxguard:queue-doctor')
            ->expectsOutputToContain('No worker is required')
            ->assertSuccessful();
    }
}
