# Intuint job router API

Routes a member's job title to the matching "big calls" questionnaire, seals their gut answers, records outcomes, and scores how well-calibrated their gut is.

## How it fits the Skool group

1. Make "What do you do for a living?" one of the Skool membership questions.
2. In Classroom module 1, link each member to a URL such as `https://api.intuint.co.za/route?job=SPAR%20owner`; a small form page on intuint.co.za can call the same URL.
3. The member seals five gut answers (`POST /answers`) and records outcomes later (`POST /answers/<id>/outcome`).
4. `GET /members/<id>/score` gives the numbers for the monthly calibration leaderboard.

## Tracks

| Occupation id | Who it's for |
|---|---|
| `retail_store` | store owners, managers, SPAR franchisees |
| `mining_operations` | shift bosses, engineers, plant and safety people |
| `sales` | reps, account managers, business development, agents |
| `people_manager` | team leads, supervisors, HR |
| `general` | fallback for anything unmatched or too vague |

To add a track or reword a question, edit `data/questionnaires.json`. No code change is needed. Domain words weigh 3, generic words like "manager" weigh 1, so "store manager" goes to retail and "sales manager" goes to sales.

## Endpoints

```
GET  /health
GET  /occupations
GET  /route?job=...                  or POST /route {"job_title": "..."}
GET  /questionnaires/<occupation>

# need header X-API-Key
POST /answers
     {"member_id": "skool-123", "job_title": "SPAR owner", "occupation": "retail_store",
      "answers": [{"question_id": "promo_order", "gut_answer": "More", "confidence": 90}]}
POST /answers/<id>/analysis {"answer": "Same", "confidence": 70}
POST /answers/<id>/outcome  {"outcome": "Less"}
GET  /members/<member_id>/score
```

Confidence is 50, 70 or 90. Order is fixed: gut (sealed) → analysis (optional, once, before the outcome) → outcome (once). The score adds analysis accuracy and, where gut and analysis disagreed, which one won. The score includes gut accuracy, a Brier score (0 is perfect, 0.25 is what always saying 50% earns), and calibration per confidence level.

## Run locally

```
pip install -r requirements.txt pytest
python -m pytest -q
INTUINT_API_KEY=choose-a-long-secret flask --app passenger_wsgi:application run
```

## Deploy on cPanel shared hosting (domains.co.za)

1. In cPanel, open **Setup Python App** and create an app (Python 3.11+). Set the application root to, say, `intuint-router`, and the URL to a subdomain such as `api.intuint.co.za`.
2. Upload these files to the application root.
3. Set the startup file to `passenger_wsgi.py` and the entry point to `application`.
4. Add the environment variable `INTUINT_API_KEY` (a long random secret). Optionally set `INTUINT_DB` to a path outside the public web folder.
5. Run pip install from `requirements.txt`, then restart the app.

If **Setup Python App** doesn't appear in your cPanel, the Web Hosting Basic plan may not include Python. Ask domains.co.za support before upgrading.

Keep the API key server-side (in n8n or the Intuint app). Never put it in a public web page.
