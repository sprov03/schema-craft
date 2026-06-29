// Readable DISPLAY of the condition tree — PURE DOM construction, no editing forms inline. A
// condition renders as a tappable CHIP ("column is value"); a group renders as a "Match ALL of" /
// "Match ANY of" block with indented children; a whereHas as a readable relationship block. Editing
// happens in a modal (see modal.js). Every interactive element carries data-action / data-path so
// index.js can wire it. This is what makes it legible on mobile — text, not a wall of inputs.

function el(tag, attrs = {}, children = []) {
  const node = document.createElement(tag);
  for (const [k, v] of Object.entries(attrs)) {
    if (k === 'class') node.className = v;
    else if (k === 'text') node.textContent = v;
    else if (k.startsWith('data-')) node.setAttribute(k, v);
    else node[k] = v;
  }
  for (const child of children) node.appendChild(child);
  return node;
}

function actionBtn(label, action, path, cls = 'qb-btn') {
  return el('button', { type: 'button', class: cls, text: label, 'data-action': action, 'data-path': path ?? '' });
}

// Plain-English operator labels for the readable chip.
const OP_LABEL = {
  '=': 'is', '!=': 'is not', '>': '>', '<': '<', '>=': '≥', '<=': '≤',
  like: 'contains', in: 'is any of', not_in: 'is none of',
  is_null: 'is null', is_not_null: 'is not null',
  is_empty: 'is empty', is_not_empty: 'is not empty', between: 'between',
};

const UNARY_OPS = ['is_null', 'is_not_null', 'is_empty', 'is_not_empty'];

export function conditionText(node) {
  if (!node.column) return '(tap to configure)';
  const op = OP_LABEL[node.operator] || node.operator;
  if (UNARY_OPS.includes(node.operator)) return `${node.column} ${op}`;
  if (node.valueType === 'computedValue' && node.referenceComputedValue) return `${node.column} ${op} ƒ{${node.referenceComputedValue}}`;
  if (node.valueType === 'aggregate' && node.referenceRelationship) {
    const agg = node.aggregateFunction === 'count'
      ? `count(${node.referenceRelationship})`
      : `${node.aggregateFunction}(${node.referenceRelationship} → ${node.referenceColumn})`;
    return `${node.column} ${op} ${agg}`;
  }
  if (node.valueType === 'relatedColumn' && node.referenceRelationship) return `${node.column} ${op} ${node.referenceRelationship} → ${node.referenceColumn}`;
  if (node.valueType === 'reference' && node.referenceColumn) return `${node.column} ${op} ${node.referenceColumn}`;
  // in/not_in carry an array; between carries [from, to] — render both readably.
  const val = Array.isArray(node.value)
    ? (node.operator === 'between' ? node.value.join(' and ') : node.value.join(', '))
    : (node.value ?? '');
  return `${node.column} ${op} ${val}`.trim();
}

function renderCondition(node, path) {
  return el('div', { class: 'qb-row', 'data-path': path }, [
    el('button', { type: 'button', class: 'qb-chip', text: conditionText(node), 'data-action': 'edit', 'data-path': path }),
    actionBtn('×', 'remove', path, 'qb-x'),
  ]);
}

function renderGroup(node, path, flags) {
  // ALL (and) / ANY (or) / NONE (negated) — the toggle cycles through all three.
  const mode = node.negate ? 'none' : (node.children?.[0]?.boolean === 'or' ? 'any' : 'all');
  const matchLabel = mode === 'none' ? 'Match NONE of' : (mode === 'any' ? 'Match ANY of' : 'Match ALL of');
  const header = el('div', { class: 'qb-group-head', 'data-path': path }, [
    el('button', {
      type: 'button', class: 'qb-match' + (mode === 'none' ? ' qb-match-none' : ''), 'data-action': 'toggle-connector', 'data-path': path,
      text: matchLabel,
    }),
    // graduate this group → a reusable Library item at its scope (only when the host can persist).
    ...(flags.graduate === false ? [] : [actionBtn('⤴ graduate', 'graduate', path, 'qb-grad')]),
    actionBtn('×', 'remove', path, 'qb-x'),
  ]);
  const children = (node.children || []).map((c, i) => renderNode(c, path + '-' + i, flags));
  const add = el('div', { class: 'qb-add' }, [actionBtn('+ Add', 'open-add', path)]);
  return el('div', { class: 'qb-group', 'data-path': path }, [header, ...children, add]);
}

const COUNT_LABEL = { '>=': 'at least', '>': 'more than', '=': 'exactly', '<=': 'at most', '<': 'fewer than' };

// "at least 5 " when a count constraint is set (positive has only) — reads into "has at least 5 X where".
function countPhrase(node) {
  if (node.hasType === 'doesntHave' || !node.countOperator || node.countValue == null) return '';
  return `${COUNT_LABEL[node.countOperator] || node.countOperator} ${node.countValue} `;
}

function renderWhereHas(node, path, flags) {
  const verb = node.hasType === 'doesntHave' ? "doesn't have" : 'has';
  const header = el('div', { class: 'qb-wherehas-head', 'data-path': path }, [
    el('button', {
      type: 'button', class: 'qb-rel', 'data-action': 'edit', 'data-path': path,
      text: `${verb} ${countPhrase(node)}${node.relationship || '(relationship)'} where`,
    }),
    actionBtn('×', 'remove', path, 'qb-x'),
  ]);
  const children = (node.children || []).map((c, i) => renderNode(c, path + '-' + i, flags));
  // whereHas can host conditions, nested relationships, AND groups (all parity-tested) — full nesting.
  const add = el('div', { class: 'qb-add' }, [actionBtn('+ Add', 'open-add', path)]);
  return el('div', { class: 'qb-wherehas', 'data-path': path }, [header, ...children, add]);
}

// A reference to a reusable Library Predicate — reads as a named chip; the host expands it at query time.
function renderPredicateRef(node, path) {
  return el('div', { class: 'qb-row', 'data-path': path }, [
    el('span', { class: 'qb-chip qb-pred-chip', text: '▣ ' + (node.label || node.ref) }),
    actionBtn('×', 'remove', path, 'qb-x'),
  ]);
}

function renderNode(node, path, flags) {
  if (node.type === 'group') return renderGroup(node, path, flags);
  if (node.type === 'whereHas') return renderWhereHas(node, path, flags);
  if (node.type === 'predicateRef') return renderPredicateRef(node, path);
  return renderCondition(node, path);
}

export function renderTree(tree, flags = {}) {
  const nodes = (tree.conditions || []).map((n, i) => renderNode(n, String(i), flags));
  const top = el('div', { class: 'qb-top' }, [
    el('span', { class: 'qb-match-static', text: 'Match ALL of' }),
    ...(flags.search === false ? [] : [actionBtn('🔍 Find a field', 'search', '', 'qb-find')]),
    ...(flags.navigate === false ? [] : [actionBtn('🧭 Browse', 'navigate', '', 'qb-find')]),
    ...(flags.defineSet === false ? [] : [actionBtn('🗂 Computed Value', 'define-computed-value', '', 'qb-find')]),
    ...(flags.definePredicate === false ? [] : [actionBtn('▣ Predicate', 'define-predicate', '', 'qb-find')]),
  ]);
  const add = el('div', { class: 'qb-add' }, [actionBtn('+ Add', 'open-add', '')]);
  return el('div', { class: 'qb-root' }, [top, ...nodes, add]);
}
