<?php

declare(strict_types=1);

namespace ExcelleInsights\AiAssistant\Support;

/**
 * Heuristics that turn a bare column name/type into something a language model
 * can reason about: a description, a display format, whether it is a measure
 * (sum/average-able) and how sensitive it is.
 *
 * These are guesses. A host's own words — a MySQL column comment, a CSV the
 * admin imported, or a description written in the schema editor — always win;
 * SchemaService only calls here when nothing better exists.
 */
final class SchemaHints
{
    private const NAME_MAP = [
        'id' => 'Unique identifier',
        'uuid' => 'Universal unique identifier',
        'name' => 'Name',
        'label' => 'Display label',
        'title' => 'Title',
        'description' => 'Free-text description',
        'details' => 'Details',
        'notes' => 'Free-text notes',
        'remark' => 'Remark',
        'remarks' => 'Remarks',
        'summary' => 'Short summary',
        'status' => 'Current status',
        'state' => 'Current state',
        'stage' => 'Workflow stage',
        'type' => 'Type',
        'category' => 'Category',
        'kind' => 'Kind',
        'code' => 'Short code',
        'slug' => 'URL-friendly identifier',
        'reference' => 'Human-readable reference number',
        'is_active' => 'Whether the record is active',
        'active' => 'Whether the record is active',
        'enabled' => 'Whether the feature is enabled',
        'deleted_at' => 'Soft-delete timestamp; NULL means the record is live',
        'first_name' => 'First name',
        'middle_name' => 'Middle name',
        'last_name' => 'Last name',
        'full_name' => 'Full name',
        'display_name' => 'Name shown to users',
        'city' => 'City',
        'town' => 'Town',
        'country' => 'Country',
        'region' => 'Region',
        'state_province' => 'State or province',
        'gender' => 'Gender',
        'language' => 'Preferred language',
        'currency' => 'Currency code',
        'locale' => 'Locale',
        'version' => 'Version',
        'position' => 'Sort order',
        'sort_order' => 'Sort order',
        'sequence' => 'Sequence number',
        'source' => 'Where the record came from',
        'channel' => 'Communication channel',
        'subject' => 'Subject line',
        'body' => 'Message body',
        'message' => 'Message text',
        'reason' => 'Reason',
        'result' => 'Result',
        'outcome' => 'Outcome',
        'quantity' => 'Quantity',
        'qty' => 'Quantity',
        'unit' => 'Unit of measure',
        'weight' => 'Weight',
        'height' => 'Height',
        'length' => 'Length',
        'width' => 'Width',
        'depth' => 'Depth',
        'size' => 'Size',
        'duration' => 'Duration',
        'percentage' => 'Percentage',
        'score' => 'Score',
        'rating' => 'Rating',
        'priority' => 'Priority',
        'level' => 'Level',
        'color' => 'Colour',
        'image' => 'Image path or URL',
        'thumbnail' => 'Thumbnail path or URL',
        'attachment' => 'Attachment path or URL',
        'url' => 'Link',
        'website' => 'Website',
        'parent_id' => 'Reference to the containing record',
        'company_id' => 'Owning company/tenant reference',
        'organization_id' => 'Owning organisation reference',
        'tenant_id' => 'Owning tenant reference',
        'created_by' => 'User who created the record',
        'updated_by' => 'User who last updated the record',
        'deleted_by' => 'User who deleted the record',
        'approved_by' => 'User who approved the record',
        'assigned_to' => 'User the work is assigned to',
        'owner_id' => 'Owner of the record',
    ];

    private const MONEY_WORDS = ['price', 'amount', 'cost', 'fee', 'total', 'sum', 'revenue',
        'income', 'expense', 'salary', 'wage', 'balance', 'rate', 'charge', 'value', 'subtotal', 'discount', 'tax', 'paid', 'due'];

    private const PII_WORDS = ['phone', 'mobile', 'tel', 'msisdn', 'email', 'address', 'street', 'postal',
        'zip', 'passport', 'national_id', 'id_number', 'tax_id', 'dob', 'birth', 'iban', 'account_number'];

    /** Short, generic description for a column of any application's database. */
    public static function autoDescription(string $table, string $column, ?string $references = null): string
    {
        $c = strtolower(trim($column));
        if ($c === '') {
            return '';
        }
        if (isset(self::NAME_MAP[$c])) {
            return self::NAME_MAP[$c];
        }

        if ($c === 'created_at' || $c === 'created') {
            return 'When the record was created';
        }
        if ($c === 'updated_at' || $c === 'modified_at' || $c === 'updated') {
            return 'When the record was last updated';
        }
        if ($references !== null && $references !== '') {
            return 'Reference to ' . self::label($references);
        }
        if (str_ends_with($c, '_at')) {
            return 'Timestamp for ' . self::label(substr($c, 0, -3));
        }
        if (str_ends_with($c, '_date') || $c === 'date') {
            return 'Date of ' . self::label((string) preg_replace('/_?date$/', '', $c));
        }
        if (str_ends_with($c, '_time') || $c === 'time') {
            return 'Time of ' . self::label((string) preg_replace('/_?time$/', '', $c));
        }
        if (str_contains($c, 'phone') || str_contains($c, 'mobile') || str_ends_with($c, 'tel')) {
            return 'Phone number';
        }
        if (str_contains($c, 'email')) {
            return 'Email address';
        }
        if (str_contains($c, 'address') || str_contains($c, 'location') || str_contains($c, 'place')) {
            return 'Physical address or location';
        }
        foreach (self::MONEY_WORDS as $word) {
            if (preg_match('/(^|_)' . preg_quote($word, '/') . '($|_)/', $c) === 1) {
                return 'Monetary amount';
            }
        }
        foreach (['quantity', 'qty'] as $word) {
            if (str_contains($c, $word)) {
                return 'Quantity';
            }
        }
        if ($c === 'count' || str_ends_with($c, '_count') || str_starts_with($c, 'count_') || str_ends_with($c, '_qty')) {
            return 'Quantity';
        }
        if (str_contains($c, 'expiry') || str_contains($c, 'expires') || str_contains($c, 'valid_until')) {
            return 'Expiry date';
        }
        if (str_contains($c, 'start') || str_contains($c, 'begin')) {
            return 'Start date/time';
        }
        if ($c === 'end' || str_ends_with($c, '_end') || str_contains($c, 'finish')) {
            return 'End date/time';
        }
        if (str_ends_with($c, '_id') || str_ends_with($c, '_uuid')) {
            $ref = (string) preg_replace('/_(id|uuid)$/', '', $c);
            if ($ref === 'user') {
                return 'Reference to the user';
            }
            return 'Reference to ' . self::label($ref);
        }
        if (str_starts_with($c, 'is_') || str_starts_with($c, 'has_')) {
            return 'Whether ' . self::label($c) . ' applies';
        }

        return self::label($c);
    }

    /** snake_case / camelCase → "Snake case". */
    public static function label(string $name): string
    {
        $raw = trim($name);
        if ($raw === '') {
            return '';
        }
        // Split on case boundaries before lowercasing, otherwise they are gone.
        $spaced = (string) preg_replace('/(?<=[a-z0-9])(?=[A-Z])|(?<=[A-Z])(?=[A-Z][a-z])/', ' ', $raw);
        $spaced = (string) preg_replace('/\s+/', ' ', strtolower(str_replace(['_', '-'], ' ', $spaced)));
        return ucfirst(trim($spaced));
    }

    /** How a value should be displayed: money|percent|date|datetime|time|bool|number|text|json. */
    public static function format(string $column, string $type): string
    {
        $t = strtolower(trim($type));
        $c = strtolower(trim($column));
        if (str_starts_with($t, 'datetime') || $t === 'timestamp') {
            return 'datetime';
        }
        if (str_starts_with($t, 'date')) {
            return 'date';
        }
        if (str_starts_with($t, 'time')) {
            return 'time';
        }
        if (str_starts_with($t, 'year')) {
            return 'number';
        }
        if ($t === 'json' || str_contains($t, 'json')) {
            return 'json';
        }
        if (str_starts_with($t, 'tinyint(1)') || $t === 'bool' || $t === 'boolean'
            || str_starts_with($c, 'is_') || str_starts_with($c, 'has_')) {
            return 'bool';
        }
        if (str_contains($c, 'percent') || str_contains($c, 'pct') || str_contains($c, 'ratio')) {
            return 'percent';
        }
        if (preg_match('/^(decimal|numeric|float|double|real)/', $t) === 1) {
            return self::isMoneyColumn($c) ? 'money' : 'number';
        }
        if (preg_match('/^(int|bigint|mediumint|smallint|integer)/', $t) === 1) {
            return (self::isMoneyColumn($c) && !str_ends_with($c, '_id')) ? 'money' : 'number';
        }

        return 'text';
    }

    /** An identifier column ("id", "user_id", "client_uuid") holds a key, not a value. */
    public static function isReferenceColumn(string $column): bool
    {
        $c = strtolower(trim($column));
        if ($c === '') {
            return false;
        }
        if (str_contains($c, '.')) {
            $parts = explode('.', $c);
            $c = (string) end($parts);
        }
        return $c === 'id' || str_ends_with($c, '_id') || str_ends_with($c, '_uuid');
    }

    /**
     * The thing a key column points at: "staff_id" → "staff", "officer" → "officer".
     */
    public static function referenceBase(string $column): string
    {
        $c = strtolower(trim($column));
        if (str_contains($c, '.')) {
            $parts = explode('.', $c);
            $c = (string) end($parts);
        }
        $base = (string) preg_replace('/_(id|uuid)$/', '', $c);

        return $base === 'id' || $base === 'uuid' ? '' : $base;
    }

    /** Measures can be summed/averaged; identifiers and counts-of-things-that-are-not-metrics cannot. */
    public static function isMeasure(string $column, string $type): bool
    {
        $c = strtolower(trim($column));
        if (self::isReferenceColumn($c)) {
            return false;
        }
        if (in_array(self::format($column, $type), ['money', 'percent', 'number'], true)) {
            return true;
        }
        return false;
    }

    /**
     * Derive a sensitivity class from the column name and the policy:
     * 'secret' | 'pii' | 'internal'. The policy's own lists always have the final
     * say on blocking.
     *
     * A catalog row may also carry 'public' or 'financial' — those are written by a
     * human (schema.save / an imported CSV) and travel to the model as a warning.
     * Nothing here derives them: an export of a monetary column is approval-gated
     * only when that column is also in schema_policy.masked_columns or
     * blocked_columns, so ordinary number exports keep working unsupervised.
     */
    public static function sensitivity(string $column, string $type = '', ?SchemaPolicy $policy = null): string
    {
        $c = strtolower(trim($column));
        if ($policy !== null && in_array($c, $policy->blockedColumns(), true)) {
            return 'secret';
        }
        foreach (['password', 'passwd', 'secret', 'token', 'api_key', 'apikey', 'otp', 'pin', 'hash', 'signature', 'private_key', 'cvv'] as $word) {
            if (str_contains($c, $word)) {
                return 'secret';
            }
        }
        if ($policy !== null && $policy->isColumnMasked($c)) {
            return 'pii';
        }
        foreach (self::PII_WORDS as $word) {
            if (str_contains($c, $word)) {
                return 'pii';
            }
        }
        return 'internal';
    }

    /** @return string[] words used to spot monetary columns */
    public static function moneyWords(): array
    {
        return self::MONEY_WORDS;
    }

    /**
     * The date shape of sampled text values, in the words a human reads:
     * 'dd-mm-yyyy', 'dd-mm-yyyy HH:MM', 'yyyy-mm-dd', … Empty when the samples
     * are not consistent dates.
     *
     * @param array<int,string> $values
     */
    public static function dateFormatHint(array $values): string
    {
        $values = array_values(array_filter(array_map(static fn ($v): string => trim((string) $v), $values), static fn (string $v): bool => $v !== ''));
        if (count($values) < 3) {
            return '';
        }
        $patterns = [
            'dd-mm-yyyy' => '/^(\d{2})-(\d{2})-(\d{4})$/',
            'dd-mm-yyyy HH:MM' => '/^(\d{2})-(\d{2})-(\d{4}) (\d{2}):(\d{2})$/',
            'dd-mm-yyyy HH:MM:SS' => '/^(\d{2})-(\d{2})-(\d{4}) (\d{2}):(\d{2}):(\d{2})$/',
            'dd-mm-yy' => '/^(\d{2})-(\d{2})-(\d{2})$/',
            'dd-mm-yy HH:MM' => '/^(\d{2})-(\d{2})-(\d{2}) (\d{2}):(\d{2})$/',
            'dd-mm-yy HH:MM:SS' => '/^(\d{2})-(\d{2})-(\d{2}) (\d{2}):(\d{2}):(\d{2})$/',
            'yyyy-mm-dd' => '/^(\d{4})-(\d{2})-(\d{2})$/',
            'yyyy-mm-dd HH:MM:SS' => '/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2}):(\d{2})$/',
        ];
        // A column that stores both "05-01-2026 12:34" and "05-01-2026 12:34:21"
        // still reads as one shape: MySQL ignores the seconds a format leaves
        // out, so the shorter format reads every row.
        $tolerant = [
            'dd-mm-yyyy' => '/^(\d{2})-(\d{2})-(\d{4})$/',
            'dd-mm-yyyy HH:MM' => '/^(\d{2})-(\d{2})-(\d{4}) (\d{2}):(\d{2})(?::\d{2})?$/',
            'dd-mm-yyyy HH:MM:SS' => '/^(\d{2})-(\d{2})-(\d{4}) (\d{2}):(\d{2}):(\d{2})$/',
            'dd-mm-yy' => '/^(\d{2})-(\d{2})-(\d{2})$/',
            'dd-mm-yy HH:MM' => '/^(\d{2})-(\d{2})-(\d{2}) (\d{2}):(\d{2})(?::\d{2})?$/',
            'dd-mm-yy HH:MM:SS' => '/^(\d{2})-(\d{2})-(\d{2}) (\d{2}):(\d{2}):(\d{2})$/',
            'yyyy-mm-dd' => '/^(\d{4})-(\d{2})-(\d{2})$/',
            'yyyy-mm-dd HH:MM:SS' => '/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2}):(\d{2})$/',
        ];

        foreach ([$patterns, $tolerant] as $set) {
            foreach ($set as $label => $pattern) {
                if (self::matchingDates($values, $pattern, $label) === count($values)) {
                    return $label;
                }
            }
        }

        // No shape reads every value — a column may hold a zero date ("0000-00-00")
        // or a stray value. The shape that reads the most values wins.
        $best = '';
        $bestCount = 0;
        foreach ($tolerant as $label => $pattern) {
            $count = self::matchingDates($values, $pattern, $label);
            if ($count > $bestCount) {
                $bestCount = $count;
                $best = $label;
            }
        }
        if ($bestCount >= 3 && $bestCount * 2 >= count($values)) {
            return $best;
        }

        return '';
    }

    /**
     * How many of the values read as this shape, and are real dates.
     *
     * @param array<int,string> $values
     */
    private static function matchingDates(array $values, string $pattern, string $label): int
    {
        $count = 0;
        foreach ($values as $value) {
            if (preg_match($pattern, $value, $m) !== 1) {
                continue;
            }
            $day = str_starts_with($label, 'd') ? (int) $m[1] : (int) $m[3];
            $month = (int) $m[2];
            $year = str_starts_with($label, 'd') ? (int) $m[3] : (int) $m[1];
            if ($year < 100) {
                // Two-digit years: 70 is 1970, 69 is 2069.
                $year += $year >= 70 ? 1900 : 2000;
            }
            if (!checkdate($month, $day, $year)) {
                continue;
            }
            $count++;
        }

        return $count;
    }

    /**
     * The MySQL format string that reads a stored date shape.
     *
     * Text dates are stored in one shape per column ("25-08-2026"), so a query
     * has to say how to read them; a native DATE column needs no format at all.
     *
     * @return string '' when the column holds real dates
     */
    public static function mysqlDateFormat(string $hint): string
    {
        $map = [
            'dd-mm-yyyy' => '%d-%m-%Y',
            'dd-mm-yyyy HH:MM' => '%d-%m-%Y %H:%i',
            'dd-mm-yyyy HH:MM:SS' => '%d-%m-%Y %H:%i:%s',
            'dd-mm-yy' => '%d-%m-%y',
            'dd-mm-yy HH:MM' => '%d-%m-%y %H:%i',
            'dd-mm-yy HH:MM:SS' => '%d-%m-%y %H:%i:%s',
            'yyyy-mm-dd' => '%Y-%m-%d',
            'yyyy-mm-dd HH:MM:SS' => '%Y-%m-%d %H:%i:%s',
        ];
        $key = trim($hint);

        return $map[$key] ?? '';
    }

    /**
     * True when the samples look like base64 of AES blocks — what this kind of
     * application stores when a column is encrypted at rest.
     *
     * @param array<int,string> $values
     */
    public static function looksEncrypted(array $values): bool
    {
        $values = array_values(array_filter(array_map(static fn ($v): string => trim((string) $v), $values), static fn (string $v): bool => $v !== ''));
        if (count($values) < 3) {
            return false;
        }
        foreach ($values as $value) {
            if (strlen($value) < 16 || strlen($value) % 4 !== 0 || preg_match('/^[A-Za-z0-9+\/=]+$/', $value) !== 1) {
                return false;
            }
            $raw = base64_decode($value, true);
            if ($raw === false || $raw === '' || strlen($raw) % 16 !== 0 || preg_match('/^[0-9a-f]+$/i', $value) === 1) {
                return false;
            }
        }
        return true;
    }

    /**
     * One sentence appended to an automatic description, learned from the
     * values the column actually holds. Empty when nothing is certain.
     *
     * @param array<int,string> $values
     */
    public static function valueHint(string $column, array $values): string
    {
        $hints = [];
        $format = self::dateFormatHint($values);
        if ($format !== '') {
            $hints[] = 'Stored as text dates (format ' . $format . ').';
        } elseif (self::looksEncrypted($values)) {
            $hints[] = 'Values are encrypted; read them through a decrypting dataset expression.';
        } else {
            $values = array_values(array_unique(array_filter(array_map('trim', array_map('strval', $values)), static fn (string $v): bool => $v !== '')));
            if (self::isEnumColumn($column) && count($values) >= 2 && count($values) <= 8) {
                $short = true;
                foreach ($values as $value) {
                    if (strlen($value) > 30 || preg_match('/^[A-Za-z0-9 _\-\/]+$/', $value) !== 1) {
                        $short = false;
                        break;
                    }
                }
                if ($short) {
                    $hints[] = 'Values seen: ' . implode(', ', $values) . '.';
                }
            }
        }

        return $hints === [] ? '' : ' ' . implode(' ', $hints);
    }

    /** Columns that behave like enums: a handful of short, repeated values. */
    public static function isEnumColumn(string $column): bool
    {
        return preg_match('/(^|_)(state|status|type|mode|category|stage|kind|role|currency|source|verified)$/', strtolower(trim($column))) === 1;
    }

    /** Word-boundary match: "total_price" is money, "summary" is not. */
    private static function isMoneyColumn(string $column): bool
    {
        foreach (self::MONEY_WORDS as $word) {
            if (preg_match('/(^|_)' . preg_quote($word, '/') . '($|_)/', $column) === 1) {
                return true;
            }
        }
        return false;
    }
}
