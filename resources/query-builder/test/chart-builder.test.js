import { test } from 'node:test';
import assert from 'node:assert/strict';
import { JSDOM } from 'jsdom';
import { initChartBuilder } from '../src/view/chart-builder.js';

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
