// src/services/osmApi.js
'use strict';

const axios = require('axios');
const { OSM_API_BASE } = require('../config/constants');
const { limit } = require('../utils/concurrency');
const { TTLCache } = require('../utils/cache');

const DEFAULT_TIMEOUT_MS = 20_000;

const apiCache = new TTLCache({ defaultTtlMs: 30_000, maxItems: 2_500 });

const rateStateByToken = new Map();

function tokenKey(accessToken) {
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
    resetInSec: reset != null ? Number(reset) : null,
    lastUpdatedMs: Date.now(),
  };

  if (parsed.limit !== null || parsed.remaining !== null || parsed.resetInSec !== null) {
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
  if (!st || st.resetInSec == null) return null;

  const elapsedMs = Date.now() - st.lastUpdatedMs;
  const elapsedSec = Math.floor(elapsedMs / 1000);

  return Math.max(0, st.resetInSec - elapsedSec);
}

async function request(accessToken, method, path, { params, data } = {}) {
  if (!accessToken) throw new Error('No access token');

  const url = `${OSM_API_BASE}${path.startsWith('/') ? '' : '/'}${path}`;

  const config = {
    method,
    url,
    params,
    data,
    headers: {
      Authorization: `Bearer ${accessToken}`,
      'Content-Type': 'application/json',
    },
    timeout: DEFAULT_TIMEOUT_MS,
  };

  try {
    const response = await axios(config);
    updateRateState(accessToken, response.headers);
    return response;
  } catch (error) {
    if (error.response) {
      updateRateState(accessToken, error.response.headers);
      if (error.response.status === 429) {
        throw new Error(`Rate limited. Try again in ${secondsUntilReset(accessToken)} seconds.`);
      }
    }
    throw error;
  }
}

function makeCacheKey({ userKey, method, path, params }) {
  const p = params ? Object.keys(params).sort().map(k => `${k}=${params[k]}`).join('&') : '';
  return `${userKey}::${method.toUpperCase()}::${path}::${p}`;
}

function getUserKeyFromSession(session) {
  return String(session.osmUserId || session.userId || session.id || session.sessionId || 'user');
}

async function get(accessToken, path, { params, ttlMs, session } = {}) {
  const userKey = getUserKeyFromSession(session);
  const key = makeCacheKey({ userKey, method: 'GET', path, params });

  return apiCache.wrap(key, ttlMs ?? 30_000, async () => {
    return request(accessToken, 'GET', path, { params });
  });
}

async function post(accessToken, path, { params, data } = {}) {
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
    resetInSec: st.resetInSec,
    secondsUntilReset: typeof until === 'number' ? until : null,
  };
}

function authHeader(accessToken) {
  return { Authorization: `Bearer ${accessToken}` };
}

async function getDynamicSections(accessToken, session = null) {
  const response = await get(accessToken, '/oauth/resource', {
    session,
    ttlMs: 5 * 60 * 1000,
  });

  const data = response.data; // Assuming the data is in response.data
  console.log('Raw /oauth/resource data:', JSON.stringify(data)); // Debug log to check structure

  let roles = data?.roles || data?.data?.roles || data?.items || data?.data?.items || [];
  if (!Array.isArray(roles)) roles = [];

  // Map roles to sections format expected by routes
  const sections = roles.map(role => ({
    section_id: role.sectionid || role.section_id,
    section_type: role.section || role.section_type,
    section_name: role.sectionname || role.section_name,
    current_term_id: role.currentterm || role.current_term_id,
    group_name: role.groupname || role.group_name,
    // Add more mappings if needed from script (e.g., groupid: role.groupid)
  }));

  console.log('Parsed sections:', sections); // Debug log

  return sections;
}

module.exports = {
  get,
  post,
  put,
  del,
  request,
  getRateLimitSnapshot,
  authHeader,
  getDynamicSections,
  _cache: apiCache,
};
