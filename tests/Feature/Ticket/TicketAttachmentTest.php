<?php

namespace Tests\Feature\Ticket;

use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Models\TicketAttachment;
use App\Models\User;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class TicketAttachmentTest extends TicketTestCase
{
    private Ticket $ticket;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(config('tickets.attachments.disk'));

        $this->ticket = $this->ticketFor($this->customer, ['assigned_to' => $this->agent->id]);
    }

    private function disk(): Filesystem
    {
        return Storage::disk(config('tickets.attachments.disk'));
    }

    private function filesUrl(?Ticket $ticket = null): string
    {
        return '/api/tickets/'.($ticket ?? $this->ticket)->number.'/attachments';
    }

    private function repliesUrl(): string
    {
        return '/api/tickets/'.$this->ticket->number.'/replies';
    }

    private function upload(User $user, array $files, ?Ticket $ticket = null)
    {
        return $this->as($user)->post($this->filesUrl($ticket), ['files' => $files], ['Accept' => 'application/json']);
    }

    private function pdf(string $name = 'invoice.pdf', int $kb = 50): UploadedFile
    {
        return UploadedFile::fake()->create($name, $kb, 'application/pdf');
    }

    // ---- Uploading ----------------------------------------------------------------------

    public function test_customer_can_attach_files_to_own_ticket(): void
    {
        $response = $this->upload($this->customer, [$this->pdf(), UploadedFile::fake()->image('shot.png')])
            ->assertCreated()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.filename', 'invoice.pdf')
            ->assertJsonPath('data.0.reply_id', null);

        $this->assertDatabaseCount('ticket_attachments', 2);

        foreach (TicketAttachment::all() as $attachment) {
            $this->disk()->assertExists($attachment->path);
        }

        // Neither the storage path nor the numeric id is exposed.
        $this->assertStringNotContainsString('ticket-attachments', $response->getContent());
    }

    public function test_files_are_stored_under_random_names_not_the_uploaded_name(): void
    {
        $this->upload($this->customer, [$this->pdf('../../etc/passwd.pdf')])->assertCreated();

        $attachment = TicketAttachment::first();

        $this->assertMatchesRegularExpression('#^ticket-attachments/\d+/[0-9a-f-]{36}\.pdf$#', $attachment->path);
        $this->assertSame('passwd.pdf', $attachment->original_name);
    }

    public function test_assigned_agent_and_admin_can_attach_others_cannot(): void
    {
        $this->upload($this->agent, [$this->pdf()])->assertCreated();
        $this->upload($this->admin, [$this->pdf()])->assertCreated();

        $this->upload($this->otherCustomer, [$this->pdf()])->assertForbidden();
        $this->upload($this->otherAgent, [$this->pdf()])->assertForbidden();
        $this->assertDatabaseCount('ticket_attachments', 2);
    }

    public function test_closed_ticket_takes_no_files_and_leaves_nothing_on_disk(): void
    {
        $closed = $this->ticketFor($this->customer, ['status' => TicketStatus::Closed]);

        $this->upload($this->admin, [$this->pdf()], $closed)->assertStatus(409);

        $this->assertDatabaseCount('ticket_attachments', 0);
        $this->assertSame([], $this->disk()->allFiles());
    }

    // ---- Validation ---------------------------------------------------------------------

    public function test_disallowed_file_types_are_rejected(): void
    {
        foreach (['malware.exe', 'page.html', 'icon.svg', 'script.php', 'bundle.zip', 'run.sh'] as $name) {
            $this->upload($this->customer, [UploadedFile::fake()->create($name, 10)])
                ->assertUnprocessable()
                ->assertJsonValidationErrors('files.0');
        }

        $this->assertDatabaseCount('ticket_attachments', 0);
        $this->assertSame([], $this->disk()->allFiles());
    }

    public function test_an_executable_renamed_to_pdf_is_rejected_by_its_content(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'att');
        file_put_contents($path, "MZ\x90\x00\x03\x00\x00\x00\x04\x00\x00\x00\xFF\xFF".str_repeat("\x00", 200));
        $fake = new UploadedFile($path, 'invoice.pdf', 'application/pdf', null, true);

        $this->upload($this->customer, [$fake])->assertUnprocessable()->assertJsonValidationErrors('files.0');

        @unlink($path);
    }

    public function test_html_disguised_as_text_is_not_stored_as_html(): void
    {
        // A .txt file whose content is HTML is a plain download; it is never served inline.
        $this->upload($this->customer, [UploadedFile::fake()->createWithContent('notes.txt', '<script>alert(1)</script>')])
            ->assertCreated();

        $attachment = TicketAttachment::first();
        $this->assertStringEndsWith('.txt', $attachment->path);
    }

    public function test_size_and_count_limits(): void
    {
        $max = config('tickets.attachments.max_kb');

        $this->upload($this->customer, [$this->pdf('big.pdf', $max + 1)])->assertUnprocessable()->assertJsonValidationErrors('files.0');
        $this->upload($this->customer, [$this->pdf('ok.pdf', $max)])->assertCreated();

        $tooMany = array_map(fn ($i) => $this->pdf("f$i.pdf"), range(1, config('tickets.attachments.max_files') + 1));
        $this->upload($this->customer, $tooMany)->assertUnprocessable()->assertJsonValidationErrors('files');
    }

    public function test_at_least_one_file_is_required(): void
    {
        $this->as($this->customer)->postJson($this->filesUrl(), [])->assertUnprocessable()->assertJsonValidationErrors('files');
    }

    // ---- Files on replies ---------------------------------------------------------------

    public function test_files_can_be_sent_with_a_reply(): void
    {
        $this->as($this->customer)
            ->post($this->repliesUrl(), ['body' => 'See attached.', 'attachments' => [$this->pdf('log.pdf')]], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('data.attachments.0.filename', 'log.pdf');

        $this->assertNotNull(TicketAttachment::first()->reply_id);
    }

    public function test_a_failed_reply_leaves_no_orphan_files(): void
    {
        // Validation passes for the file but the reply is refused: closed ticket.
        $closed = $this->ticketFor($this->customer, ['status' => TicketStatus::Closed]);

        $this->as($this->customer)
            ->post('/api/tickets/'.$closed->number.'/replies', ['body' => 'x', 'attachments' => [$this->pdf()]], ['Accept' => 'application/json'])
            ->assertStatus(409);

        $this->assertSame([], $this->disk()->allFiles());
        $this->assertDatabaseCount('ticket_replies', 0);
    }

    // ---- Download and secure access -----------------------------------------------------

    public function test_owner_can_download_with_safe_headers(): void
    {
        $this->upload($this->customer, [UploadedFile::fake()->createWithContent('hello.txt', 'hello world')])->assertCreated();
        $attachment = TicketAttachment::first();

        $response = $this->as($this->customer)->get($this->filesUrl().'/'.$attachment->uuid)->assertOk();

        $this->assertSame('hello world', $response->streamedContent());
        $this->assertStringContainsString('attachment', $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('hello.txt', $response->headers->get('Content-Disposition'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }

    public function test_download_requires_login_and_access_to_the_ticket(): void
    {
        $this->upload($this->customer, [$this->pdf()])->assertCreated();
        $url = $this->filesUrl().'/'.TicketAttachment::first()->uuid;

        // actingAs() above stays logged in for the rest of the test; drop it to act as a guest.
        $this->app['auth']->forgetGuards();
        $this->getJson($url)->assertUnauthorized();
        $this->as($this->otherCustomer)->getJson($url)->assertForbidden();
        $this->as($this->otherAgent)->getJson($url)->assertForbidden();
        $this->as($this->agent)->get($url)->assertOk();
        $this->as($this->admin)->get($url)->assertOk();
    }

    public function test_an_attachment_cannot_be_fetched_through_a_different_ticket(): void
    {
        $this->upload($this->customer, [$this->pdf()])->assertCreated();
        $uuid = TicketAttachment::first()->uuid;

        $mine = $this->ticketFor($this->otherCustomer);

        $this->as($this->otherCustomer)->getJson($this->filesUrl($mine).'/'.$uuid)->assertNotFound();
    }

    public function test_files_have_no_public_url(): void
    {
        $this->upload($this->customer, [$this->pdf()])->assertCreated();

        $this->assertNotSame('public', config('tickets.attachments.disk'));
        $this->assertFalse(config('filesystems.disks.'.config('tickets.attachments.disk').'.visibility') === 'public');
    }

    public function test_unknown_attachment_is_404(): void
    {
        $this->as($this->customer)->getJson($this->filesUrl().'/'.Str::uuid())->assertNotFound();
    }

    // ---- Internal notes and files -------------------------------------------------------

    public function test_files_on_internal_notes_are_hidden_from_the_customer_everywhere(): void
    {
        // Agent writes an internal note with a file, and a public reply with another.
        $this->as($this->agent)
            ->post($this->repliesUrl(), ['body' => 'Internal', 'is_internal' => 'true', 'attachments' => [$this->pdf('secret.pdf')]], ['Accept' => 'application/json'])
            ->assertCreated();
        $this->as($this->agent)
            ->post($this->repliesUrl(), ['body' => 'Public', 'attachments' => [$this->pdf('shared.pdf')]], ['Accept' => 'application/json'])
            ->assertCreated();

        $secret = TicketAttachment::where('original_name', 'secret.pdf')->first();

        // Listing: customer sees only the public file, staff see both.
        $this->assertSame(['shared.pdf'], $this->as($this->customer)->getJson($this->filesUrl())->json('data.*.filename'));
        $this->assertEqualsCanonicalizing(['secret.pdf', 'shared.pdf'], $this->as($this->agent)->getJson($this->filesUrl())->json('data.*.filename'));

        // Conversation: customer never receives the internal note, so not its file either.
        $conversation = $this->as($this->customer)->getJson($this->repliesUrl());
        $this->assertStringNotContainsString('secret.pdf', $conversation->getContent());
        $this->assertStringNotContainsString($secret->uuid, $conversation->getContent());

        // Direct download: 404, as if it did not exist. Staff can still download it.
        $this->as($this->customer)->getJson($this->filesUrl().'/'.$secret->uuid)->assertNotFound();
        $this->as($this->agent)->get($this->filesUrl().'/'.$secret->uuid)->assertOk();
    }

    public function test_customer_cannot_attach_files_as_an_internal_note(): void
    {
        $this->as($this->customer)
            ->post($this->repliesUrl(), ['body' => 'x', 'is_internal' => '1', 'attachments' => [$this->pdf()]], ['Accept' => 'application/json'])
            ->assertForbidden();

        $this->assertDatabaseCount('ticket_attachments', 0);
        $this->assertSame([], $this->disk()->allFiles());
    }
}
