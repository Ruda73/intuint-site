"""Intuint job router API.

Public:
  GET  /health
  GET  /occupations                       list of tracks
  GET|POST /route                         job title -> matching questionnaire
  GET  /questionnaires/<occupation>       one questionnaire

Needs the X-API-Key header (set INTUINT_API_KEY on the server):
  POST /answers                           seal a member's gut answers
  POST /answers/<id>/analysis             answer after checking the numbers
  POST /answers/<id>/outcome              record what actually happened
  GET  /members/<member_id>/score         accuracy and calibration
"""
import hmac
import json
import os
from pathlib import Path

from flask import Flask, abort, g, jsonify, request

from . import store
from .router import route

BASE = Path(__file__).resolve().parent.parent
CONFIDENCE_LEVELS = (50, 70, 90)


def public_questionnaire(occ_id: str, q: dict) -> dict:
    return {"occupation": occ_id, "title": q["title"], "intro": q["intro"],
            "confidence_levels": list(CONFIDENCE_LEVELS),
            "questions": [{k: v for k, v in item.items()} for item in q["questions"]]}


def create_app(db_path=None, data_path=None, api_key=None) -> Flask:
    app = Flask(__name__)
    app.json.sort_keys = False
    db_path = db_path or os.environ.get("INTUINT_DB", str(BASE / "intuint.db"))
    data_path = data_path or os.environ.get("INTUINT_QUESTIONNAIRES", str(BASE / "data" / "questionnaires.json"))
    api_key = api_key if api_key is not None else os.environ.get("INTUINT_API_KEY", "")
    with open(data_path, encoding="utf-8") as f:
        questionnaires = json.load(f)

    def db():
        if "db" not in g:
            g.db = store.connect(db_path)
        return g.db

    @app.teardown_appcontext
    def close_db(_exc):
        conn = g.pop("db", None)
        if conn is not None:
            conn.close()

    def require_key():
        if not api_key:
            abort(503, "Server has no INTUINT_API_KEY set; writes are disabled.")
        sent = request.headers.get("X-API-Key", "")
        if not hmac.compare_digest(sent, api_key):
            abort(401, "Missing or wrong X-API-Key.")

    def body() -> dict:
        data = request.get_json(silent=True)
        if not isinstance(data, dict):
            abort(400, "Send a JSON object.")
        return data

    @app.errorhandler(400)
    @app.errorhandler(401)
    @app.errorhandler(404)
    @app.errorhandler(409)
    @app.errorhandler(503)
    def error(e):
        return jsonify(error=e.description), e.code

    @app.get("/health")
    def health():
        return {"status": "ok", "tracks": len(questionnaires)}

    @app.get("/occupations")
    def occupations():
        return {"occupations": [{"id": k, "title": v["title"]} for k, v in questionnaires.items()]}

    @app.route("/route", methods=["GET", "POST"])
    def route_job():
        job = request.args.get("job") if request.method == "GET" else body().get("job_title")
        if not job or not str(job).strip():
            abort(400, "Give a job title: ?job=... or {\"job_title\": \"...\"}.")
        result = route(str(job), questionnaires)
        occ = result["occupation"]
        return {"job_title": job, **result,
                "questionnaire": public_questionnaire(occ, questionnaires[occ])}

    @app.get("/questionnaires/<occ_id>")
    def get_questionnaire(occ_id):
        if occ_id not in questionnaires:
            abort(404, f"No questionnaire called '{occ_id}'.")
        return public_questionnaire(occ_id, questionnaires[occ_id])

    @app.post("/answers")
    def seal_answers():
        require_key()
        data = body()
        member_id = str(data.get("member_id", "")).strip()
        occ = data.get("occupation")
        answers = data.get("answers")
        if not member_id:
            abort(400, "member_id is required.")
        if occ not in questionnaires:
            abort(400, "occupation must be one of: " + ", ".join(questionnaires))
        if not isinstance(answers, list) or not answers:
            abort(400, "answers must be a non-empty list.")
        questions = {q["id"]: q for q in questionnaires[occ]["questions"]}
        rows = []
        for a in answers:
            q = questions.get(a.get("question_id"))
            if q is None:
                abort(400, f"Unknown question_id '{a.get('question_id')}' for {occ}.")
            if a.get("gut_answer") not in q["options"]:
                abort(400, f"gut_answer for '{q['id']}' must be one of {q['options']}.")
            if a.get("confidence") not in CONFIDENCE_LEVELS:
                abort(400, f"confidence must be one of {list(CONFIDENCE_LEVELS)}.")
            rows.append((member_id, data.get("job_title"), occ, q["id"],
                         a["gut_answer"], a["confidence"], store.now()))
        conn = db()
        ids = []
        for r in rows:
            cur = conn.execute(
                "INSERT INTO answers (member_id, job_title, occupation, question_id, gut_answer,"
                " confidence, sealed_at) VALUES (?,?,?,?,?,?,?)", r)
            ids.append(cur.lastrowid)
        conn.commit()
        return {"sealed": len(ids), "answer_ids": ids, "sealed_at": rows[0][-1]}, 201

    @app.post("/answers/<int:answer_id>/analysis")
    def record_analysis(answer_id):
        require_key()
        data = body()
        conn = db()
        row = conn.execute("SELECT * FROM answers WHERE id = ?", (answer_id,)).fetchone()
        if row is None:
            abort(404, f"No answer {answer_id}.")
        if row["outcome"] is not None:
            abort(409, "Outcome already recorded; analysis must come before the outcome.")
        if row["analysis_answer"] is not None:
            abort(409, "Analysis already recorded; it's final.")
        q = next(q for q in questionnaires[row["occupation"]]["questions"] if q["id"] == row["question_id"])
        if data.get("answer") not in q["options"]:
            abort(400, f"answer must be one of {q['options']}.")
        if data.get("confidence") not in CONFIDENCE_LEVELS:
            abort(400, f"confidence must be one of {list(CONFIDENCE_LEVELS)}.")
        conn.execute("UPDATE answers SET analysis_answer = ?, analysis_confidence = ?, analysis_at = ? WHERE id = ?",
                     (data["answer"], data["confidence"], store.now(), answer_id))
        conn.commit()
        return {"answer_id": answer_id, "gut_answer": row["gut_answer"], "analysis_answer": data["answer"]}

    @app.post("/answers/<int:answer_id>/outcome")
    def record_outcome(answer_id):
        require_key()
        outcome = body().get("outcome")
        conn = db()
        row = conn.execute("SELECT * FROM answers WHERE id = ?", (answer_id,)).fetchone()
        if row is None:
            abort(404, f"No answer {answer_id}.")
        if row["outcome"] is not None:
            abort(409, "Outcome already recorded; outcomes are final.")
        q = next(q for q in questionnaires[row["occupation"]]["questions"] if q["id"] == row["question_id"])
        if outcome not in q["options"]:
            abort(400, f"outcome must be one of {q['options']}.")
        conn.execute("UPDATE answers SET outcome = ?, outcome_at = ? WHERE id = ?",
                     (outcome, store.now(), answer_id))
        conn.commit()
        an = row["analysis_answer"]
        return {"answer_id": answer_id, "gut_answer": row["gut_answer"], "analysis_answer": an,
                "outcome": outcome, "gut_was_right": row["gut_answer"] == outcome,
                "analysis_was_right": (an == outcome) if an is not None else None}

    @app.get("/members/<member_id>/score")
    def member_score(member_id):
        require_key()
        rows = db().execute("SELECT * FROM answers WHERE member_id = ?", (member_id,)).fetchall()
        resolved = [r for r in rows if r["outcome"] is not None]
        by_level = {}
        brier = 0.0
        for r in resolved:
            hit = r["gut_answer"] == r["outcome"]
            p = r["confidence"] / 100
            brier += (p - hit) ** 2
            lvl = by_level.setdefault(r["confidence"], {"calls": 0, "right": 0})
            lvl["calls"] += 1
            lvl["right"] += hit
        n = len(resolved)
        with_an = [r for r in resolved if r["analysis_answer"] is not None]
        split = [r for r in with_an if r["analysis_answer"] != r["gut_answer"]]
        return {
            "member_id": member_id,
            "sealed": len(rows),
            "resolved": n,
            "gut_accuracy": round(sum(v["right"] for v in by_level.values()) / n, 2) if n else None,
            "analysis_accuracy": round(sum(r["analysis_answer"] == r["outcome"] for r in with_an) / len(with_an), 2) if with_an else None,
            "disagreements": {"count": len(split),
                              "gut_won": sum(r["gut_answer"] == r["outcome"] for r in split),
                              "analysis_won": sum(r["analysis_answer"] == r["outcome"] for r in split)},
            # 0 is perfect; always saying 50% scores 0.25.
            "brier_score": round(brier / n, 3) if n else None,
            "calibration": [
                {"stated_confidence": lvl, "calls": v["calls"],
                 "actually_right": round(v["right"] / v["calls"], 2)}
                for lvl, v in sorted(by_level.items())
            ],
        }

    return app
