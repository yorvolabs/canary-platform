<?php

declare(strict_types=1);

namespace YorvoLabs\Canary\Tests\Unit\Response;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use YorvoLabs\Canary\Response\PrometheusFormatter;

final class PrometheusFormatterTest extends TestCase
{
    #[Test]
    public function it_formats_a_simple_gauge(): void
    {
        $output = (new PrometheusFormatter('canary'))->format([
            'orders_total' => ['value' => 142, 'help' => 'Orders in the last 24h', 'type' => 'gauge'],
        ]);
        self::assertStringContainsString('# HELP canary_orders_total Orders in the last 24h', $output);
        self::assertStringContainsString('# TYPE canary_orders_total gauge', $output);
        self::assertStringContainsString('canary_orders_total 142', $output);
    }

    #[Test]
    public function it_formats_labels_correctly(): void
    {
        $output = (new PrometheusFormatter('canary'))->format([
            'queue_depth' => ['value' => 7, 'type' => 'gauge', 'labels' => ['queue' => 'default']],
        ]);
        self::assertStringContainsString('canary_queue_depth{queue="default"} 7', $output);
    }

    #[Test]
    public function it_escapes_special_characters_in_label_values(): void
    {
        $output = (new PrometheusFormatter('canary'))->format([
            'test_metric' => ['value' => 1, 'labels' => ['name' => 'has "quotes" and \\backslash']],
        ]);
        self::assertStringContainsString('has \\"quotes\\" and \\\\backslash', $output);
    }

    #[Test]
    public function it_outputs_multiple_metrics(): void
    {
        $output = (new PrometheusFormatter('canary'))->format([
            'orders_total'    => ['value' => 10],
            'customers_total' => ['value' => 5],
        ]);
        self::assertStringContainsString('canary_orders_total 10', $output);
        self::assertStringContainsString('canary_customers_total 5', $output);
    }

    #[Test]
    public function it_omits_help_line_when_not_provided(): void
    {
        $output = (new PrometheusFormatter('canary'))->format(['test_metric' => ['value' => 1]]);
        self::assertStringNotContainsString('# HELP canary_test_metric', $output);
        self::assertStringContainsString('canary_test_metric 1', $output);
    }

    #[Test]
    public function it_ends_with_a_newline(): void
    {
        self::assertStringEndsWith("\n", (new PrometheusFormatter('canary'))->format(['x' => ['value' => 0]]));
    }

    #[Test]
    public function it_uses_custom_prefix(): void
    {
        self::assertStringContainsString('myapp_orders 1', (new PrometheusFormatter('myapp'))->format(['orders' => ['value' => 1]]));
    }
}
