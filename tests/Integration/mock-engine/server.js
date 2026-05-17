// SPDX-License-Identifier: Apache-2.0
//
// Mock OpenSalesTax engine for the Mg-1 live-Magento integration test.
//
// Implements just enough of the engine's v1 API to drive a real Magento
// $quote->collectTotals() down the OpenSalesTax code path:
//   - GET  /v1/health    → { status: "ok", version: "mock-1.0", database_connected: true }
//   - POST /v1/calculate → an engine-shaped response for the MN-fixture cart
//
// The response shape MUST exactly match what
// `EJOsterberg\OpenSalesTax\Model\OstaxResponse::fromArray()` parses
// (engine v0.58+ contract):
//
//   {
//     "subtotal":  "100.00",
//     "tax_total": "9.025",
//     "lines": [
//       {
//         "amount":   "100.00",
//         "category": "general",
//         "tax":      "9.025",
//         "rate_pct": "9.025",
//         "jurisdictions": [
//           { "name": "Minnesota",      "type": "state",   "rate_pct": "6.875", "tax": "6.875" },
//           { "name": "Hennepin County","type": "county",  "rate_pct": "0.15",  "tax": "0.15"  },
//           { "name": "Minneapolis",    "type": "city",    "rate_pct": "2.0",   "tax": "2.0"   }
//         ]
//       }
//     ]
//   }
//
// We respond deterministically based on the request payload's line_items
// total, so the test can assert against an exact tax_amount value:
// total * 0.09025 (MN compound rate for our fixture address).
//
// Boots in <50ms; zero external deps; runs on Node 24 (default on
// GitHub Actions ubuntu-latest).

const http = require('http');

const PORT = parseInt(process.env.PORT || '8080', 10);
const HOST = process.env.HOST || '127.0.0.1';

// MN compound rate for the test fixture address (Minneapolis 55401):
// 6.875% state + 0.15% Hennepin County + 2.0% Minneapolis = 9.025%
// Matches the portfolio's VM-916/918/919 fixtures.
const MN_RATE_PCT = 9.025;
const MN_JURISDICTIONS = [
    { name: 'Minnesota', type: 'state', rate_pct: '6.875' },
    { name: 'Hennepin County', type: 'county', rate_pct: '0.15' },
    { name: 'Minneapolis', type: 'city', rate_pct: '2.0' },
];

function calculateLine(line) {
    const amount = parseFloat(line.amount);
    const tax = +(amount * (MN_RATE_PCT / 100)).toFixed(4);
    const jurisdictions = MN_JURISDICTIONS.map(j => ({
        name: j.name,
        type: j.type,
        rate_pct: j.rate_pct,
        tax: (+(amount * (parseFloat(j.rate_pct) / 100)).toFixed(4)).toFixed(4),
    }));
    return {
        amount: line.amount,
        category: line.category || 'general',
        tax: tax.toFixed(4),
        rate_pct: MN_RATE_PCT.toFixed(3),
        jurisdictions,
    };
}

function handleCalculate(req, res, body) {
    let payload;
    try {
        payload = JSON.parse(body);
    } catch (e) {
        res.writeHead(400, { 'Content-Type': 'application/json' });
        res.end(JSON.stringify({ error: 'invalid JSON' }));
        return;
    }

    const lineItems = Array.isArray(payload.line_items) ? payload.line_items : [];
    const responseLines = lineItems
        .filter(l => l && typeof l.amount === 'string')
        .map(calculateLine);

    const subtotal = responseLines
        .reduce((acc, l) => acc + parseFloat(l.amount), 0)
        .toFixed(2);
    const taxTotal = responseLines
        .reduce((acc, l) => acc + parseFloat(l.tax), 0)
        .toFixed(4);

    const response = {
        subtotal,
        tax_total: taxTotal,
        lines: responseLines,
        disclaimer: 'mock-engine response for Mg-1 integration test only',
    };

    // Structured log line for debugging from the runner log; never log
    // the body itself — it carries the fixture address.
    process.stderr.write(JSON.stringify({
        evt: 'calculate',
        line_count: responseLines.length,
        subtotal,
        tax_total: taxTotal,
        zip5: (payload.address && payload.address.zip5) || '',
    }) + '\n');

    res.writeHead(200, { 'Content-Type': 'application/json' });
    res.end(JSON.stringify(response));
}

function handleHealth(req, res) {
    res.writeHead(200, { 'Content-Type': 'application/json' });
    res.end(JSON.stringify({
        status: 'ok',
        version: 'mock-1.0',
        database_connected: true,
    }));
}

const server = http.createServer((req, res) => {
    if (req.method === 'GET' && req.url === '/v1/health') {
        handleHealth(req, res);
        return;
    }
    if (req.method === 'POST' && req.url === '/v1/calculate') {
        let body = '';
        req.on('data', chunk => { body += chunk; });
        req.on('end', () => handleCalculate(req, res, body));
        return;
    }
    res.writeHead(404, { 'Content-Type': 'application/json' });
    res.end(JSON.stringify({ error: 'not found', method: req.method, url: req.url }));
});

server.listen(PORT, HOST, () => {
    process.stderr.write(JSON.stringify({
        evt: 'listen',
        host: HOST,
        port: PORT,
    }) + '\n');
});

// Graceful shutdown on the workflow's `always()` teardown step.
process.on('SIGTERM', () => server.close(() => process.exit(0)));
process.on('SIGINT', () => server.close(() => process.exit(0)));
