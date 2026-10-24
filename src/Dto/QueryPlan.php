<?php

declare(strict_types=1);

namespace ExcelleInsights\AiAssistant\Dto;

/**
 * A semantic query: what to measure, slice, filter and over which period.
 *
 * This is the safe path for data questions — the model chooses *this*, never
 * raw SQL, and QueryCompiler turns it into parameterised SQL with the tenant and
 * permission scope injected by the host rather than written by the model.
 *
 * @see Services\QueryCompiler
 */
final class QueryPlan
{
    /**
     * @param string[] $metrics
     * @param string[] $dimensions
     * @param array<int,array{field:string,operator:string,value:mixed}> $filters
     * @param array<int,array{field:string,direction:string}> $orderBy
     */
    public function __construct(
        public string $dataset = '',
        public array $metrics = [],
        public array $dimensions = [],
        public array $filters = [],
        public array $dateRange = [],
        public array $orderBy = [],
        public int $limit = 0,
        public string $chart = '',
        public array $joins = [],
        public string $entity = '',
        public array $raw = [],
    ) {
    }

    private const OPERATORS = ['=', '!=', '>', '>=', '<', '<=', 'in', 'not_in', 'like', 'between', 'is_null', 'not_null', 'contains'];

    public static function fromArray(array $data): self
    {
        $filters = [];
        foreach ((array) ($data['filters'] ?? []) as $filter) {
            if (!is_array($filter)) {
                continue;
            }
            $field = (string) ($filter['field'] ?? '');
            $operator = strtolower(trim((string) ($filter['operator'] ?? '=')));
            if ($field === '') {
                continue;
            }
            // A filter the plan cannot express must fail loudly: dropping it
            // silently would answer an unfiltered question as if it were scoped.
            if (!in_array($operator, self::OPERATORS, true)) {
                throw new \InvalidArgumentException('Unsupported filter operator "' . $operator . '" on "' . $field . '". Try: ' . implode(', ', self::OPERATORS) . '.');
            }
            $filters[] = ['field' => $field, 'operator' => $operator, 'value' => $filter['value'] ?? null];
        }

        $orderBy = [];
        foreach ((array) ($data['order_by'] ?? $data['orderBy'] ?? []) as $clause) {
            if (is_string($clause)) {
                [$f, $dir] = array_pad(preg_split('/\s+/', trim($clause)) ?: [], 2, 'asc');
                $clause = ['field' => $f, 'direction' => $dir];
            }
            if (!is_array($clause)) {
                continue;
            }
            $field = (string) ($clause['field'] ?? '');
            if ($field !== '') {
                $orderBy[] = ['field' => $field, 'direction' => strtolower((string) ($clause['direction'] ?? 'asc')) === 'desc' ? 'desc' : 'asc'];
            }
        }

        $dateRange = $data['date_range'] ?? $data['dateRange'] ?? [];
        if (is_string($dateRange)) {
            $preset = \ExcelleInsights\AiAssistant\Support\DateRange::presetFromText($dateRange);
            $dateRange = ['preset' => $preset !== '' ? $preset : $dateRange, 'label' => $dateRange];
        }

        return new self(
            dataset: trim((string) ($data['dataset'] ?? '')),
            metrics: array_values(array_map('strval', (array) ($data['metrics'] ?? []))),
            dimensions: array_values(array_map('strval', (array) ($data['dimensions'] ?? []))),
            filters: $filters,
            dateRange: is_array($dateRange) ? array_filter(array_map('strval', $dateRange)) : [],
            orderBy: $orderBy,
            limit: max(0, (int) ($data['limit'] ?? 0)),
            chart: strtolower(trim((string) ($data['chart'] ?? ''))),
            joins: array_values((array) ($data['joins'] ?? [])),
            entity: trim((string) ($data['entity'] ?? '')),
            raw: $data,
        );
    }

    /** Relative periods ("this quarter") resolve in the host's timezone. */
    public function withResolvedDates(?\DateTimeZone $tz = null): self
    {
        $clone = clone $this;
        $clone->dateRange = \ExcelleInsights\AiAssistant\Support\DateRange::resolve($this->dateRange, $tz);
        return $clone;
    }

    /**
     * A plan aggregates only when it asks for a measure. Naming fields without a
     * metric is a detail lookup, and grouping those rows would collapse them.
     */
    public function isAggregate(): bool
    {
        return $this->metrics !== [];
    }

    public function hasMetrics(): bool
    {
        return $this->metrics !== [];
    }

    public function isEmpty(): bool
    {
        return $this->dataset === '' && $this->entity === '' && $this->metrics === [];
    }

    public function toArray(): array
    {
        return [
            'dataset' => $this->dataset,
            'entity' => $this->entity,
            'metrics' => $this->metrics,
            'dimensions' => $this->dimensions,
            'filters' => $this->filters,
            'date_range' => $this->dateRange,
            'order_by' => $this->orderBy,
            'limit' => $this->limit,
            'chart' => $this->chart,
        ];
    }
}
