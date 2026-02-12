const express = require('express');
const session = require('express-session');
const path = require('path');

const { assertEnv } = require('./config/env');

const authRoutes = require('./routes/auth.routes');
const dashboardRoutes = require('./routes/dashboard.routes');
const membersRoutes = require('./routes/members.routes');
const waitingListRoutes = require('./routes/waitingList.routes');
const equipmentRoutes = require('./routes/equipment.routes');
const bankTransfersRoutes = require('./routes/bankTransfers.routes');
const membershipDashboardRoutes = require('./routes/membershipDashboard.routes');

function createApp() {
  assertEnv();

  const app = express();
  app.set('trust proxy', 1);
  app.set('view engine', 'ejs');
  app.set('views', path.join(process.cwd(), 'views'));

  // Modern Express has body parsing built in; no need for body-parser.
  app.use(express.urlencoded({ extended: true }));

  app.use(express.static(path.join(process.cwd(), 'public')));

  // NOTE: default MemoryStore is fine for dev but not recommended for production multi-instance setups.
  app.use(session({
    secret: process.env.SESSION_SECRET,
    resave: false,
    saveUninitialized: false,
    cookie: {
      httpOnly: true,
      sameSite: 'lax',
      secure: process.env.NODE_ENV === 'production', // requires HTTPS
      maxAge: 1000 * 60 * 60 * 8, // 8 hours
    }
  }));

  // Routes
  app.use(authRoutes);
  app.use(dashboardRoutes);
  app.use(membersRoutes);
  app.use(waitingListRoutes);
  app.use(equipmentRoutes);
  app.use(bankTransfersRoutes);
  app.use(membershipDashboardRoutes);
  app.get('/health', (req, res) => res.status(200).send('ok'));

  // Reset auth
  app.get('/reset', (req, res) => {
    req.session.destroy(() => res.redirect('/'));
  });

  // Minimal error handler (you can expand this later)
  // eslint-disable-next-line no-unused-vars
  app.use((err, req, res, next) => {
    console.error(err);
    res.status(500).render('error', { message: 'Something went wrong.' });
  });

  return app;
}

module.exports = { createApp };
