<?php

namespace Tests\Feature\Ticket;

use App\Services\Ticket\TicketNumberGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TicketNumberGeneratorTest extends TestCase
{
    use RefreshDatabase;

    public function test_numbers_follow_the_prefix_year_counter_format(): void
    {
        $generator = new TicketNumberGenerator;

        $this->assertSame('TKT-2026-000001', $generator->next(Carbon::create(2026, 3, 1)));
        $this->assertSame('TKT-2026-000002', $generator->next(Carbon::create(2026, 3, 2)));
        $this->assertSame('TKT-2026-000003', $generator->next(Carbon::create(2026, 12, 31)));
    }

    public function test_counter_restarts_each_year_and_years_are_independent(): void
    {
        $generator = new TicketNumberGenerator;

        $generator->next(Carbon::create(2026, 6, 1));
        $generator->next(Carbon::create(2026, 6, 1));

        $this->assertSame('TKT-2027-000001', $generator->next(Carbon::create(2027, 1, 1)));
        $this->assertSame('TKT-2026-000003', $generator->next(Carbon::create(2026, 6, 2)));
    }

    public function test_format_keeps_working_past_six_digits(): void
    {
        DB::table('ticket_sequences')->insert(['year' => 2026, 'last_number' => 999999]);

        $this->assertSame('TKT-2026-1000000', (new TicketNumberGenerator)->next(Carbon::create(2026, 1, 1)));
    }

    public function test_prefix_and_padding_come_from_config(): void
    {
        config(['tickets.number_prefix' => 'SUP', 'tickets.number_padding' => 4]);

        $this->assertSame('SUP-2026-0001', (new TicketNumberGenerator)->next(Carbon::create(2026, 1, 1)));
    }

    public function test_a_rolled_back_transaction_does_not_burn_a_number(): void
    {
        $generator = new TicketNumberGenerator;
        $at = Carbon::create(2026, 1, 1);

        try {
            DB::transaction(function () use ($generator, $at) {
                $generator->next($at);

                throw new \RuntimeException('ticket insert failed');
            });
        } catch (\RuntimeException) {
            // expected
        }

        $this->assertSame('TKT-2026-000001', $generator->next($at));
    }
}
