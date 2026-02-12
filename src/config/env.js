function assertEnv() {
  const required = [
    'SESSION_SECRET',
    'CLIENT_ID',
    'CLIENT_SECRET',
    'REDIRECT_URI',
  ];

  const missing = required.filter(k => !process.env[k] || String(process.env[k]).trim() === '');
  if (missing.length) {
    throw new Error(`Missing required environment variables: ${missing.join(', ')}`);
  }
}

module.exports = { assertEnv };
