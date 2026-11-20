import { lookup } from 'node:dns/promises';
import http from 'node:http';
import https from 'node:https';
import { BlockList, isIP } from 'node:net';
import { chromium } from 'playwright-core';

const restricted = new BlockList();
for (const [address, prefix] of [
    ['0.0.0.0', 8],
    ['10.0.0.0', 8],
    ['100.64.0.0', 10],
    ['127.0.0.0', 8],
    ['169.254.0.0', 16],
    ['172.16.0.0', 12],
    ['192.0.0.0', 24],
    ['192.0.2.0', 24],
    ['192.88.99.0', 24],
    ['192.168.0.0', 16],
    ['198.18.0.0', 15],
    ['198.51.100.0', 24],
    ['203.0.113.0', 24],
    ['224.0.0.0', 3],
]) {
    restricted.addSubnet(address, prefix);
}

const pageUrl = new URL(process.argv[2]);
const executablePath = process.argv[3];
const limit = 6_000_000;
let received = 0;
let requests = 0;

async function load(url, deadline) {
    if (
        url.origin !== pageUrl.origin ||
        !['http:', 'https:'].includes(url.protocol) ||
        url.port ||
        url.username ||
        url.password ||
        isIP(url.hostname) ||
        !url.hostname.includes('.')
    ) {
        throw new Error('Unsupported origin');
    }
    const entries = await lookup(url.hostname, { family: 4, all: true });
    if (
        !entries.length ||
        entries.some(({ address }) => restricted.check(address))
    ) {
        throw new Error('Non-public destination');
    }

    return new Promise((resolve, reject) => {
        const transport = url.protocol === 'https:' ? https : http;
        const request = transport.get(
            url,
            {
                agent: false,
                family: 4,
                autoSelectFamily: false,
                lookup: (_hostname, _options, callback) =>
                    callback(null, entries[0].address, 4),
                signal: deadline,
                headers: { Accept: '*/*', 'User-Agent': 'CVGenerator/1.0' },
            },
            (response) => {
                const chunks = [];
                let bytes = 0;
                response.on('data', (chunk) => {
                    received += chunk.length;
                    bytes += chunk.length;
                    if (received > limit || bytes > 2_000_000) {
                        request.destroy(new Error('Response exceeds budget'));
                        return;
                    }
                    chunks.push(chunk);
                });
                response.on('error', reject);
                response.on('end', () => {
                    const headers = {};
                    for (const key of [
                        'content-type',
                        'content-encoding',
                        'location',
                    ]) {
                        if (typeof response.headers[key] === 'string') {
                            headers[key] = response.headers[key];
                        }
                    }
                    resolve({
                        status: response.statusCode,
                        headers,
                        body: Buffer.concat(chunks),
                    });
                });
            },
        );
        request.on('error', reject);
    });
}

const deadline = AbortSignal.timeout(11_000);
const browser = await chromium.launch({
    executablePath,
    chromiumSandbox: true,
    timeout: 4000,
    args: [
        '--disable-background-networking',
        '--disable-extensions',
        '--force-webrtc-ip-handling-policy=disable_non_proxied_udp',
    ],
    proxy: { server: 'http://127.0.0.1:1', bypass: '<-loopback>' },
});

try {
    const context = await browser.newContext({
        serviceWorkers: 'block',
        acceptDownloads: false,
    });
    await context.route('**/*', async (route) => {
        const request = route.request();
        if (
            deadline.aborted ||
            ++requests > 32 ||
            request.method() !== 'GET' ||
            !['document', 'script', 'xhr', 'fetch', 'stylesheet'].includes(
                request.resourceType(),
            )
        ) {
            await route.abort().catch(() => {});
            return;
        }
        try {
            await route.fulfill(await load(new URL(request.url()), deadline));
        } catch {
            await route.abort().catch(() => {});
        }
    });
    const page = await context.newPage();
    const response = await page.goto(pageUrl.href, {
        waitUntil: 'domcontentloaded',
        timeout: 6000,
    });
    if (!response?.ok()) {
        throw new Error('Page unavailable');
    }

    const expand = page
        .getByRole('button', {
            name: /^(voir plus|afficher plus|show more|read more)$/i,
        })
        .first();
    if (await expand.isVisible()) {
        await expand.click({ timeout: 750 }).catch(() => {});
    }
    for (let step = 0; step < 4 && !deadline.aborted; step++) {
        await page.evaluate(() =>
            window.scrollBy(0, Math.max(window.innerHeight, 500)),
        );
        await new Promise((resolve) => setTimeout(resolve, 210));
    }

    const html = await page.evaluate(() => {
        const content =
            document.querySelector(
                'main article, article, main, [role=main]',
            ) ?? document.body;
        const safe = new DOMParser().parseFromString(
            '<!doctype html><html><head></head><body></body></html>',
            'text/html',
        );
        safe.title = document.title.slice(0, 300);
        for (const schema of document.querySelectorAll(
            'script[type="application/ld+json"]',
        )) {
            const script = safe.createElement('script');
            script.type = 'application/ld+json';
            script.textContent = schema.textContent.slice(0, 50_000);
            safe.head.append(script);
        }
        const main = safe.createElement('main');
        main.textContent = content?.innerText?.slice(0, 40_000) ?? '';
        safe.body.append(main);
        return safe.documentElement.outerHTML.slice(0, 200_000);
    });
    process.stdout.write(html);
} finally {
    await browser.close();
}
