<?php

namespace App\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Normalizer;
use RuntimeException;

class EncryptedSearchIndex
{
    private array $availability = [];

    public function available(): bool
    {
        $connection = DB::connection();
        $key = spl_object_id($connection).':'.$connection->getDatabaseName();

        return $this->availability[$key] ??= Schema::hasTable('encrypted_search_tokens');
    }

    public static function key(): string
    {
        $configured = config('data_security.blind_index_key');
        $value = filled($configured) ? $configured : config('app.key');
        $key = str_starts_with($value, 'base64:') ? base64_decode(substr($value, 7), true) : $value;
        if (! is_string($key) || strlen($key) < 32) {
            throw new RuntimeException('Blind-index key must contain at least 32 random bytes.');
        }

        return hash_hkdf('sha256', $key, 32, 'siem:blind-index:v1');
    }

    public static function hash(string $purpose, string $value): string
    {
        if ($purpose === 'search' && filter_var($value, FILTER_VALIDATE_IP)) {
            $value = self::ip($value);
        }

        return hash_hmac('sha256', $purpose."\0".self::normalized($value), self::key());
    }

    private static function normalized(string $value): string
    {
        return mb_strtolower(Normalizer::normalize(trim($value), Normalizer::FORM_C) ?: trim($value), 'UTF-8');
    }

    public static function ip(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }
        $packed = @inet_pton(trim($value));

        return $packed === false ? trim($value) : inet_ntop($packed);
    }

    /** One bounded index for log words, usernames and notification/audit notes. */
    public function rows(string $entity, int $recordId, int $alertId, mixed $values): array
    {
        $hashes = [];
        $remaining = (int) config('data_security.search_max_bytes', 131072);
        $limit = (int) config('data_security.search_max_tokens', 512);
        $key = self::key();
        $walk = function (mixed $value) use (&$walk, &$hashes, &$remaining, $limit, $key): void {
            if ($remaining <= 0 || count($hashes) >= $limit) {
                return;
            }
            if (is_array($value)) {
                foreach ($value as $child) {
                    $walk($child);
                    if ($remaining <= 0 || count($hashes) >= $limit) {
                        break;
                    }
                }

                return;
            }
            if (! is_scalar($value)) {
                return;
            }
            $text = mb_strcut((string) $value, 0, $remaining, 'UTF-8');
            $remaining -= strlen($text);
            // Addresses in free-form logs, URLs and socket endpoints need the
            // same canonical form as exact IP queries, including compressed IPv6.
            preg_match_all('/(?<![\pL\pN])(?:(?:[a-f0-9]{0,4}:){2,}[a-f0-9:.]*|(?:\d{1,3}\.){3}\d{1,3})(?![\pL\pN])/iu', $text, $addresses);
            foreach ($addresses[0] ?? [] as $address) {
                $address = rtrim($address, '.');
                if (filter_var($address, FILTER_VALIDATE_IP)) {
                    $hashes[hash_hmac('sha256', "search\0".self::ip($address), $key)] = true;
                    if (count($hashes) >= $limit) {
                        return;
                    }
                }
            }
            preg_match_all('/[\pL\pN\pM]+(?:[_.:@\/\\\\-][\pL\pN\pM]+)*/u', self::normalized($text), $matches);
            foreach ($matches[0] ?? [] as $word) {
                // Also index components: DOMAIN\\alice and alice can both be found.
                foreach ([$word, ...(preg_split('/[^\pL\pN\pM]+/u', $word, -1, PREG_SPLIT_NO_EMPTY) ?: [])] as $token) {
                    if (mb_strlen($token) >= 2 && mb_strlen($token) <= 128) {
                        $hashes[hash_hmac('sha256', "search\0".$token, $key)] = true;
                    }
                    if (count($hashes) >= $limit) {
                        break 2;
                    }
                }
            }
        };
        $walk($values);

        return array_map(fn ($hash) => ['alert_id' => $alertId, 'entity' => $entity, 'record_id' => $recordId, 'token_hash' => $hash], array_keys($hashes));
    }

    public function replace(string $entity, int $recordId, int $alertId, mixed $values): void
    {
        if (! $this->available()) {
            return;
        }
        $query = DB::table('encrypted_search_tokens')->where('entity', $entity)->where('record_id', $recordId);
        $rows = $this->rows($entity, $recordId, $alertId, $values);
        $hashes = array_column($rows, 'token_hash');
        $postings = (clone $query)->get(['token_hash', 'alert_id']);
        $existing = $postings->pluck('token_hash')->all();
        sort($existing);
        $sorted = $hashes;
        sort($sorted);
        if ($existing === $sorted && $postings->every(fn ($row) => (int) $row->alert_id === $alertId)) {
            return;
        }
        (clone $query)->whereNotIn('token_hash', $hashes)->delete();
        foreach (array_chunk($rows, 250) as $chunk) {
            DB::table('encrypted_search_tokens')->upsert($chunk, ['entity', 'record_id', 'token_hash'], ['alert_id']);
        }
    }

    public function matches(string $entity, int $recordId, int $alertId, mixed $values): bool
    {
        $expected = array_column($this->rows($entity, $recordId, $alertId, $values), 'token_hash');
        $postings = DB::table('encrypted_search_tokens')->where('entity', $entity)->where('record_id', $recordId)->get(['token_hash', 'alert_id']);
        $actual = $postings->pluck('token_hash')->all();
        sort($expected);
        sort($actual);

        return $actual === $expected && $postings->every(fn ($row) => (int) $row->alert_id === $alertId);
    }

    public static function match(Builder $query, string $entity, string $recordColumn, string $term): void
    {
        $postings = DB::table('encrypted_search_tokens')->select('record_id')
            ->where('entity', $entity)->where('token_hash', self::hash('search', $term))->distinct();
        $query->selectRaw('1')->fromSub($postings, 'encrypted_match')
            ->whereColumn('encrypted_match.record_id', $recordColumn);
    }
}
