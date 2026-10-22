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
