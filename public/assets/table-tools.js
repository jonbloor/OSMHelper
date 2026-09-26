(function () {
  'use strict';

  function textOf(el) {
    return (el && (el.textContent || '') || '').replace(/\s+/g, ' ').trim();
  }

  // Text of a cell/header without helper bits marked .no-export (info icons, score breakdowns).
  function plainText(el) {
    if (!el || !el.querySelector || !el.querySelector('.no-export')) return textOf(el);
    var c = el.cloneNode(true);
    Array.prototype.forEach.call(c.querySelectorAll('.no-export'), function (n) { n.remove(); });
    return textOf(c);
  }

  // Export value of a cell: data-export if given; else the value of any form controls in it
  // (text input, textarea, select → chosen option text); else its text.
  function cellValue(td) {
    if (!td || !td.querySelectorAll) return '';
    if (td.hasAttribute('data-export')) return td.getAttribute('data-export');
    var controls = Array.prototype.filter.call(td.querySelectorAll('input, select, textarea'), function (c) {
      // Selection checkboxes (other pages) are not data, so they keep exporting as before.
      return ['hidden', 'submit', 'button', 'checkbox', 'radio'].indexOf(c.type) === -1;
    });
    if (!controls.length) return plainText(td);
    var vals = controls.map(function (c) {
      if (c.tagName === 'SELECT') {
        var o = c.options[c.selectedIndex];
        return o ? (o.getAttribute('data-export') !== null ? o.getAttribute('data-export') : textOf(o)) : '';
      }
      return String(c.value || '').replace(/\s+/g, ' ').trim();
    });
    var extra = plainText(td); // e.g. existing text shown next to a control
    var out = vals.join(' ').trim();
    return out === '' ? extra : out;
  }

  function sortValue(td) {
    if (!td) return '';
    if (td.hasAttribute && td.hasAttribute('data-sort')) return td.getAttribute('data-sort');
    return plainText(td);
  }

  // Rows to export: all data rows when the table has data-export-rows="all", else visible rows.
  function exportRows(table) {
    var body = table.tBodies[0] || table;
    if (table.getAttribute('data-export-rows') === 'all') {
      return Array.prototype.slice.call(body.querySelectorAll('tr'));
    }
    return visibleRows(body);
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
      return plainText(th).replace(/\s*[▲▼↕]\s*$/, '') || 'Column';
    });
  }

  function rowCells(tr) {
    return Array.prototype.map.call(tr.children, function (td) { return cellValue(td); });
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
    exportRows(table).forEach(function (tr) {
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
    exportRows(table).forEach(function (tr) {
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
    var title = (table.getAttribute('data-export-title') || '').trim() || name;
    var subtitle = (table.getAttribute('data-export-subtitle') || '').trim();
    var cols = headers(table);
    var html = '<!DOCTYPE html><html><head><meta charset="utf-8"><title>' + xmlEsc(title) + '</title>' +
      '<style>body{font:12px/1.4 system-ui,sans-serif;padding:16px;color:#111}' +
      'h1{font-size:18px;margin:0 0 6px}p.sub{margin:0 0 14px;font-size:13px;color:#333}' +
      'table{border-collapse:collapse;width:100%}th,td{border:1px solid #ccc;padding:4px 6px;text-align:left}' +
      'th{background:#f0f3f6}tfoot td{font-weight:600;background:#f7f9fb}' +
      '@media print{button{display:none}}</style></head><body>' +
      '<h1>' + xmlEsc(title) + '</h1>';
    if (subtitle) {
      html += '<p class="sub">' + xmlEsc(subtitle) + '</p>';
    }
    html += '<table><thead><tr>' +
      cols.map(function (c) { return '<th>' + xmlEsc(c) + '</th>'; }).join('') +
      '</tr></thead><tbody>';
    var nightsCol = -1;
    cols.forEach(function (c, i) {
      if (/^nights$/i.test(c)) nightsCol = i;
    });
    var visibleNights = 0;
    var rowCount = 0;
    exportRows(table).forEach(function (tr) {
      if (tr.querySelector('td[colspan]')) return;
      var cells = rowCells(tr);
      rowCount++;
      if (nightsCol >= 0) {
        var n = parseInt(String(cells[nightsCol] || '').replace(/,/g, ''), 10);
        if (!isNaN(n)) visibleNights += n;
      }
      html += '<tr>' + cells.map(function (c) {
        return '<td>' + xmlEsc(c) + '</td>';
      }).join('') + '</tr>';
    });
    html += '</tbody>';
    if (nightsCol >= 0 && rowCount > 0) {
      html += '<tfoot><tr>';
      cols.forEach(function (c, i) {
        if (i === 0) {
          html += '<td>' + (table.getAttribute('data-export-rows') === 'all' ? 'Total' : 'Total (rows shown)') + '</td>';
        } else if (i === nightsCol) {
          html += '<td>' + visibleNights + '</td>';
        } else {
          html += '<td></td>';
        }
      });
      html += '</tr></tfoot>';
    }
    html += '</table><script>window.onload=function(){window.print()}<\/script></body></html>';
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
      var av = sortValue(a.children[colIndex]);
      var bv = sortValue(b.children[colIndex]);
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
    var allRows = table.getAttribute('data-export-rows') === 'all';
    toolbar.innerHTML =
      '<label class="table-search">Search <input type="search" placeholder="Filter rows…" data-table-search></label>' +
      '<span class="table-export">' +
      '<button type="button" class="btn btn-secondary btn-sm" data-export="csv">CSV</button> ' +
      '<button type="button" class="btn btn-secondary btn-sm" data-export="xls">Excel</button> ' +
      '<button type="button" class="btn btn-secondary btn-sm" data-export="pdf">PDF</button>' +
      (allRows ? ' <span class="muted table-export-note">Exports every row, even when searching.</span>' : '') +
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
      th.addEventListener('click', function (e) {
        // Links / toggles inside a header (e.g. "How the score works") must not sort.
        if (e && e.target && e.target.closest && e.target.closest('a, button, summary, details, input, select')) return;
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
        if (e.target !== th) return;
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
