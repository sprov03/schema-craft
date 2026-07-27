// Editor MODAL + cross-hop SEARCH modal. PURE DOM. The editor configures one node; its value input is
// TYPED by the column's metadata (enum → select, date → date, number → number); the column field has an
// autocomplete of known columns. The search modal fuzzy-finds a field a few hops out and hands the
// chosen field-path back so the host can auto-build the whereHas chain. Rendered into document.body.

import { columnTypeInfo, levelAt, searchModelPaths } from '../core/graph.js';
import { createCombobox } from './combobox.js';

function shortName(fqcn) {
  if (!fqcn) return '';
  const parts = String(fqcn).split('\\');
  return parts[parts.length - 1];
}

function el(tag, attrs = {}, children = []) {
  const node = document.createElement(tag);
  for (const [k, v] of Object.entries(attrs)) {
    if (k === 'class') node.className = v;
    else if (k === 'text') node.textContent = v;
    else if (k.startsWith('data-') || k === 'list') node.setAttribute(k, v); // `list` is a readonly prop
    else node[k] = v;
  }
  for (const child of children) node.appendChild(child);
  return node;
}

function field(labelText, control) {
  return el('label', { class: 'qb-field' }, [el('span', { class: 'qb-field-label', text: labelText }), control]);
}

function selectControl(value, options, cls = 'qb-modal-select') {
  return el('select', { class: cls }, options.map((o) => {
    const label = typeof o === 'string' ? o : o.label;
    const val = typeof o === 'string' ? o : o.value;
    return el('option', { value: val, text: label, selected: val === value });
  }));
}

// Operators that make sense for a column type (the selector changes based on the data type). is_null
// / is_not_null are always offered; 'like' only for strings; comparisons/between only for number/date.
// Strings also get is_empty / is_not_empty — distinct from null ('' ≠ NULL).
function operatorsForType(type) {
  const NULLS = ['is_null', 'is_not_null'];
  // number includes in/not_in — FK columns (id, *_id) are commonly filtered with whereIn / "from a set".
  if (type === 'number') return ['=', '!=', '>', '<', '>=', '<=', 'between', 'in', 'not_in', ...NULLS];
  if (type === 'date') return ['=', '!=', '>', '<', '>=', '<=', 'between', ...NULLS];
  if (type === 'enum' || type === 'boolean') return ['=', '!=', 'in', 'not_in', ...NULLS];
  return ['=', '!=', 'like', 'in', 'not_in', ...NULLS, 'is_empty', 'is_not_empty']; // string
}

// Operator → value ARITY. The value input is GENERATED from this (× the column type), so a new operator
// never needs a bespoke input — its arity decides the shape. Matches the parity-locked backend: unary
// operators bind no value; in/not_in bind an ARRAY; between binds [from, to]; everything else a scalar.
function valueArity(op) {
  if (op === 'is_null' || op === 'is_not_null' || op === 'is_empty' || op === 'is_not_empty') return 'none';
  if (op === 'in' || op === 'not_in') return 'multi';
  if (op === 'between') return 'range';
  return 'single';
}

const isUnary = (op) => valueArity(op) === 'none';

// One typed value slot, by COLUMN type (enum→select, number/date→typed, boolean→true/false, else text).
// Reused for the single value, each in/not_in value, and each between bound — type stays consistent.
function typedInput(info, value, onInput) {
  let node;
  if (info.options && info.options.length) node = selectControl(value, info.options, 'qb-modal-input');
  else if (info.type === 'number') node = el('input', { type: 'number', class: 'qb-modal-input', value: value ?? '' });
  else if (info.type === 'date') node = el('input', { type: 'date', class: 'qb-modal-input', value: value ?? '' });
  else if (info.type === 'boolean') node = selectControl(value || 'true', ['true', 'false'], 'qb-modal-input');
  else node = el('input', { type: 'text', class: 'qb-modal-input', value: value ?? '' });
  if (onInput) { node.addEventListener('input', onInput); node.addEventListener('change', onInput); }
  return node;
}

// Build the value control for (operator, column). Returns { control, getValue, isFilled } — or null for
// the unary operators that take no value. getValue returns the shape the backend expects: a scalar for
// single, an ARRAY for in/not_in (added with +, never comma-typed), [from, to] for between.
function buildValueControl(op, info, currentValue, onInput) {
  const arity = valueArity(op);
  if (arity === 'none') return null;

  if (arity === 'single') {
    const input = typedInput(info, currentValue, onInput);
    return { control: input, getValue: () => input.value, isFilled: () => String(input.value).trim() !== '' };
  }

  if (arity === 'range') {
    const arr = Array.isArray(currentValue) ? currentValue : [];
    const from = typedInput(info, arr[0] ?? '', onInput);
    const to = typedInput(info, arr[1] ?? '', onInput);
    const control = el('div', { class: 'qb-range' }, [from, el('span', { class: 'qb-range-sep', text: 'and' }), to]);
    return { control, getValue: () => [from.value, to.value], isFilled: () => String(from.value).trim() !== '' && String(to.value).trim() !== '' };
  }

  // multi (in / not_in): a +/× value list that emits an ARRAY — not a comma-separated string.
  const seed = Array.isArray(currentValue) ? currentValue : (currentValue != null && currentValue !== '' ? [currentValue] : []);
  const inputs = [];
  const list = el('div', { class: 'qb-multi' });
  const addRow = (val) => {
    const input = typedInput(info, val ?? '', onInput);
    const x = el('button', { type: 'button', class: 'qb-multi-x', text: '×' });
    const row = el('div', { class: 'qb-multi-row' }, [input, x]);
    x.addEventListener('click', () => {
      const i = inputs.indexOf(input);
      if (i >= 0) inputs.splice(i, 1);
      list.removeChild(row);
      if (onInput) onInput();
    });
    inputs.push(input);
    list.appendChild(row);
    if (onInput) onInput();
  };
  (seed.length ? seed : ['']).forEach(addRow);
  const addBtn = el('button', { type: 'button', class: 'qb-multi-add', text: '+ value' });
  addBtn.addEventListener('click', () => addRow(''));
  const control = el('div', { class: 'qb-multi-wrap' }, [list, addBtn]);
  return { control, getValue: () => inputs.map((i) => i.value).filter((v) => String(v).trim() !== ''), isFilled: () => inputs.some((i) => String(i.value).trim() !== '') };
}

export function openEditor(node, { onSave, onCancel, columns = [], relationships = [], relatedOptions = [], aggregateOptions = [], computedValues = [] } = {}) {
  const isWhereHas = node.type === 'whereHas';
  const controls = {};
  const rows = [];
  let valueControl;
  let refreshValidity = () => {}; // assigned once saveBtn exists; safe to call before then (no-op)

  if (isWhereHas) {
    // Two clean options — "has" / "doesn't have". Conditions are added INSIDE the block (+ condition)
    // for EITHER, so "doesn't have X where …" (whereDoesntHave with conditions) is first-class. The
    // runtime method (whereHas / whereDoesntHave) is derived from negation + whether children exist.
    controls.hasType = selectControl(node.hasType || 'has', [
      { value: 'has', label: 'has' }, { value: 'doesntHave', label: "doesn't have" },
    ]);
    controls.relationship = createCombobox({
      items: relationships.map((r) => ({ label: r.name, sublabel: r.target ? shortName(r.target) : '', value: r.name })),
      placeholder: 'relationship', initialValue: node.relationship ?? '',
    });
    controls.relationship.input.addEventListener('input', () => refreshValidity());

    // Count constraint — "has AT LEAST 5 calls where …". Backend (ConditionNode.countOperator/
    // countValue → has($rel, $op, $count)) is parity-locked. Only meaningful for a positive "has";
    // hidden for "doesn't have" (which is just "has zero").
    // Default (no count operator) is whereHas/has = EXISTS = "at least one"; the rest add an explicit count.
    const COUNT_OPS = [
      { value: '', label: 'at least one' }, { value: '>=', label: 'at least' }, { value: '>', label: 'more than' },
      { value: '=', label: 'exactly' }, { value: '<=', label: 'at most' }, { value: '<', label: 'fewer than' },
    ];
    controls.countOp = selectControl(node.countOperator || '', COUNT_OPS);
    controls.countNum = el('input', { type: 'number', class: 'qb-modal-input', min: '0', value: node.countValue ?? '' });
    const countField = el('div', {});
    const renderCount = () => {
      countField.innerHTML = '';
      if (controls.hasType.value === 'doesntHave') return; // count N/A for "doesn't have"
      const row = el('div', { class: 'qb-count-row' }, [controls.countOp, ...(controls.countOp.value ? [controls.countNum] : [])]);
      countField.appendChild(field('How many', row));
    };
    controls.countOp.addEventListener('change', () => { renderCount(); refreshValidity(); });
    controls.hasType.addEventListener('change', () => { renderCount(); refreshValidity(); });
    controls.countNum.addEventListener('input', () => refreshValidity());
    renderCount();

    rows.push(field('Match', controls.hasType), field('Relationship', controls.relationship.el), countField);
  } else {
    // THREE-STEP progressive disclosure: pick a column → the operator field appears (filtered by the
    // column's type) → the value field appears (typed by the column, and hidden entirely for the unary
    // is_null / is_not_null operators, which take no value).
    const operatorField = el('div', {});
    const valueField = el('div', {});
    let curInfo = columnTypeInfo(columns, node.column || '');
    // The value can be a literal, another COLUMN (reference: updated_at > created_at → whereColumn), or a
    // column on a to-one RELATED table (relatedColumn → correlated whereHas). Comparison operators only.
    let valueMode = node.valueType === 'reference' ? 'column'
      : (node.valueType === 'relatedColumn' ? 'related'
        : (node.valueType === 'aggregate' ? 'aggregate' : 'value'));
    // A Computed Value can be inserted into ANY value slot via the ƒ button (not a mode) — it overrides.
    let cvRef = node.valueType === 'computedValue' ? (node.referenceComputedValue || null) : null;

    // The ƒ button + a type-matched Computed Value picker — list CVs fit array slots (in/not_in), scalar
    // CVs fit scalar slots. Picking one overrides the value with a chip; × reverts to a typed value.
    const cvAffordance = (op, slotKind) => {
      const fit = computedValues.filter((c) => c.kind === slotKind);
      if (!fit.length) return null;
      const picker = createCombobox({
        items: fit.map((c) => ({ label: c.label || c.name, sublabel: c.kind, name: c.name })),
        placeholder: 'computed value…',
        onSelect: (it) => { cvRef = it.name; renderValue(op, ''); },
      });
      const drop = el('div', { class: 'qb-cv-picker', style: 'display:none' }, [picker.el]);
      const fx = el('button', { type: 'button', class: 'qb-cv-btn', title: 'Insert a computed value', text: 'ƒ' });
      fx.addEventListener('click', () => {
        const open = drop.style.display === 'none';
        drop.style.display = open ? 'block' : 'none';
        if (open) picker.focus();
      });
      return el('div', {}, [fx, drop]);
    };

    const renderValue = (op, currentValue) => {
      valueField.innerHTML = '';
      const arity = valueArity(op);
      if (arity === 'none') { valueControl = null; refreshValidity(); return; } // unary: no value

      // Computed Value selected → show a chip (overrides the slot), with × to revert.
      if (cvRef) {
        const cv = computedValues.find((c) => c.name === cvRef);
        const x = el('button', { type: 'button', class: 'qb-cv-x', text: '×' });
        x.addEventListener('click', () => { cvRef = null; renderValue(op, ''); });
        valueControl = { isComputedValue: true, getValue: () => cvRef, isFilled: () => true };
        valueField.appendChild(field('Value', el('div', { class: 'qb-cv-chip' }, [el('span', { text: 'ƒ ' + (cv ? (cv.label || cv.name) : cvRef) }), x])));
        refreshValidity();
        return;
      }

      const modeBtn = (label, mode) => {
        const b = el('button', { type: 'button', class: 'qb-valmode-btn' + (valueMode === mode ? ' qb-valmode-on' : ''), text: label });
        b.addEventListener('click', () => { if (valueMode !== mode) { valueMode = mode; renderValue(op, ''); } });
        return b;
      };
      if (arity === 'single') {
        const modes = [modeBtn('a value', 'value'), modeBtn('another column', 'column')];
        if (relatedOptions.length) modes.push(modeBtn('another table', 'related'));
        if (aggregateOptions.length) modes.push(modeBtn('an aggregate', 'aggregate'));
        valueField.appendChild(el('div', { class: 'qb-valmode' }, modes));
      } else {
        valueMode = 'value';
      }

      if (valueMode === 'column') {
        // Reference mode — compare to another column of the same schema.
        const refCombo = createCombobox({
          items: columns.map((c) => ({ label: c.name, sublabel: c.type || '', value: c.name })),
          placeholder: 'column', initialValue: node.referenceColumn ?? '',
        });
        refCombo.input.addEventListener('input', () => refreshValidity());
        valueControl = { isReference: true, getValue: () => refCombo.getValue(), isFilled: () => String(refCombo.getValue() || '').trim() !== '' };
        valueField.appendChild(field('Compare to', el('div', { class: 'qb-value-wrap' }, [refCombo.el])));
      } else if (valueMode === 'related') {
        // relatedColumn — compare to a column on a to-one related record (correlated whereHas).
        let chosen = (node.valueType === 'relatedColumn' && node.referenceRelationship)
          ? { relationship: node.referenceRelationship, column: node.referenceColumn } : null;
        const relCombo = createCombobox({
          items: relatedOptions.map((o) => ({ label: o.label, sublabel: o.type || '', relationship: o.relationship, column: o.column })),
          placeholder: 'related table → column',
          initialValue: chosen ? chosen.relationship + ' → ' + chosen.column : '',
          onSelect: (it) => { chosen = { relationship: it.relationship, column: it.column }; refreshValidity(); },
        });
        valueControl = { isRelated: true, getValue: () => chosen, isFilled: () => chosen != null };
        valueField.appendChild(field('Compare to', el('div', { class: 'qb-value-wrap' }, [relCombo.el])));
      } else if (valueMode === 'aggregate') {
        // aggregate — compare to AGG(col) over a to-many (hasMany) relation (correlated subquery).
        let chosen = (node.valueType === 'aggregate' && node.referenceRelationship)
          ? { relationship: node.referenceRelationship, column: node.referenceColumn } : null;
        const fnSel = selectControl(node.aggregateFunction || 'avg', ['avg', 'sum', 'min', 'max', 'count']);
        fnSel.addEventListener('change', () => refreshValidity());
        const aggCombo = createCombobox({
          items: aggregateOptions.map((o) => ({ label: o.label, sublabel: o.type || '', relationship: o.relationship, column: o.column })),
          placeholder: 'relation → column',
          initialValue: chosen ? chosen.relationship + ' → ' + chosen.column : '',
          onSelect: (it) => { chosen = { relationship: it.relationship, column: it.column }; refreshValidity(); },
        });
        valueControl = {
          isAggregate: true,
          getValue: () => (chosen ? { function: fnSel.value, relationship: chosen.relationship, column: chosen.column } : null),
          isFilled: () => chosen != null,
        };
        valueField.appendChild(field('Aggregate', fnSel));
        valueField.appendChild(field('Of', el('div', { class: 'qb-value-wrap' }, [aggCombo.el])));
      } else {
        valueControl = buildValueControl(op, curInfo, currentValue, () => refreshValidity());
        if (valueControl) valueField.appendChild(field('Value', el('div', { class: 'qb-value-wrap' }, [valueControl.control])));
      }
      // ƒ — insert a type-matched Computed Value instead of typing the value.
      if (valueMode === 'value') {
        const aff = cvAffordance(op, arity === 'multi' ? 'list' : 'scalar');
        if (aff) valueField.appendChild(aff);
      }
      refreshValidity();
    };

    const renderOperator = (info, currentOp, currentValue) => {
      operatorField.innerHTML = '';
      curInfo = info;
      const ops = operatorsForType(info.type);
      const op = currentOp && ops.includes(currentOp) ? currentOp : ops[0];
      controls.operator = selectControl(op, ops);
      controls.operator.addEventListener('change', () => renderValue(controls.operator.value, ''));
      operatorField.appendChild(field('Operator', controls.operator));
      renderValue(op, currentValue);
    };

    controls.column = createCombobox({
      items: columns.map((c) => ({ label: c.name, sublabel: c.type || '', value: c.name, col: c })),
      placeholder: 'column', initialValue: node.column ?? '',
      onSelect: (it) => renderOperator(it.col, null, ''),
    });
    // Also reveal on a typed column (not just a dropdown pick), so a free-typed column still works.
    controls.column.input.addEventListener('change', () => renderOperator(columnTypeInfo(columns, controls.column.getValue()), null, ''));
    controls.column.input.addEventListener('input', () => refreshValidity());

    rows.push(field('Column', controls.column.el), operatorField, valueField);

    // Editing an existing condition (column already set) → show all three steps up front.
    if (node.column) renderOperator(columnTypeInfo(columns, node.column), node.operator, node.value);
  }

  const saveBtn = el('button', { type: 'button', class: 'qb-modal-save', text: 'Save' });
  const cancelBtn = el('button', { type: 'button', class: 'qb-modal-cancel', text: 'Cancel' });
  const panel = el('div', { class: 'qb-modal' }, [
    el('div', { class: 'qb-modal-title', text: isWhereHas ? 'Edit relationship' : 'Edit condition' }),
    ...rows,
    el('div', { class: 'qb-modal-actions' }, [cancelBtn, saveBtn]),
  ]);
  const overlay = el('div', { class: 'qb-modal-overlay' }, [panel]);
  const close = () => overlay.remove();

  // A condition is incomplete until it has a column, an operator, and — for everything except the unary
  // is null / is not null — a non-empty value. Save stays disabled until then, so you can't commit a
  // half-filled condition. (To match NULL, choose the "is null" operator; that's distinct from = '' on
  // purpose — empty string and null are different values.)
  const isValid = () => {
    if (isWhereHas) {
      if (!String(controls.relationship.getValue() || '').trim()) return false;
      // a chosen count operator needs a number
      if (controls.hasType.value === 'has' && controls.countOp.value && String(controls.countNum.value).trim() === '') return false;
      return true;
    }
    if (!String(controls.column.getValue() || '').trim()) return false;
    if (!controls.operator) return false; // operator step not reached yet
    if (isUnary(controls.operator.value)) return true; // is null / is empty etc. take no value
    return valueControl != null && valueControl.isFilled();
  };
  refreshValidity = () => { saveBtn.disabled = !isValid(); };

  saveBtn.addEventListener('click', () => {
    if (!isValid()) return; // guard: must complete (or Cancel) — no committing an incomplete condition
    const useCount = controls.hasType && controls.hasType.value === 'has' && controls.countOp && controls.countOp.value;
    const values = isWhereHas
      ? {
          hasType: controls.hasType.value,
          relationship: controls.relationship.getValue(),
          countOperator: useCount ? controls.countOp.value : null,
          countValue: useCount ? Number(controls.countNum.value) : null,
        }
      : (() => {
          const vc = valueControl;
          const isRel = vc != null && vc.isRelated === true;
          const isRef = vc != null && vc.isReference === true;
          const isAgg = vc != null && vc.isAggregate === true;
          const isCV = vc != null && vc.isComputedValue === true;
          // base nulls the reference fields so switching modes never leaves stale data on the node.
          const base = {
            column: controls.column.getValue(),
            operator: controls.operator ? controls.operator.value : '=',
            referenceColumn: null,
            referenceRelationship: null,
            aggregateFunction: null,
            referenceComputedValue: null,
            value: null,
          };
          if (isCV) return { ...base, valueType: 'computedValue', referenceComputedValue: vc.getValue() };
          if (isAgg) { const a = vc.getValue() || {}; return { ...base, valueType: 'aggregate', aggregateFunction: a.function, referenceRelationship: a.relationship, referenceColumn: a.column }; }
          if (isRel) { const r = vc.getValue() || {}; return { ...base, valueType: 'relatedColumn', referenceRelationship: r.relationship, referenceColumn: r.column }; }
          if (isRef) return { ...base, valueType: 'reference', referenceColumn: vc.getValue() };
          return { ...base, valueType: 'hardcoded', value: vc ? vc.getValue() : '' }; // scalar/array/[from,to]; '' for unary
        })();
    if (onSave) onSave(values);
    close();
  });
  cancelBtn.addEventListener('click', () => { if (onCancel) onCancel(); close(); });
  // No click-outside-to-close on the EDITOR: the only exits are Save (when valid) or Cancel, so a
  // half-filled condition can't be dropped by an accidental tap on the backdrop.

  document.body.appendChild(overlay);
  refreshValidity(); // set the initial disabled state (a fresh condition starts invalid)
  return { close, overlay };
}

// The cross-hop discovery search — a GROUPED combobox: type a vague term, see field-paths a few hops
// out grouped by their relationship path, pick one. Items carry the original field for auto-pathing.
export function openSearch(fields, { onSelect, onCancel } = {}) {
  const close = () => overlay.remove();

  const items = fields.map((f) => ({
    label: f.column,
    sublabel: f.type || '',
    group: f.relationshipPath.length ? f.relationshipPath.join(' → ') : 'This record',
    field: f,
  }));

  const combo = createCombobox({
    items,
    placeholder: 'Search fields across relationships…',
    grouped: true,
    onSelect: (it) => { if (onSelect) onSelect(it.field); close(); },
  });

  const cancelBtn = el('button', { type: 'button', class: 'qb-modal-cancel', text: 'Close' });
  const panel = el('div', { class: 'qb-modal' }, [
    el('div', { class: 'qb-modal-title', text: 'Find a field' }),
    combo.el,
    el('div', { class: 'qb-modal-actions' }, [cancelBtn]),
  ]);
  const overlay = el('div', { class: 'qb-modal-overlay' }, [panel]);
  cancelBtn.addEventListener('click', () => { if (onCancel) onCancel(); close(); });
  overlay.addEventListener('click', (e) => { if (e.target === overlay) close(); });

  document.body.appendChild(overlay);
  combo.open();
  return { close, overlay };
}

// DEFINE A SET — name it, point a mini-builder at a whitelisted lookup model, filter it, pick the ID
// column. The nested builder is mounted via mountBuilder (the host's init) once a model is chosen; its
// tree becomes the set's conditions. The host resolves the set once → whereIn (FilterSetResolver).
// DEFINE A COMPUTED VALUE — a named query over ANY model that yields a TYPED result: a `list` (take a
// column → an array, for in/not_in) or a `scalar` (an aggregate → a single value). Start model is a
// searchable picker over all schema-backed models; its schema is fetched on demand to drive a nested
// builder (the filter conditions) + the column picker.
export function openComputedValueDefinition({ models = [], fetchSchema, mountBuilder, onSave, onCancel } = {}) {
  const close = () => overlay.remove();

  const nameInput = el('input', { type: 'text', class: 'qb-modal-input', placeholder: 'name (e.g. CA counties)' });
  const builderHost = el('div', { class: 'qb-setbuilder' });
  const kindField = el('div', {});
  const selectField = el('div', {});
  const aggSel = selectControl('avg', ['avg', 'sum', 'min', 'max', 'count']);

  let selectedModel = null;
  let schemaColumns = [];
  let builderHandle = null;
  let columnControl = null;
  let kind = 'list';

  const renderSelect = () => {
    selectField.innerHTML = '';
    columnControl = createCombobox({
      items: schemaColumns.map((c) => ({ label: c.name, sublabel: c.type || '', value: c.name })),
      placeholder: 'column',
    });
    if (kind === 'scalar') {
      selectField.appendChild(field('Aggregate', aggSel));
      if (aggSel.value !== 'count') selectField.appendChild(field('Of column', el('div', { class: 'qb-value-wrap' }, [columnControl.el])));
    } else {
      selectField.appendChild(field('Take column (gives a list)', el('div', { class: 'qb-value-wrap' }, [columnControl.el])));
    }
  };
  aggSel.addEventListener('change', renderSelect);

  const kindBtn = (label, k) => {
    const b = el('button', { type: 'button', class: 'qb-valmode-btn' + (kind === k ? ' qb-valmode-on' : ''), text: label });
    b.addEventListener('click', () => { if (kind !== k) { kind = k; renderKind(); } });
    return b;
  };
  const renderKind = () => {
    kindField.innerHTML = '';
    kindField.appendChild(el('div', { class: 'qb-valmode' }, [kindBtn('a list', 'list'), kindBtn('a single value', 'scalar')]));
    renderSelect();
  };
  renderKind();

  const modelCombo = createCombobox({
    items: models.map((m) => ({ label: m.label, sublabel: '', key: m.key })),
    placeholder: 'start from any model…',
    onSelect: async (it) => {
      selectedModel = it.key;
      builderHost.textContent = 'Loading schema…';
      const schema = (fetchSchema ? await fetchSchema(it.key) : {}) || {};
      builderHost.innerHTML = '';
      const g = schema.graph;
      schemaColumns = (g && g.base && g.models && g.models[g.base] && g.models[g.base].columns) || [];
      builderHandle = mountBuilder ? mountBuilder(builderHost, schema) : null;
      renderSelect();
    },
  });

  // Save to the shared Library (reuse across Reports) vs keep ad-hoc (this Report only).
  const saveLibCheck = el('input', { type: 'checkbox', class: 'qb-cv-savelib' });
  const visSel = selectControl('private', [{ value: 'private', label: 'private (just me)' }, { value: 'account', label: 'shared (my account)' }]);

  const saveBtn = el('button', { type: 'button', class: 'qb-modal-save', text: 'Save' });
  const cancelBtn = el('button', { type: 'button', class: 'qb-modal-cancel', text: 'Cancel' });
  saveBtn.addEventListener('click', () => {
    const name = nameInput.value.trim();
    if (!name || !selectedModel) return; // need a name + a model
    const useColumn = !(kind === 'scalar' && aggSel.value === 'count');
    if (onSave) onSave({
      name,
      model: selectedModel,
      kind,
      column: useColumn && columnControl ? (columnControl.getValue() || 'id') : 'id',
      aggregateFunction: kind === 'scalar' ? aggSel.value : null,
      conditions: builderHandle ? builderHandle.getTree() : [],
      saveToLibrary: saveLibCheck.checked,
      visibility: visSel.value,
    });
    close();
  });

  const panel = el('div', { class: 'qb-modal qb-modal-wide' }, [
    el('div', { class: 'qb-modal-title', text: 'Define a Computed Value' }),
    field('Name', nameInput),
    field('Start model', modelCombo.el),
    field('Result is', kindField),
    selectField,
    field('Filter it (optional)', builderHost),
    el('div', { class: 'qb-cv-savelib-row' }, [el('label', {}, [saveLibCheck, el('span', { text: ' Save to Library' })]), visSel]),
    el('div', { class: 'qb-modal-actions' }, [cancelBtn, saveBtn]),
  ]);
  const overlay = el('div', { class: 'qb-modal-overlay' }, [panel]);
  cancelBtn.addEventListener('click', () => { if (onCancel) onCancel(); close(); });

  document.body.appendChild(overlay);
  return { close, overlay };
}

// DEFINE A PREDICATE — a reusable, named condition fragment on the base model (e.g. "Engaged caller").
// Just a name + the query builder; saved to the Library and referenced by slug, expanded at query time.
export function openPredicateDefinition({ schema, mountBuilder, onSave, onCancel } = {}) {
  const close = () => overlay.remove();
  const nameInput = el('input', { type: 'text', class: 'qb-modal-input', placeholder: 'name (e.g. Engaged caller)' });
  const visSel = selectControl('private', [{ value: 'private', label: 'private (just me)' }, { value: 'account', label: 'shared (my account)' }]);
  const builderHost = el('div', { class: 'qb-setbuilder' });
  const builderHandle = mountBuilder ? mountBuilder(builderHost, schema || {}) : null;

  const saveBtn = el('button', { type: 'button', class: 'qb-modal-save', text: 'Save predicate' });
  const cancelBtn = el('button', { type: 'button', class: 'qb-modal-cancel', text: 'Cancel' });
  saveBtn.addEventListener('click', () => {
    const name = nameInput.value.trim();
    if (!name) return;
    if (onSave) onSave({ name, visibility: visSel.value, conditions: builderHandle ? builderHandle.getTree() : [] });
    close();
  });

  const panel = el('div', { class: 'qb-modal qb-modal-wide' }, [
    el('div', { class: 'qb-modal-title', text: 'Define a Predicate' }),
    field('Name', nameInput),
    field('Conditions', builderHost),
    el('div', { class: 'qb-cv-savelib-row' }, [el('span', { text: 'Visibility' }), visSel]),
    el('div', { class: 'qb-modal-actions' }, [cancelBtn, saveBtn]),
  ]);
  const overlay = el('div', { class: 'qb-modal-overlay' }, [panel]);
  cancelBtn.addEventListener('click', () => { if (onCancel) onCancel(); close(); });
  document.body.appendChild(overlay);
  return { close, overlay };
}

// Pick a Library Predicate to insert as a reference node.
export function openPredicatePicker(predicates, { onSelect, onCancel } = {}) {
  const close = () => overlay.remove();
  const combo = createCombobox({
    items: predicates.map((p) => ({ label: p.label || p.name, sublabel: 'predicate', name: p.name })),
    placeholder: 'choose a predicate…',
    onSelect: (it) => { if (onSelect) onSelect(it); close(); },
  });
  const cancelBtn = el('button', { type: 'button', class: 'qb-modal-cancel', text: 'Close' });
  const panel = el('div', { class: 'qb-modal' }, [
    el('div', { class: 'qb-modal-title', text: 'Insert a Predicate' }),
    combo.el,
    el('div', { class: 'qb-modal-actions' }, [cancelBtn]),
  ]);
  const overlay = el('div', { class: 'qb-modal-overlay' }, [panel]);
  cancelBtn.addEventListener('click', () => { if (onCancel) onCancel(); close(); });
  overlay.addEventListener('click', (e) => { if (e.target === overlay) close(); });
  document.body.appendChild(overlay);
  combo.open();
  return { close, overlay };
}

// GRADUATE a piece of the current filter into the Library at ITS scope (the node's context model). Just a
// name + visibility — the conditions + scope are captured from the node you graduated.
export function openGraduate({ scope, count } = {}, { onSave, onCancel } = {}) {
  const close = () => overlay.remove();
  const nameInput = el('input', { type: 'text', class: 'qb-modal-input', placeholder: 'name (e.g. Engaged caller)' });
  const visSel = selectControl('private', [{ value: 'private', label: 'private (just me)' }, { value: 'account', label: 'shared (my account)' }]);

  const saveBtn = el('button', { type: 'button', class: 'qb-modal-save', text: 'Graduate to Library' });
  const cancelBtn = el('button', { type: 'button', class: 'qb-modal-cancel', text: 'Cancel' });
  saveBtn.addEventListener('click', () => {
    const name = nameInput.value.trim();
    if (!name) return;
    if (onSave) onSave({ name, visibility: visSel.value });
    close();
  });
  cancelBtn.addEventListener('click', () => { if (onCancel) onCancel(); close(); });

  const scopeLabel = scope ? String(scope).split('\\').pop() : 'base';
  const panel = el('div', { class: 'qb-modal' }, [
    el('div', { class: 'qb-modal-title', text: 'Graduate to Library' }),
    el('div', { class: 'qb-grad-note', text: count + ' condition(s) · scope: ' + scopeLabel }),
    field('Name', nameInput),
    el('div', { class: 'qb-cv-savelib-row' }, [el('span', { text: 'Visibility' }), visSel]),
    el('div', { class: 'qb-modal-actions' }, [cancelBtn, saveBtn]),
  ]);
  const overlay = el('div', { class: 'qb-modal-overlay' }, [panel]);
  document.body.appendChild(overlay);
  return { close, overlay };
}

// Find-a-RELATIONSHIP search — type a model name (e.g. "lead"), see every reachable model FROM THE
// CURRENT CONTEXT with its shortest whereHas chain, pick one. Resolves from `fromModel` (top level =
// base, nested = the context model), so the chain is correct wherever you're standing. Unreachable
// models simply don't appear → "No matches" reads as "no connection from here".
export function openRelationshipSearch(adjacency, fromModel, { onSelect, onCancel } = {}) {
  const close = () => overlay.remove();
  const reachable = searchModelPaths(adjacency, fromModel, '', 1000);
  const items = reachable.map((r) => ({
    label: r.name,
    // chain with cardinality — a "»" marks a one-to-many hop (where the query fans out).
    sublabel: r.edges.length ? 'via ' + r.edges.map((e) => e.name + (e.many ? ' »' : '')).join(' → ') : 'direct',
    path: r.path,
  }));

  const combo = createCombobox({
    items,
    placeholder: 'Search for a model (e.g. lead)…',
    onSelect: (it) => { if (onSelect) onSelect(it.path); close(); },
  });

  const cancelBtn = el('button', { type: 'button', class: 'qb-modal-cancel', text: 'Close' });
  const panel = el('div', { class: 'qb-modal' }, [
    el('div', { class: 'qb-modal-title', text: 'Find a relationship — from ' + (fromModel ? shortName(fromModel) : 'base') }),
    combo.el,
    el('div', { class: 'qb-modal-actions' }, [cancelBtn]),
  ]);
  const overlay = el('div', { class: 'qb-modal-overlay' }, [panel]);
  cancelBtn.addEventListener('click', () => { if (onCancel) onCancel(); close(); });
  overlay.addEventListener('click', (e) => { if (e.target === overlay) close(); });

  document.body.appendChild(overlay);
  combo.open();
  return { close, overlay };
}

// The schema NAVIGATOR — browse, don't search. Walk into a relationship to see its model's fields,
// breadcrumb back, pick a column → hand back the field-path so the host auto-builds the whereHas.
// Lazy-friendly: it only ever asks levelAt() for the current level, never the whole graph.
// Type groups for the accept filter on schemaField knobs.
const TYPE_GROUPS = {
  date: ['date', 'datetime', 'timestamp', 'time'],
  numeric: ['number', 'integer', 'biginteger', 'float', 'double', 'decimal', 'unsignedinteger', 'unsignedbiginteger', 'unsignedsmallinteger', 'unsignedtinyinteger', 'tinyinteger', 'smallinteger', 'mediumminteger', 'mediuminteger', 'year'],
};

function columnMatchesAccept(col, accept) {
  if (!accept || !accept.length) return true;
  const t = (col.type || '').toLowerCase().replace(/[^a-z]/g, '');
  return accept.some((group) => (TYPE_GROUPS[group] || []).includes(t));
}

export function openNavigator(metadata, { onSelect, onCancel, mode = 'field', accept = null } = {}) {
  const isRelMode = mode === 'relationship';
  let relPath = [];
  const crumb = el('div', { class: 'qb-nav-crumb' });
  const body = el('div', { class: 'qb-nav-body' });
  const close = () => overlay.remove();

  // Relationship mode: a has / doesn't-have toggle that the Select buttons carry.
  const hasTypeSelect = selectControl('has', [
    { value: 'has', label: 'has' }, { value: 'doesntHave', label: "doesn't have" },
  ]);

  function render() {
    crumb.innerHTML = '';
    const base = el('button', { type: 'button', class: 'qb-crumb', text: shortName(metadata && metadata.base) || 'base' });
    base.addEventListener('click', () => { relPath = []; render(); });
    crumb.appendChild(base);
    relPath.forEach((rel, i) => {
      crumb.appendChild(el('span', { class: 'qb-crumb-sep', text: ' › ' }));
      const c = el('button', { type: 'button', class: 'qb-crumb', text: rel });
      c.addEventListener('click', () => { relPath = relPath.slice(0, i + 1); render(); });
      crumb.appendChild(c);
    });

    const level = levelAt(metadata, relPath);
    body.innerHTML = '';
    for (const rel of level.relationships) {
      // Drillable only if the target model is actually loaded in the graph. Polymorphic relations
      // (target null → "varies") and targets past the loaded depth can't be browsed into — they'd
      // dead-end on an empty level — so they're Select-only (you can still filter by them).
      const inGraph = !!(rel.target && metadata && metadata.models && metadata.models[rel.target]);
      const modelTag = rel.target ? '  (' + shortName(rel.target) + ')' : '  (varies)';
      const open = el('button', { type: 'button', class: 'qb-nav-rel' + (inGraph ? '' : ' qb-nav-edge'), text: '↳ ' + rel.name + modelTag });
      if (inGraph) open.addEventListener('click', () => { relPath = [...relPath, rel.name]; render(); });
      else open.disabled = true;
      if (isRelMode) {
        const pick = el('button', { type: 'button', class: 'qb-nav-pick', text: 'Select' });
        pick.addEventListener('click', () => {
          if (onSelect) onSelect({ relationshipPath: [...relPath, rel.name], hasType: hasTypeSelect.value });
          close();
        });
        body.appendChild(el('div', { class: 'qb-nav-row' }, [open, pick]));
      } else {
        body.appendChild(open);
      }
    }
    // Columns are only selectable in field mode (relationship mode is picking a relationship endpoint).
    if (!isRelMode) {
      for (const col of level.columns.filter((c) => columnMatchesAccept(c, accept))) {
        const item = el('button', { type: 'button', class: 'qb-nav-col', text: col.name });
        item.addEventListener('click', () => {
          if (onSelect) onSelect({ relationshipPath: [...relPath], column: col.name, type: col.type, options: col.options || null });
          close();
        });
        body.appendChild(item);
      }
    }
  }

  const cancelBtn = el('button', { type: 'button', class: 'qb-modal-cancel', text: 'Close' });
  const titleRow = isRelMode
    ? el('div', { class: 'qb-modal-title' }, [el('span', { text: 'Pick a relationship — ' }), hasTypeSelect])
    : el('div', { class: 'qb-modal-title', text: 'Browse schema' });
  const panel = el('div', { class: 'qb-modal' }, [
    titleRow,
    crumb,
    body,
    el('div', { class: 'qb-modal-actions' }, [cancelBtn]),
  ]);
  const overlay = el('div', { class: 'qb-modal-overlay' }, [panel]);
  cancelBtn.addEventListener('click', () => { if (onCancel) onCancel(); close(); });
  overlay.addEventListener('click', (e) => { if (e.target === overlay) close(); });

  document.body.appendChild(overlay);
  render();
  return { close, overlay };
}

// The single "+ Add" menu — one entry point that lists what you can add HERE (replaces the button row).
// Each option carries the action the builder runs; scope-invalid options are simply not passed in.
export function openActionMenu(options, { onPick, onCancel } = {}) {
  const close = () => overlay.remove();
  const list = el('div', { class: 'qb-addmenu-list' });
  options.forEach((o) => {
    const btn = el('button', { type: 'button', class: 'qb-addmenu-item', text: o.label });
    btn.addEventListener('click', () => { if (onPick) onPick(o.action); close(); });
    list.appendChild(btn);
  });
  const cancelBtn = el('button', { type: 'button', class: 'qb-modal-cancel', text: 'Cancel' });
  cancelBtn.addEventListener('click', () => { if (onCancel) onCancel(); close(); });

  const panel = el('div', { class: 'qb-modal' }, [
    el('div', { class: 'qb-modal-title', text: 'Add to filter' }),
    list,
    el('div', { class: 'qb-modal-actions' }, [cancelBtn]),
  ]);
  const overlay = el('div', { class: 'qb-modal-overlay' }, [panel]);
  overlay.addEventListener('click', (e) => { if (e.target === overlay) close(); });
  document.body.appendChild(overlay);
  return { close, overlay };
}
