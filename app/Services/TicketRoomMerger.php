<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Merge duplicate per-ticket chat rooms into the oldest one. Used by the
 * migration that adds the unique `(ticket_id, type)` key so no message, read
 * receipt or attachment is lost when legacy rows collide.
 */
class TicketRoomMerger
{
    /**
     * @return int number of rooms removed
     */
    public static function mergeDuplicates(): int
    {
        $duplicates = DB::table('conversations')
            ->select('ticket_id', 'type', DB::raw('MIN(id) as keep_id'), DB::raw('COUNT(*) as total'))
            ->whereNotNull('ticket_id')
            ->groupBy('ticket_id', 'type')
            ->having('total', '>', 1)
            ->get();

        $removed = 0;

        foreach ($duplicates as $group) {
            $keepId = (int) $group->keep_id;

            $otherIds = DB::table('conversations')
                ->where('ticket_id', $group->ticket_id)
                ->where('type', $group->type)
                ->where('id', '!=', $keepId)
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();

            if ($otherIds === []) {
                continue;
            }

            DB::table('messages')->whereIn('conversation_id', $otherIds)
                ->update(['conversation_id' => $keepId]);

            $stamps = array_filter([
                DB::table('messages')->where('conversation_id', $keepId)->max('created_at'),
                DB::table('conversations')->whereIn('id', $otherIds)->max('last_message_at'),
                DB::table('conversations')->where('id', $keepId)->value('last_message_at'),
            ]);

            DB::table('conversations')->where('id', $keepId)->update([
                'last_message_at' => $stamps ? max($stamps) : null,
            ]);

            // Rooms are empty now: every message (soft-deleted included) moved.
            $removed += DB::table('conversations')->whereIn('id', $otherIds)->delete();
        }

        return $removed;
    }
}
