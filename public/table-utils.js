// public/table-utils.js
(function () {
  const tables = document.querySelectorAll('[data-table]');
  tables.forEach(table => {
    const search = document.querySelector('[data-search]');
    const countSpan = document.querySelector('[data-count]');
    const tbody = table.querySelector('tbody');
    const rows = Array.from(tbody.querySelectorAll('tr'));

    function normalise(s) { return String(s || '').toLowerCase(); }

    function updateCount() {
      if (countSpan) {
        const visible = rows.filter(tr => tr.style.display !== 'none').length;
        countSpan.textContent = visible;
      }
    }

    if (search) {
      search.addEventListener('input', () => {
        const q = normalise(search.value);
        rows.forEach(tr => {
          const text = normalise(tr.innerText);
          tr.style.display = text.includes(q) ? '' : 'none';
        });
        updateCount();
      });
    }

    table.querySelectorAll('th[data-sort]').forEach((th, idx) => {
      let dir = 1;
      th.style.cursor = 'pointer';

      th.addEventListener('click', () => {
        dir *= -1;
        const type = th.getAttribute('data-sort');

        const sorted = rows.slice().sort((a, b) => {
          const av = a.children[idx]?.innerText?.trim() || '';
          const bv = b.children[idx]?.innerText?.trim() || '';

          if (type === 'number') return dir * ((parseFloat(av) || 0) - (parseFloat(bv) || 0));
          if (type === 'date') return dir * (new Date(av).getTime() - new Date(bv).getTime());
          return dir * av.localeCompare(bv, 'en', { numeric: true, sensitivity: 'base' });
        });

        sorted.forEach(tr => tbody.appendChild(tr));
        updateCount();
      });
    });

    updateCount(); // Initial count
  });
})();
