function requireAuth(req, res, next) {
  if (!req.session?.accessToken) {
    return res.redirect('/auth');
  }
  next();
}

module.exports = { requireAuth };
