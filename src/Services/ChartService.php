<?php

declare(strict_types=1);

namespace ExcelleInsights\AiAssistant\Services;

use ExcelleInsights\AiAssistant\Dto\QueryResult;
use ExcelleInsights\AiAssistant\Support\Config;
use ExcelleInsights\AiAssistant\Support\NumberFormat;

/**
 * Turns a tabular result into a DECLARATIVE chart spec.
 *
 * The package never draws anything: it emits JSON arrays shaped like an ECharts
 * option object plus a `dataset`, and the host's front-end renders them. That
 * keeps the library dependency-free (no chart library, no image rendering, no
 * canvas) and lets the same spec serve a web widget, a dashboard or a report.
 *
 * Every spec has the same key set — type, title, option, series, dataset,
 * insight, reason, note, format, meta — so a host can render blind:
 *
 *   type     one of types(); 'table' is the honest fallback for anything that
 *            cannot be charted (empty sets, wide grids, missing geo data)
 *   option   ECharts-compatible option object (axes/series/visualMap as JSON)
 *   series   the same series list on its own, for renderers that build their own option
 *   dataset  {source:[[header],[row]], dimensions, source_format:'table'}
 *   insight  one factual sentence, formatted with the host's currency/locale
 *   reason   why this type was chosen (suggest) or why it fell back
 *   note     null unless something was lost (fallback, truncation, missing input)
 *   format   how to render the numbers: unit, currency, position, locale
 *   meta     dimension/measure/row_count/labels + type-specific extras
 *
 * Nothing about the host's domain is assumed: measures, labels, currency and
 * units all come from the data and from Config, never from this file.
 */
final class ChartService
{
    /** Supported chart types; 'map' is only ever served as a 'geo_table' fallback. */
    private const TYPES = [
        'bar', 'column', 'line', 'area', 'pie', 'donut', 'stacked_bar',
        'funnel', 'scatter', 'heatmap', 'kpi', 'table', 'pivot', 'map',
    ];

    /** Generic calendar words that mark a column as a time axis. */
    private const TIME_NAME_HINTS = ['date', 'datetime', 'day', 'week', 'month', 'year', 'quarter', 'period', 'time', 'hour', 'timestamp', 'created', 'updated'];

    /** Generic ordering words that mark a column as an ordered pipeline stage. */
    private const STAGE_NAME_HINTS = ['stage', 'step', 'phase', 'state', 'status', 'level', 'tier', 'funnel', 'sequence'];

    /** Last name token of a column that identifies a row rather than measuring it. */
    private const ID_NAME_TOKENS = ['id', 'uuid', 'guid', 'key', 'code', 'slug', 'hash'];

    /**
     * Calendar month tokens recognised as a period label. Only the Gregorian
     * month names of the package's own locale default (English) live here; a host
     * in another language configures charts.month_tokens instead of patching code.
     */
    private const MONTH_TOKENS = [
        'jan', 'january', 'feb', 'february', 'mar', 'march', 'apr', 'april',
        'may', 'jun', 'june', 'jul', 'july', 'aug', 'august',
        'sep', 'september', 'oct', 'october', 'nov', 'november', 'dec', 'december',
    ];

    /** intent (free text from the host/LLM) => chart type, longest phrase wins. */
    private const INTENT_TYPES = [
        'time series' => 'line', 'timeseries' => 'line', 'over time' => 'line', 'trend' => 'line',
        'cumulative' => 'area', 'area chart' => 'area',
        'share of total' => 'pie', 'share' => 'pie', 'proportion' => 'pie', 'distribution' => 'pie',
        'donut' => 'donut', 'doughnut' => 'donut', 'ring' => 'donut',
        'breakdown' => 'bar', 'comparison' => 'bar', 'compare' => 'bar', 'top' => 'bar',
        'stacked' => 'stacked_bar', 'stack' => 'stacked_bar',
        'funnel' => 'funnel', 'conversion' => 'funnel', 'pipeline' => 'funnel',
        'scatter' => 'scatter', 'correlation' => 'scatter', 'relationship' => 'scatter',
        'heatmap' => 'heatmap', 'heat map' => 'heatmap',
        'kpi' => 'kpi', 'metric' => 'kpi', 'headline' => 'kpi', 'summary' => 'kpi',
        'pivot' => 'pivot', 'cross tab' => 'pivot', 'crosstab' => 'pivot', 'matrix' => 'pivot',
        'table' => 'table', 'raw rows' => 'table', 'list' => 'table', 'detail' => 'table',
        'map' => 'map', 'geo' => 'map', 'geographic' => 'map',
    ];

    /**
     * @return string[] the chart types this build supports, honouring
     *                  charts.enabled_types when a host narrows the list
     */
    public function types(): array
    {
        $enabled = Config::list('charts.enabled_types', self::TYPES);
        $allowed = array_values(array_filter($enabled, fn ($t) => in_array(strtolower((string) $t), self::TYPES, true)));

        return $allowed !== [] ? array_map('strval', $allowed) : self::TYPES;
    }

    /**
     * Pick the best chart from the shape of the data.
     *
     * @param array|QueryResult  $data   tabular result
     * @param string|null        $intent free-text hint ('trend', 'share of total', …)
     */
    public function suggest(mixed $data, ?string $intent = null): array
    {
        $options = ['intent' => $intent];
        $ctx = $this->resolve($data, $options);
        [$type, $reason] = $this->chooseType($ctx, is_string($intent) ? $intent : null);

        return $this->render($type, $ctx, $options + ['reason' => $reason], $reason);
    }

    /**
     * Build one specific chart type. An unknown type, or a type the data cannot
     * support, degrades to 'table' with a `note` — never an exception.
     *
     * @param array|QueryResult $data
     * @param array<string,mixed> $options title, unit, currency, locale, dimension,
     *                                     measure(s), agg, sort, limit, rows, columns,
     *                                     x, y, size, group, split, orientation, insight
     */
    public function build(string $type, mixed $data, array $options = []): array
    {
        $wanted = strtolower(trim($type));
        $ctx = $this->resolve($data, $options);
        if (!in_array($wanted, self::TYPES, true)) {
            $options['fallback_of'] = $wanted;

            return $this->renderTable($ctx, $options, 'Chart type "' . $wanted . '" is not supported; the rows are returned as a table.');
        }

        return $this->render($wanted, $ctx, $options, (string) ($options['reason'] ?? ''));
    }

    /**
     * One factual sentence about the data. The currency comes from
     * Config('domain.currency') — never from this class.
     *
     * @param array|QueryResult $data
     */
    public function insight(mixed $data, string $type, ?string $unit = null): string
    {
        $ctx = $this->resolve($data, $unit === null ? [] : ['unit' => $unit]);

        return $this->composeInsight($ctx, strtolower(trim($type)), $unit);
    }

    /**
     * Recursively strip anything json_encode() would choke on: resources,
     * closures, non-serialisable objects, NAN/INF, invalid UTF-8, runaway depth.
     */
    public function sanitize(array $spec): array
    {
        return $this->cleanArray($spec, 0);
    }

    // ── dispatch ─────────────────────────────────────────────────────────────

    private function render(string $type, array $ctx, array $options, string $reason): array
    {
        return match ($type) {
            'bar' => $this->renderCartesian($ctx, $options, 'bar', true, $reason),
            'column' => $this->renderCartesian($ctx, $options, 'column', false, $reason),
            'line' => $this->renderCartesian($ctx, $options, 'line', false, $reason),
            'area' => $this->renderCartesian($ctx, $options, 'area', false, $reason),
            'pie' => $this->renderPie($ctx, $options, false, $reason),
            'donut' => $this->renderPie($ctx, $options, true, $reason),
            'stacked_bar' => $this->renderStacked($ctx, $options, $reason),
            'funnel' => $this->renderFunnel($ctx, $options, $reason),
            'scatter' => $this->renderScatter($ctx, $options, $reason),
            'heatmap' => $this->renderHeatmap($ctx, $options, $reason),
            'kpi' => $this->renderKpi($ctx, $options, $reason),
            'pivot' => $this->renderPivot($ctx, $options, $reason),
            'map' => $this->renderGeoTable($ctx, $options, $reason),
            default => $this->renderTable($ctx, $options, $reason),
        };
    }

    // ── type selection ───────────────────────────────────────────────────────

    /**
     * The decision ladder. Ordered most-specific first, because "20 rows of a
     * monthly trend" must stay a line even though "> 12 rows" normally means a
     * table, and a 4-cell row×column grid must stay a pivot instead of collapsing
     * into a bar chart of one measure.
     *
     * @return array{0:string,1:string} [type, reason]
     */
    private function chooseType(array $ctx, ?string $intent): array
    {
        if ($ctx['rowCount'] === 0) {
            return ['table', 'The result set is empty, so there is nothing to plot — an empty table keeps the columns visible.'];
        }

        $allowed = $this->types();
        $enabled = static fn (string $t): bool => in_array($t, $allowed, true);

        $hint = $this->intentType($intent);
        if ($hint !== null && $enabled($hint)) {
            return [$hint, 'Chosen because the request says "' . $intent . '" — a chart type was asked for explicitly.'];
        }

        // 1 row of scalars = headline numbers, not a chart.
        if ($ctx['rowCount'] === 1 && $ctx['measures'] !== []) {
            return ['kpi', 'A single row of scalar values reads best as headline figures (1 row).'];
        }

        $dims = $ctx['dims'];
        $measures = $ctx['measures'];

        // Two dimensions + a measure form a grid; a date × category grid is a
        // heatmap, two categorical dimensions are a pivot matrix.
        if (count($dims) >= 2 && count($measures) <= 3 && $ctx['rowCount'] >= 4 && $this->gridReady($ctx)) {
            [$rowDim, $colDim] = $this->gridDims($ctx);
            if ($this->isTime($ctx, $colDim) !== $this->isTime($ctx, $rowDim)) {
                if ($enabled('heatmap')) {
                    return ['heatmap', 'One dimension is a time axis and the other a category, so the values map onto a ' . $this->label($ctx, $rowDim) . ' × ' . $this->label($ctx, $colDim) . ' grid.'];
                }
            } elseif ($enabled('pivot')) {
                return ['pivot', 'Two categorical dimensions (' . $this->label($ctx, $rowDim) . ' × ' . $this->label($ctx, $colDim) . ') with one measure make a cross-tabulation.'];
            }
        }

        // Ordered stage names + a shrinking measure = funnel.
        if ($enabled('funnel') && $ctx['dim'] !== null && $this->looksOrdinal($ctx, (string) $ctx['dim'])
            && $measures !== [] && $ctx['rowCount'] >= 2 && $ctx['rowCount'] <= 8 && !$this->isTime($ctx, (string) $ctx['dim'])) {
            return ['funnel', 'The first column reads as ordered stages (' . $ctx['rowCount'] . ' of them) with one quantity, which is a funnel.'];
        }

        // A time (or ordinal) axis with a quantity is a trend line whatever the row count.
        if ($ctx['dim'] !== null && $measures !== [] && ($this->isTime($ctx, (string) $ctx['dim']) || $this->isStrictlyIncreasing($ctx, (string) $ctx['dim']))) {
            return ['line', 'The first column is a time/period axis, so the measure is shown as a trend over ' . $ctx['rowCount'] . ' points.'];
        }

        // Two quantities and many rows ask for a correlation, not a bar per row.
        if ($enabled('scatter') && count($measures) >= 2 && $ctx['rowCount'] >= 8) {
            return ['scatter', 'Two quantities (' . $this->label($ctx, $measures[0]) . ', ' . $this->label($ctx, $measures[1]) . ') across ' . $ctx['rowCount'] . ' rows are read as a distribution.'];
        }

        // Share of a whole: few categories, one measure.
        if ($ctx['dim'] !== null && count($measures) === 1) {
            $categories = $this->distinctCount($ctx, (string) $ctx['dim']);
            if ($categories >= 2 && $categories <= Config::int('charts.pie_max_categories', 6)) {
                return [$enabled('pie') ? 'pie' : 'bar', 'One measure split over ' . $categories . ' categories is a share-of-total picture.'];
            }
            if ($categories >= 2 && $categories <= Config::int('charts.bar_max_categories', 8)) {
                return [$categories > 5 ? 'bar' : 'column', 'One measure across ' . $categories . ' categories compares discrete values' . ($categories > 5 ? ', so the labels sit on the horizontal axis' : '') . '.'];
            }
        }

        // Several measures, few rows: grouped bars.
        if (count($measures) >= 2 && $ctx['rowCount'] <= 8) {
            return ['column', count($measures) . ' quantities across ' . $ctx['rowCount'] . ' rows compare side by side.'];
        }

        if ($ctx['rowCount'] > Config::int('charts.table_above_rows', 12) || count($ctx['columns']) > Config::int('charts.table_above_columns', 6)) {
            return ['table', $ctx['rowCount'] . ' rows × ' . count($ctx['columns']) . ' columns is too dense for a single chart — the table is the faithful view.'];
        }

        if ($ctx['dim'] !== null && $measures !== []) {
            $categories = $this->distinctCount($ctx, (string) $ctx['dim']);

            return [$categories > 5 ? 'bar' : 'column', 'One dimension (' . $this->label($ctx, (string) $ctx['dim']) . ') against ' . count($measures) . ' measure(s) with ' . $categories . ' categories.'];
        }

        return ['table', 'The shape of the result does not fit any supported chart, so the rows are returned unchanged.'];
    }

    private function intentType(?string $intent): ?string
    {
        $text = strtolower(trim((string) $intent));
        if ($text === '') {
            return null;
        }
        foreach (self::INTENT_TYPES as $phrase => $type) {
            if (str_contains($text, $phrase)) {
                return $type;
            }
        }
        $direct = str_replace([' ', '-'], '_', $text);
        if (in_array($direct, self::TYPES, true)) {
            return $direct;
        }

        return null;
    }

    /** A grid needs two dimensions with at least two values each. */
    private function gridReady(array $ctx): bool
    {
        [$rowDim, $colDim] = $this->gridDims($ctx);

        return $this->distinctCount($ctx, $rowDim) >= 2 && $this->distinctCount($ctx, $colDim) >= 2;
    }

    /** @return array{0:string,1:string} row dimension then column dimension */
    private function gridDims(array $ctx): array
    {
        $dims = array_values(array_filter($ctx['dims'], fn ($d) => $d !== $ctx['measure']));
        $rowDim = (string) ($ctx['options']['rows'] ?? $dims[0] ?? $ctx['dim'] ?? '');
        $colDim = (string) ($ctx['options']['columns'] ?? '');
        if ($colDim === '' || $colDim === $rowDim) {
            $colDim = (string) ($dims[1] ?? $rowDim);
        }

        return [$rowDim, $colDim];
    }

    // ── renderers ────────────────────────────────────────────────────────────

    /**
     * bar (horizontal), column (vertical), line and area share one cartesian body.
     */
    private function renderCartesian(array $ctx, array $options, string $type, bool $horizontalHint, string $reason): array
    {
        [$labels, $groups] = $this->grouped($ctx, $options);
        $measures = $this->seriesOf($ctx, $options);
        $horizontal = $type === 'bar' ? ($horizontalHint && !$this->isTime($ctx, (string) $ctx['dim'])) : false;

        $series = [];
        foreach ($measures as $measure) {
            $entry = [
                'name' => $this->label($ctx, $measure),
                'type' => $type === 'area' ? 'line' : ($type === 'bar' || $type === 'column' ? 'bar' : 'line'),
                'orientation' => $horizontal ? 'horizontal' : 'vertical',
                'data' => $this->numericColumn($groups, $measure),
            ];
            if ($type === 'area') {
                $entry['areaStyle'] = ['opacity' => 0.35];
                $entry['showSymbol'] = count($labels) <= 40;
            }
            if ($type === 'line' || $type === 'area') {
                $entry['smooth'] = false;
                $entry['connectNulls'] = true;
            } else {
                $entry['barMaxWidth'] = Config::int('charts.bar_max_width', 48);
            }
            $series[] = $entry;
        }

        $valueLabel = $this->axisLabel($ctx, $measures);
        $axes = $this->axes($labels, $this->label($ctx, (string) $ctx['dim']), $valueLabel, $horizontal);

        $option = $this->baseOption($this->title($ctx, $options), 'axis');
        $option += $axes + ['series' => $series];
        $option['legend']['data'] = array_column($series, 'name');

        return $this->spec($type, $ctx, $options, $reason, $this->title($ctx, $options), $option, $series,
            $this->dataset($labels, $measures, $groups, $ctx), $this->noteForSeries($ctx, $type, count($labels)));
    }

    private function renderPie(array $ctx, array $options, bool $donut, string $reason): array
    {
        [$labels, $groups] = $this->grouped($ctx, $options);
        $measure = $this->primaryMeasure($ctx, $options);

        $data = [];
        foreach ($groups as $group) {
            $data[] = ['name' => $group['label'], 'value' => $this->groupValue($group, $measure)];
        }

        $series = [[
            'name' => $measure === null ? 'count' : $this->label($ctx, $measure),
            'type' => 'pie',
            'radius' => $donut ? [Config::string('charts.donut_inner_radius', '45%'), '70%'] : '65%',
            'avoidLabelOverlap' => true,
            'label' => ['show' => true, 'formatter' => '{b}: {d}%'],
            'data' => $data,
        ]];

        $option = $this->baseOption($this->title($ctx, $options), 'item');
        $option += ['series' => $series, 'legend' => ['show' => true, 'data' => $labels]];

        return $this->spec($donut ? 'donut' : 'pie', $ctx, $options, $reason, $this->title($ctx, $options), $option, $series,
            $this->dataset($labels, $measure === null ? [] : [$measure], $groups, $ctx), null);
    }

    private function renderStacked(array $ctx, array $options, string $reason): array
    {
        $measures = $this->seriesOf($ctx, $options);
        $split = $this->splitDimension($ctx, $options);

        if ($split !== null && count($measures) === 1) {
            // One measure but a second category: one stacked series per category value.
            [$rowLabels, $groups] = $this->grouped($ctx, $options, (string) $ctx['dim']);
            $matrix = $this->matrix($ctx, (string) $ctx['dim'], $split, (string) $measures[0], $this->agg($options));
            $series = [];
            foreach ($matrix['colKeys'] as $colKey) {
                $data = [];
                foreach ($matrix['rowKeys'] as $rowKey) {
                    $data[] = $matrix['cells'][$rowKey][$colKey] ?? null;
                }
                $series[] = [
                    'name' => (string) $colKey,
                    'type' => 'bar',
                    'stack' => 'total',
                    'orientation' => 'vertical',
                    'data' => $data,
                ];
            }
            $option = $this->baseOption($this->title($ctx, $options), 'axis');
            $option += $this->axes($rowLabels, $this->label($ctx, (string) $ctx['dim']), $this->label($ctx, $measures[0]), false)
                + ['series' => $series];
            $option['legend']['data'] = array_map(strval(...), $matrix['colKeys']);

            return $this->spec('stacked_bar', $ctx, $options, $reason, $this->title($ctx, $options), $option, $series,
                $this->matrixDataset($matrix, $this->label($ctx, $measures[0])), null);
        }

        [$labels, $groups] = $this->grouped($ctx, $options);
        $series = [];
        foreach ($measures as $measure) {
            $series[] = [
                'name' => $this->label($ctx, $measure),
                'type' => 'bar',
                'stack' => 'total',
                'orientation' => 'vertical',
                'data' => $this->numericColumn($groups, $measure),
            ];
        }
        $option = $this->baseOption($this->title($ctx, $options), 'axis');
        $option += $this->axes($labels, $this->label($ctx, (string) $ctx['dim']), $this->axisLabel($ctx, $measures), false) + ['series' => $series];
        $option['legend']['data'] = array_column($series, 'name');

        return $this->spec('stacked_bar', $ctx, $options, $reason, $this->title($ctx, $options), $option, $series,
            $this->dataset($labels, $measures, $groups, $ctx), null);
    }

    private function renderFunnel(array $ctx, array $options, string $reason): array
    {
        [$labels, $groups] = $this->grouped($ctx, $options + ['sort' => 'none']);
        $measure = $this->primaryMeasure($ctx, $options);

        $data = [];
        foreach ($groups as $group) {
            $data[] = ['name' => $group['label'], 'value' => $this->groupValue($group, $measure)];
        }

        $series = [[
            'name' => $measure === null ? 'count' : $this->label($ctx, $measure),
            'type' => 'funnel',
            'sort' => 'descending',
            'gap' => 2,
            'label' => ['show' => true, 'position' => 'inside', 'formatter' => '{b}: {c}'],
            'data' => $data,
        ]];

        $option = $this->baseOption($this->title($ctx, $options), 'item');
        $option += ['series' => $series, 'legend' => ['show' => true, 'data' => $labels]];

        return $this->spec('funnel', $ctx, $options, $reason, $this->title($ctx, $options), $option, $series,
            $this->dataset($labels, $measure === null ? [] : [$measure], $groups, $ctx), null);
    }

    private function renderScatter(array $ctx, array $options, string $reason): array
    {
        $measures = $this->seriesOf($ctx, $options);
        $x = (string) ($options['x'] ?? $measures[0] ?? '');
        $y = (string) ($options['y'] ?? $measures[1] ?? $measures[0] ?? '');
        $size = isset($options['size']) && is_string($options['size']) ? trim($options['size']) : null;
        $group = null;
        if (isset($options['group']) && is_string($options['group']) && trim($options['group']) !== '') {
            $group = trim($options['group']);
        } elseif ($ctx['dim'] !== null && !$this->isIdColumn((string) $ctx['dim'])
            && $this->distinctCount($ctx, (string) $ctx['dim']) <= Config::int('charts.max_series', 6)) {
            // Auto-grouping is limited to small category columns: hundreds of
            // series would be unreadable, and a scatter reads as a distribution.
            $group = $ctx['dim'];
        }

        $buckets = [];
        foreach ($ctx['rows'] as $row) {
            $xv = $this->toNumber($row[$x] ?? null);
            $yv = $this->toNumber($row[$y] ?? null);
            if ($xv === null && $yv === null) {
                continue;
            }
            $key = $group === null ? '' : $this->cellLabel($row[$group] ?? null);
            $point = [$xv, $yv];
            if ($size !== null) {
                $point[] = $this->toNumber($row[$size] ?? null);
            }
            $buckets[$key][] = $point;
        }

        $series = [];
        foreach ($buckets as $key => $points) {
            $entry = [
                'name' => $key === '' ? $this->label($ctx, $y) : $key,
                'type' => 'scatter',
                'symbolSize' => $size === null ? Config::int('charts.scatter_symbol_size', 12) : 'dataSize',
                'data' => $points,
            ];
            if ($size !== null) {
                $entry['encode'] = ['value' => 2];
            }
            $series[] = $entry;
        }

        $source = [[
            $x === '' ? 'x' : $this->label($ctx, $x),
            $y === '' ? 'y' : $this->label($ctx, $y),
        ]];
        foreach ($ctx['rows'] as $row) {
            $source[] = [$this->cell($row[$x] ?? null), $this->cell($row[$y] ?? null)];
        }

        $option = $this->baseOption($this->title($ctx, $options), 'item');
        $option += [
            'xAxis' => ['type' => 'value', 'name' => $x === '' ? 'x' : $this->label($ctx, $x), 'nameLocation' => 'middle', 'nameGap' => 28, 'axisLabel' => ['formatter' => '{value}']],
            'yAxis' => ['type' => 'value', 'name' => $y === '' ? 'y' : $this->label($ctx, $y), 'nameLocation' => 'middle', 'nameGap' => 28, 'axisLabel' => ['formatter' => '{value}']],
            'series' => $series,
        ];
        $option['legend']['data'] = array_column($series, 'name');

        return $this->spec('scatter', $ctx, $options, $reason, $this->title($ctx, $options), $option, $series,
            ['source' => $source, 'dimensions' => $source[0], 'source_format' => 'table'],
            $measures === [] ? 'No two numeric columns were found, so the scatter plots what it can.' : null);
    }

    private function renderHeatmap(array $ctx, array $options, string $reason): array
    {
        [$rowDim, $colDim] = $this->gridDims($ctx);
        $measure = $this->primaryMeasure($ctx, $options);
        if ($rowDim === '' || $colDim === '' || $rowDim === $colDim) {
            return $this->renderTable($ctx, $options, 'A heatmap needs two dimensions (rows × columns); the result only offers ' . count($ctx['dims']) . '.', 'heatmap');
        }

        $matrix = $this->matrix($ctx, $rowDim, $colDim, (string) $measure, $this->agg($options));
        $data = [];
        $min = null;
        $max = null;
        foreach ($matrix['rowKeys'] as $yi => $rowKey) {
            foreach ($matrix['colKeys'] as $xi => $colKey) {
                $value = $matrix['cells'][$rowKey][$colKey] ?? null;
                $data[] = [$xi, $yi, $value];
                if (is_numeric($value)) {
                    $min = $min === null ? (float) $value : min($min, (float) $value);
                    $max = $max === null ? (float) $value : max($max, (float) $value);
                }
            }
        }

        $series = [[
            'name' => $measure === null ? 'count' : $this->label($ctx, $measure),
            'type' => 'heatmap',
            'data' => $data,
            'label' => ['show' => Config::bool('charts.heatmap_labels', true)],
            'emphasis' => ['itemStyle' => ['shadowBlur' => 6, 'shadowColor' => 'rgba(0,0,0,0.4)']],
        ]];

        $option = $this->baseOption($this->title($ctx, $options), 'item');
        $option += [
            'xAxis' => ['type' => 'category', 'data' => $matrix['colKeys'], 'splitArea' => ['show' => true]],
            'yAxis' => ['type' => 'category', 'data' => $matrix['rowKeys'], 'splitArea' => ['show' => true]],
            'visualMap' => [
                'min' => $min,
                'max' => $max,
                'calculable' => true,
                'orient' => 'horizontal',
                'left' => 'center',
                'top' => 'bottom',
            ],
            'series' => $series,
        ];

        return $this->spec('heatmap', $ctx, $options, $reason, $this->title($ctx, $options), $option, $series,
            $this->matrixDataset($matrix, $measure === null ? 'count' : $this->label($ctx, $measure)),
            $measure === null ? 'No numeric measure was found, so each cell counts rows instead.' : null);
    }

    private function renderKpi(array $ctx, array $options, string $reason): array
    {
        $measures = $this->seriesOf($ctx, $options);
        if ($measures === []) {
            $measures = array_slice($ctx['columns'], 0, Config::int('charts.kpi_max_items', 6));
        }
        $row = $ctx['rows'][0] ?? [];

        $items = [];
        $series = [];
        foreach ($measures as $measure) {
            $value = $this->toNumber($row[$measure] ?? null);
            $formatted = $this->formatValue($ctx, $value);
            $items[] = [
                'label' => $this->label($ctx, $measure),
                'value' => $value,
                'formatted' => $formatted,
                'column' => $measure,
            ];
            $series[] = [
                'type' => 'kpi',
                'name' => $this->label($ctx, $measure),
                'value' => $value,
                'formatted' => $formatted,
            ];
        }

        $title = $this->title($ctx, $options);
        $option = ['title' => ['text' => $title, 'top' => 10, 'left' => 'center']] + ['textStyle' => ['fontSize' => Config::int('charts.kpi_font_size', 42)]];
        $option['kpi'] = ['items' => $items, 'layout' => 'grid', 'columns' => min(4, max(1, count($items)))];

        $delta = $this->deltaSentence($ctx, $options);
        if ($delta !== null) {
            $option['kpi']['comparison'] = $delta;
        }

        $source = [array_map(fn ($c) => $this->label($ctx, $c), $measures)];
        $source[] = array_map(fn ($c) => $this->cell($row[$c] ?? null), $measures);

        return $this->spec('kpi', $ctx, $options, $reason, $title, $option, $series,
            ['source' => $source, 'dimensions' => $source[0], 'source_format' => 'table'], null);
    }

    private function renderPivot(array $ctx, array $options, string $reason): array
    {
        [$rowDim, $colDim] = $this->gridDims($ctx);
        if ($rowDim === '') {
            $rowDim = (string) ($ctx['dim'] ?? '');
        }
        if ($colDim === '' || $colDim === $rowDim) {
            $colDim = $this->firstDimensionOtherThan($ctx, $rowDim);
        }
        $measure = $this->primaryMeasure($ctx, $options);
        $agg = $this->agg($options);

        if ($colDim === '') {
            return $this->renderTable($ctx, $options, 'A pivot needs a second dimension for the columns; only one was found.', 'pivot');
        }

        $matrix = $this->matrix($ctx, $rowDim, $colDim, (string) $measure, $agg);
        $values = [];
        $rowTotals = [];
        $grand = 0.0;
        $hasValue = false;
        foreach ($matrix['rowKeys'] as $rowKey) {
            $line = [];
            $rowTotal = 0.0;
            $rowHas = false;
            foreach ($matrix['colKeys'] as $colKey) {
                $cell = $matrix['cells'][$rowKey][$colKey] ?? null;
                $line[] = $cell;
                if (is_numeric($cell)) {
                    $rowTotal += (float) $cell;
                    $grand += (float) $cell;
                    $rowHas = true;
                    $hasValue = true;
                }
            }
            $values[] = $line;
            $rowTotals[] = $rowHas ? $rowTotal : null;
        }

        $colTotals = [];
        foreach ($matrix['colKeys'] as $colKey) {
            $sum = 0.0;
            $any = false;
            foreach ($matrix['rowKeys'] as $rowKey) {
                $cell = $matrix['cells'][$rowKey][$colKey] ?? null;
                if (is_numeric($cell)) {
                    $sum += (float) $cell;
                    $any = true;
                }
            }
            $colTotals[] = $any ? $sum : null;
        }

        $title = $this->title($ctx, $options);
        $option = ['title' => ['text' => $title, 'top' => 10]] + [
            'pivot' => [
                'rows' => $matrix['rowKeys'],
                'columns' => $matrix['colKeys'],
                'row_dimension' => $rowDim,
                'column_dimension' => $colDim,
                'measure' => $measure,
                'agg' => $agg,
                'values' => $values,
                'row_totals' => $rowTotals,
                'column_totals' => $colTotals,
                'grand_total' => $hasValue ? $grand : null,
            ],
        ];

        return $this->spec('pivot', $ctx, $options, $reason, $title, $option, [],
            $this->matrixDataset($matrix, $measure === null ? 'count' : $this->label($ctx, $measure)),
            $measure === null ? 'No numeric measure was found, so each cell counts rows.' : null);
    }

    /**
     * 'map' is served as a geo_table fallback: the package ships no gazetteer or
     * base map, so claiming a map would mean inventing coordinates.
     */
    private function renderGeoTable(array $ctx, array $options, string $reason): array
    {
        $geo = $this->findGeoColumns($ctx);
        $note = $geo === []
            ? 'No latitude/longitude columns were found in the result, and this package ships no gazetteer, so a map cannot be drawn honestly — the rows are returned as a table (geo_table).'
            : 'Coordinates are present in "' . $geo['lat'] . '" / "' . $geo['lng'] . '", but a map needs a base map (GeoJSON) supplied by the host — the rows are returned as a table (geo_table) with the coordinate columns named.';

        $spec = $this->renderTable($ctx, $options + ['title' => $this->title($ctx, $options)], $reason, 'map');
        $spec['note'] = $note;
        $spec['meta']['requested_type'] = 'map';
        $spec['meta']['fallback'] = 'geo_table';
        $spec['meta']['geo'] = $geo === [] ? null : ['latitude_column' => $geo['lat'], 'longitude_column' => $geo['lng']];

        return $spec;
    }

    private function renderTable(array $ctx, array $options, string $reason, ?string $requestedAs = null): array
    {
        $columns = $ctx['columns'];
        $limit = $this->rowLimit($ctx, $options);
        $rows = array_slice($ctx['rows'], 0, $limit);

        $header = array_map(fn ($c) => $this->label($ctx, $c), $columns);
        $source = [$header];
        $body = [];
        foreach ($rows as $row) {
            $line = array_map(fn ($c) => $this->cell($row[$c] ?? null), $columns);
            $source[] = $line;
            $body[] = $line;
        }

        $title = $this->title($ctx, $options);
        $option = ['title' => ['text' => $title, 'top' => 10]] + [
            'table' => [
                'columns' => array_map(fn ($c) => [
                    'key' => $c,
                    'label' => $this->label($ctx, $c),
                    'kind' => (string) ($ctx['profile'][$c]['kind'] ?? 'text'),
                    'align' => ($ctx['profile'][$c]['kind'] ?? '') === 'number' ? 'right' : 'left',
                ], $columns),
                'rows' => $body,
                'row_count' => $ctx['rowCount'],
                'page_size' => Config::int('charts.table_page_size', 25),
            ],
        ];

        $note = null;
        if ($limit < $ctx['rowCount']) {
            $note = 'Showing ' . $limit . ' of ' . $ctx['rowCount'] . ' rows.';
        }

        return $this->spec($requestedAs ?? 'table', $ctx, $options, $reason, $title, $option, [],
            ['source' => $source, 'dimensions' => $header, 'source_format' => 'table'], $note, $requestedAs);
    }

    // ── spec assembly ────────────────────────────────────────────────────────

    /** @param array<string,mixed> $option @param array<int,mixed> $series @param array<string,mixed> $dataset */
    private function spec(string $type, array $ctx, array $options, string $reason, string $title, array $option, array $series, array $dataset, ?string $note, ?string $requestedAs = null): array
    {
        $spec = [
            'type' => $type === 'map' || $requestedAs === 'map' ? 'table' : $type,
            'title' => $title,
            'option' => $option,
            'series' => $series,
            'dataset' => $dataset,
            'insight' => $options['insight'] ?? $this->composeInsight($ctx, $type, $ctx['unit']),
            'reason' => $reason !== '' ? $reason : 'Chart type requested by the caller.',
            'note' => $note,
            'format' => [
                'unit' => $ctx['unit'],
                'currency' => NumberFormat::currency($ctx['currency']),
                'currency_position' => NumberFormat::position($ctx['currency_position']),
                'locale' => $ctx['locale'] !== '' ? $ctx['locale'] : Config::string('domain.locale', 'en'),
                'compact_axis' => Config::bool('charts.compact_axis', true),
                'axis_formatter' => '{value}',
            ],
            'meta' => [
                'dimension' => $ctx['dim'],
                'measure' => $this->primaryMeasure($ctx, $options),
                'measures' => $this->seriesOf($ctx, $options),
                'columns' => $ctx['columns'],
                'row_count' => $ctx['rowCount'],
                'labels' => $ctx['labels'],
                'requested_type' => $requestedAs ?? $type,
            ],
        ];

        return $this->sanitize($spec);
    }

    /**
     * @param array<int,array<string,mixed>> $groups
     * @param array<int,array<string,mixed>> $axes
     */
    private function baseOption(string $title, string $trigger): array
    {
        return [
            'title' => ['text' => $title, 'top' => 10, 'left' => 'center'],
            'tooltip' => ['trigger' => $trigger],
            'legend' => ['show' => true, 'top' => $trigger === 'axis' ? 34 : 10],
            'grid' => ['left' => '4%', 'right' => '5%', 'top' => '18%', 'bottom' => '6%', 'containLabel' => true],
        ];
    }

    /**
     * @param array<int,string> $labels
     * @return array{xAxis:array<string,mixed>,yAxis:array<string,mixed>}
     */
    private function axes(array $labels, string $categoryName, string $valueName, bool $horizontal): array
    {
        $category = [
            'type' => 'category',
            'data' => array_values($labels),
            'name' => $categoryName,
            'nameLocation' => 'middle',
            'nameGap' => 26,
            'axisLabel' => ['interval' => count($labels) > 30 ? 'auto' : 0, 'hideOverlap' => true],
        ];
        $value = [
            'type' => 'value',
            'name' => $valueName,
            'nameLocation' => 'middle',
            'nameGap' => 34,
            'axisLabel' => ['formatter' => '{value}'],
            'splitLine' => ['show' => true],
        ];

        return $horizontal ? ['xAxis' => $value, 'yAxis' => $category] : ['xAxis' => $category, 'yAxis' => $value];
    }

    /**
     * @param array<int,string> $labels
     * @param string[] $measures
     * @param array<int,array<string,mixed>> $groups
     * @return array{source:array<int,array<int,mixed>>,dimensions:array<int,string>,source_format:string}
     */
    private function dataset(array $labels, array $measures, array $groups, array $ctx): array
    {
        $dimensions = array_merge([$ctx['dim'] === null ? 'group' : $this->label($ctx, (string) $ctx['dim'])], array_map(fn ($m) => $this->label($ctx, $m), $measures));
        $source = [$dimensions];
        foreach ($groups as $i => $group) {
            $line = [$group['label']];
            foreach ($measures as $measure) {
                $line[] = $group['values'][$measure] ?? null;
            }
            $source[] = $line;
        }

        return ['source' => $source, 'dimensions' => $dimensions, 'source_format' => 'table'];
    }

    /**
     * @param array{rowKeys:array<int,string>,colKeys:array<int,string>,cells:array<string,array<string,mixed>>} $matrix
     * @return array{source:array<int,array<int,mixed>>,dimensions:array<int,string>,source_format:string}
     */
    private function matrixDataset(array $matrix, string $measureLabel): array
    {
        $dimensions = array_merge([$measureLabel], array_map(strval(...), $matrix['colKeys']));
        $source = [$dimensions];
        foreach ($matrix['rowKeys'] as $rowKey) {
            $line = [$rowKey];
            foreach ($matrix['colKeys'] as $colKey) {
                $line[] = $matrix['cells'][$rowKey][$colKey] ?? null;
            }
            $source[] = $line;
        }

        return ['source' => $source, 'dimensions' => $dimensions, 'source_format' => 'table'];
    }

    private function noteForSeries(array $ctx, string $type, int $categoryCount): ?string
    {
        if ($ctx['rowCount'] === 0) {
            return 'No rows to plot — the chart is an empty, valid ' . $type . ' skeleton.';
        }
        if ($categoryCount === 1 && $type !== 'kpi') {
            return 'Only one category is present; a single-bar ' . $type . ' is honest but not very informative.';
        }

        return null;
    }

    private function title(array $ctx, array $options): string
    {
        $explicit = trim((string) ($options['title'] ?? ''));
        if ($explicit !== '') {
            return $explicit;
        }
        $measure = $this->primaryMeasure($ctx, $options);
        $dim = $ctx['dim'];
        if ($measure !== null && $dim !== null && $this->distinctCount($ctx, (string) $dim) > 1) {
            return $this->label($ctx, $measure) . ' by ' . $this->label($ctx, (string) $dim);
        }
        if ($measure !== null) {
            return $this->label($ctx, $measure);
        }

        return trim((string) ($options['question'] ?? '')) !== '' ? (string) $options['question'] : 'Result';
    }

    // ── insight ──────────────────────────────────────────────────────────────

    private function composeInsight(array $ctx, string $type, ?string $unit): string
    {
        if ($ctx['rowCount'] === 0) {
            return 'No rows to chart.';
        }

        $measure = $ctx['measure'];
        $dim = $ctx['dim'];
        $ctx['unit'] = $unit ?? $ctx['unit'];

        if ($type === 'kpi' || $ctx['rowCount'] === 1) {
            return $this->kpiInsight($ctx);
        }

        if ($type === 'heatmap' || $type === 'pivot' || $type === 'map') {
            return $this->gridInsight($ctx, $type);
        }

        if ($type === 'scatter') {
            $measures = $ctx['measures'];
            if (count($measures) >= 2) {
                $x = $this->numericValues($ctx, $measures[0]);
                $y = $this->numericValues($ctx, $measures[1]);

                return $ctx['rowCount'] . ' points compare ' . $this->label($ctx, $measures[0])
                    . ' (max ' . $this->brief($ctx, max($x), $measures[0]) . ') with ' . $this->label($ctx, $measures[1])
                    . ' (max ' . $this->brief($ctx, max($y), $measures[1]) . ').';
            }

            return $ctx['rowCount'] . ' points across ' . count($ctx['columns']) . ' columns.';
        }

        if ($dim === null || $measure === null) {
            return $ctx['rowCount'] . ' rows across ' . count($ctx['columns']) . ' columns'
                . ($type === 'table' || $type === 'geo_table' ? '.' : ' — no numeric measure to summarise.');
        }

        [$labels, $groups] = $this->grouped($ctx, ['sort' => 'none']);
        if ($groups === []) {
            return $ctx['rowCount'] . ' rows across ' . count($ctx['columns']) . ' columns.';
        }

        $values = $this->numericColumn($groups, $measure);
        $pairs = [];
        foreach ($groups as $i => $group) {
            if (is_numeric($values[$i])) {
                $pairs[] = ['label' => $group['label'], 'value' => (float) $values[$i]];
            }
        }
        if ($pairs === []) {
            return $ctx['rowCount'] . ' rows across ' . count($ctx['columns']) . ' columns; "' . $this->label($ctx, $measure) . '" holds no numeric values.';
        }

        $total = array_sum(array_column($pairs, 'value'));
        $high = $pairs[0];
        $low = $pairs[0];
        $highIndex = 0;
        $lowIndex = 0;
        foreach ($pairs as $i => $pair) {
            if ($pair['value'] > $high['value']) {
                $high = $pair;
                $highIndex = $i;
            }
            if ($pair['value'] < $low['value']) {
                $low = $pair;
                $lowIndex = $i;
            }
        }

        $label = $this->label($ctx, $measure);

        if ($type === 'line' || $type === 'area') {
            $movement = $this->movement($pairs);
            $sentence = $label . ' peaked in ' . $high['label'] . ' at ' . $this->brief($ctx, $high['value'], $measure)
                . ' and was lowest in ' . $low['label'] . ' at ' . $this->brief($ctx, $low['value'], $measure)
                . '; total ' . $this->brief($ctx, $total, $measure) . ' across ' . count($pairs) . ' periods';

            return $sentence . ($movement === '' ? '' : ', ' . $movement) . '.';
        }

        if ($type === 'funnel') {
            $first = $pairs[0]['value'];
            $last = $pairs[count($pairs) - 1]['value'];
            $rate = $first > 0.0 ? NumberFormat::percent($last / $first * 100, 1, $ctx['locale'] ?: null) : '—';

            return $label . ' runs from ' . $pairs[0]['label'] . ' (' . $this->brief($ctx, $first, $measure) . ') to '
                . $pairs[count($pairs) - 1]['label'] . ' (' . $this->brief($ctx, $last, $measure) . '), a ' . $rate . ' end-to-end rate across ' . count($pairs) . ' stages.';
        }

        $share = $total > 0.0 ? NumberFormat::percent($high['value'] / $total * 100, 1, $ctx['locale'] ?: null) : '—';
        $kind = match ($type) {
            'pie', 'donut' => 'of the total',
            default => 'of the total',
        };

        $sentence = $label . ' is highest for ' . $high['label'] . ' at ' . $this->brief($ctx, $high['value'], $measure)
            . ' (' . $share . ' ' . $kind . ') and lowest for ' . $low['label'] . ' at ' . $this->brief($ctx, $low['value'], $measure)
            . '; ' . count($pairs) . ' categories, total ' . $this->brief($ctx, $total, $measure);

        return $sentence . ($type === 'table' ? ' over ' . $ctx['rowCount'] . ' rows.' : '.');
    }

    private function kpiInsight(array $ctx): string
    {
        $row = $ctx['rows'][0] ?? [];
        $parts = [];
        foreach ($ctx['measures'] as $measure) {
            $value = $this->toNumber($row[$measure] ?? null);
            if ($value !== null) {
                $parts[] = $this->label($ctx, $measure) . ' ' . $this->brief($ctx, $value, $measure);
            }
        }
        if ($parts === []) {
            foreach (array_slice($ctx['columns'], 0, 3) as $column) {
                $parts[] = $this->label($ctx, $column) . ' ' . $this->cellLabel($row[$column] ?? null);
            }
        }
        if ($parts === []) {
            return 'Single row with no readable values.';
        }

        $comparison = $this->deltaSentence($ctx, []);

        return implode('; ', $parts) . '.' . ($comparison === null ? '' : ' ' . $comparison);
    }

    private function gridInsight(array $ctx, string $type): string
    {
        [$rowDim, $colDim] = $this->gridDims($ctx);
        $measure = $this->primaryMeasure($ctx, []);
        if ($rowDim === '' || $colDim === '' || $rowDim === $colDim) {
            return $ctx['rowCount'] . ' rows across ' . count($ctx['columns']) . ' columns; no row × column grid in the data.';
        }
        $matrix = $this->matrix($ctx, $rowDim, $colDim, (string) $measure, 'sum');
        $best = null;
        $total = 0.0;
        foreach ($matrix['rowKeys'] as $rowKey) {
            foreach ($matrix['colKeys'] as $colKey) {
                $cell = $matrix['cells'][$rowKey][$colKey] ?? null;
                if (!is_numeric($cell)) {
                    continue;
                }
                $total += (float) $cell;
                if ($best === null || (float) $cell > $best['value']) {
                    $best = ['row' => $rowKey, 'col' => $colKey, 'value' => (float) $cell];
                }
            }
        }
        $label = $measure === null ? 'count' : $this->label($ctx, $measure);
        if ($best === null) {
            return $type === 'pivot'
                ? 'Pivot of ' . $this->label($ctx, $rowDim) . ' × ' . $this->label($ctx, $colDim) . ' over ' . $ctx['rowCount'] . ' rows, with no numeric values in the cells.'
                : $ctx['rowCount'] . ' rows; the grid cells hold no numeric values.';
        }

        return $label . ' peaks at ' . $best['row'] . ' / ' . $best['col'] . ' with ' . $this->brief($ctx, $best['value'], (string) $measure)
            . '; grid total ' . $this->brief($ctx, $total, (string) $measure) . ' across ' . count($matrix['rowKeys']) . ' × ' . count($matrix['colKeys']) . ' cells.';
    }

    /** First vs last point, phrased as the arithmetic difference only. */
    private function movement(array $pairs): string
    {
        $first = $pairs[0]['value'];
        $last = $pairs[count($pairs) - 1]['value'];
        if (count($pairs) < 2 || abs($last - $first) < 1e-9) {
            return '';
        }
        $change = $last - $first;
        $word = $change > 0 ? 'up' : 'down';
        $abs = abs($change);

        return $word . ' ' . ($first > 0.0 ? NumberFormat::percent($abs / $first * 100, 1) : NumberFormat::plain($abs)) . ' from first to last period';
    }

    /**
     * Only used when the host points at a comparison column (options['delta'] or
     * charts.delta_column); the value there is reported as a percentage, with no
     * further interpretation invented here.
     */
    private function deltaSentence(array $ctx, array $options): ?string
    {
        $column = trim((string) ($options['delta'] ?? Config::string('charts.delta_column', '')));
        if ($column === '' || !isset($ctx['profile'][$column])) {
            return null;
        }
        $row = $ctx['rows'][0] ?? [];
        $value = $this->toNumber($row[$column] ?? null);
        if ($value === null) {
            return null;
        }
        $word = $value > 0 ? 'up' : ($value < 0 ? 'down' : 'flat');

        return 'Read against "' . $this->label($ctx, $column) . '": ' . $word . ' '
            . NumberFormat::percent(abs($value), 1, $ctx['locale'] ?: null) . '.';
    }

    // ── data plumbing ────────────────────────────────────────────────────────

    /**
     * @return array{columns:string[],rows:array<int,array<string,mixed>>,profile:array<string,array<string,mixed>>,
     *               dims:string[],measures:string[],timeDims:string[],dim:?string,measure:?string,
     *               unit:?string,currency:?string,currency_position:?string,locale:string,
     *               labels:array<string,string>,options:array<string,mixed>,rowCount:int}
     */
    private function resolve(mixed $data, array $options): array
    {
        [$columns, $rows] = $this->ingest($data);
        $profile = $this->profile($columns, $rows);

        $timeDims = [];
        $textDims = [];
        $measures = [];
        foreach ($columns as $name) {
            $kind = $profile[$name]['kind'];
            if ($kind === 'time') {
                $timeDims[] = $name;
                $textDims[] = $name;
            } elseif ($kind === 'text') {
                $textDims[] = $name;
            } elseif ($kind === 'number' && !$this->isIdColumn($name)) {
                $measures[] = $name;
            }
        }

        $dim = $this->pickDimension($columns, $textDims, $timeDims, $profile, $options);
        $measure = $this->pickMeasure($measures, $options);

        $unit = isset($options['unit']) && is_string($options['unit']) && trim($options['unit']) !== ''
            ? strtolower(trim($options['unit']))
            : ($this->unitFromIntent((string) ($options['intent'] ?? '')) ?? (Config::string('charts.default_unit', '') ?: null));

        $labels = [];
        foreach ($columns as $name) {
            $labels[$name] = $this->humanize($name);
        }

        return [
            'columns' => $columns,
            'rows' => $rows,
            'profile' => $profile,
            'dims' => $textDims,
            'measures' => $measures,
            'timeDims' => $timeDims,
            'dim' => $dim,
            'measure' => $measure,
            'unit' => $unit,
            'currency' => isset($options['currency']) && is_string($options['currency']) ? $options['currency'] : null,
            'currency_position' => isset($options['currency_position']) && is_string($options['currency_position']) ? $options['currency_position'] : null,
            'locale' => isset($options['locale']) && is_string($options['locale']) ? trim($options['locale']) : '',
            'labels' => $labels,
            'options' => $options,
            'rowCount' => count($rows),
        ];
    }

    /**
     * Accept a QueryResult, {columns,rows}, a list of records, a list of lists,
     * a list of scalars or a single record. Always returns [columns, rows] where
     * rows is a list of complete assoc arrays keyed by the column names.
     *
     * @return array{0:string[],1:array<int,array<string,mixed>>}
     */
    private function ingest(mixed $data): array
    {
        $columns = [];
        $rows = [];

        if ($data instanceof QueryResult) {
            $columns = array_values(array_map(strval(...), $data->columns));
            $rows = array_values($data->rows);
        } elseif ($data instanceof \Traversable) {
            $rows = iterator_to_array($data);
        } elseif (!is_array($data)) {
            return [[], []];
        } elseif (isset($data['columns'], $data['rows']) && is_array($data['columns']) && is_array($data['rows'])) {
            $columns = array_values(array_map(strval(...), $data['columns']));
            $rows = array_values($data['rows']);
        } elseif ($data === []) {
            return [[], []];
        } elseif (array_is_list($data)) {
            $first = reset($data);
            if (is_array($first) && !array_is_list($first)) {
                $columns = $this->keysOf($data);
                $rows = $data;
            } elseif (is_array($first)) {
                $width = 0;
                foreach ($data as $row) {
                    $width = max($width, is_array($row) ? count($row) : 0);
                }
                $columns = array_map(fn ($i) => 'column_' . ($i + 1), range(0, max(0, $width - 1)));
                $rows = $data;
            } else {
                $columns = ['value'];
                $rows = $data;
            }
        } else {
            // A single record keyed by column name.
            $columns = array_map(strval(...), array_keys($data));
            $rows = [$data];
        }

        $normalised = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                $name = $columns[0] ?? 'value';
                $row = [$name => $row];
            }
            if (array_is_list($row)) {
                $positional = $row;
                $row = [];
                foreach (array_values($positional) as $i => $value) {
                    $name = $columns[$i] ?? ('column_' . ($i + 1));
                    $row[$name] = $this->cellValue($value);
                }
            } else {
                $assoc = [];
                foreach ($row as $key => $value) {
                    $assoc[is_string($key) ? $key : (string) $key] = $this->cellValue($value);
                }
                $row = $assoc;
            }
            foreach (array_keys($row) as $name) {
                if (!in_array($name, $columns, true)) {
                    $columns[] = $name;
                }
            }
            $normalised[] = $row;
        }

        if ($columns === []) {
            return [[], []];
        }

        $padded = [];
        foreach ($normalised as $row) {
            $line = [];
            foreach ($columns as $name) {
                $line[$name] = $this->cellValue($row[$name] ?? null);
            }
            $padded[] = $line;
        }

        return [$columns, $padded];
    }

    /** @param array<int,mixed> $rows @return string[] */
    private function keysOf(array $rows): array
    {
        $keys = [];
        foreach ($rows as $row) {
            if (!is_array($row) || array_is_list($row)) {
                continue;
            }
            foreach (array_keys($row) as $key) {
                $name = is_string($key) ? $key : (string) $key;
                if (!in_array($name, $keys, true)) {
                    $keys[] = $name;
                }
            }
        }

        return $keys;
    }

    /** @return array<string,array{name:string,index:int,kind:string,distinct:int,non_null:int,values:array<int,mixed>}> */
    private function profile(array $columns, array $rows): array
    {
        $profile = [];
        foreach ($columns as $index => $name) {
            $profile[$name] = ['name' => $name, 'index' => $index, 'kind' => 'empty', 'distinct' => 0, 'non_null' => 0, 'values' => []];
        }
        if ($columns === []) {
            return $profile;
        }

        $stats = [];
        foreach ($columns as $index => $name) {
            $stats[$name] = ['non_null' => 0, 'numeric' => 0, 'time' => 0, 'year' => 0, 'distinct' => [], 'numbers' => []];
        }

        foreach ($rows as $row) {
            foreach ($columns as $name) {
                $value = $row[$name] ?? null;
                if ($value === null || (is_string($value) && trim($value) === '')) {
                    continue;
                }
                $stats[$name]['non_null']++;
                $token = is_scalar($value) ? (string) $value : (string) json_encode($value);
                $stats[$name]['distinct'][$token] = true;

                if (is_bool($value)) {
                    continue;
                }
                $text = (string) $value;
                if (is_numeric($value)) {
                    $stats[$name]['numeric']++;
                    $stats[$name]['numbers'][] = (float) $value;
                    if (preg_match('/^\d{4}$/', $text) === 1 && (int) $text >= 1900 && (int) $text <= 2200) {
                        $stats[$name]['year']++;
                    }
                    if ($this->looksLikeTime($text)) {
                        $stats[$name]['time']++;
                    }
                } elseif ($this->looksLikeTime($text)) {
                    $stats[$name]['time']++;
                }
            }
        }

        foreach ($columns as $name) {
            $s = $stats[$name];
            $nonNull = $s['non_null'];
            $distinct = count($s['distinct'], COUNT_NORMAL);
            $kind = 'text';
            if ($nonNull === 0) {
                $kind = 'empty';
            } elseif ($s['time'] >= (int) ceil($nonNull * 0.6)) {
                $kind = 'time';
            } elseif ($s['numeric'] >= (int) ceil($nonNull * 0.8)) {
                // A numeric column that is only plausible years, or that a host
                // named after a period and ordered, is a time axis not a measure.
                if ($s['year'] >= (int) ceil($nonNull * 0.8) && $distinct >= 2) {
                    $kind = 'time';
                } elseif ($this->looksLikeTimeName($name) && $distinct >= 2 && $this->nonDecreasing($s['numbers'])) {
                    $kind = 'time';
                } else {
                    $kind = 'number';
                }
            }

            $profile[$name]['kind'] = $kind;
            $profile[$name]['distinct'] = $distinct;
            $profile[$name]['non_null'] = $nonNull;
        }

        return $profile;
    }

    private function pickDimension(array $columns, array $textDims, array $timeDims, array $profile, array $options): ?string
    {
        $wanted = isset($options['dimension']) ? trim((string) $options['dimension']) : '';
        if ($wanted !== '' && in_array($wanted, $columns, true)) {
            return $wanted;
        }
        // The first column is the axis when it is a category or a period; otherwise
        // fall back to the widest non-id text column, then to the first column.
        foreach ($timeDims as $name) {
            if (($profile[$name]['distinct'] ?? 0) >= 2) {
                return $name;
            }
        }
        $first = $columns[0] ?? null;
        if ($first !== null && in_array($first, $textDims, true) && ($profile[$first]['distinct'] ?? 0) >= 1) {
            return $first;
        }
        $best = null;
        $bestRatio = -1.0;
        foreach ($textDims as $name) {
            $nonNull = max(1, (int) ($profile[$name]['non_null'] ?? 0));
            $ratio = (int) ($profile[$name]['distinct'] ?? 0) / $nonNull;
            if ($ratio > $bestRatio && $ratio < 1.0) {
                $bestRatio = $ratio;
                $best = $name;
            }
        }
        if ($best !== null) {
            return $best;
        }
        foreach ($textDims as $name) {
            return $name;
        }

        return $first;
    }

    private function pickMeasure(array $measures, array $options): ?string
    {
        $wanted = isset($options['measure']) ? trim((string) $options['measure']) : '';
        if ($wanted !== '' && in_array($wanted, $measures, true)) {
            return $wanted;
        }
        if ($wanted !== '' && isset($options['measure'])) {
            return $wanted;
        }

        return $measures[0] ?? null;
    }

    /** @return string[] */
    private function seriesOf(array $ctx, array $options): array
    {
        $wanted = [];
        foreach ([$options['measures'] ?? null, $options['series'] ?? null] as $list) {
            if (is_array($list)) {
                foreach ($list as $item) {
                    $name = is_string($item) ? trim($item) : '';
                    if ($name !== '' && !in_array($name, $wanted, true)) {
                        $wanted[] = $name;
                    }
                }
            }
        }
        if ($wanted !== []) {
            return $wanted;
        }
        $single = $this->primaryMeasure($ctx, $options);
        if ($single === null) {
            return [];
        }
        if ($ctx['measures'] === []) {
            return [$single];
        }

        $max = max(1, Config::int('charts.max_series', 6));
        $all = $ctx['measures'];
        if (in_array($single, $all, true)) {
            $all = array_values(array_diff($all, [$single]));
            array_unshift($all, $single);
        }

        return array_slice($all, 0, $max);
    }

    private function primaryMeasure(array $ctx, array $options): ?string
    {
        $single = isset($options['measure']) ? trim((string) $options['measure']) : '';
        if ($single !== '') {
            return $single;
        }

        return $ctx['measure'];
    }

    private function splitDimension(array $ctx, array $options): ?string
    {
        $wanted = isset($options['split']) ? trim((string) $options['split']) : '';
        if ($wanted !== '' && in_array($wanted, $ctx['columns'], true)) {
            return $wanted;
        }
        $dim = (string) ($ctx['dim'] ?? '');
        foreach ($ctx['dims'] as $candidate) {
            if ($candidate !== '' && $candidate !== $dim && $this->distinctCount($ctx, $candidate) <= Config::int('charts.max_series', 6)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Group rows by the primary dimension and aggregate the measures.
     *
     * @return array{0:array<int,string>,1:array<int,array<string,mixed>>}
     */
    private function grouped(array $ctx, array $options, ?string $dimOverride = null): array
    {
        $dim = $dimOverride ?? (string) ($ctx['dim'] ?? '');
        if ($dim === '' || !isset($ctx['profile'][$dim])) {
            return [[], []];
        }
        $measures = $this->seriesOf($ctx, $options);
        $agg = $this->agg($options);

        $groups = [];
        $order = [];
        foreach ($ctx['rows'] as $row) {
            $label = $this->cellLabel($row[$dim] ?? null);
            if ($label === '') {
                $label = '—';
            }
            if (!isset($groups[$label])) {
                $groups[$label] = ['label' => $label, 'count' => 0, 'raw' => []];
                $order[] = $label;
            }
            $groups[$label]['count']++;
            foreach ($measures as $measure) {
                $groups[$label]['raw'][$measure][] = $this->rawNumber($row[$measure] ?? null);
            }
        }

        foreach ($order as $label) {
            $values = [];
            foreach ($measures as $measure) {
                $values[$measure] = $this->reduce($groups[$label]['raw'][$measure] ?? [], $agg, $measure);
            }
            $groups[$label]['values'] = $values;
        }

        $sort = strtolower(trim((string) ($options['sort'] ?? ($this->isTime($ctx, $dim) ? 'none' : (Config::string('charts.sort_categories', 'value_desc'))))));
        if ($sort !== 'none' && $measures !== []) {
            $measure = $measures[0];
            usort($order, function (string $a, string $b) use ($groups, $measure, $sort): int {
                $av = $groups[$a]['values'][$measure] ?? null;
                $bv = $groups[$b]['values'][$measure] ?? null;
                $an = is_numeric($av) ? (float) $av : -NAN;
                $bn = is_numeric($bv) ? (float) $bv : -NAN;
                if (is_nan($an) && !is_nan($bn)) {
                    return 1;
                }
                if (is_nan($bn) && !is_nan($an)) {
                    return -1;
                }
                $cmp = $an <=> $bn;

                return match ($sort) {
                    'value_asc', 'asc' => $cmp,
                    'label', 'label_asc' => strcmp($a, $b),
                    'label_desc' => strcmp($b, $a),
                    default => -$cmp,
                };
            });
        }

        $limit = isset($options['limit']) ? max(1, (int) $options['limit']) : 0;
        if ($limit > 0 && count($order) > $limit) {
            $order = array_slice($order, 0, $limit);
        }
        $ordered = [];
        foreach ($order as $label) {
            $ordered[] = $groups[$label];
        }

        return [array_values($order), $ordered];
    }

    /** @param array<int,array<string,mixed>> $groups @return array<int,int|float|null> */
    private function numericColumn(array $groups, string $measure): array
    {
        $out = [];
        foreach ($groups as $group) {
            $value = $group['values'][$measure] ?? null;
            $out[] = is_numeric($value) ? ($value + 0) : null;
        }

        return $out;
    }

    /** @return array<int,int|float|null> */
    private function numericValues(array $ctx, string $column): array
    {
        $out = [];
        foreach ($ctx['rows'] as $row) {
            $value = $this->toNumber($row[$column] ?? null);
            if ($value !== null) {
                $out[] = $value;
            }
        }
        if ($out === []) {
            $out[] = 0;
        }

        return $out;
    }

    /**
     * Build the row × column matrix a pivot/heatmap renders. Cells keep numbers as
     * numbers; a missing combination is null (never zero) so the host can tell an
     * empty cell apart from a real zero.
     *
     * @return array{rowKeys:array<int,string>,colKeys:array<int,string>,cells:array<string,array<string,mixed>>}
     */
    private function matrix(array $ctx, string $rowDim, string $colDim, ?string $measure, string $agg): array
    {
        $rowKeys = [];
        $colKeys = [];
        $seenRows = [];
        $seenCols = [];
        $buckets = [];

        foreach ($ctx['rows'] as $row) {
            $rk = $this->cellLabel($row[$rowDim] ?? null);
            $ck = $this->cellLabel($row[$colDim] ?? null);
            if ($rk === '') {
                $rk = '—';
            }
            if ($ck === '') {
                $ck = '—';
            }
            if (!isset($seenRows[$rk])) {
                $seenRows[$rk] = true;
                $rowKeys[] = $rk;
            }
            if (!isset($seenCols[$ck])) {
                $seenCols[$ck] = true;
                $colKeys[] = $ck;
            }
            $buckets[$rk][$ck][] = $measure === null ? null : $this->rawNumber($row[$measure] ?? null);
        }

        // Sort keys: numbers ascending, everything else in order of appearance.
        $rowKeys = $this->sortKeys($rowKeys);
        $colKeys = $this->sortKeys($colKeys);

        $cells = [];
        foreach ($rowKeys as $rk) {
            $cells[$rk] = [];
            foreach ($colKeys as $ck) {
                $samples = $buckets[$rk][$ck] ?? [];
                if ($samples === []) {
                    continue;
                }
                if ($measure === null) {
                    $cells[$rk][$ck] = count($samples);
                    continue;
                }
                $cells[$rk][$ck] = $this->reduce($samples, $agg, $measure);
            }
        }

        return ['rowKeys' => $rowKeys, 'colKeys' => $colKeys, 'cells' => $cells];
    }

    /** @param array<int,mixed> $samples */
    private function reduce(array $samples, string $agg, ?string $measure = null): int|float|null
    {
        $numbers = [];
        foreach ($samples as $sample) {
            if (is_numeric($sample)) {
                $numbers[] = (float) $sample;
            }
        }

        return match (strtolower($agg)) {
            'count' => count($samples),
            'avg', 'average', 'mean' => $numbers === [] ? null : array_sum($numbers) / count($numbers),
            'sum', 'total' => $numbers === [] ? ($measure === null ? count($samples) : null) : array_sum($numbers),
            'min' => $numbers === [] ? null : min($numbers),
            'max' => $numbers === [] ? null : max($numbers),
            'first' => $samples[0] ?? null,
            'last' => $samples[count($samples) - 1] ?? null,
            default => $numbers === [] ? null : array_sum($numbers),
        };
    }

    private function agg(array $options): string
    {
        $agg = strtolower(trim((string) ($options['agg'] ?? '')));

        return $agg === '' ? Config::string('charts.default_agg', 'sum') : $agg;
    }

    /** @param array<int,string> $keys @return array<int,string> */
    private function sortKeys(array $keys): array
    {
        $numeric = array_filter($keys, fn ($k) => is_numeric($k));
        if (count($numeric) === count($keys) && $keys !== []) {
            sort($keys, SORT_NUMERIC);

            return $keys;
        }

        return $keys;
    }

    private function rowLimit(array $ctx, array $options): int
    {
        $limit = isset($options['limit']) ? (int) $options['limit'] : 0;
        if ($limit > 0) {
            return $limit;
        }

        return max(1, Config::int('charts.max_rows', 500));
    }

    // ── value helpers ────────────────────────────────────────────────────────

    private function toNumber(mixed $value): ?float
    {
        if (is_bool($value) || $value === null || is_array($value) || is_object($value)) {
            return null;
        }
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }
        $text = trim((string) $value);
        if ($text === '') {
            return null;
        }
        $text = str_replace([' ', "\u{00A0}"], '', $text);
        $commaDecimal = preg_match('/^-?\d{1,3}(?:[.\x{00A0} ]\d{3})*(,\d+)?$/', $text) === 1;
        if ($commaDecimal) {
            $text = str_replace(['.', ','], ['', '.'], $text);
        } else {
            $text = str_replace(',', '', $text);
        }
        if (preg_match('/^-?\d+(\.\d+)?$/', $text) !== 1) {
            return null;
        }

        return (float) $text;
    }

    /** A cell keeps its type: numbers stay numbers, everything else becomes text. */
    private function rawNumber(mixed $value): int|float|string|null
    {
        if ($value === null) {
            return null;
        }
        if (is_bool($value)) {
            return null;
        }
        if (is_int($value)) {
            return $value;
        }
        if (is_float($value)) {
            return $value;
        }
        $text = trim((string) $value);
        if ($text === '') {
            return null;
        }
        $number = $this->toNumber($text);

        return $number === null ? $text : $number;
    }

    private function cell(mixed $value): mixed
    {
        if ($value === null || is_bool($value)) {
            return $value === null ? null : ($value ? 1 : 0);
        }
        if (is_int($value) || is_float($value)) {
            return $value;
        }
        if (is_array($value) || is_object($value)) {
            $encoded = json_encode($value, JSON_UNESCAPED_UNICODE);

            return is_string($encoded) ? $encoded : null;
        }

        return (string) $value;
    }

    private function cellLabel(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if (is_array($value) || is_object($value)) {
            return (string) json_encode($value, JSON_UNESCAPED_UNICODE);
        }

        return trim((string) $value);
    }

    /** Format one measure value the way the host's locale and currency expect. */
    private function formatValue(array $ctx, int|float|null $value): string
    {
        if ($value === null) {
            return '—';
        }

        return NumberFormat::format(
            (float) $value,
            $ctx['unit'] ?? ($this->wantsCurrency($ctx) ? 'currency' : null),
            $ctx['currency'],
            $ctx['currency_position'],
            $ctx['locale'] ?: null,
        );
    }

    /** The aggregated number for one group (row counts when there is no measure). */
    private function groupValue(array $group, ?string $measure): int|float|null
    {
        if ($measure === null) {
            return (int) $group['count'];
        }
        $value = $group['values'][$measure] ?? null;

        return is_numeric($value) ? $value + 0 : null;
    }

    /** Compact wording for one-line insights: RSD 1.2M rather than RSD 1,234,567. */
    private function brief(array $ctx, float $value, string $column): string
    {
        $currency = $this->wantsCurrency($ctx) ? NumberFormat::currency($ctx['currency']) : '';

        return NumberFormat::compact($value, $currency, $ctx['currency_position'], $ctx['locale'] ?: null);
    }

    private function wantsCurrency(array $ctx): bool
    {
        $unit = (string) ($ctx['unit'] ?? '');
        if ($unit !== '' && in_array($unit, ['currency', 'money', 'cash', 'amount', 'price', 'cost'], true)) {
            return true;
        }

        return Config::bool('charts.currency_by_default', false);
    }

    private function axisLabel(array $ctx, array $measures): string
    {
        if ($measures === []) {
            return '';
        }
        if (count($measures) === 1) {
            return $this->label($ctx, $measures[0]);
        }

        return Config::string('charts.multi_axis_label', 'values');
    }

    private function label(array $ctx, string $column): string
    {
        return (string) ($ctx['labels'][$column] ?? $this->humanize($column));
    }

    private function humanize(string $name): string
    {
        $clean = trim(str_replace(['_', '-'], ' ', $name));
        if ($clean === '') {
            return $name;
        }
        if (preg_match('/^[a-z]/', $clean) === 1) {
            return mb_strtoupper(mb_substr($clean, 0, 1)) . mb_substr($clean, 1);
        }

        return $clean;
    }

    private function distinctCount(array $ctx, string $column): int
    {
        return (int) ($ctx['profile'][$column]['distinct'] ?? 0);
    }

    private function isTime(array $ctx, string $column): bool
    {
        return ($ctx['profile'][$column]['kind'] ?? '') === 'time';
    }

    /** Ordered stage detection: "1. …" prefixes or a stage-named column. */
    private function looksOrdinal(array $ctx, string $column): bool
    {
        if ($this->isTime($ctx, $column)) {
            return false;
        }
        $name = strtolower($column);
        foreach (self::STAGE_NAME_HINTS as $hint) {
            if (str_contains($name, $hint)) {
                return true;
            }
        }
        $configured = Config::list('charts.funnel_stage_terms', []);
        foreach ($configured as $term) {
            if (is_string($term) && $term !== '' && str_contains($name, strtolower($term))) {
                return true;
            }
        }

        $labels = [];
        foreach ($ctx['rows'] as $row) {
            $label = $this->cellLabel($row[$column] ?? null);
            if ($label !== '') {
                $labels[] = $label;
            }
        }
        if ($labels === []) {
            return false;
        }
        $prefixed = 0;
        foreach ($labels as $label) {
            if (preg_match('/^\s*\d+\s*[.)\-–:]/u', $label) === 1) {
                $prefixed++;
            }
        }
        if ($prefixed >= (int) ceil(count($labels) * 0.8)) {
            return true;
        }

        // Same vocabulary as the heatmap rule: a stage column is also monotonic.
        return $this->isStrictlyIncreasing($ctx, $column);
    }

    private function isStrictlyIncreasing(array $ctx, string $column): bool
    {
        if ($this->isTime($ctx, $column)) {
            return false;
        }
        $previous = null;
        $seen = 0;
        foreach ($ctx['rows'] as $row) {
            $value = $this->toNumber($row[$column] ?? null);
            if ($value === null) {
                return false;
            }
            if ($previous !== null && $value <= $previous) {
                return false;
            }
            $previous = $value;
            $seen++;
        }

        return $seen >= 2;
    }

    private function nonDecreasing(array $numbers): bool
    {
        $previous = null;
        foreach ($numbers as $number) {
            if ($previous !== null && $number < $previous) {
                return false;
            }
            $previous = $number;
        }

        return count($numbers) >= 2;
    }

    private function firstDimensionOtherThan(array $ctx, string $exclude): string
    {
        foreach ($ctx['dims'] as $candidate) {
            if ($candidate !== $exclude) {
                return $candidate;
            }
        }

        return '';
    }

    private function findGeoColumns(array $ctx): array
    {
        $latNames = array_map(mb_strtolower(...), Config::list('charts.latitude_columns', ['latitude', 'lat', 'lat_deg', 'gps_latitude', 'geo_lat']));
        $lngNames = array_map(mb_strtolower(...), Config::list('charts.longitude_columns', ['longitude', 'lng', 'lon', 'long', 'geo_lng', 'gps_longitude']));
        $lat = null;
        $lng = null;
        foreach ($ctx['columns'] as $column) {
            $name = mb_strtolower(trim($column));
            $kind = (string) ($ctx['profile'][$column]['kind'] ?? '');
            if ($kind !== 'number') {
                continue;
            }
            if (in_array($name, $latNames, true)) {
                $lat = $column;
            } elseif (in_array($name, $lngNames, true)) {
                $lng = $column;
            }
        }
        if ($lat === null || $lng === null) {
            return [];
        }

        return ['lat' => $lat, 'lng' => $lng];
    }

    private function looksLikeTime(string $text): bool
    {
        $text = trim($text);
        if ($text === '') {
            return false;
        }
        if (preg_match('/^\d{4}-\d{1,2}(-\d{1,2}([ T]\d{1,2}:\d{2}(:\d{2})?)?)?$/D', $text) === 1) {
            return true;
        }
        if (preg_match('/^\d{1,2}[-\/.]\d{1,2}[-\/.]\d{2,4}$/D', $text) === 1) {
            return true;
        }
        if (preg_match('/^\d{4}[-\s]?Q[1-4]$/iu', $text) === 1 || preg_match('/^Q[1-4][-\s]?\d{0,4}$/iu', $text) === 1) {
            return true;
        }
        $lower = mb_strtolower($text);
        $months = $this->monthPattern();
        if ($months !== '') {
            if (preg_match('/^(?:' . $months . ')(?:[.\-–\s]?\d{2,4})?$/iu', $lower) === 1) {
                return true;
            }
            // "1 March", "12-mar-2026"
            if (preg_match('/^\d{1,2}[.\-–\s]?(?:' . $months . ')(?:[.\-–\s]?\d{2,4})?$/iu', $lower) === 1) {
                return true;
            }
        }

        return false;
    }

    private function looksLikeTimeName(string $name): bool
    {
        $lower = strtolower(str_replace('_', '', $name));
        foreach (self::TIME_NAME_HINTS as $hint) {
            if (str_contains($lower, $hint)) {
                return true;
            }
        }
        foreach (Config::list('charts.time_column_names', []) as $hint) {
            if (is_string($hint) && $hint !== '' && str_contains($lower, strtolower(str_replace('_', '', $hint)))) {
                return true;
            }
        }

        return false;
    }

    /** Escape-ready alternation of month tokens, longest first so "january" wins over "jan". */
    private function monthPattern(): string
    {
        $configured = Config::list('charts.month_tokens', []);
        $tokens = [];
        foreach ($configured !== [] ? $configured : self::MONTH_TOKENS as $token) {
            if (is_string($token) && trim($token) !== '') {
                $tokens[] = preg_quote(mb_strtolower(trim($token)), '/');
            }
        }
        $tokens = array_values(array_unique($tokens));
        usort($tokens, fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));

        return implode('|', $tokens);
    }

    private function isIdColumn(string $name): bool
    {
        $lower = strtolower(trim($name));
        if ($lower === 'id') {
            return true;
        }
        $tokens = preg_split('/[_\-\s]+/', $lower) ?: [];
        $last = strtolower((string) end($tokens));
        if (in_array($last, self::ID_NAME_TOKENS, true)) {
            return true;
        }
        foreach (Config::list('charts.id_columns', []) as $extra) {
            if (is_string($extra) && strtolower(trim($extra)) === $lower) {
                return true;
            }
        }

        return false;
    }

    private function unitFromIntent(string $intent): ?string
    {
        $text = strtolower(trim($intent));
        if ($text === '') {
            return null;
        }
        foreach (['amount', 'price', 'cost', 'revenue', 'income', 'sales', 'money', 'currency', 'value in cash'] as $word) {
            if (str_contains($text, $word)) {
                return Config::string('domain.currency', '') !== '' ? 'currency' : null;
            }
        }
        if (str_contains($text, 'percent') || str_contains($text, 'share')) {
            return 'percent';
        }

        return null;
    }

    /** Normalise a cell into a JSON-safe scalar before profiling. */
    private function cellValue(mixed $value): mixed
    {
        if ($value === null || is_bool($value) || is_int($value) || is_float($value)) {
            return $value;
        }
        if (is_string($value)) {
            return mb_check_encoding($value, 'UTF-8') ? $value : (string) iconv('UTF-8', 'UTF-8//IGNORE', $value);
        }
        if (is_array($value)) {
            $encoded = json_encode($value, JSON_UNESCAPED_UNICODE);

            return is_string($encoded) ? $encoded : null;
        }

        return null;
    }

    // ── sanitising ───────────────────────────────────────────────────────────

    private function cleanArray(array $node, int $depth): array
    {
        if ($depth > Config::int('charts.max_depth', 12)) {
            return [];
        }
        $out = [];
        foreach ($node as $key => $value) {
            $cleanKey = is_int($key) ? $key : (string) $key;
            $out[$cleanKey] = $this->cleanValue($value, $depth + 1);
        }

        return $out;
    }

    private function cleanValue(mixed $value, int $depth): mixed
    {
        if ($value === null || is_bool($value) || is_int($value)) {
            return $value;
        }
        if (is_float($value)) {
            return is_finite($value) ? $value : null;
        }
        if (is_string($value)) {
            return mb_check_encoding($value, 'UTF-8') ? $value : (string) iconv('UTF-8', 'UTF-8//IGNORE', $value);
        }
        if (is_array($value)) {
            return $this->cleanArray($value, $depth);
        }
        if ($value instanceof \stdClass) {
            return $this->cleanArray((array) $value, $depth);
        }
        if (is_object($value)) {
            if (method_exists($value, 'toArray')) {
                /** @var array<mixed> $array */
                $array = $value->toArray();

                return $this->cleanArray($array, $depth);
            }
            if ($value instanceof \JsonSerializable || $value instanceof \DateTimeInterface) {
                $encoded = json_encode($value, JSON_UNESCAPED_UNICODE);
                $decoded = is_string($encoded) ? json_decode($encoded, true) : null;

                return is_array($decoded) ? $this->cleanArray($decoded, $depth) : null;
            }

            return null;
        }

        return null; // resources, closures, enums without a scalar value
    }
}
