(function () {
  'use strict';

  function textOf(el) {
    return (el && (el.textContent || '') || '').replace(/\s+/g, ' ').trim();
  }

  function visibleRows(tbody) {
    return Array.prototype.filter.call(tbody.querySelectorAll('tr'), function (tr) {
      return !tr.hidden && tr.offsetParent !== null;
    });
  }

  function colCount(table) {
    var ths = table.querySelectorAll('thead th');
    return ths.length || (table.querySelector('tr') ? table.querySelector('tr').children.length : 0);
  }

  function headers(table) {
    return Array.prototype.map.call(table.querySelectorAll('thead th'), function (th) {
      return textOf(th).replace(/\s*[▲▼↕]\s*$/, '') || 'Column';
    });
  }

  function rowCells(tr) {
    return Array.prototype.map.call(tr.children, function (td) { return textOf(td); });
  }

  function downloadBlob(filename, mime, content) {
    var blob = content instanceof Blob ? content : new Blob([content], { type: mime });
    var a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = filename;
    document.body.appendChild(a);
    a.click();
    setTimeout(function () {
      URL.revokeObjectURL(a.href);
      a.remove();
    }, 1000);
  }

  function csvEscape(v) {
    v = String(v == null ? '' : v);
    if (/[",\n\r]/.test(v)) return '"' + v.replace(/"/g, '""') + '"';
    return v;
  }

  function exportCsv(table, name) {
    var cols = headers(table);
    var lines = [cols.map(csvEscape).join(',')];
    visibleRows(table.tBodies[0] || table).forEach(function (tr) {
      if (tr.querySelector('td[colspan]')) return;
      lines.push(rowCells(tr).map(csvEscape).join(','));
    });
    downloadBlob(name + '.csv', 'text/csv;charset=utf-8', '\ufeff' + lines.join('\n'));
  }

  function exportXls(table, name) {
    // Excel-friendly SpreadsheetML
    var cols = headers(table);
    var rowsXml = '<Row>' + cols.map(function (c) {
      return '<Cell><Data ss:Type="String">' + xmlEsc(c) + '</Data></Cell>';
    }).join('') + '</Row>';
    visibleRows(table.tBodies[0] || table).forEach(function (tr) {
      if (tr.querySelector('td[colspan]')) return;
      rowsXml += '<Row>' + rowCells(tr).map(function (c) {
        var n = parseFloat(c);
        if (c !== '' && !isNaN(n) && String(n) === c) {
          return '<Cell><Data ss:Type="Number">' + n + '</Data></Cell>';
        }
        return '<Cell><Data ss:Type="String">' + xmlEsc(c) + '</Data></Cell>';
      }).join('') + '</Row>';
    });
    var xml = '<?xml version="1.0"?>' +
      '<?mso-application progid="Excel.Sheet"?>' +
      '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet"' +
      ' xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet">' +
      '<Worksheet ss:Name="Sheet1"><Table>' + rowsXml + '</Table></Worksheet></Workbook>';
    downloadBlob(name + '.xls', 'application/vnd.ms-excel', xml);
  }

  function xmlEsc(s) {
    return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
  }

  function exportPdf(table, name) {
    // Printable window — user can Save as PDF from the browser print dialog
    var cols = headers(table);
    var html = '<!DOCTYPE html><html><head><meta charset="utf-8"><title>' + xmlEsc(name) + '</title>' +
      '<style>body{font:12px/1.4 system-ui,sans-serif;padding:16px}h1{font-size:16px;margin:0 0 12px}' +
      'table{border-collapse:collapse;width:100%}th,td{border:1px solid #ccc;padding:4px 6px;text-align:left}' +
      'th{background:#f0f3f6}@media print{button{display:none}}</style></head><body>' +
      '<h1>' + xmlEsc(name) + '</h1><table><thead><tr>' +
      cols.map(function (c) { return '<th>' + xmlEsc(c) + '</th>'; }).join('') +
      '</tr></thead><tbody>';
    visibleRows(table.tBodies[0] || table).forEach(function (tr) {
      if (tr.querySelector('td[colspan]')) return;
      html += '<tr>' + rowCells(tr).map(function (c) {
        return '<td>' + xmlEsc(c) + '</td>';
      }).join('') + '</tr>';
    });
    html += '</tbody></table><script>window.onload=function(){window.print()}<\/script></body></html>';
    var w = window.open('', '_blank');
    if (!w) {
      alert('Allow pop-ups to export PDF (print → Save as PDF).');
      return;
    }
    w.document.write(html);
    w.document.close();
  }

  function sortTable(table, colIndex, dir) {
    var tbody = table.tBodies[0];
    if (!tbody) return;
    var rows = Array.prototype.slice.call(tbody.querySelectorAll('tr'));
    var dataRows = rows.filter(function (tr) { return !tr.querySelector('td[colspan]'); });
    dataRows.sort(function (a, b) {
      var av = textOf(a.children[colIndex] || {});
      var bv = textOf(b.children[colIndex] || {});
      var an = parseFloat(av.replace(/,/g, ''));
      var bn = parseFloat(bv.replace(/,/g, ''));
      var bothNum = av !== '' && bv !== '' && !isNaN(an) && !isNaN(bn);
      var cmp;
      if (bothNum) cmp = an - bn;
      else cmp = av.localeCompare(bv, undefined, { numeric: true, sensitivity: 'base' });
      return dir === 'desc' ? -cmp : cmp;
    });
    dataRows.forEach(function (tr) { tbody.appendChild(tr); });
  }

  function enhance(table) {
    if (!table || table.dataset.tableToolsReady) return;
    table.dataset.tableToolsReady = '1';
    table.classList.add('is-enhanced');

    var name = table.getAttribute('data-export-name') || table.id || 'table';
    var toolbar = document.createElement('div');
    toolbar.className = 'table-tools';
    toolbar.innerHTML =
      '<label class="table-search">Search <input type="search" placeholder="Filter rows…" data-table-search></label>' +
      '<span class="table-export">' +
      '<button type="button" class="btn btn-secondary btn-sm" data-export="csv">CSV</button> ' +
      '<button type="button" class="btn btn-secondary btn-sm" data-export="xls">Excel</button> ' +
      '<button type="button" class="btn btn-secondary btn-sm" data-export="pdf">PDF</button>' +
      '</span>';

    var wrap = table.closest('.table-wrap') || table.parentNode;
    wrap.insertBefore(toolbar, table);

    var search = toolbar.querySelector('[data-table-search]');
    search.addEventListener('input', function () {
      var q = search.value.trim().toLowerCase();
      Array.prototype.forEach.call(table.tBodies[0].querySelectorAll('tr'), function (tr) {
        if (tr.querySelector('td[colspan]')) return;
        // Don't override section-filter hide unless searching — combine: hide if section-hidden OR search miss
        var sectionHidden = tr.hasAttribute('data-section') && tr.dataset.filterHidden === '1';
        if (!q) {
          if (tr.hasAttribute('data-section')) {
            tr.hidden = tr.dataset.filterHidden === '1';
          } else {
            tr.hidden = false;
          }
          return;
        }
        var hay = textOf(tr).toLowerCase();
        var miss = hay.indexOf(q) === -1;
        tr.hidden = miss || sectionHidden;
      });
    });

    toolbar.querySelector('[data-export="csv"]').addEventListener('click', function () { exportCsv(table, name); });
    toolbar.querySelector('[data-export="xls"]').addEventListener('click', function () { exportXls(table, name); });
    toolbar.querySelector('[data-export="pdf"]').addEventListener('click', function () { exportPdf(table, name); });

    Array.prototype.forEach.call(table.querySelectorAll('thead th'), function (th, idx) {
      th.classList.add('sortable');
      th.setAttribute('tabindex', '0');
      th.setAttribute('title', 'Sort by this column');
      th.addEventListener('click', function () {
        var dir = th.dataset.sortDir === 'asc' ? 'desc' : 'asc';
        Array.prototype.forEach.call(table.querySelectorAll('thead th'), function (h) {
          h.dataset.sortDir = '';
          h.classList.remove('sort-asc', 'sort-desc');
        });
        th.dataset.sortDir = dir;
        th.classList.add(dir === 'asc' ? 'sort-asc' : 'sort-desc');
        sortTable(table, idx, dir);
      });
      th.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); th.click(); }
      });
    });
  }

  // Hook members section filter to mark filterHidden
  function hookMembersFilter() {
    var root = document.querySelector('[data-members-filter]');
    var table = document.getElementById('members-table');
    if (!root || !table) return;
    var origApply = null;
    // Patch after members script: observe hidden changes
    var observer = new MutationObserver(function () {
      Array.prototype.forEach.call(table.querySelectorAll('tbody tr[data-section]'), function (tr) {
        // members script sets tr.hidden; mirror into data-filter-hidden for search combo
        if (tr.hidden && !(document.querySelector('[data-table-search]') || {}).value) {
          tr.dataset.filterHidden = '1';
        }
      });
    });
    // Better: wrap apply by listening to checkbox changes after members script
    root.addEventListener('change', function () {
      setTimeout(function () {
        Array.prototype.forEach.call(table.querySelectorAll('tbody tr[data-section]'), function (tr) {
          tr.dataset.filterHidden = tr.hidden ? '1' : '0';
        });
      }, 0);
    });
  }

  document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('table[data-table-tools]').forEach(enhance);
    hookMembersFilter();
  });
})();
