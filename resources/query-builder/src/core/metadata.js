// Schema metadata — PURE accessors, no DOM. The HOST supplies this (Filament: the page model's real
// columns/relationships + their types and enum options; visualizer: its schema API). The view reads
// it to drive guided pickers (column/relationship dropdowns) and TYPED value inputs (enum → select,
// date → picker + relative dates, number → number, etc.). Keeping it a plain data contract means the
// builder never fetches — the host owns where the schema comes from.
//
// Shape:
//   {
//     columns: [{ name, label?, type, options? }],   // type: 'string'|'number'|'date'|'boolean'|'enum'
//     relationships: [{ name, label?, relatedModel?, columns: [ ...same column shape... ] }],
//   }
// `options` is the list of valid values for an enum column (and what `in`/`not_in` multi-selects show).

export function columnsFor(metadata, relationshipName = null) {
  if (!metadata) return [];
  if (relationshipName) {
    const rel = (metadata.relationships || []).find((r) => r.name === relationshipName);
    return rel ? rel.columns || [] : [];
  }
  return metadata.columns || [];
}

export function relationshipsFor(metadata) {
  return metadata ? metadata.relationships || [] : [];
}

export function columnMeta(metadata, columnName, relationshipName = null) {
  return columnsFor(metadata, relationshipName).find((c) => c.name === columnName) || null;
}

// The value-input type for a column — defaults to 'string' when unknown so the builder always works
// even with sparse metadata.
export function typeOf(metadata, columnName, relationshipName = null) {
  const c = columnMeta(metadata, columnName, relationshipName);
  return c && c.type ? c.type : 'string';
}

// The enum/valid-value list for a column, or null when it's free-form.
export function optionsFor(metadata, columnName, relationshipName = null) {
  const c = columnMeta(metadata, columnName, relationshipName);
  return c && Array.isArray(c.options) ? c.options : null;
}
