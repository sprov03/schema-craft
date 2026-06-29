// Fuzzy matching — PURE, no DOM. fuzzyMatch is ported VERBATIM from the visualizer (so search ranks
// identically): subsequence match with bonuses for consecutive characters (+5) and word starts (+10,
// after '.', '_' or ' → '). Returns { score, indices } when the whole query is a subsequence, else null.

export function fuzzyMatch(query, text) {
  if (!query) return { score: 0, indices: [] };
  let qi = 0;
  const indices = [];
  let score = 0;
  let lastIdx = -1;
  const qLower = query.toLowerCase();
  const tLower = text.toLowerCase();
  for (let ti = 0; ti < tLower.length && qi < qLower.length; ti++) {
    if (tLower[ti] === qLower[qi]) {
      indices.push(ti);
      if (ti === lastIdx + 1) score += 5; // consecutive
      if (ti === 0 || text[ti - 1] === '.' || text[ti - 1] === '_' || text[ti - 1] === ' ') score += 10; // word start
      score += 1;
      lastIdx = ti;
      qi++;
    }
  }
  return qi === qLower.length ? { score, indices } : null;
}

// Rank a list of field-paths by how well their label matches the query. Empty query returns the first
// `limit` as-is (the "just browsing the list" case).
export function searchFields(query, fields, limit = 25) {
  if (!query) return fields.slice(0, limit);
  return fields
    .map((f) => {
      const m = fuzzyMatch(query, f.label);
      return m ? { ...f, score: m.score, indices: m.indices } : null;
    })
    .filter(Boolean)
    .sort((a, b) => b.score - a.score)
    .slice(0, limit);
}
