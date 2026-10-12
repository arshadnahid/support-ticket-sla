<?php

namespace App\Services\Ticket;

use App\Models\TicketAttachment;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The only class that touches attachment files on disk.
 *
 * Safety rules:
 *  - files go to a private disk (not under /public), so there is no direct URL to them;
 *  - the stored name is a random UUID plus a whitelisted extension: nothing the user typed
 *    ends up in a path, so path traversal and overwrites are impossible;
 *  - the MIME type is detected from the file's content, not taken from the client;
 *  - downloads are always sent as an attachment with "nosniff", so a file can never be
 *    rendered as a web page (no stored XSS).
 */
class AttachmentStorage
{
    private const MAX_NAME_LENGTH = 150;

    /**
     * Writes the file and returns the attributes for its database row (without ticket or user).
     *
     * @return array{uuid: string, disk: string, path: string, original_name: string, mime_type: string, size: int}
     */
    public function put(int $ticketId, UploadedFile $file): array
    {
        $disk = (string) config('tickets.attachments.disk');
        $uuid = (string) Str::uuid();
        $path = sprintf('ticket-attachments/%d/%s.%s', $ticketId, $uuid, $this->extension($file));

        // Read before storing: the temp file is still there and these do not depend on the client.
        $mime = $file->getMimeType() ?: 'application/octet-stream';
        $size = (int) $file->getSize();

        Storage::disk($disk)->putFileAs(dirname($path), $file, basename($path));

        return [
            'uuid' => $uuid,
            'disk' => $disk,
            'path' => $path,
            'original_name' => $this->displayName($file),
            'mime_type' => $mime,
            'size' => $size,
        ];
    }

    /**
     * @param  list<array{disk: string, path: string}>  $files
     */
    public function delete(array $files): void
    {
        foreach ($files as $file) {
            Storage::disk($file['disk'])->delete($file['path']);
        }
    }

    public function exists(TicketAttachment $attachment): bool
    {
        return Storage::disk($attachment->disk)->exists($attachment->path);
    }

    public function download(TicketAttachment $attachment): StreamedResponse
    {
        return Storage::disk($attachment->disk)->download($attachment->path, $attachment->original_name, [
            'Content-Type' => $attachment->mime_type,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    private function extension(UploadedFile $file): string
    {
        $allowed = (array) config('tickets.attachments.types');
        $extension = strtolower($file->getClientOriginalExtension());

        // Validation already enforced the whitelist; this keeps the guarantee local.
        return in_array($extension, $allowed, true) ? $extension : ($file->extension() ?: 'bin');
    }

    /**
     * The name shown to users: no folders, no control characters, bounded length.
     */
    private function displayName(UploadedFile $file): string
    {
        $name = basename(str_replace('\\', '/', $file->getClientOriginalName()));
        $name = (string) preg_replace('/[\x00-\x1F\x7F]/u', '', $name);

        if (mb_strlen($name) > self::MAX_NAME_LENGTH) {
            $extension = pathinfo($name, PATHINFO_EXTENSION);
            $name = mb_substr(pathinfo($name, PATHINFO_FILENAME), 0, self::MAX_NAME_LENGTH - 10).($extension !== '' ? '.'.$extension : '');
        }

        return $name !== '' ? $name : 'file';
    }
}
