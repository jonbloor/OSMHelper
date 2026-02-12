function limit(concurrency = 3) {
  let active = 0;
  const queue = [];

  const next = () => {
    if (active >= concurrency) return;
    const job = queue.shift();
    if (!job) return;

    active += 1;
    job().finally(() => {
      active -= 1;
      next();
    });
  };

  return fn => new Promise((resolve, reject) => {
    queue.push(() => Promise.resolve().then(fn).then(resolve, reject));
    next();
  });
}

module.exports = { limit };
