"""Route a free-text job title to the matching Intuint questionnaire.

Scoring: each occupation has weighted keywords. Domain words ("supermarket",
"shift boss") weigh more than generic role words ("manager", "owner"), so
"store manager" routes to retail and "sales manager" routes to sales. A title
with only one generic word ("manager") is too vague and goes to "general". Typos are caught with
fuzzy matching on single words. Anything unmatched falls back to "general".
"""
import difflib
import re

FALLBACK = "general"
MIN_SCORE = 2          # below this, we don't trust the match
FUZZY_CUTOFF = 0.85    # how close a misspelt word must be


def normalise(text: str) -> str:
    text = text.lower().replace("&", " and ")
    text = re.sub(r"[^a-z0-9 ]+", " ", text)
    return re.sub(r"\s+", " ", text).strip()


def _score(title: str, keywords: dict) -> tuple[int, list[str]]:
    padded = f" {title} "
    words = title.split()
    score, matched = 0, []
    for kw, weight in keywords.items():
        if f" {kw} " in padded:
            score += weight
            matched.append(kw)
        elif " " not in kw and len(kw) >= 5:
            close = difflib.get_close_matches(kw, words, n=1, cutoff=FUZZY_CUTOFF)
            if close:
                score += weight
                matched.append(f"{kw}~{close[0]}")
    return score, matched


def route(job_title: str, questionnaires: dict) -> dict:
    title = normalise(job_title or "")
    ranked = []
    for occ_id, q in questionnaires.items():
        if occ_id == FALLBACK:
            continue
        score, matched = _score(title, q.get("keywords", {}))
        if score:
            ranked.append((score, occ_id, matched))
    ranked.sort(key=lambda r: r[0], reverse=True)

    if not ranked or ranked[0][0] < MIN_SCORE:
        return {"occupation": FALLBACK, "confidence": 0.0, "matched_on": [],
                "alternatives": [r[1] for r in ranked]}

    top_score, occ_id, matched = ranked[0]
    runner_up = ranked[1][0] if len(ranked) > 1 else 0
    confidence = round(top_score / (top_score + runner_up), 2)
    return {"occupation": occ_id, "confidence": confidence, "matched_on": matched,
            "alternatives": [r[1] for r in ranked[1:]]}
