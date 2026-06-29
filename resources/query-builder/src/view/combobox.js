// A filterable, GROUPED combobox — input + an always-visible (while open) live-filtered list, ported
// in spirit from the visualizer's sc-filter-select. Replaces native <datalist> (which won't open on
// focus, can't group, and is awful on mobile). PURE DOM. Items: { label, sublabel?, value, group? }.

import { fuzzyMatch } from '../core/fuzzy.js';

function el(tag, attrs = {}, children = []) {
  const node = document.createElement(tag);
  for (const [k, v] of Object.entries(attrs)) {
    if (k === 'class') node.className = v;
    else if (k === 'text') node.textContent = v;
    else node[k] = v;
  }
  for (const child of children) node.appendChild(child);
  return node;
}

export function createCombobox({ items = [], placeholder = '', initialValue = '', grouped = false, onSelect } = {}) {
  const input = el('input', { type: 'text', class: 'qb-combo-input', value: initialValue, placeholder });
  const list = el('div', { class: 'qb-combo-list' });
  const wrap = el('div', { class: 'qb-combo' }, [input, list]);

  let highlighted = -1;

  function filter() {
    const q = input.value.trim();
    if (!q) return items;
    return items
      .map((it) => {
        const m = fuzzyMatch(q, it.label + ' ' + (it.sublabel || '') + ' ' + (it.group || ''));
        return m ? { it, score: m.score } : null;
      })
      .filter(Boolean)
      .sort((a, b) => b.score - a.score)
      .map((x) => x.it);
  }

  function itemEl(it) {
    const node = el('button', { type: 'button', class: 'qb-combo-item' }, [
      el('span', { class: 'qb-combo-label', text: it.label }),
      ...(it.sublabel ? [el('span', { class: 'qb-combo-sub', text: it.sublabel })] : []),
    ]);
    node.__item = it;
    // mousedown (not click) so selection fires before the input's blur hides the list.
    node.addEventListener('mousedown', (e) => { e.preventDefault(); select(it); });
    return node;
  }

  function render() {
    const matched = filter();
    list.innerHTML = '';
    highlighted = -1;

    // HARD render cap — the field search can yield tens of thousands of paths; rendering them all locks
    // the browser. Show the top slice; "keep typing" narrows via the fuzzy ranking in filter().
    const CAP = 100;
    const shown = matched.slice(0, CAP);

    if (grouped) {
      const groups = new Map();
      for (const it of shown) {
        const g = it.group || '';
        if (!groups.has(g)) groups.set(g, []);
        groups.get(g).push(it);
      }
      for (const [g, rows] of groups) {
        if (g) list.appendChild(el('div', { class: 'qb-combo-group', text: g }));
        for (const it of rows) list.appendChild(itemEl(it));
      }
    } else {
      for (const it of shown) list.appendChild(itemEl(it));
    }

    if (!matched.length) list.appendChild(el('div', { class: 'qb-combo-empty', text: 'No matches' }));
    else if (matched.length > CAP) list.appendChild(el('div', { class: 'qb-combo-more', text: `+${matched.length - CAP} more — keep typing to narrow` }));
  }

  function select(it) {
    input.value = it.label;
    hide();
    if (onSelect) onSelect(it);
  }

  const open = () => { wrap.classList.add('qb-combo-open'); render(); };
  const hide = () => wrap.classList.remove('qb-combo-open');

  function highlight(nodes, idx) {
    nodes.forEach((n, i) => n.classList.toggle('qb-combo-hl', i === idx));
    if (nodes[idx]) nodes[idx].scrollIntoView({ block: 'nearest' });
  }

  input.addEventListener('focus', open);
  input.addEventListener('input', () => (wrap.classList.contains('qb-combo-open') ? render() : open()));
  input.addEventListener('keydown', (e) => {
    const nodes = [...list.querySelectorAll('.qb-combo-item')];
    if (e.key === 'ArrowDown') { e.preventDefault(); highlighted = Math.min(highlighted + 1, nodes.length - 1); highlight(nodes, highlighted); }
    else if (e.key === 'ArrowUp') { e.preventDefault(); highlighted = Math.max(highlighted - 1, 0); highlight(nodes, highlighted); }
    else if (e.key === 'Enter') { e.preventDefault(); if (nodes[highlighted]) select(nodes[highlighted].__item); }
    else if (e.key === 'Escape') { hide(); }
  });
  // blur deferred so a mousedown selection lands first.
  input.addEventListener('blur', () => setTimeout(hide, 150));

  return { el: wrap, input, getValue: () => input.value, focus: () => input.focus(), open };
}
