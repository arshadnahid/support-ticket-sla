<?php

namespace Tests\Feature\Ticket;

use App\Enums\TicketStatus;
use App\Events\Ticket\TicketReplied;
use App\Models\Ticket;
use App\Models\TicketReply;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;

class TicketConversationTest extends TicketTestCase
{
    private Ticket $ticket;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(config('tickets.attachments.disk'));

        $this->ticket = $this->ticketFor($this->customer, ['assigned_to' => $this->agent->id]);
    }

    private function url(?Ticket $ticket = null): string
    {
        return '/api/tickets/'.($ticket ?? $this->ticket)->number.'/replies';
    }

    private function note(string $body = 'Customer seems confused, escalate if no reply.'): TicketReply
    {
        return TicketReply::create([
            'ticket_id' => $this->ticket->id,
            'user_id' => $this->agent->id,
            'body' => $body,
            'is_internal' => true,
        ]);
    }

    public function test_customer_can_reply_on_own_ticket(): void
    {
        Event::fake([TicketReplied::class]);

        $this->as($this->customer)
            ->postJson($this->url(), ['body' => 'It still fails.'])
            ->assertCreated()
            ->assertJsonPath('data.body', 'It still fails.')
            ->assertJsonPath('data.is_internal', false)
            ->assertJsonPath('data.author.id', $this->customer->id)
            ->assertJsonPath('data.author_is_staff', false);

        $this->assertDatabaseHas('ticket_replies', ['ticket_id' => $this->ticket->id, 'user_id' => $this->customer->id, 'is_internal' => false]);
        Event::assertDispatched(TicketReplied::class);
    }

    public function test_assigned_agent_and_admin_can_reply(): void
    {
        $this->as($this->agent)->postJson($this->url(), ['body' => 'Looking into it.'])
            ->assertCreated()->assertJsonPath('data.author_is_staff', true);

        $this->as($this->admin)->postJson($this->url(), ['body' => 'Escalated.'])->assertCreated();
    }

    public function test_other_people_cannot_reply(): void
    {
        $this->as($this->otherCustomer)->postJson($this->url(), ['body' => 'Hi'])->assertForbidden();
        // An agent who is not assigned must accept the ticket first.
        $this->as($this->otherAgent)->postJson($this->url(), ['body' => 'Hi'])->assertForbidden();

        $unassigned = $this->ticketFor($this->customer);
        $this->as($this->agent)->postJson($this->url($unassigned), ['body' => 'Hi'])->assertForbidden();

        $this->assertDatabaseCount('ticket_replies', 0);
    }

    public function test_guests_get_401(): void
    {
        $this->postJson($this->url(), ['body' => 'Hi'])->assertUnauthorized();
        $this->getJson($this->url())->assertUnauthorized();
    }

    public function test_body_is_required_and_bounded(): void
    {
        $this->as($this->customer)->postJson($this->url(), [])->assertUnprocessable()->assertJsonValidationErrors('body');
        $this->as($this->customer)->postJson($this->url(), ['body' => str_repeat('a', 10001)])->assertUnprocessable();
    }

    public function test_closed_ticket_takes_no_replies(): void
    {
        $closed = $this->ticketFor($this->customer, ['status' => TicketStatus::Closed, 'assigned_to' => $this->agent->id]);

        $this->as($this->customer)->postJson($this->url($closed), ['body' => 'One more thing'])
            ->assertStatus(409);
        $this->as($this->agent)->postJson($this->url($closed), ['body' => 'Note', 'is_internal' => true])
            ->assertStatus(409);
    }

    public function test_resolved_ticket_still_takes_replies(): void
    {
        $resolved = $this->ticketFor($this->customer, ['status' => TicketStatus::Resolved, 'assigned_to' => $this->agent->id]);

        $this->as($this->customer)->postJson($this->url($resolved), ['body' => 'Thanks, but it came back.'])->assertCreated();
    }

    // ---- Internal notes -----------------------------------------------------------------

    public function test_agent_can_add_an_internal_note(): void
    {
        $this->as($this->agent)
            ->postJson($this->url(), ['body' => 'Check the billing system.', 'is_internal' => true])
            ->assertCreated()
            ->assertJsonPath('message', 'Internal note added')
            ->assertJsonPath('data.is_internal', true);

        $this->as($this->admin)
            ->postJson($this->url(), ['body' => 'Noted.', 'is_internal' => true])
            ->assertCreated();
    }

    public function test_customer_cannot_create_an_internal_note(): void
    {
        $this->as($this->customer)
            ->postJson($this->url(), ['body' => 'secret?', 'is_internal' => true])
            ->assertForbidden();

        $this->assertDatabaseCount('ticket_replies', 0);
    }

    public function test_internal_notes_are_never_returned_to_the_customer(): void
    {
        $this->note('INTERNAL-ONLY-TEXT');
        $this->as($this->agent)->postJson($this->url(), ['body' => 'Public answer'])->assertCreated();

        $response = $this->as($this->customer)->getJson($this->url())->assertOk();

        $response->assertJsonCount(1, 'data')->assertJsonPath('data.0.body', 'Public answer');
        $this->assertStringNotContainsString('INTERNAL-ONLY-TEXT', $response->getContent());
        $this->assertSame([false], $response->json('data.*.is_internal'));
    }

    public function test_staff_see_internal_notes_in_order(): void
    {
        $this->as($this->customer)->postJson($this->url(), ['body' => 'First'])->assertCreated();
        $this->note('Second (note)');
        $this->as($this->agent)->postJson($this->url(), ['body' => 'Third'])->assertCreated();

        foreach ([$this->agent, $this->admin] as $staff) {
            $this->as($staff)->getJson($this->url())
                ->assertOk()
                ->assertJsonPath('data.*.body', ['First', 'Second (note)', 'Third'])
                ->assertJsonPath('data.*.is_internal', [false, true, false]);
        }
    }

    public function test_a_ticket_with_only_internal_notes_looks_empty_to_the_customer(): void
    {
        $this->note();

        $response = $this->as($this->customer)->getJson($this->url())->assertNotFound();

        $this->assertSame([], $response->json('data'));
        $this->assertStringNotContainsString('Customer seems confused', $response->getContent());
    }

    public function test_internal_flag_accepts_multipart_text_values(): void
    {
        $this->as($this->agent)
            ->post($this->url(), ['body' => 'Form note', 'is_internal' => 'true'], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('data.is_internal', true);

        $this->as($this->agent)
            ->post($this->url(), ['body' => 'x', 'is_internal' => 'banana'], ['Accept' => 'application/json'])
            ->assertUnprocessable();
    }

    // ---- Who can read -------------------------------------------------------------------

    public function test_only_people_who_can_view_the_ticket_can_read_the_conversation(): void
    {
        $this->as($this->customer)->postJson($this->url(), ['body' => 'Hello'])->assertCreated();

        $this->as($this->otherCustomer)->getJson($this->url())->assertForbidden();
        $this->as($this->otherAgent)->getJson($this->url())->assertForbidden();
        $this->as($this->agent)->getJson($this->url())->assertOk();
    }

    public function test_conversation_is_paginated(): void
    {
        foreach (range(1, 5) as $i) {
            $this->as($this->customer)->postJson($this->url(), ['body' => "Message $i"])->assertCreated();
        }

        $this->as($this->customer)->getJson($this->url().'?per_page=2&page=2')
            ->assertOk()
            ->assertJsonPath('data.*.body', ['Message 3', 'Message 4'])
            ->assertJsonPath('pagination.total', 5);
    }

    public function test_conversation_does_not_run_extra_queries_per_reply(): void
    {
        $count = function (): int {
            \DB::flushQueryLog();
            \DB::enableQueryLog();
            $this->as($this->agent)->getJson($this->url())->assertOk();

            return count(\DB::getQueryLog());
        };

        $this->as($this->customer)->postJson($this->url(), ['body' => 'a'])->assertCreated();
        $withOne = $count();

        foreach (range(1, 10) as $i) {
            $this->as($this->customer)->postJson($this->url(), ['body' => "b$i"])->assertCreated();
        }

        $this->assertSame($withOne, $count());
    }
}
