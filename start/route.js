/* Know the Score: matches a free-text job title to a questionnaire track.
   Same rules as skool/router/app/router.py: weighted keywords, domain words
   outweigh generic ones ("store manager" -> retail), typos caught by fuzzy
   matching on single words, weak or no match -> "general". */
(function (root) {
  const FALLBACK = "general";
  const MIN_SCORE = 2;
  const FUZZY_CUTOFF = 0.85;

  function normalise(text) {
    return String(text || "").toLowerCase().replace(/&/g, " and ")
      .replace(/[^a-z0-9 ]+/g, " ").replace(/\s+/g, " ").trim();
  }

  function similarity(a, b) {
    const m = a.length, n = b.length;
    if (!m || !n) return 0;
    let prev = Array.from({ length: n + 1 }, (_, j) => j);
    for (let i = 1; i <= m; i++) {
      const cur = [i];
      for (let j = 1; j <= n; j++) {
        cur[j] = Math.min(prev[j] + 1, cur[j - 1] + 1, prev[j - 1] + (a[i - 1] === b[j - 1] ? 0 : 1));
      }
      prev = cur;
    }
    return 1 - prev[n] / Math.max(m, n);
  }

  function score(title, keywords) {
    const padded = ` ${title} `, words = title.split(" ");
    let total = 0; const matched = [];
    for (const [kw, weight] of Object.entries(keywords || {})) {
      if (padded.includes(` ${kw} `)) { total += weight; matched.push(kw); }
      else if (!kw.includes(" ") && kw.length >= 5) {
        const close = words.find(w => similarity(kw, w) >= FUZZY_CUTOFF);
        if (close) { total += weight; matched.push(`${kw}~${close}`); }
      }
    }
    return [total, matched];
  }

  function route(jobTitle, questionnaires) {
    const title = normalise(jobTitle);
    const ranked = [];
    for (const [id, q] of Object.entries(questionnaires)) {
      if (id === FALLBACK) continue;
      const [s, matched] = score(title, q.keywords);
      if (s) ranked.push({ s, id, matched });
    }
    ranked.sort((a, b) => b.s - a.s);
    if (!ranked.length || ranked[0].s < MIN_SCORE) {
      return { occupation: FALLBACK, confidence: 0, matched_on: [], alternatives: ranked.map(r => r.id) };
    }
    const top = ranked[0], runner = ranked[1] ? ranked[1].s : 0;
    return { occupation: top.id, confidence: Math.round(100 * top.s / (top.s + runner)) / 100,
             matched_on: top.matched, alternatives: ranked.slice(1).map(r => r.id) };
  }

  const api = { route, normalise };
  if (typeof module !== "undefined" && module.exports) module.exports = api;
  else root.KTSRoute = api;
})(this);
