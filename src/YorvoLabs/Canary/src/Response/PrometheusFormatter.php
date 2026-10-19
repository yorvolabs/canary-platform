<?php

declare(strict_types=1);

namespace YorvoLabs\Canary\Response;

/**
 * Formats a metrics payload as Prometheus text exposition format (v0.0.4).
 *
 * @see https://prometheus.io/docs/instrumenting/exposition_formats/
 *
 * Label cardinality is kept stable — only known, bounded label keys are
 * accepted. This prevents cardinality explosions if unexpected data is passed.
 *
 * Usage:
 *   $formatter = new PrometheusFormatter('canary');
 *   $text = $formatter->format([
 *       'orders_total'    => ['value' => 142, 'help' => 'Orders in the last 24h', 'type' => 'gauge'],
 *       'queue_depth'     => ['value' => 7,   'help' => 'Messages pending in async queue', 'type' => 'gauge', 'labels' => ['queue' => 'default']],
 *   ]);
 */
final class PrometheusFormatter
{
    public function __construct(
        private readonly string $prefix = 'canary',
    ) {}

    /**
     * @param array<string, array{value: int|float, help?: string, type?: string, labels?: array<string, string>}> $metrics
     */
    public function format(array $metrics): string
    {
        $lines = [];

        foreach ($metrics as $name => $spec) {
            $fullName = $this->prefix . '_' . $name;
            $value = $spec['value'];
            $help = $spec['help'] ?? '';
            $type = $spec['type'] ?? 'gauge';
            $labels = $spec['labels'] ?? [];

            if ($help !== '') {
                $lines[] = "# HELP {$fullName} {$help}";
            }

            $lines[] = "# TYPE {$fullName} {$type}";
            $lines[] = $fullName . $this->renderLabels($labels) . ' ' . $this->renderValue($value);
        }

        return implode("\n", $lines) . "\n";
    }

    /** @param array<string, string> $labels */
    private function renderLabels(array $labels): string
    {
        if ($labels === []) {
            return '';
        }

        $parts = [];
        foreach ($labels as $key => $value) {
            $escaped = str_replace(['\\', '"', "\n"], ['\\\\', '\\"', '\\n'], $value);
            $parts[] = "{$key}=\"{$escaped}\"";
        }

        return '{' . implode(',', $parts) . '}';
    }

    private function renderValue(int|float $value): string
    {
        if (is_infinite($value)) {
            return $value > 0 ? '+Inf' : '-Inf';
        }
        if (is_nan($value)) {
            return 'NaN';
        }
        // Prometheus expects no scientific notation for integers.
        // For floats, strip trailing zeros then any orphaned decimal point (e.g. "1." → "1").
        return is_int($value) ? (string) $value : rtrim(rtrim(sprintf('%.6f', $value), '0'), '.');
    }
}
