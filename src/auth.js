const express = require('express');
const { AuthorizationCode } = require('simple-oauth2');
const { OSM_API_BASE, REDIRECT_URI } = require('./config');

const router = express.Router();

// src/auth.js - around the client creation
if (!process.env.CLIENT_ID || !process.env.CLIENT_SECRET) {
  console.error('CRITICAL: Missing CLIENT_ID or CLIENT_SECRET in environment');
  process.exit(1); // crash early during startup
}

const client = new AuthorizationCode({
  client: {
    id: process.env.CLIENT_ID,
    secret: process.env.CLIENT_SECRET,
  auth: {
    tokenHost: OSM_API_BASE,
    authorizePath: '/oauth/authorize',
    tokenPath: '/oauth/token',
  },
});

router.get('/auth', (req, res) => {
  const uri = client.authorizeURL({
    redirect_uri: REDIRECT_URI,
    scope: 'section:member:read section:quartermaster:write section:finance:read',
    access_type: 'offline',
  });
  res.redirect(uri);
});

router.get('/callback', async (req, res) => {
  const { code } = req.query;
  try {
    const result = await client.getToken({ code, redirect_uri: REDIRECT_URI });
    req.session.accessToken = result.token.access_token;
    res.redirect('/');
  } catch (error) {
    console.error('Auth callback error:', error.message);
    res.status(500).send('Authentication failed');
  }
});

router.get('/reset', (req, res) => {
  req.session.destroy();
  res.redirect('/');
});

module.exports = router;
