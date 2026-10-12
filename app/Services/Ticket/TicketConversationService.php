<?php

namespace App\Services\Ticket;

use App\Enums\TicketStatus;
use App\Events\Ticket\TicketReplied;
use App\Exceptions\TicketConversationException;
use App\Models\Ticket;
use App\Models\TicketAttachment;
use App\Models\TicketReply;
use App\Models\User;
use App\Repositories\Attachment\AttachmentInterfaces\AttachmentInterface;
use App\Repositories\Reply\ReplyInterfaces\ReplyInterface;
use App\Repositories\Ticket\TicketInterfaces\TicketInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Replies, internal notes and attachments on a ticket.
 *
 * Who may do what is decided by TicketPolicy (before these methods run). Who may SEE what is
 * decided by the `visibleTo` scopes on TicketReply and TicketAttachment, which every read below
 * goes through, so an internal note cannot reach a customer by any route.
 */
class TicketConversationService
{
    public function __construct(
        private readonly TicketInterface $tickets,
        private readonly ReplyInterface $replies,
        private readonly AttachmentInterface $attachments,
        private readonly AttachmentStorage $storage,
    ) {}

    /**
     * @return LengthAwarePaginator<int, TicketReply>
     */
    public function conversation(Ticket $ticket, User $viewer, int $perPage): LengthAwarePaginator
    {
        return $this->replies->paginateFor($ticket, $viewer, $perPage);
    }

    /**
     * @return Collection<int, TicketAttachment>
     */
    public function attachmentsFor(Ticket $ticket, User $viewer): Collection
    {
        return $this->attachments->listFor($ticket, $viewer);
    }

    /**
     * Adds a reply (public) or an internal note, optionally with files.
     *
     * @param  list<UploadedFile>  $files
     *
     * @throws TicketConversationException when the ticket is closed
     */
    public function reply(Ticket $ticket, User $author, string $body, bool $internal, array $files = []): TicketReply
    {
        $reply = $this->withFiles($ticket, $author, $files, function (Ticket $locked, array $stored) use ($author, $body, $internal): TicketReply {
            $reply = $this->replies->create([
                'ticket_id' => $locked->id,
                'user_id' => $author->id,
                'body' => $body,
                'is_internal' => $internal,
            ]);

            $this->saveAttachments($locked, $author, $stored, $reply);

            TicketReplied::dispatch($locked, $reply, $author);

            return $reply;
        });

        return $this->replies->loadRelations($reply);
    }

    /**
     * Attaches files to the ticket itself (not to a reply).
     *
     * @param  list<UploadedFile>  $files
     * @return Collection<int, TicketAttachment>
     *
     * @throws TicketConversationException when the ticket is closed
     */
    public function attach(Ticket $ticket, User $uploader, array $files): Collection
    {
        $created = $this->withFiles(
            $ticket,
            $uploader,
            $files,
            fn (Ticket $locked, array $stored) => $this->saveAttachments($locked, $uploader, $stored, null),
        );

        return $created->load('uploader:id,name,email,role');
    }

    /**
     * Writes the files, then runs $save in a transaction on the locked ticket. If anything
     * fails, the files already written are removed so nothing is left orphaned on disk.
     *
     * @template T
     *
     * @param  list<UploadedFile>  $files
     * @param  callable(Ticket, list<array>): T  $save
     * @return T
     */
    private function withFiles(Ticket $ticket, User $user, array $files, callable $save): mixed
    {
        $stored = [];

        try {
            foreach ($files as $file) {
                $stored[] = $this->storage->put($ticket->id, $file);
            }

            return DB::transaction(function () use ($ticket, $stored, $save) {
                // Lock so a reply cannot slip in while another request is closing the ticket.
                $locked = $this->tickets->lockForUpdate($ticket->id);

                if ($locked->status === TicketStatus::Closed) {
                    throw TicketConversationException::closed();
                }

                return $save($locked, $stored);
            }, attempts: 3);
        } catch (Throwable $e) {
            $this->storage->delete($stored);

            throw $e;
        }
    }

    /**
     * @param  list<array>  $stored
     * @return Collection<int, TicketAttachment>
     */
    private function saveAttachments(Ticket $ticket, User $uploader, array $stored, ?TicketReply $reply): Collection
    {
        return new Collection(array_map(
            fn (array $file) => $this->attachments->create($file + [
                'ticket_id' => $ticket->id,
                'reply_id' => $reply?->id,
                'uploaded_by' => $uploader->id,
            ]),
            $stored,
        ));
    }
}
