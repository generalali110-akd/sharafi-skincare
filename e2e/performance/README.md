# Storefront performance gate

`Performance CI` runs Lighthouse against deterministic Storefront fixtures on the Laravel development server. It is a regression gate, not a substitute for measurements from the public HTTPS staging/production origin.

Current CI budgets:

- Performance score: >= 80
- Accessibility score: >= 90
- Best Practices score: >= 90
- SEO score: >= 90
- LCP: <= 3300 ms in this throttled lab harness
- CLS: <= 0.10
- Total Blocking Time: <= 300 ms
- Total transfer size: <= 1.5 MB

The public delivery target remains LCP <= 2.5 seconds on the real HTTPS staging origin. The CI LCP ceiling is intentionally a slightly wider regression guard because the test uses PHP's development server and a shared hosted runner rather than the production Caddy/PHP-FPM stack.

Known follow-up: Lighthouse identifies parser-blocking CSS/JavaScript as the main remaining load-path debt. That cleanup belongs to the frontend/CSP hardening stage so script loading order can be normalized once, rather than applying page-by-page timing hacks.
