#!/usr/bin/env node
/**
 * stdio <-> Streamable HTTP bridge for the TYPO3 MCP server (EXT:mcp_server).
 *
 * Claude Desktop starts this script with its built-in Node.js, so nothing has
 * to be installed on the client. The script forwards every JSON-RPC message
 * from stdin to the remote /mcp endpoint and writes the answers to stdout.
 *
 * Authentication uses a personal MCP token from the TYPO3 backend module
 * "MCP Server", sent as Bearer token. This avoids the OAuth login flow, which
 * is harder to set up for every team member.
 *
 * No dependencies on purpose: plain node:http/https, works on Node >= 16.
 */
'use strict';

const http = require('http');
const https = require('https');
const readline = require('readline');

const SERVER_URL = (process.env.TYPO3_MCP_URL || '').trim();
const TOKEN = (process.env.TYPO3_MCP_TOKEN || '').trim();
const TIMEOUT_MS = 300000;

function log(message) {
    // stderr ends up in Claude Desktop's MCP log (mcp-server-*.log)
    process.stderr.write(`[typo3-mcp-bridge] ${message}\n`);
}

function writeMessage(message) {
    process.stdout.write(JSON.stringify(message) + '\n');
}

function replyError(id, message) {
    if (id === undefined || id === null) {
        return;
    }
    writeMessage({ jsonrpc: '2.0', id, error: { code: -32000, message } });
}

let endpoint;
try {
    endpoint = new URL(SERVER_URL);
    if (!/\/mcp\/?$/.test(endpoint.pathname)) {
        endpoint.pathname = endpoint.pathname.replace(/\/?$/, '/mcp');
    }
} catch (e) {
    endpoint = null;
}

let sessionId = null;
let protocolVersion = null;
let initializeMessage = null;
let initialized = Promise.resolve();

/**
 * POST one JSON-RPC message, resolves with { status, headers, body }.
 */
function post(message) {
    return new Promise((resolve, reject) => {
        const payload = Buffer.from(JSON.stringify(message), 'utf8');
        const headers = {
            'Content-Type': 'application/json',
            'Accept': 'application/json, text/event-stream',
            'Content-Length': payload.length,
            'User-Agent': 'typo3-mcp-bridge/1.0',
        };
        if (TOKEN) {
            headers['Authorization'] = `Bearer ${TOKEN}`;
        }
        if (sessionId) {
            headers['Mcp-Session-Id'] = sessionId;
        }
        if (protocolVersion) {
            headers['MCP-Protocol-Version'] = protocolVersion;
        }

        const client = endpoint.protocol === 'http:' ? http : https;
        const request = client.request(endpoint, { method: 'POST', headers, timeout: TIMEOUT_MS }, (response) => {
            const chunks = [];
            response.on('data', (chunk) => chunks.push(chunk));
            response.on('end', () => resolve({
                status: response.statusCode,
                headers: response.headers,
                body: Buffer.concat(chunks).toString('utf8'),
            }));
            response.on('error', reject);
        });
        request.on('timeout', () => request.destroy(new Error(`no answer within ${TIMEOUT_MS / 1000}s`)));
        request.on('error', reject);
        request.end(payload);
    });
}

/**
 * Extract JSON-RPC messages from a JSON or SSE response body.
 */
function parseMessages(response) {
    const contentType = String(response.headers['content-type'] || '');
    const body = response.body.trim();
    if (!body) {
        return [];
    }
    if (contentType.includes('text/event-stream')) {
        const messages = [];
        for (const event of body.split(/\r?\n\r?\n/)) {
            const data = event.split(/\r?\n/)
                .filter((line) => line.startsWith('data:'))
                .map((line) => line.slice(5).replace(/^ /, ''))
                .join('\n');
            if (data) {
                messages.push(JSON.parse(data));
            }
        }
        return messages;
    }
    const parsed = JSON.parse(body);
    return Array.isArray(parsed) ? parsed : [parsed];
}

function describeHttpError(response) {
    const snippet = response.body.replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim().slice(0, 300);
    if (response.status === 401) {
        return `HTTP 401: MCP token missing, invalid or expired. Create a new one in the TYPO3 backend module "MCP Server". ${snippet}`;
    }
    return `HTTP ${response.status} from ${endpoint.host}: ${snippet}`;
}

function isExpiredSession(response) {
    return sessionId !== null
        && (response.status === 404 || (response.status === 400 && /session/i.test(response.body)));
}

/**
 * Re-run the handshake after the server dropped the session (30 min idle).
 */
async function reinitialize() {
    log('session expired, re-initializing');
    sessionId = null;
    const response = await post(initializeMessage);
    if (response.status >= 400) {
        throw new Error(describeHttpError(response));
    }
    sessionId = response.headers['mcp-session-id'] || null;
    await post({ jsonrpc: '2.0', method: 'notifications/initialized' });
}

async function forward(message, isRetry = false) {
    let response;
    try {
        response = await post(message);
    } catch (e) {
        log(`request failed: ${e.message}`);
        replyError(message.id, `Cannot reach ${endpoint.host}: ${e.message}`);
        return;
    }

    if (isExpiredSession(response) && !isRetry && initializeMessage && message.method !== 'initialize') {
        try {
            await reinitialize();
        } catch (e) {
            replyError(message.id, e.message);
            return;
        }
        return forward(message, true);
    }

    if (response.headers['mcp-session-id']) {
        sessionId = response.headers['mcp-session-id'];
    }

    if (response.status >= 400) {
        const error = describeHttpError(response);
        log(error);
        // A JSON-RPC error body is still a valid answer, pass it through
        try {
            const messages = parseMessages(response).filter((m) => m && m.jsonrpc === '2.0');
            if (messages.length > 0) {
                messages.forEach(writeMessage);
                return;
            }
        } catch (e) {
            // not JSON, fall through
        }
        replyError(message.id, error);
        return;
    }

    let messages;
    try {
        messages = parseMessages(response);
    } catch (e) {
        log(`unparseable response (${response.status}): ${response.body.slice(0, 300)}`);
        replyError(message.id, `Invalid response from ${endpoint.host}: ${e.message}`);
        return;
    }
    for (const reply of messages) {
        if (message.method === 'initialize' && reply.result && reply.result.protocolVersion) {
            protocolVersion = reply.result.protocolVersion;
        }
        writeMessage(reply);
    }
}

function handleLine(line) {
    if (!line.trim()) {
        return;
    }
    let message;
    try {
        message = JSON.parse(line);
    } catch (e) {
        log(`ignoring invalid JSON from client: ${line.slice(0, 200)}`);
        return;
    }

    if (!endpoint) {
        replyError(message.id, `Invalid server URL "${SERVER_URL}" - check the extension settings.`);
        return;
    }

    if (message.method === 'initialize') {
        initializeMessage = message;
        sessionId = null;
        protocolVersion = null;
        initialized = forward(message);
        return;
    }
    // Everything else waits for the handshake, then runs concurrently
    initialized.then(() => forward(message));
}

if (!TOKEN) {
    log('no MCP token configured - requests will be rejected with 401');
}
log(`forwarding to ${endpoint ? endpoint.origin + endpoint.pathname : SERVER_URL}`);

const input = readline.createInterface({ input: process.stdin, terminal: false });
input.on('line', handleLine);
input.on('close', () => {
    // Let pending requests finish, then exit
    initialized.finally(() => setTimeout(() => process.exit(0), 100));
});
