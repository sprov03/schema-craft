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
 * The chart builder: pick a chart type from the catalog, fill its knobs, save. A `schemaField` knob reuses
 * the filter builder's column navigator (so columns are picked the ONE way, resolving to {relationship,
 * column}); `select`/`text` are plain inputs. Emits onSave(type, rawInput) — the host resolves + persists
 * it via the page's addChart. The chart classes (the catalog) stay the source of truth for the knobs.
 */
export function initChartBuilder(host, { catalog = [], metadata = null, mountFilter = null, onSave = () => {} } = {}) {
  // A clearly-labelled section so the entry point is unmistakable (not a bare button in the filter area).
  const section = el('div', { class: 'qb-chart-section' }, [
    el('div', { class: 'qb-chart-section-label', text: '📊 Charts' }),
  ]);
  const addBtn = el('button', { type: 'button', class: 'qb-add-chart', text: '+ Add Chart' });
  addBtn.addEventListener('click', openPanel);
  section.appendChild(addBtn);
  host.appendChild(section);

  function openPanel() {
    let currentType = null;
    const raw = {};
    const knobsHost = el('div', { class: 'qb-chart-knobs' });

    const typeCombo = createCombobox({
      items: catalog.map((c) => ({ label: c.label, name: c.type })),
      placeholder: 'choose a chart type…',
      onSelect: (it) => {
        currentType = catalog.find((c) => c.type === it.name) || null;
        Object.keys(raw).forEach((k) => delete raw[k]);
        renderKnobs();
      },
    });

    function renderKnobs() {
      knobsHost.innerHTML = '';
      if (!currentType) return;
      (currentType.inputs || []).forEach((input) => knobsHost.appendChild(renderKnob(input)));
    }

    function renderKnob(input) {
      const row = el('div', { class: 'qb-knob' }, [el('label', { class: 'qb-knob-label', text: input.label })]);

      if (input.type === 'schemaField') {
        const chip = el('span', { class: 'qb-knob-value', text: '(pick a column)' });
        const pick = el('button', { type: 'button', class: 'qb-knob-pick', text: 'Pick column' });
        pick.addEventListener('click', () => {
          openNavigator(metadata, {
            mode: 'field',
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
        if (mountFilter) {
          mountFilter(container, { initialTree: [], onChange: (tree) => { raw[input.key] = tree; } });
        }
        row.appendChild(container);
      } else if (input.type === 'select') {
        const sel = el('select', { class: 'qb-knob-select' });
        Object.entries(input.options || {}).forEach(([val, label]) => sel.appendChild(el('option', { value: val, text: label })));
        sel.addEventListener('change', () => { raw[input.key] = sel.value; });
        raw[input.key] = sel.value; // default to the first option
        row.appendChild(sel);
      } else {
        const inp = el('input', { type: 'text', class: 'qb-knob-text', value: input.default != null ? String(input.default) : '' });
        inp.addEventListener('input', () => { raw[input.key] = inp.value; });
        if (input.default != null) raw[input.key] = input.default;
        row.appendChild(inp);
      }

      return row;
    }

    const saveBtn = el('button', { type: 'button', class: 'qb-modal-save', text: 'Add chart' });
    saveBtn.addEventListener('click', () => { if (currentType) { onSave(currentType.type, raw); close(); } });
    const cancelBtn = el('button', { type: 'button', class: 'qb-modal-cancel', text: 'Cancel' });
    cancelBtn.addEventListener('click', close);

    const panel = el('div', { class: 'qb-modal qb-chart-builder-modal' }, [
      el('div', { class: 'qb-modal-title', text: 'Add a chart' }),
      typeCombo.el,
      knobsHost,
      el('div', { class: 'qb-modal-actions' }, [cancelBtn, saveBtn]),
    ]);
    const overlay = el('div', { class: 'qb-modal-overlay' }, [panel]);
    overlay.addEventListener('click', (e) => { if (e.target === overlay) close(); });
    function close() { overlay.remove(); }

    document.body.appendChild(overlay);
    if (typeCombo.open) typeCombo.open();
  }

  return { open: openPanel };
}
