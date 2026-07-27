import { test } from 'node:test';
import assert from 'node:assert/strict';
import { JSDOM } from 'jsdom';
import { initChartBuilder, chartForm } from '../src/view/chart-builder.js';

function mount() {
  const dom = new JSDOM('<!DOCTYPE html><div id="root"></div>');
  globalThis.document = dom.window.document;
  globalThis.window = dom.window;
  return dom.window.document.getElementById('root');
}

const CATALOG = [
  {
    type: 'TrendChart',
    label: 'Call Trend',
    inputs: [
      { key: 'axis', label: 'Date axis', type: 'schemaField' },
      { key: 'bucket', label: 'Bucket', type: 'select', options: { day: 'Daily', week: 'Weekly', month: 'Monthly' } },
      { key: 'window', label: 'Window', type: 'text', default: 6 },
    ],
  },
];

test('chart builder: pick a type → its knobs render (schemaField picker + select + text default)', () => {
  const root = mount();
  initChartBuilder(root, { catalog: CATALOG, metadata: null, onSave: () => {} });

  root.querySelector('.qb-add-chart').click();
  const overlay = document.querySelector('.qb-modal-overlay');
  assert.ok(overlay, 'the panel opens');

  // pick the Trend type from the combobox
  overlay.querySelector('.qb-combo-input').dispatchEvent(new globalThis.window.Event('focus'));
  [...overlay.querySelectorAll('.qb-combo-item')].find((b) => b.textContent.includes('Call Trend'))
    .dispatchEvent(new globalThis.window.Event('mousedown'));

  // the chart type's knobs render: a schemaField pick button, a select, a text input defaulted to 6
  assert.ok(overlay.querySelector('.qb-knob-pick'), 'schemaField → column picker button');
  assert.ok(overlay.querySelector('.qb-knob-select'), 'select knob');
  assert.equal(overlay.querySelector('.qb-knob-text').value, '6', 'text knob carries the author default');
});

test('chart builder: a filter knob mounts the embedded builder and streams its tree into raw input', () => {
  const root = mount();
  let saved = null;
  let mountedInto = null;
  let streamTree = null; // capture the embedded builder's onChange so the test can drive it
  const CAT = [{ type: 'TrendChart', label: 'Trend Chart', inputs: [
    { key: 'bucket', label: 'Bucket', type: 'select', options: { month: 'Monthly' } },
    { key: 'filter', label: 'Filter', type: 'filter' },
  ] }];

  initChartBuilder(root, {
    catalog: CAT,
    metadata: null,
    // stand-in for the real query builder: records where it mounted, hands us its onChange.
    mountFilter: (el, { onChange }) => { mountedInto = el; streamTree = onChange; },
    onSave: (type, raw) => { saved = { type, raw }; },
  });

  root.querySelector('.qb-add-chart').click();
  const overlay = document.querySelector('.qb-modal-overlay');
  overlay.querySelector('.qb-combo-input').dispatchEvent(new globalThis.window.Event('focus'));
  [...overlay.querySelectorAll('.qb-combo-item')].find((b) => b.textContent.includes('Trend Chart'))
    .dispatchEvent(new globalThis.window.Event('mousedown'));

  assert.ok(overlay.querySelector('.qb-knob-filter'), 'the filter knob renders a container');
  assert.ok(mountedInto && overlay.contains(mountedInto), 'the embedded builder mounts inside the knob');

  // the embedded builder edits → streams a tree back; it must land on raw.filter.
  const tree = [{ type: 'condition', column: 'first_name', operator: '=', value: 'Bob', boolean: 'and', valueType: 'hardcoded' }];
  streamTree(tree);

  overlay.querySelector('.qb-modal-save').click();
  assert.deepEqual(saved.raw.filter, tree, 'the chart carries its own filter tree');
});

test('chart builder: save emits the chosen type + raw knob input', () => {
  const root = mount();
  let saved = null;
  initChartBuilder(root, { catalog: CATALOG, metadata: null, onSave: (type, raw) => { saved = { type, raw }; } });

  root.querySelector('.qb-add-chart').click();
  const overlay = document.querySelector('.qb-modal-overlay');
  overlay.querySelector('.qb-combo-input').dispatchEvent(new globalThis.window.Event('focus'));
  [...overlay.querySelectorAll('.qb-combo-item')].find((b) => b.textContent.includes('Call Trend'))
    .dispatchEvent(new globalThis.window.Event('mousedown'));

  const select = overlay.querySelector('.qb-knob-select');
  select.value = 'week';
  select.dispatchEvent(new globalThis.window.Event('change'));

  overlay.querySelector('.qb-modal-save').click();

  assert.equal(saved.type, 'TrendChart');
  assert.equal(saved.raw.bucket, 'week', 'chosen select value');
  assert.equal(saved.raw.window, 6, 'text default carried through');
});

test('chart builder: the section is collapsed behind an Edit toggle; clicking drops it down', () => {
  const root = mount();
  initChartBuilder(root, { catalog: CATALOG, metadata: null, onSave: () => {} });

  const body = root.querySelector('.qb-chart-body');
  assert.ok(body.classList.contains('qb-collapsed'), 'chart list + add start hidden');

  const toggle = root.querySelector('.qb-chart-toggle');
  toggle.click();
  assert.ok(!body.classList.contains('qb-collapsed'), 'Edit drops the section down');

  toggle.click();
  assert.ok(body.classList.contains('qb-collapsed'), 'clicking again folds it back up');
});

test('chart builder: lists existing charts with an edit control; editing opens the seeded form and fires onUpdate', async () => {
  const root = mount();
  let updated = null;
  const CAT = [{ type: 'TrendChart', label: 'Trend Chart', inputs: [
    { key: 'name', label: 'Name', type: 'text', default: 'Trend' },
    { key: 'bucket', label: 'Bucket', type: 'select', options: { day: 'Daily', week: 'Weekly', month: 'Monthly' } },
  ] }];
  // what the page's reportCharts() returns — index is the updateChart handle, raw seeds the form.
  const charts = [{ index: 0, type: 'TrendChart', label: 'Trend Chart', name: 'Calls weekly', raw: { name: 'Calls weekly', bucket: 'week' } }];

  initChartBuilder(root, {
    catalog: CAT,
    metadata: null,
    loadCharts: async () => charts,
    onUpdate: async (index, raw) => { updated = { index, raw }; },
  });
  await new Promise((r) => setTimeout(r, 0)); // let the async chart list render

  // each existing chart gets a row: its name + an edit control.
  const row = root.querySelector('.qb-chart-item');
  assert.ok(row, 'existing chart listed');
  assert.ok(row.textContent.includes('Calls weekly'), 'row shows the chart name');

  row.querySelector('.qb-chart-edit').click();
  const overlay = document.querySelector('.qb-modal-overlay');
  assert.ok(overlay.querySelector('.qb-chart-type-fixed'), 'edit form locks the type');
  assert.equal(overlay.querySelector('.qb-knob-text').value, 'Calls weekly', 'name pre-filled from stored config');
  assert.equal(overlay.querySelector('.qb-knob-select').value, 'week', 'bucket pre-filled');

  // change the name and save → onUpdate gets the chart's index + edited raw values.
  const name = overlay.querySelector('.qb-knob-text');
  name.value = 'Calls (renamed)'; name.dispatchEvent(new globalThis.window.Event('input'));
  overlay.querySelector('.qb-modal-save').click();
  await new Promise((r) => setTimeout(r, 0));

  assert.equal(updated.index, 0);
  assert.equal(updated.raw.name, 'Calls (renamed)');
  assert.equal(updated.raw.bucket, 'week', 'untouched knob still carries the stored value');
  assert.equal(document.querySelector('.qb-modal-overlay'), null, 'editor closes after save');
});

test('chart form: the SAME component edits — locks the type, pre-fills knobs, saves (edited) values', () => {
  mount();
  let saved = null;
  const CAT = [{ type: 'TrendChart', label: 'Trend Chart', inputs: [
    { key: 'name', label: 'Name', type: 'text', default: 'Trend' },
    { key: 'bucket', label: 'Bucket', type: 'select', options: { day: 'Daily', week: 'Weekly', month: 'Monthly' } },
  ] }];

  // edit = the same chartForm seeded with the chart's type + stored knob values.
  const form = chartForm({
    catalog: CAT,
    initialType: 'TrendChart',
    initialValues: { name: 'Calls over time', bucket: 'week' },
    saveLabel: 'Save chart',
    onSave: (type, raw) => { saved = { type, raw }; },
  });
  document.body.appendChild(form.el);

  // type is locked (fixed label, no picker) so an edit can't change what kind of chart it is.
  assert.ok(form.el.querySelector('.qb-chart-type-fixed'), 'type fixed in edit mode');
  assert.equal(form.el.querySelector('.qb-combo-input'), null, 'no type picker in edit mode');

  // knobs pre-filled from the stored values, and the save button reads as an edit.
  assert.equal(form.el.querySelector('.qb-knob-text').value, 'Calls over time', 'text knob pre-filled');
  assert.equal(form.el.querySelector('.qb-knob-select').value, 'week', 'select knob pre-filled');
  assert.equal(form.el.querySelector('.qb-modal-save').textContent, 'Save chart', 'edit save label');

  // saving without re-touching still emits the pre-filled values (they seed raw immediately).
  form.el.querySelector('.qb-modal-save').click();
  assert.equal(saved.type, 'TrendChart');
  assert.equal(saved.raw.name, 'Calls over time');
  assert.equal(saved.raw.bucket, 'week');
});

const FUNNEL_CAT = [{ type: 'FunnelChart', label: 'Funnel', inputs: [
  { key: 'name', label: 'Name', type: 'text', default: 'Funnel' },
  { key: 'stages', label: 'Stages', type: 'filterList' },
] }];

test('filterList: a labeled repeater — each row = a name + one embedded filter builder; save emits [{label, criteria}]', () => {
  const root = mount();
  let saved = null;
  const onChanges = []; // one embedded builder mounts per row; capture each row's onChange
  initChartBuilder(root, {
    catalog: FUNNEL_CAT,
    metadata: null,
    mountFilter: (el, { onChange }) => { onChanges.push(onChange); },
    onSave: (type, raw) => { saved = { type, raw }; },
  });

  root.querySelector('.qb-add-chart').click();
  const overlay = document.querySelector('.qb-modal-overlay');
  overlay.querySelector('.qb-combo-input').dispatchEvent(new globalThis.window.Event('focus'));
  [...overlay.querySelectorAll('.qb-combo-item')].find((b) => b.textContent.includes('Funnel'))
    .dispatchEvent(new globalThis.window.Event('mousedown'));

  assert.ok(overlay.querySelector('.qb-knob-filterlist'), 'filterList → a repeater container');
  const addRow = overlay.querySelector('.qb-filterlist-add');
  assert.ok(addRow, 'an add-row button');
  assert.equal(overlay.querySelectorAll('.qb-filterlist-row').length, 0, 'starts with no rows');

  addRow.click();
  addRow.click();
  assert.equal(overlay.querySelectorAll('.qb-filterlist-row').length, 2, 'two stage rows');
  assert.equal(onChanges.length, 2, 'a filter builder mounted per row');

  const labels = overlay.querySelectorAll('.qb-filterlist-label');
  labels[0].value = 'Contacted'; labels[0].dispatchEvent(new globalThis.window.Event('input'));
  labels[1].value = 'Engaged'; labels[1].dispatchEvent(new globalThis.window.Event('input'));
  const t0 = [{ type: 'whereHas', relationship: 'communicationLogs', hasType: 'whereHas', children: [] }];
  const t1 = [{ type: 'condition', column: 'duration_in_seconds', operator: '>=', value: 120 }];
  onChanges[0](t0);
  onChanges[1](t1);

  overlay.querySelector('.qb-modal-save').click();
  assert.deepEqual(saved.raw.stages, [
    { label: 'Contacted', criteria: t0 },
    { label: 'Engaged', criteria: t1 },
  ], 'stages emit as an ordered list of {label, criteria}');
});

test('filterList: removing a row drops it from the emitted list', () => {
  const root = mount();
  let saved = null;
  initChartBuilder(root, {
    catalog: FUNNEL_CAT, metadata: null,
    mountFilter: () => {},
    onSave: (type, raw) => { saved = { type, raw }; },
  });
  root.querySelector('.qb-add-chart').click();
  const overlay = document.querySelector('.qb-modal-overlay');
  overlay.querySelector('.qb-combo-input').dispatchEvent(new globalThis.window.Event('focus'));
  [...overlay.querySelectorAll('.qb-combo-item')].find((b) => b.textContent.includes('Funnel'))
    .dispatchEvent(new globalThis.window.Event('mousedown'));

  const addRow = overlay.querySelector('.qb-filterlist-add');
  addRow.click(); addRow.click();
  const labels = overlay.querySelectorAll('.qb-filterlist-label');
  labels[0].value = 'Keep'; labels[0].dispatchEvent(new globalThis.window.Event('input'));
  labels[1].value = 'Drop'; labels[1].dispatchEvent(new globalThis.window.Event('input'));

  overlay.querySelectorAll('.qb-filterlist-remove')[1].click();
  assert.equal(overlay.querySelectorAll('.qb-filterlist-row').length, 1);

  overlay.querySelector('.qb-modal-save').click();
  assert.equal(saved.raw.stages.length, 1);
  assert.equal(saved.raw.stages[0].label, 'Keep');
});

test('filterList: edit seeds one row per stored stage — label pre-filled + its criteria seed the row builder', () => {
  mount();
  let saved = null;
  const seededTrees = [];
  const STAGE_TREE = [{ type: 'whereHas', relationship: 'communicationLogs', hasType: 'whereHas', children: [] }];

  const form = chartForm({
    catalog: FUNNEL_CAT,
    mountFilter: (el, { initialTree }) => { seededTrees.push(initialTree); },
    initialType: 'FunnelChart',
    initialValues: { name: 'Funnel', stages: [{ label: 'Contacted', criteria: STAGE_TREE }] },
    saveLabel: 'Save chart',
    onSave: (type, raw) => { saved = { type, raw }; },
  });
  document.body.appendChild(form.el);

  assert.equal(form.el.querySelectorAll('.qb-filterlist-row').length, 1, 'one row per stored stage');
  assert.equal(form.el.querySelector('.qb-filterlist-label').value, 'Contacted', 'stage name pre-filled');
  assert.deepEqual(seededTrees[0], STAGE_TREE, 'the row builder is seeded with the stage criteria');

  form.el.querySelector('.qb-modal-save').click();
  assert.deepEqual(saved.raw.stages, [{ label: 'Contacted', criteria: STAGE_TREE }]);
});
