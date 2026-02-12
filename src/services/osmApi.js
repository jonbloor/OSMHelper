// src/services/osmApi.js
'use strict';

const axios = require('axios');
const { OSM_API_BASE } = require('../config/constants');
const { withConcurrencyLimit } = require('../utils/concurrency');
const { TTLCache } = require('../utils/cache');

const DEFAULT_TIMEOUT_MS = 20_000;

// Cache:
// - Per-process memory cache, keyed per user+request
// - Default TTL 30s, can override per call
const apiCache = new TTLCache({ defaultTtlMs: 30_000, maxItems: 2_500 });

// Rate limit tracking per access token (per process)
const rateStateByToken = new Map();
/**
 * rateState shape:
 * {
 *   limit: number|null,
 *   remaining: number|null,
 *   resetEpochSec: number|null,
 *   lastUpdatedMs: number
 * }
 */

function tokenKey(accessToken) {
  // Keep it simple: store by token string
  // If you want, we can hash it later, but this stays in server memory only
  return String(accessToken || '');
}

function updateRateState(accessToken, headers = {}) {
  const key = tokenKey(accessToken);
  if (!key) return;

  const limit = headers['x-ratelimit-limit'];
  const remaining = headers['x-ratelimit-remaining'];
  const reset = headers['x-ratelimit-reset'];

  const parsed = {
    limit: limit != null ? Number(limit) : null,
    remaining: remaining != null ? Number(remaining) : null,
    resetEpochSec: reset != null ? Number(reset) : null,
    lastUpdatedMs: Date.now(),
  };

  // Only store if at least one header is present
  if (parsed.limit !== null || parsed.remaining !== null || parsed.resetEpochSec !== null) {
    rateStateByToken.set(key, parsed);
  }
}

function getRateState(accessToken) {
  const key = tokenKey(accessToken);
  if (!key) return null;
  return rateStateByToken.get(key) || null;
}

function secondsUntilReset(accessToken) {
  const st = getRateState(accessToken);
  if (!st || !st.resetEpochSec) return null;
  const nowSec = Math.floor(Date.now() / 1000);
  return st.resetEpochSec - nowSec;
}

async function sleep(ms) {
  return new Promise(resolve => setTimeout(resolve, ms));
}

function isRetryableStatus(status) {
  return status === 429 || status === 502 || status === 503 || status === 504;
}

async function politePreflight(accessToken) {
  // If we know we’re basically out of calls and reset is soon, wait.
  const st = getRateState(accessToken);
  if (!st) return;

  if (typeof st.remaining === 'number' && st.remaining <= 1) {
    const until = secondsUntilReset(accessToken);
    if (typeof until === 'number' && until > 0) {
      // Cap the wait to keep UX sane. If it’s longer, we’ll still attempt and handle 429.
      const waitMs = Math.min(until * 1000, 8_000);
      if (waitMs > 250) await sleep(waitMs);
    }
  }
}

// Create an axios instance for OSM
const client = axios.create({
  baseURL: OSM_API_BASE,
  timeout: DEFAULT_TIMEOUT_MS,
});

// Core request, with concurrency control + retries + rate-limit tracking
async function request(accessToken, method, path, { params, data } = {}) {
  if (!accessToken) {
    const err = new Error('Missing access token');
    err.status = 401;
    throw err;
  }

  await politePreflight(accessToken);

  const doRequest = async () => {
    const res = await client.request({
      method,
      url: path,
      params,
      data,
      headers: {
        Authorization: `Bearer ${accessToken}`,
      },
      validateStatus: s => s >= 200 && s < 300, // axios throws on non-2xx
    });

    updateRateState(accessToken, res.headers || {});
    return res.data;
  };

  // Wrap in concurrency limiter (your existing helper)
  const limited = () => withConcurrencyLimit(() => doRequest());

  // Simple retry loop
  let attempt = 0;
  const maxAttempts = 3;

  // eslint-disable-next-line no-constant-condition
  while (true) {
    try {
      return await limited();
    } catch (err) {
      attempt += 1;

      const status = err?.response?.status;
      const headers = err?.response?.headers || {};
      if (accessToken) updateRateState(accessToken, headers);

      const retryable = isRetryableStatus(status);
      const canRetry = retryable && attempt < maxAttempts;

      if (!canRetry) {
        // Normalise a bit for route error handling
        err.status = status || err.status || 500;
        throw err;
      }

      // Backoff:
      // - If Retry-After present, respect it (seconds)
      // - Otherwise exponential backoff with jitter
      const retryAfter = headers['retry-after'];
      if (retryAfter) {
        const waitMs = Math.min(Number(retryAfter) * 1000, 10_000);
        if (waitMs > 0) await sleep(waitMs);
        continue;
      }

      const base = 300 * Math.pow(2, attempt - 1); // 300, 600, 1200...
      const jitter = Math.floor(Math.random() * 200);
      await sleep(Math.min(base + jitter, 2_000));
    }
  }
}

// Public API ---------------------------------------------------------------

function makeCacheKey({ userKey, method, path, params }) {
  // Params must be stable for cache keys
  const p = params ? JSON.stringify(params, Object.keys(params).sort()) : '';
  return `${userKey}::${method.toUpperCase()}::${path}::${p}`;
}

function getUserKeyFromSession(session) {
  // Make sure cache is per user
  // Prefer OSM user id if you store it, else fall back to session id
  if (!session) return 'anon';
  return String(session.osmUserId || session.userId || session.id || session.sessionId || 'user');
}

/**
 * Cached GET:
 * - cacheScope is per-session (per user) by default
 * - ttlMs defaults to 30s but can be overridden
 */
async function get(accessToken, path, { params, ttlMs, session } = {}) {
  const userKey = getUserKeyFromSession(session);
  const key = makeCacheKey({ userKey, method: 'GET', path, params });

  return apiCache.wrap(key, ttlMs ?? 30_000, async () => {
    return request(accessToken, 'GET', path, { params });
  });
}

async function post(accessToken, path, { params, data } = {}) {
  // POST is not cached
  return request(accessToken, 'POST', path, { params, data });
}

async function put(accessToken, path, { params, data } = {}) {
  return request(accessToken, 'PUT', path, { params, data });
}

async function del(accessToken, path, { params } = {}) {
  return request(accessToken, 'DELETE', path, { params });
}

function getRateLimitSnapshot(accessToken) {
  const st = getRateState(accessToken);
  if (!st) return null;

  const until = secondsUntilReset(accessToken);
  return {
    limit: st.limit,
    remaining: st.remaining,
    resetEpochSec: st.resetEpochSec,
    secondsUntilReset: typeof until === 'number' ? until : null,
  };
}

module.exports = {
  get,
  post,
  put,
  del,
  request, // keep available if you have niche needs
  getRateLimitSnapshot,
  _cache: apiCache, // optional: useful for debugging
};
