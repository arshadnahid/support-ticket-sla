<?php

namespace App\Http\Requests\Api\Ticket;

/**
 * Validation for uploaded files, shared by every endpoint that accepts them.
 *
 * `mimes` looks at the file's CONTENT, `extensions` at its name, and both must agree with the
 * whitelist in config/tickets.php. A renamed executable fails the first; a disallowed name
 * fails the second. `max` is in kilobytes.
 */
class AttachmentRules
{
    /**
     * @return array<string, array<int, string>>
     */
    public static function forField(string $field): array
    {
        $types = implode(',', (array) config('tickets.attachments.types'));

        return [
            $field => ['array', 'max:'.config('tickets.attachments.max_files')],
            $field.'.*' => [
                'file',
                'max:'.config('tickets.attachments.max_kb'),
                'extensions:'.$types,
                'mimes:'.$types,
            ],
        ];
    }
}
