const { AuthorizationCode } = require('simple-oauth2');
const { OSM_API_BASE } = require('../config/constants');

function createOAuthClient() {
  return new AuthorizationCode({
    client: {
      id: process.env.CLIENT_ID,
      secret: process.env.CLIENT_SECRET,
    },
    auth: {
      tokenHost: OSM_API_BASE,
      authorizePath: '/oauth/authorize',
      tokenPath: '/oauth/token',
    },
  });
}

module.exports = { createOAuthClient };
