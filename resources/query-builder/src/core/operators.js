// Operators + node factories — PURE, no DOM. Ported verbatim from the visualizer so the builder's
// output stays identical. The operator list and the smart-default-by-column-type logic are part of
// the contract; the PHP side (QueryCodeGenerator / ConditionNodeApplier) understands exactly these.

export const OPERATORS = ['=', '!=', '>', '<', '>=', '<=', 'like', 'in', 'not_in', 'is_null', 'is_not_null', 'between'];

// Smart default operator when a condition is created from a column, based on its type.
// (Ported from addConditionFromColumn in visualizer.html.)
export function defaultOperatorFor(columnType) {
  const t = (columnType || '').toLowerCase();
  if (t.includes('date') || t.includes('timestamp') || t === 'datetime') return 'between';
  if (t === 'string' || t === 'text' || t === 'varchar' || t === 'char') return 'like';
  if (t === 'boolean' || t === 'bool' || t === 'tinyint') return '=';
  return '=';
}

// A fresh condition node. Defaults to valueType 'dynamic' exactly like the visualizer — a new row is
// a parameter until the author types a literal (the serializer falls back to 'hardcoded').
export function newCondition(overrides = {}) {
  return { type: 'condition', column: '', operator: '=', value: '', boolean: 'and', valueType: 'dynamic', ...overrides };
}

// A group seeds its child with the group's connector so it starts homogeneous. Default 'or' (Match
// ANY) — the usual reason to nest a group is to OR things together (the top level is AND-only).
export function newGroup(connector = 'or') {
  return { type: 'group', boolean: connector, children: [newCondition({ boolean: connector })] };
}
