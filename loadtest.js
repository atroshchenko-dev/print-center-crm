// k6 Load Test — CRM Print
//
// Usage:
//   k6 run loadtest.js
//   k6 run --vus 10 --duration 30s loadtest.js
//
// What this actually covers — three public GETs, no authenticated traffic:
//   - /ping        health check, hits the database
//   - /login       the Inertia page, not the POST
//   - /price-list  the heaviest public page
//
// The header used to claim it drove "/login (POST, auth flow)" and
// "/orders (authenticated, main workload)". It never has. Testing the
// authenticated workload needs a session, and /login allows five attempts a
// minute — see e2e/auth.setup.ts for how the Playwright suite solves it.
//
// Load profile: the department has at most five people working at once, so the
// peak here is ten — double the real ceiling, enough to show headroom without
// pretending to be a scenario that will never happen. It used to ramp to 50,
// which put ten times the real load on production for no information.
//
// Thresholds:
//   - p95 < 500ms
//   - error rate < 1%

import http from 'k6/http';
import { check, sleep } from 'k6';

const BASE_URL = __ENV.BASE_URL || 'https://print.example.com';

export const options = {
    stages: [
        { duration: '10s', target: 5 },   // Ramp up to the real working ceiling
        { duration: '30s', target: 10 },  // Peak: double it, to show headroom
        { duration: '10s', target: 0 },   // Ramp down
    ],
    thresholds: {
        http_req_duration: ['p(95)<500'],  // 95th percentile < 500ms
        http_req_failed: ['rate<0.01'],    // Error rate < 1%
    },
};

// ─── Scenario: Public endpoints ──────────────────

export default function () {
    // 1. Health ping (public, lightweight)
    const ping = http.get(`${BASE_URL}/ping`);
    check(ping, {
        'ping returns 200': (r) => r.status === 200,
        'ping returns ok': (r) => r.json('status') === 'ok',
        'ping < 200ms': (r) => r.timings.duration < 200,
    });

    sleep(0.5);

    // 2. Login page (GET — renders Inertia page)
    const loginPage = http.get(`${BASE_URL}/login`);
    check(loginPage, {
        'login page returns 200': (r) => r.status === 200,
        'login page < 1s': (r) => r.timings.duration < 1000,
    });

    sleep(0.5);

    // 3. Price list (public, data-heavy)
    const priceList = http.get(`${BASE_URL}/price-list`);
    check(priceList, {
        'price-list returns 200': (r) => r.status === 200,
        'price-list < 2s': (r) => r.timings.duration < 2000,
    });

    sleep(1);
}

// ─── Scenario: Authenticated flow ────────────────
// Uncomment below for authenticated load test (requires valid credentials)

/*
export function authenticatedFlow() {
    const loginRes = http.post(`${BASE_URL}/login`, {
        login: 'loadtest@example.com',
        password: 'loadtest123',
        _token: '...',  // Need to extract CSRF from login page
    });

    if (loginRes.status === 200 || loginRes.status === 302) {
        const orders = http.get(`${BASE_URL}/orders`);
        check(orders, {
            'orders page loads': (r) => r.status === 200,
        });
    }

    sleep(1);
}
*/
