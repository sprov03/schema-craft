import { test } from 'node:test';
import assert from 'node:assert/strict';
import { JSDOM } from 'jsdom';
import { init } from '../src/index.js';

// jsdom gives render.js / modal.js a real DOM (they use the bare `document` global, set on globalThis
// — the same global the browser bundle resolves at runtime). A fresh document per test, so modals
// appended to document.body never leak across tests.
function mount() {
  const dom = new JSDOM('<!DOCTYPE html><div id="root"></div>');
  globalThis.document = dom.window.document;
  globalThis.window = dom.window;
  return dom.window.document.getElementById('root');
}

// The add-button row was consolidated into one "+ Add" → menu (data-path picks WHERE). This opens it
// and clicks the option by label — the test equivalent of "+ condition" / "+ relationship" / etc.
function openAdd(root, label, path = '') {
  root.querySelector('[data-action="open-add"][data-path="' + path + '"]').click();
  [...document.querySelectorAll('.qb-addmenu-item')].find((b) => b.textContent.includes(label)).click();
}

// Same, scoped to a container (e.g. the "+ Add" INSIDE a whereHas block).
function openAddIn(scopeEl, label) {
  scopeEl.querySelector('[data-action="open-add"]').click();
  [...document.querySelectorAll('.qb-addmenu-item')].find((b) => b.textContent.includes(label)).click();
}

test('renders the tree as readable chips, with Match ALL at the top', () => {
  const root = mount();
  init(root, {
    initialTree: [{ type: 'condition', column: 'status', operator: '=', value: 'active', boolean: 'and', valueType: 'hardcoded' }],
  });
  assert.ok(root.textContent.includes('Match ALL of'), 'top connector label');
  assert.ok(root.querySelector('.qb-chip'), 'condition is a chip');
  assert.ok(root.querySelector('.qb-chip').textContent.includes('status is active'), 'readable text');
  assert.equal(root.querySelector('[data-field]'), null, 'no inline form inputs anymore');
});

test('+ Condition opens a modal; saving adds the condition and emits', () => {
  const root = mount();
  let emitted = null;
  init(root, { onChange: (t) => { emitted = t; } });

  openAdd(root, 'Condition');
  const overlay = document.querySelector('.qb-modal-overlay');
  assert.ok(overlay, 'modal opened');

  const colInput = overlay.querySelector('.qb-combo-input'); // column combobox
  colInput.value = 'status';
  colInput.dispatchEvent(new globalThis.window.Event('change')); // step 2/3 appear
  const valInput = overlay.querySelector('.qb-value-wrap .qb-modal-input');
  valInput.value = 'active';
  valInput.dispatchEvent(new globalThis.window.Event('input')); // enables Save
  overlay.querySelector('.qb-modal-save').click();

  assert.equal(emitted.length, 1);
  assert.equal(emitted[0].column, 'status');
  assert.equal(emitted[0].value, 'active');
  assert.ok(root.querySelector('.qb-chip'));
});

test('the condition editor is progressive: column first, then operator, then value', () => {
  const root = mount();
  init(root, { metadata: GRAPH });
  openAdd(root, 'Condition');
  const overlay = document.querySelector('.qb-modal-overlay');

  // step 1 only — no operator, no value yet
  assert.equal(overlay.querySelector('.qb-modal-select'), null, 'operator hidden before a column');
  assert.equal(overlay.querySelector('.qb-value-wrap'), null, 'value hidden before a column');

  const col = overlay.querySelector('.qb-combo-input');
  col.value = 'age'; // number
  col.dispatchEvent(new globalThis.window.Event('change'));
  assert.ok(overlay.querySelector('.qb-modal-select'), 'operator appears after a column');
  assert.ok(overlay.querySelector('.qb-value-wrap'), 'value appears after a column');
});

test('operators are filtered by column type', () => {
  const root = mount();
  init(root, { metadata: GRAPH });
  openAdd(root, 'Condition');
  const overlay = document.querySelector('.qb-modal-overlay');
  const col = overlay.querySelector('.qb-combo-input');

  col.value = 'age'; // number
  col.dispatchEvent(new globalThis.window.Event('change'));
  let ops = [...overlay.querySelector('.qb-modal-select').options].map((o) => o.value);
  assert.ok(ops.includes('>') && !ops.includes('like'), 'number → comparisons, no like');

  col.value = 'product_name'; // string
  col.dispatchEvent(new globalThis.window.Event('change'));
  ops = [...overlay.querySelector('.qb-modal-select').options].map((o) => o.value);
  assert.ok(ops.includes('like'), 'string → like');
});

test('the value input is generated from the operator: in → multi-value array (+ / ×)', () => {
  const root = mount();
  let emitted = null;
  init(root, { metadata: GRAPH, onChange: (t) => { emitted = t; } });
  openAdd(root, 'Condition');
  const overlay = document.querySelector('.qb-modal-overlay');
  const col = overlay.querySelector('.qb-combo-input');
  col.value = 'name'; // string column → offers `in`
  col.dispatchEvent(new globalThis.window.Event('change'));
  overlay.querySelector('.qb-modal-select').value = 'in';
  overlay.querySelector('.qb-modal-select').dispatchEvent(new globalThis.window.Event('change'));

  // one row to start; + value adds another
  assert.equal(overlay.querySelectorAll('.qb-multi-row').length, 1, 'starts with one value row');
  overlay.querySelector('.qb-multi-add').click();
  let rows = overlay.querySelectorAll('.qb-multi-row');
  assert.equal(rows.length, 2, '+ value adds a row');
  rows[0].querySelector('.qb-modal-input').value = '5';
  rows[1].querySelector('.qb-modal-input').value = '9';
  rows[1].querySelector('.qb-modal-input').dispatchEvent(new globalThis.window.Event('input'));
  overlay.querySelector('.qb-modal-save').click();

  assert.deepEqual(emitted[0].value, ['5', '9'], 'value is an ARRAY, not a comma string');
  assert.equal(emitted[0].operator, 'in');
});

test('the value input is generated from the operator: between → two bounds', () => {
  const root = mount();
  let emitted = null;
  init(root, { metadata: GRAPH, onChange: (t) => { emitted = t; } });
  openAdd(root, 'Condition');
  const overlay = document.querySelector('.qb-modal-overlay');
  const col = overlay.querySelector('.qb-combo-input');
  col.value = 'age';
  col.dispatchEvent(new globalThis.window.Event('change'));
  overlay.querySelector('.qb-modal-select').value = 'between';
  overlay.querySelector('.qb-modal-select').dispatchEvent(new globalThis.window.Event('change'));

  const bounds = overlay.querySelectorAll('.qb-range .qb-modal-input');
  assert.equal(bounds.length, 2, 'between shows two bounds');
  bounds[0].value = '1';
  bounds[1].value = '10';
  bounds[1].dispatchEvent(new globalThis.window.Event('input'));
  overlay.querySelector('.qb-modal-save').click();

  assert.deepEqual(emitted[0].value, ['1', '10'], 'value is [from, to]');
});

test('is_empty is a unary, no-value operator (distinct from is_null)', () => {
  const root = mount();
  init(root, { metadata: GRAPH, initialTree: [{ type: 'whereHas', relationship: 'deals', hasType: 'whereHas', boolean: 'and', children: [] }] });
  // product_name is a string column → offers is_empty
  openAddIn(root.querySelector('.qb-wherehas'), 'Condition');
  const overlay = document.querySelector('.qb-modal-overlay');
  const col = overlay.querySelector('.qb-combo-input');
  col.value = 'product_name';
  col.dispatchEvent(new globalThis.window.Event('change'));
  const ops = [...overlay.querySelector('.qb-modal-select').options].map((o) => o.value);
  assert.ok(ops.includes('is_empty') && ops.includes('is_not_empty'), 'string column offers empty-string ops');

  const op = overlay.querySelector('.qb-modal-select');
  op.value = 'is_empty';
  op.dispatchEvent(new globalThis.window.Event('change'));
  assert.equal(overlay.querySelector('.qb-value-wrap'), null, 'no value field for is_empty');
  assert.ok(!overlay.querySelector('.qb-modal-save').disabled, 'Save enabled (no value needed)');
});

test('reference mode compares a column to another column (valueType reference)', () => {
  const root = mount();
  let emitted = null;
  init(root, { metadata: GRAPH, onChange: (t) => { emitted = t; } });
  openAdd(root, 'Condition');
  const overlay = document.querySelector('.qb-modal-overlay');
  const col = overlay.querySelector('.qb-combo-input');
  col.value = 'age'; // single-value operator → reference toggle offered
  col.dispatchEvent(new globalThis.window.Event('change'));

  const colMode = [...overlay.querySelectorAll('.qb-valmode-btn')].find((b) => b.textContent === 'another column');
  assert.ok(colMode, 'reference toggle present for a comparison operator');
  colMode.click();

  const ref = overlay.querySelector('.qb-value-wrap .qb-combo-input');
  ref.value = 'name';
  ref.dispatchEvent(new globalThis.window.Event('input'));
  overlay.querySelector('.qb-modal-save').click();

  assert.equal(emitted[0].valueType, 'reference');
  assert.equal(emitted[0].referenceColumn, 'name');
  assert.equal(emitted[0].value, null, 'no literal value in reference mode');
  assert.ok(root.querySelector('.qb-chip').textContent.includes('name'), 'chip shows the compared column');
});

test('related-column reference compares to a column on a to-one related table', () => {
  const root = mount();
  let emitted = null;
  const ADJ = { base: 'Contact', models: { Contact: [{ name: 'account', target: 'Account', kind: 'belongsTo', many: false }], Account: [] } };
  const META = {
    base: 'Contact',
    models: {
      Contact: { columns: [{ name: 'created_at', type: 'date' }], relationships: [{ name: 'account', target: 'Account' }] },
      Account: { columns: [{ name: 'created_at', type: 'date' }], relationships: [] },
    },
  };
  init(root, { metadata: META, adjacency: ADJ, onChange: (t) => { emitted = t; } });

  openAdd(root, 'Condition');
  const overlay = document.querySelector('.qb-modal-overlay');
  const col = overlay.querySelector('.qb-combo-input');
  col.value = 'created_at';
  col.dispatchEvent(new globalThis.window.Event('change'));

  const tableBtn = [...overlay.querySelectorAll('.qb-valmode-btn')].find((b) => b.textContent === 'another table');
  assert.ok(tableBtn, 'related mode offered when to-one relations exist');
  tableBtn.click();

  const relInput = overlay.querySelector('.qb-value-wrap .qb-combo-input');
  relInput.value = 'account';
  relInput.dispatchEvent(new globalThis.window.Event('input'));
  const hit = [...overlay.querySelectorAll('.qb-combo-item')].find((b) => b.textContent.includes('account → created_at'));
  assert.ok(hit, 'lists the related table\'s columns');
  hit.dispatchEvent(new globalThis.window.Event('mousedown'));
  overlay.querySelector('.qb-modal-save').click();

  assert.equal(emitted[0].valueType, 'relatedColumn');
  assert.equal(emitted[0].referenceRelationship, 'account');
  assert.equal(emitted[0].referenceColumn, 'created_at');
  assert.ok(root.querySelector('.qb-chip').textContent.includes('account → created_at'), 'chip reads the related path');
});

test('aggregate value-source compares to AVG/SUM over a to-many (hasMany) relation', () => {
  const root = mount();
  let emitted = null;
  const ADJ = { base: 'Contact', models: { Contact: [{ name: 'communicationLogs', target: 'CommunicationLog', kind: 'hasMany', many: true }], CommunicationLog: [] } };
  const META = {
    base: 'Contact',
    models: {
      Contact: { columns: [{ name: 'id', type: 'number' }], relationships: [{ name: 'communicationLogs', target: 'CommunicationLog' }] },
      CommunicationLog: { columns: [{ name: 'duration_in_seconds', type: 'number' }], relationships: [] },
    },
  };
  init(root, { metadata: META, adjacency: ADJ, onChange: (t) => { emitted = t; } });

  openAdd(root, 'Condition');
  const overlay = document.querySelector('.qb-modal-overlay');
  const col = overlay.querySelector('.qb-combo-input');
  col.value = 'id';
  col.dispatchEvent(new globalThis.window.Event('change'));

  const aggBtn = [...overlay.querySelectorAll('.qb-valmode-btn')].find((b) => b.textContent === 'an aggregate');
  assert.ok(aggBtn, 'aggregate mode offered when hasMany relations exist');
  aggBtn.click();

  // pick the function (sum) + the relation→column
  const fnSel = overlay.querySelector('.qb-modal-select'); // operator is the first select; aggregate fn is added after — grab the last
  const selects = overlay.querySelectorAll('.qb-modal-select');
  selects[selects.length - 1].value = 'sum';
  const aggInput = overlay.querySelector('.qb-value-wrap .qb-combo-input');
  aggInput.value = 'duration';
  aggInput.dispatchEvent(new globalThis.window.Event('input'));
  const hit = [...overlay.querySelectorAll('.qb-combo-item')].find((b) => b.textContent.includes('communicationLogs → duration_in_seconds'));
  assert.ok(hit, 'lists hasMany relation columns');
  hit.dispatchEvent(new globalThis.window.Event('mousedown'));
  overlay.querySelector('.qb-modal-save').click();

  assert.equal(emitted[0].valueType, 'aggregate');
  assert.equal(emitted[0].aggregateFunction, 'sum');
  assert.equal(emitted[0].referenceRelationship, 'communicationLogs');
  assert.equal(emitted[0].referenceColumn, 'duration_in_seconds');
  assert.ok(root.querySelector('.qb-chip').textContent.includes('sum('), 'chip reads the aggregate');
});

test('in / between do not offer the reference toggle (single-value operators only)', () => {
  const root = mount();
  init(root, { metadata: GRAPH });
  openAdd(root, 'Condition');
  const overlay = document.querySelector('.qb-modal-overlay');
  const col = overlay.querySelector('.qb-combo-input');
  col.value = 'age';
  col.dispatchEvent(new globalThis.window.Event('change'));
  overlay.querySelector('.qb-modal-select').value = 'between';
  overlay.querySelector('.qb-modal-select').dispatchEvent(new globalThis.window.Event('change'));
  assert.equal(overlay.querySelector('.qb-valmode'), null, 'no reference toggle for between');
});

test('Define a Computed Value: pick any model, save → registers a typed (list) CV', async () => {
  const root = mount();
  const fakeSchema = { graph: { base: 'Market', models: { Market: { columns: [{ name: 'id', type: 'number' }, { name: 'name', type: 'string' }], relationships: [] } } }, adjacency: null };
  const handle = init(root, {
    models: [{ key: 'App\\Models\\Market', label: 'Markets' }],
    fetchSchema: async () => fakeSchema,
  });

  root.querySelector('[data-action="define-computed-value"]').click();
  const overlay = document.querySelector('.qb-modal-overlay');
  overlay.querySelectorAll('.qb-modal-input')[0].value = 'CA markets'; // name

  const modelInput = overlay.querySelector('.qb-combo-input'); // the model picker (first combo)
  modelInput.dispatchEvent(new globalThis.window.Event('focus'));
  [...overlay.querySelectorAll('.qb-combo-item')].find((b) => b.textContent.includes('Markets')).dispatchEvent(new globalThis.window.Event('mousedown'));
  await new Promise((r) => setTimeout(r, 0)); // flush fetchSchema + nested mount
  overlay.querySelector('.qb-modal-save').click();

  const defs = handle.getComputedValueDefinitions();
  assert.equal(defs.length, 1, 'CV registered');
  assert.equal(defs[0].name, 'CA markets');
  assert.equal(defs[0].model, 'App\\Models\\Market', 'any real model, by class');
  assert.equal(defs[0].kind, 'list');
  assert.ok(Array.isArray(defs[0].conditions));
});

test('the ƒ button inserts a type-matched Computed Value into a value slot', () => {
  const root = mount();
  let emitted = null;
  const META = { base: 'Contact', models: { Contact: { columns: [{ name: 'market_id', type: 'number' }], relationships: [] } } };
  init(root, {
    metadata: META,
    computedValues: [{ name: 'caMarkets', label: 'CA markets', kind: 'list' }, { name: 'avgAge', label: 'Avg age', kind: 'scalar' }],
    onChange: (t) => { emitted = t; },
  });

  openAdd(root, 'Condition');
  const overlay = document.querySelector('.qb-modal-overlay');
  const col = overlay.querySelector('.qb-combo-input');
  col.value = 'market_id';
  col.dispatchEvent(new globalThis.window.Event('change'));
  // `in` → an array slot → only LIST computed values are offered by ƒ
  overlay.querySelector('.qb-modal-select').value = 'in';
  overlay.querySelector('.qb-modal-select').dispatchEvent(new globalThis.window.Event('change'));

  const fx = overlay.querySelector('.qb-cv-btn');
  assert.ok(fx, 'ƒ button present beside the value');
  fx.click();
  const picker = overlay.querySelector('.qb-cv-picker .qb-combo-input');
  picker.dispatchEvent(new globalThis.window.Event('focus'));
  const items = [...overlay.querySelectorAll('.qb-cv-picker .qb-combo-item')].map((b) => b.textContent);
  assert.ok(items.some((t) => t.includes('CA markets')), 'list CV offered in the array slot');
  assert.ok(!items.some((t) => t.includes('Avg age')), 'scalar CV NOT offered in the array slot');

  [...overlay.querySelectorAll('.qb-cv-picker .qb-combo-item')].find((b) => b.textContent.includes('CA markets')).dispatchEvent(new globalThis.window.Event('mousedown'));
  overlay.querySelector('.qb-modal-save').click();

  assert.equal(emitted[0].valueType, 'computedValue');
  assert.equal(emitted[0].referenceComputedValue, 'caMarkets');
  assert.ok(root.querySelector('.qb-chip').textContent.includes('ƒ{caMarkets}'), 'chip reads the computed value');
});

test('is_null / is_not_null hide the value field', () => {
  const root = mount();
  init(root, { metadata: GRAPH });
  openAdd(root, 'Condition');
  const overlay = document.querySelector('.qb-modal-overlay');
  const col = overlay.querySelector('.qb-combo-input');
  col.value = 'status';
  col.dispatchEvent(new globalThis.window.Event('change'));
  assert.ok(overlay.querySelector('.qb-value-wrap'), 'value shown for a normal operator');

  const op = overlay.querySelector('.qb-modal-select');
  op.value = 'is_null';
  op.dispatchEvent(new globalThis.window.Event('change'));
  assert.equal(overlay.querySelector('.qb-value-wrap'), null, 'value hidden for is_null');
});

test('Save is disabled until the condition has a value', () => {
  const root = mount();
  init(root, { metadata: GRAPH });
  openAdd(root, 'Condition');
  const overlay = document.querySelector('.qb-modal-overlay');
  const save = overlay.querySelector('.qb-modal-save');
  assert.ok(save.disabled, 'disabled with no column');

  const col = overlay.querySelector('.qb-combo-input');
  col.value = 'age';
  col.dispatchEvent(new globalThis.window.Event('change'));
  assert.ok(save.disabled, 'still disabled with a column but no value');

  const val = overlay.querySelector('.qb-value-wrap .qb-modal-input');
  val.value = '42';
  val.dispatchEvent(new globalThis.window.Event('input'));
  assert.ok(!save.disabled, 'enabled once a value is entered');
});

test('is_null enables Save without a value (unary operator)', () => {
  const root = mount();
  init(root, { metadata: GRAPH });
  openAdd(root, 'Condition');
  const overlay = document.querySelector('.qb-modal-overlay');
  const col = overlay.querySelector('.qb-combo-input');
  col.value = 'status';
  col.dispatchEvent(new globalThis.window.Event('change'));
  const op = overlay.querySelector('.qb-modal-select');
  op.value = 'is_null';
  op.dispatchEvent(new globalThis.window.Event('change'));
  assert.equal(overlay.querySelector('.qb-value-wrap'), null, 'no value field for is_null');
  assert.ok(!overlay.querySelector('.qb-modal-save').disabled, 'Save enabled for is_null with no value');
});

test('clicking the backdrop does not close the editor — only Save or Cancel does', () => {
  const root = mount();
  init(root, { metadata: GRAPH });
  openAdd(root, 'Condition');
  const overlay = document.querySelector('.qb-modal-overlay');
  overlay.dispatchEvent(new globalThis.window.MouseEvent('click', { bubbles: true })); // backdrop tap
  assert.ok(document.querySelector('.qb-modal-overlay'), 'still open after a backdrop tap');

  overlay.querySelector('.qb-modal-cancel').click();
  assert.equal(document.querySelector('.qb-modal-overlay'), null, 'Cancel closes it');
});

test('cancel adds nothing and does not emit', () => {
  const root = mount();
  let emitted = null;
  init(root, { onChange: (t) => { emitted = t; } });

  openAdd(root, 'Condition');
  document.querySelector('.qb-modal-cancel').click();

  assert.equal(root.querySelectorAll('.qb-chip').length, 0);
  assert.equal(emitted, null);
});

test('tapping a chip opens a prefilled modal and updates on save', () => {
  const root = mount();
  let emitted = null;
  init(root, {
    initialTree: [{ type: 'condition', column: 'name', operator: 'like', value: '%a%', boolean: 'and', valueType: 'hardcoded' }],
    onChange: (t) => { emitted = t; },
  });

  root.querySelector('.qb-chip').click();
  const overlay = document.querySelector('.qb-modal-overlay');
  assert.equal(overlay.querySelector('.qb-combo-input').value, 'name', 'column prefilled');

  overlay.querySelector('.qb-value-wrap .qb-modal-input').value = '%bob%';
  overlay.querySelector('.qb-modal-save').click();
  assert.equal(emitted[0].value, '%bob%');
});

test('a group renders as Match ANY of and the toggle cycles ANY → NONE → ALL', () => {
  const root = mount();
  init(root, {
    initialTree: [{ type: 'group', boolean: 'or', children: [{ type: 'condition', column: 'a', operator: '=', value: '1', boolean: 'or' }] }],
  });
  const toggle = () => root.querySelector('[data-action="toggle-connector"]').click();
  assert.ok(root.querySelector('.qb-match').textContent.includes('Match ANY of'));

  toggle();
  assert.ok(root.querySelector('.qb-match').textContent.includes('Match NONE of'), 'ANY → NONE');
  toggle();
  assert.ok(root.querySelector('.qb-match').textContent.includes('Match ALL of'), 'NONE → ALL');
});

test('+ Group adds a Match ANY group without opening a modal', () => {
  const root = mount();
  init(root, {});
  openAdd(root, 'Group');
  assert.equal(document.querySelector('.qb-modal-overlay'), null, 'groups need no modal');
  assert.ok(root.querySelector('.qb-group'));
  assert.ok(root.querySelector('.qb-match').textContent.includes('Match ANY of'));
});

test('a whereHas renders as a readable relationship block with an add-child control', () => {
  const root = mount();
  init(root, {
    initialTree: [{
      type: 'whereHas', relationship: 'communicationLogs', hasType: 'whereHas', boolean: 'and',
      children: [{ type: 'condition', column: 'type', operator: '=', value: '1', boolean: 'and', valueType: 'hardcoded' }],
    }],
  });
  assert.ok(root.querySelector('.qb-wherehas'));
  assert.ok(root.querySelector('.qb-rel').textContent.includes('has communicationLogs where'));
  assert.equal(root.querySelector('.qb-wherehas').querySelectorAll('.qb-chip').length, 1);
  // a whereHas offers full nesting via its own "+ Add" menu (condition / relationship / group).
  assert.ok(root.querySelector('.qb-wherehas [data-action="open-add"]'), '+ Add inside whereHas');
});

test('+ Relationship opens the navigator; Select adds a whereHas', () => {
  const root = mount();
  let emitted = null;
  init(root, { metadata: GRAPH, onChange: (t) => { emitted = t; } });

  openAdd(root, 'Relationship');
  const overlay = document.querySelector('.qb-modal-overlay');
  const dealsRow = [...overlay.querySelectorAll('.qb-nav-row')].find((r) => r.textContent.includes('deals'));
  dealsRow.querySelector('.qb-nav-pick').click();

  assert.equal(emitted[0].type, 'whereHas');
  assert.equal(emitted[0].relationship, 'deals');
  assert.ok(root.querySelector('.qb-wherehas'), 'rendered as a relationship block');
});

test('a relationship can be selected as "doesn\'t have" (navigator toggle)', () => {
  const root = mount();
  let emitted = null;
  init(root, { metadata: GRAPH, onChange: (t) => { emitted = t; } });

  openAdd(root, 'Relationship');
  const overlay = document.querySelector('.qb-modal-overlay');
  overlay.querySelector('.qb-modal-select').value = 'doesntHave'; // the has / doesn't-have toggle
  [...overlay.querySelectorAll('.qb-nav-row')].find((r) => r.textContent.includes('deals')).querySelector('.qb-nav-pick').click();

  assert.equal(emitted[0].hasType, 'doesntHave');
  assert.ok(root.querySelector('.qb-rel').textContent.includes("doesn't have"), 'reads as doesn\'t have');
});

test('a "doesn\'t have" relationship can carry conditions (whereDoesntHave with conditions)', () => {
  const root = mount();
  let emitted = null;
  init(root, { metadata: GRAPH, onChange: (t) => { emitted = t; } });

  // Select a "doesn't have" relationship via the navigator.
  openAdd(root, 'Relationship');
  let overlay = document.querySelector('.qb-modal-overlay');
  overlay.querySelector('.qb-modal-select').value = 'doesntHave';
  [...overlay.querySelectorAll('.qb-nav-row')].find((r) => r.textContent.includes('deals')).querySelector('.qb-nav-pick').click();

  // Add a condition INSIDE it.
  openAddIn(root.querySelector('.qb-wherehas'), 'Condition');
  overlay = document.querySelector('.qb-modal-overlay');
  const colInput = overlay.querySelector('.qb-combo-input'); // column
  colInput.value = 'product_name';
  colInput.dispatchEvent(new globalThis.window.Event('change')); // reveal operator + value
  const valInput = overlay.querySelector('.qb-value-wrap .qb-modal-input');
  valInput.value = 'Widget';
  valInput.dispatchEvent(new globalThis.window.Event('input')); // enables Save
  overlay.querySelector('.qb-modal-save').click();

  assert.equal(emitted[0].hasType, 'doesntHave', 'still negated');
  assert.equal(emitted[0].children[0].column, 'product_name', 'with a nested condition');
});

test('the column picker is scoped to the relationship context, not the whole graph', () => {
  const root = mount();
  init(root, {
    metadata: GRAPH,
    initialTree: [{ type: 'whereHas', relationship: 'deals', hasType: 'whereHas', boolean: 'and', children: [] }],
  });
  openAddIn(root.querySelector('.qb-wherehas'), 'Condition');
  const overlay = document.querySelector('.qb-modal-overlay');
  overlay.querySelector('.qb-combo-input').dispatchEvent(new globalThis.window.Event('focus'));

  const items = [...overlay.querySelectorAll('.qb-combo-item')].map((b) => b.textContent);
  assert.ok(items.some((t) => t.includes('product_name')), 'shows Deal (the context) columns');
  assert.ok(!items.some((t) => t.includes('status')), 'does NOT show Contact columns');
});

test('a relationship can carry a count constraint (has at least N)', () => {
  const root = mount();
  let emitted = null;
  init(root, {
    metadata: GRAPH,
    initialTree: [{ type: 'whereHas', relationship: 'deals', hasType: 'has', boolean: 'and', children: [] }],
    onChange: (t) => { emitted = t; },
  });

  root.querySelector('.qb-rel').click(); // edit the relationship
  const overlay = document.querySelector('.qb-modal-overlay');
  const countSel = [...overlay.querySelectorAll('.qb-modal-select')].find((s) => [...s.options].some((o) => o.textContent === 'at least'));
  assert.ok(countSel, 'count operator select present for "has"');
  countSel.value = '>=';
  countSel.dispatchEvent(new globalThis.window.Event('change'));
  const num = overlay.querySelector('.qb-count-row .qb-modal-input');
  num.value = '5';
  num.dispatchEvent(new globalThis.window.Event('input'));
  overlay.querySelector('.qb-modal-save').click();

  assert.equal(emitted[0].countOperator, '>=');
  assert.equal(emitted[0].countValue, 5);
  assert.ok(root.querySelector('.qb-rel').textContent.includes('at least 5'), 'header reads "has at least 5 deals where"');
});

test('count is not offered for "doesn\'t have"', () => {
  const root = mount();
  init(root, {
    metadata: GRAPH,
    initialTree: [{ type: 'whereHas', relationship: 'deals', hasType: 'doesntHave', boolean: 'and', children: [] }],
  });
  root.querySelector('.qb-rel').click();
  const overlay = document.querySelector('.qb-modal-overlay');
  const countSel = [...overlay.querySelectorAll('.qb-modal-select')].find((s) => [...s.options].some((o) => o.textContent === 'at least'));
  assert.equal(countSel, undefined, 'no count field for doesn\'t have');
});

test('+ predicate inserts a reusable predicate reference node', () => {
  const root = mount();
  let emitted = null;
  init(root, { predicates: [{ name: 'engaged-caller', label: 'Engaged caller' }], onChange: (t) => { emitted = t; } });

  openAdd(root, 'Predicate');
  const overlay = document.querySelector('.qb-modal-overlay');
  overlay.querySelector('.qb-combo-input').dispatchEvent(new globalThis.window.Event('focus'));
  [...overlay.querySelectorAll('.qb-combo-item')].find((b) => b.textContent.includes('Engaged caller')).dispatchEvent(new globalThis.window.Event('mousedown'));

  assert.equal(emitted[0].type, 'predicateRef');
  assert.equal(emitted[0].ref, 'engaged-caller');
  assert.ok(root.querySelector('.qb-pred-chip'), 'renders a predicate chip');
  assert.ok(root.querySelector('.qb-pred-chip').textContent.includes('Engaged caller'));
});

test('predicate insertion is SCOPE-GATED to the node context model', () => {
  const root = mount();
  init(root, {
    adjacency: { base: 'Contact' },
    predicates: [
      { name: 'verified', label: 'Verified contact', scope: 'Contact' },
      { name: 'longcall', label: 'Long call', scope: 'CommunicationLog' },
    ],
  });

  // at the BASE (Contact), only Contact-scoped predicates are offered.
  openAdd(root, 'Predicate');
  const overlay = document.querySelector('.qb-modal-overlay');
  overlay.querySelector('.qb-combo-input').dispatchEvent(new globalThis.window.Event('focus'));
  const labels = [...overlay.querySelectorAll('.qb-combo-item')].map((b) => b.textContent);

  assert.ok(labels.some((t) => t.includes('Verified contact')), 'base-scoped predicate shown at base');
  assert.ok(!labels.some((t) => t.includes('Long call')), 'CommunicationLog-scoped predicate hidden at base');
});

test('graduating a group promotes it to the Library at its scope', async () => {
  const root = mount();
  let saved = null;
  init(root, {
    adjacency: { base: 'Contact' },
    onSaveToLibrary: async (payload) => { saved = payload; return { ok: true, slug: 'x', name: payload.name }; },
    initialTree: [
      { type: 'group', boolean: 'and', children: [
        { type: 'condition', column: 'first_name', operator: '=', value: 'Bob', boolean: 'and', valueType: 'hardcoded' },
      ] },
    ],
  });

  const grad = root.querySelector('[data-action="graduate"]');
  assert.ok(grad, 'the group has a graduate action');
  grad.click();

  const overlay = document.querySelector('.qb-modal-overlay');
  overlay.querySelector('.qb-modal-input').value = 'Engaged';
  overlay.querySelector('.qb-modal-save').click();
  await new Promise((r) => setTimeout(r, 0)); // onSaveToLibrary is async

  assert.equal(saved.kind, 'predicate');
  assert.equal(saved.name, 'Engaged');
  assert.equal(saved.definition.scope, 'Contact', 'captured at the node scope');
  assert.ok(saved.definition.conditions.length, 'captured the group subtree');
});

test('the group toggle cycles to Match NONE of and serializes negate', () => {
  const root = mount();
  let emitted = null;
  init(root, {
    initialTree: [{ type: 'group', boolean: 'and', children: [{ type: 'condition', column: 'a', operator: '=', value: '1', boolean: 'or', valueType: 'hardcoded' }] }],
    onChange: (t) => { emitted = t; },
  });

  root.querySelector('[data-action="toggle-connector"]').click(); // ANY → NONE
  assert.ok(root.querySelector('.qb-match').textContent.includes('Match NONE of'), 'label shows NONE');
  assert.equal(emitted[0].negate, true, 'serialized with negate');
});

test('removing a node drops it and re-emits', () => {
  const root = mount();
  let emitted = null;
  init(root, {
    initialTree: [
      { type: 'condition', column: 'a', operator: '=', value: '1', boolean: 'and', valueType: 'hardcoded' },
      { type: 'condition', column: 'b', operator: '=', value: '2', boolean: 'and', valueType: 'hardcoded' },
    ],
    onChange: (t) => { emitted = t; },
  });
  root.querySelector('.qb-row [data-action="remove"]').click();
  assert.equal(root.querySelectorAll('.qb-chip').length, 1);
  assert.equal(emitted.length, 1);
  assert.equal(emitted[0].column, 'b');
});

test('allowGroups:false hides + Group but keeps + Condition (in the Add menu)', () => {
  const root = mount();
  init(root, { flags: { allowGroups: false } });
  root.querySelector('[data-action="open-add"]').click();
  const items = [...document.querySelectorAll('.qb-addmenu-item')].map((b) => b.textContent);
  assert.ok(!items.some((t) => t.includes('Group')), 'allowGroups:false hides Group');
  assert.ok(items.some((t) => t.includes('Condition')), 'keeps Condition');
});

const GRAPH = {
  base: 'Contact',
  models: {
    Contact: { columns: [{ name: 'status', type: 'enum', options: ['Verified', 'Unknown'] }, { name: 'age', type: 'number' }, { name: 'name', type: 'string' }], relationships: [{ name: 'deals', target: 'Deal' }] },
    Deal: { columns: [{ name: 'product_name', type: 'string' }], relationships: [] },
  },
};

test('Find a field searches across hops and auto-builds the whereHas path on select', () => {
  const root = mount();
  let emitted = null;
  init(root, { metadata: GRAPH, onChange: (t) => { emitted = t; } });

  root.querySelector('[data-action="search"]').click();
  const overlay = document.querySelector('.qb-modal-overlay');
  const search = overlay.querySelector('.qb-combo-input');
  search.value = 'product';
  search.dispatchEvent(new globalThis.window.Event('input'));

  // grouped by relationship path; the item label is the column name.
  const hit = [...overlay.querySelectorAll('.qb-combo-item')].find((b) => b.textContent.includes('product_name'));
  assert.ok(hit, 'ranked the deep field');
  hit.dispatchEvent(new globalThis.window.Event('mousedown')); // combobox selects on mousedown

  assert.equal(emitted[0].type, 'whereHas', 'auto-built the relationship path');
  assert.equal(emitted[0].relationship, 'deals');
  assert.equal(emitted[0].children[0].column, 'product_name');
});

test('the value input is typed by the column — an enum column renders a select of its options', () => {
  const root = mount();
  init(root, {
    metadata: GRAPH,
    initialTree: [{ type: 'condition', column: 'status', operator: '=', value: 'Verified', boolean: 'and', valueType: 'hardcoded' }],
  });
  root.querySelector('.qb-chip').click();
  const overlay = document.querySelector('.qb-modal-overlay');
  const valueControl = overlay.querySelector('.qb-value-wrap').firstChild;
  assert.equal(valueControl.tagName, 'SELECT', 'enum → dropdown');
  assert.deepEqual([...valueControl.options].map((o) => o.value), ['Verified', 'Unknown']);
});

test('the search combobox caps rendering so a huge field list cannot lock up', () => {
  const root = mount();
  // a graph that flattens to thousands of paths
  const cols = Array.from({ length: 60 }, (_, i) => ({ name: 'c' + i, type: 'string' }));
  const rels = Array.from({ length: 40 }, (_, i) => ({ name: 'r' + i, target: 'M' + i }));
  const models = { Base: { columns: cols, relationships: rels } };
  rels.forEach((r) => { models[r.target] = { columns: cols, relationships: [] }; });
  init(root, { metadata: { base: 'Base', models } });

  root.querySelector('[data-action="search"]').click();
  const overlay = document.querySelector('.qb-modal-overlay');
  const rendered = overlay.querySelectorAll('.qb-combo-item').length;
  assert.ok(rendered <= 100, `rendered ${rendered} items (capped, not thousands)`);
  assert.ok(overlay.querySelector('.qb-combo-more'), 'shows a "keep typing" hint when capped');
});

test('🔗 find related searches reachable models and builds the chain on select', () => {
  const root = mount();
  let emitted = null;
  const adjacency = {
    base: 'Contact',
    models: {
      Contact: [{ name: 'deals', target: 'Deal', kind: 'hasMany', many: true }],
      Deal: [{ name: 'lead', target: 'Lead', kind: 'belongsTo', many: false }],
      Lead: [],
    },
  };
  init(root, { adjacency, onChange: (t) => { emitted = t; } });

  openAdd(root, 'Find related');
  const overlay = document.querySelector('.qb-modal-overlay');
  const input = overlay.querySelector('.qb-combo-input');
  input.value = 'lead';
  input.dispatchEvent(new globalThis.window.Event('input'));

  const hit = [...overlay.querySelectorAll('.qb-combo-item')].find((b) => b.textContent.includes('Lead'));
  assert.ok(hit, 'found Lead with its chain');
  hit.dispatchEvent(new globalThis.window.Event('mousedown'));

  // built the nested whereHas chain deals → lead
  assert.equal(emitted[0].type, 'whereHas');
  assert.equal(emitted[0].relationship, 'deals');
  assert.equal(emitted[0].children[0].relationship, 'lead');
});

test('Browse schema navigates levels and auto-builds the path on column select', () => {
  const root = mount();
  let emitted = null;
  init(root, { metadata: GRAPH, onChange: (t) => { emitted = t; } });

  root.querySelector('[data-action="navigate"]').click();
  let overlay = document.querySelector('.qb-modal-overlay');
  // base level shows the deals relationship + Contact's own columns
  const deals = [...overlay.querySelectorAll('.qb-nav-rel')].find((b) => b.textContent.includes('deals'));
  assert.ok(deals, 'base level lists the deals relationship');
  assert.ok([...overlay.querySelectorAll('.qb-nav-col')].some((b) => b.textContent.includes('status')), 'lists base columns');

  deals.click(); // drill into deals
  overlay = document.querySelector('.qb-modal-overlay');
  assert.ok(overlay.querySelector('.qb-nav-crumb').textContent.includes('deals'), 'breadcrumb updated');
  const productCol = [...overlay.querySelectorAll('.qb-nav-col')].find((b) => b.textContent.includes('product_name'));
  assert.ok(productCol, 'drilled into deals → shows product_name');

  productCol.click();
  assert.equal(emitted[0].type, 'whereHas');
  assert.equal(emitted[0].relationship, 'deals');
  assert.equal(emitted[0].children[0].column, 'product_name');
});

test('a relationship whose target is not in the graph is Select-only, not drillable', () => {
  const root = mount();
  // 'orphan' points to a model not present in models; 'deals' resolves to Deal (in graph)
  const g = {
    base: 'Contact',
    models: {
      Contact: { columns: [], relationships: [{ name: 'deals', target: 'Deal' }, { name: 'orphan', target: 'NotLoaded' }, { name: 'poly', target: null }] },
      Deal: { columns: [{ name: 'product_name', type: 'string' }], relationships: [] },
    },
  };
  init(root, { metadata: g });
  openAdd(root, 'Relationship');
  const overlay = document.querySelector('.qb-modal-overlay');
  const rows = [...overlay.querySelectorAll('.qb-nav-row')];
  const dealOpen = rows.find((r) => r.textContent.includes('deals')).querySelector('.qb-nav-rel');
  const orphanOpen = rows.find((r) => r.textContent.includes('orphan')).querySelector('.qb-nav-rel');
  const polyRow = rows.find((r) => r.textContent.includes('poly'));

  assert.equal(dealOpen.disabled, false, 'in-graph relationship is drillable');
  assert.equal(orphanOpen.disabled, true, 'out-of-graph relationship is not drillable');
  assert.ok(polyRow.textContent.includes('varies'), 'polymorphic target shows "varies", not "(Model)"');
  assert.ok(rows.every((r) => r.querySelector('.qb-nav-pick')), 'all are still Selectable');
});

test('the navigator (relationship mode) lists relationships each with a Select button', () => {
  const root = mount();
  init(root, { metadata: GRAPH });
  openAdd(root, 'Relationship');
  const overlay = document.querySelector('.qb-modal-overlay');
  const dealsRow = [...overlay.querySelectorAll('.qb-nav-row')].find((r) => r.textContent.includes('deals'));
  assert.ok(dealsRow, 'lists the deals relationship');
  assert.ok(dealsRow.querySelector('.qb-nav-pick'), 'with a Select button');
});

test('the column field offers a combobox of the base schema columns (context-scoped)', () => {
  const root = mount();
  init(root, { metadata: GRAPH });
  openAdd(root, 'Condition');
  const overlay = document.querySelector('.qb-modal-overlay');
  overlay.querySelector('.qb-combo-input').dispatchEvent(new globalThis.window.Event('focus'));
  const items = [...overlay.querySelectorAll('.qb-combo-item')].map((b) => b.textContent);
  assert.ok(items.some((t) => t.includes('status')), 'includes a base (Contact) column');
  assert.ok(!items.some((t) => t.includes('product_name')), 'excludes a related-model column at top level');
});

test('getTree / setTree round-trip through the handle', () => {
  const root = mount();
  const api = init(root, {});
  api.setTree([{ type: 'condition', column: 'x', operator: '=', value: '5', boolean: 'and', valueType: 'hardcoded' }]);
  assert.equal(api.getTree()[0].column, 'x');
  assert.ok(root.querySelector('.qb-chip'));
});
