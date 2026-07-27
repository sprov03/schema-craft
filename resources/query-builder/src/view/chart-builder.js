import { openNavigator } from './modal.js';
import { createCombobox } from './combobox.js';

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

/**
 * The chart form — the ONE reusable component for building/editing a chart's config: a type picker + the
 * type's knobs + Save/Cancel. Mounted by BOTH the "+ Add Chart" flow and the per-chart Edit flow (add wraps
 * it in a modal, edit in a full-screen panel), so its look is cleaned up in exactly one place.
 *
 * Knobs: `schemaField` reuses the filter builder's column navigator (columns picked the ONE way →
 * {relationship, column}); `filter` embeds the query builder; `select`/`text` are plain inputs. Pre-fill for
 * edit comes via initialType (locks the picker) + initialValues (raw knob values, keyed by input.key).
 *
 * It only EMITS onSave(type, rawInput) / onCancel — it never touches the surface it lives in (the caller
 * closes the modal / navigates). That separation is what lets the same body render in different containers.
 */
export function chartForm({
  catalog = [],
  metadata = null,
  mountFilter = null,
  initialType = null,
  initialValues = {},
  saveLabel = 'Add chart',
  onSave = () => {},
  onCancel = () => {},
} = {}) {
  const raw = {};
  let currentType = initialType ? (catalog.find((c) => c.type === initialType) || null) : null;
  const knobsHost = el('div', { class: 'qb-chart-knobs' });

  function renderKnobs() {
    knobsHost.innerHTML = '';
    if (!currentType) return;
    (currentType.inputs || []).forEach((input) => knobsHost.appendChild(renderKnob(input)));
  }

  function renderKnob(input) {
    const row = el('div', { class: 'qb-knob' }, [el('label', { class: 'qb-knob-label', text: input.label })]);
    const seed = initialValues[input.key]; // edit: the stored raw value for this knob (undefined on add)

    if (input.type === 'schemaField') {
      const label = seed && seed.column ? (seed.relationship ? seed.relationship + '.' : '') + seed.column : '(pick a column)';
      const chip = el('span', { class: 'qb-knob-value', text: label });
      if (seed && seed.column) raw[input.key] = { relationship: seed.relationship || null, column: seed.column };
      const pick = el('button', { type: 'button', class: 'qb-knob-pick', text: 'Pick column' });
      pick.addEventListener('click', () => {
        openNavigator(metadata, {
          mode: 'field',
          accept: input.accept || null,
          onSelect: (field) => {
            const path = field.relationshipPath || [];
            const relationship = path.length ? path[path.length - 1] : null;
            raw[input.key] = { relationship, column: field.column };
            chip.textContent = (relationship ? relationship + '.' : '') + field.column;
          },
        });
      });
      row.appendChild(chip);
      row.appendChild(pick);
    } else if (input.type === 'filter') {
      // Embed the SAME query builder used at report level, scoped to the chart's base schema. Its
      // serialized ConditionNode tree streams into raw[key]; an empty tree = no filter (backend skips it).
      const container = el('div', { class: 'qb-knob-filter' });
      const initialTree = Array.isArray(seed) ? seed : [];
      if (initialTree.length) raw[input.key] = initialTree;
      if (mountFilter) {
        mountFilter(container, { initialTree, onChange: (tree) => { raw[input.key] = tree; } });
      }
      row.appendChild(container);
    } else if (input.type === 'select') {
      const sel = el('select', { class: 'qb-knob-select' });
      Object.entries(input.options || {}).forEach(([val, label]) => sel.appendChild(el('option', { value: val, text: label })));
      if (seed != null) sel.value = seed;
      sel.addEventListener('change', () => { raw[input.key] = sel.value; });
      raw[input.key] = sel.value; // default to the first option (or the seeded value)
      row.appendChild(sel);
    } else if (input.type === 'filterList') {
      // A labeled REPEATER: N rows, each = a name + one instance of the SAME embedded filter builder the
      // `filter` knob mounts. Each row emits {label, criteria}; the knob streams the ordered list into
      // raw[key]. Used by the funnel (stages) and composition (segments) — separate named filter trees,
      // not one AND-ed tree. Reuses the proven query builder; only the list-wrapper + per-row label is new.
      const container = el('div', { class: 'qb-knob-filterlist' });
      const rowsHost = el('div', { class: 'qb-filterlist-rows' });
      const stages = Array.isArray(seed)
        ? seed.map((s) => ({ label: s.label || '', criteria: Array.isArray(s.criteria) ? s.criteria : [] }))
        : [];
      raw[input.key] = stages; // same reference kept in sync as rows are added/edited/removed

      const addStageRow = (stage) => {
        const rowEl = el('div', { class: 'qb-filterlist-row' });
        const labelInp = el('input', { type: 'text', class: 'qb-filterlist-label', value: stage.label != null ? String(stage.label) : '', placeholder: 'Stage name' });
        labelInp.addEventListener('input', () => { stage.label = labelInp.value; });
        const filterHost = el('div', { class: 'qb-filterlist-filter' });
        if (mountFilter) {
          mountFilter(filterHost, { initialTree: stage.criteria, onChange: (tree) => { stage.criteria = tree; } });
        }
        const removeBtn = el('button', { type: 'button', class: 'qb-filterlist-remove', text: '\u2715' });
        removeBtn.addEventListener('click', () => {
          const i = stages.indexOf(stage);
          if (i >= 0) stages.splice(i, 1);
          rowEl.remove();
        });
        rowEl.appendChild(labelInp);
        rowEl.appendChild(filterHost);
        rowEl.appendChild(removeBtn);
        rowsHost.appendChild(rowEl);
      };

      stages.forEach(addStageRow); // edit: one row per stored stage, pre-filled
      const addBtn = el('button', { type: 'button', class: 'qb-filterlist-add', text: '+ Add stage' });
      addBtn.addEventListener('click', () => { const stage = { label: '', criteria: [] }; stages.push(stage); addStageRow(stage); });
      container.appendChild(rowsHost);
      container.appendChild(addBtn);
      row.appendChild(container);
    } else {
      // Store the raw default/seed with its ORIGINAL type (a numeric default stays a number); only the
      // displayed value is stringified. User edits become strings (the backend casts), as before.
      const initial = seed != null ? seed : input.default;
      const inp = el('input', { type: 'text', class: 'qb-knob-text', value: initial != null ? String(initial) : '' });
      inp.addEventListener('input', () => { raw[input.key] = inp.value; });
      if (initial != null) raw[input.key] = initial;
      row.appendChild(inp);
    }

    return row;
  }

  // Type control: edit locks to the chart's type (a label); add offers the catalog combobox.
  let typeControl;
  let openTypePicker = null;
  if (initialType && currentType) {
    typeControl = el('div', { class: 'qb-chart-type-fixed', text: currentType.label });
  } else {
    const typeCombo = createCombobox({
      items: catalog.map((c) => ({ label: c.label, name: c.type })),
      placeholder: 'choose a chart type…',
      onSelect: (it) => {
        currentType = catalog.find((c) => c.type === it.name) || null;
        Object.keys(raw).forEach((k) => delete raw[k]);
        renderKnobs();
      },
    });
    typeControl = typeCombo.el;
    openTypePicker = typeCombo.open || null;
  }

  const saveBtn = el('button', { type: 'button', class: 'qb-modal-save', text: saveLabel });
  saveBtn.addEventListener('click', () => { if (currentType) onSave(currentType.type, raw); });
  const cancelBtn = el('button', { type: 'button', class: 'qb-modal-cancel', text: 'Cancel' });
  cancelBtn.addEventListener('click', () => onCancel());

  const root = el('div', { class: 'qb-chart-form' }, [
    el('div', { class: 'qb-modal-title', text: initialType ? 'Edit chart' : 'Add a chart' }),
    typeControl,
    knobsHost,
    el('div', { class: 'qb-modal-actions' }, [cancelBtn, saveBtn]),
  ]);

  renderKnobs(); // edit mode renders the pre-filled knobs immediately; add waits for a type pick
  return { el: root, openTypePicker };
}

/**
 * The Charts section: lists the report's EXISTING charts (each with an ✎ edit control) and the "+ Add
 * Chart" button. Both flows open the SAME chartForm — add in pick-a-type mode, edit seeded with the chart's
 * stored knob values (loadCharts is the page's reportCharts()). Emits onSave(type, raw) for adds and
 * onUpdate(index, raw) for edits — the host persists via addChart / updateChart.
 */
export function initChartBuilder(host, { catalog = [], metadata = null, mountFilter = null, loadCharts = null, onSave = () => {}, onUpdate = () => {} } = {}) {
  // The section's contents fold behind an Edit toggle — the charts themselves are the page's main event;
  // the management UI (list + add) only drops down when you actually mean to change something.
  const toggle = el('button', { type: 'button', class: 'qb-chart-toggle', text: '✎ Edit' });
  const section = el('div', { class: 'qb-chart-section' }, [
    el('div', { class: 'qb-chart-section-head' }, [
      el('div', { class: 'qb-chart-section-label', text: '📊 Charts' }),
      toggle,
    ]),
  ]);
  const listHost = el('div', { class: 'qb-chart-list' });
  const addBtn = el('button', { type: 'button', class: 'qb-add-chart', text: '+ Add Chart' });
  addBtn.addEventListener('click', openAdd);
  const body = el('div', { class: 'qb-chart-body qb-collapsed' }, [listHost, addBtn]);
  toggle.addEventListener('click', () => body.classList.toggle('qb-collapsed'));
  section.appendChild(body);
  host.appendChild(section);

  // Re-fetched from the page (not stamped at load) so the list stays fresh after every add/edit — the
  // section lives in a wire:ignore'd host, so Livewire will never re-render it for us.
  async function renderList() {
    if (!loadCharts) return;
    const charts = (await loadCharts()) || [];
    listHost.innerHTML = '';
    charts.forEach((chart) => {
      const editBtn = el('button', { type: 'button', class: 'qb-chart-edit', title: 'Edit chart', text: '✎ Edit' });
      editBtn.addEventListener('click', () => openEdit(chart));
      listHost.appendChild(el('div', { class: 'qb-chart-item' }, [
        el('span', { class: 'qb-chart-item-name', text: chart.name || chart.label }),
        editBtn,
      ]));
    });
  }

  // One modal opener for both flows — the form options are the only difference.
  function openForm(formOptions) {
    const overlay = el('div', { class: 'qb-modal-overlay' });
    const close = () => overlay.remove();

    const form = chartForm({
      catalog,
      metadata,
      mountFilter,
      ...formOptions(close),
    });

    const panel = el('div', { class: 'qb-modal qb-chart-builder-modal' }, [form.el]);
    overlay.appendChild(panel);
    overlay.addEventListener('click', (e) => { if (e.target === overlay) close(); });

    document.body.appendChild(overlay);
    return form;
  }

  function openAdd() {
    const form = openForm((close) => ({
      saveLabel: 'Add chart',
      onSave: async (type, raw) => { await onSave(type, raw); close(); renderList(); },
      onCancel: close,
    }));
    if (form.openTypePicker) form.openTypePicker();
  }

  function openEdit(chart) {
    openForm((close) => ({
      initialType: chart.type,
      initialValues: chart.raw || {},
      saveLabel: 'Save chart',
      onSave: async (type, raw) => { await onUpdate(chart.index, raw); close(); renderList(); },
      onCancel: close,
    }));
  }

  renderList();
  return { open: openAdd, refresh: renderList };
}
