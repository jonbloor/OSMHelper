'use strict';

const path = require('path');
require('dotenv').config({ path: path.join(__dirname, '.env') });

console.log('server.js: env loaded, PORT=', process.env.PORT);

const { createApp } = require('./src/app');
console.log('server.js: createApp loaded');

const app = createApp();
console.log('server.js: app created, listen type=', typeof app.listen);

const port = Number(process.env.PORT || 3000);
app.listen(port, () => console.log(`Server running on http://0.0.0.0:${port}`));
