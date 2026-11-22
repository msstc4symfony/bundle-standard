# Decoration priorities of shared services

Bundles of the family decorate the same Symfony services. A lower priority is applied later, i.e. further
out. Every decorator here must work in any order relative to the others; the only fixed bound is the
framework's own decorator.

## `http_client.transport`

| Priority | Decorator | Bundle |
|---|---|---|
| -10 | `mock_response_factory` (FrameworkBundle, functional tests) | symfony/framework-bundle |
| -15 | `TraceHttpClientDecorator` — adds `traceparent`, `tracestate`, `baggage` | msstc4symfony/tracing-bundle |
| -20 | `HttpClientDecorator` — counts requests and their duration | msstc4symfony/metrics-bundle |
| -25 | `ProfilingHttpClient` — a detached `http <METHOD> <host>` span per request (`auto_spans.http_client`, 2.2) | msstc4symfony/profiling-bundle |

Rules:

1. Every family decorator has a priority below -10, so tests with a mocked transport still go through it.
   Each bundle has a kernel test with `mock_response_factory` that proves it.
2. A decorator never depends on another family decorator being inside or outside it.
3. A new decorator takes an unused priority and is added to this table first.

## logic-bundle Action middleware

Here a **higher** priority runs **further out**: `WrapActionsPass` sorts middleware descending, the opposite
direction of the decorator table above.

| Priority | Middleware | Package | Why here |
|---|---|---|---|
| 600 | `TracingMiddleware` | msstc4symfony/tracing-bridge-logic | outermost: trace context for everything inside |
| 500 | `ProfilingMiddleware` (`action <name>` span) | msstc4symfony/profiling-bridge-logic | span covers metrics and SLO |
| 400 | `MetricsMiddleware` (`logic_action_duration_histogram_seconds`) | msstc4symfony/metrics-bridge-logic | duration includes validation and lock wait |
| 350 | `SloMiddleware` (`symfony_slo_events_total`) | msstc4symfony/slo-bridge-logic | inside metrics, outside logging |
| 300 | `LoggingMiddleware` | msstc4symfony/logic-bundle | |
| 200 | `ValidationMiddleware` | msstc4symfony/logic-bundle | |
| 100 | `LockMiddleware` | msstc4symfony/logic-bundle | |

Rules:

1. A new middleware takes an unused priority and is added to this table first.
2. A middleware never depends on another family middleware being inside or outside it, except for the documented
   reason of its own position.
