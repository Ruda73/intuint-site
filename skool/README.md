# Know the Score (Skool community)

Everything for Intuint's Skool group, *Know the Score: Get Better at Your Job*.

## Contents

- `router/`: job router API. It matches a member's job title to their five-call questionnaire, seals gut answers, records outcomes and scores calibration. Flask and SQLite, with tests. Deployment steps are in `router/README.md`.

## Content (live docs)

The written content lives in two editable docs, so it isn't duplicated here and can't drift out of date:

- **Know the Score: Skool Launch Content**: group setup, About page, Classroom modules 1 and 3 (The De-biaser), weeks 1 and 2 of feed posts, launch checklist. https://claude.ai/artifact/LqaGpUzhATNbyuhTsd3o2Y
- **What Do They Do for a Living? Starter Snippet Set**: 20 guess-the-job snippets with answers and clue breakdowns. https://claude.ai/artifact/MZspdWMuXhUm4KF58K3hjT

## Important: don't upload `router/` to the public web folder

The router is a Python app, not part of the static site. Deploy it separately through cPanel's **Setup Python App** (for example on `api.intuint.co.za`), with its database stored outside `public_html`. If you upload this repo to the website as-is, leave out `skool/router/`.

## The member page: `/start/`

`start/` in the site root is the page Lesson 1.2 links to: https://www.intuint.co.za/start/ (add `?job=...` to prefill the job). Members type their job, get their five calls, seal their gut answers, and later log outcomes and see their own score. It's plain HTML and PHP, like the survey, so it runs on the normal hosting with nothing to install.

- Sealed calls are saved to `~/intuint-data/start-sealed.jsonl` (outside the public folder) and emailed to rudolph@intuint.co.za.
- After sealing, members check their numbers and lock an analysis answer per call (`~/intuint-data/start-analysis.jsonl`). The order is fixed: gut → analysis → outcome.
- Members get a personal link (`/start/?ref=...`, also emailed if they give an email) that reloads their calls on any device. Seals that fail to send are retried automatically next visit; `client_sealed_at` marks those (it's the browser's clock, so not proof of timing).
- Name and email are only stored with the member's consent (recorded in the seal).
- Outcomes go to `~/intuint-data/start-outcomes.jsonl`. Only the browser that sealed a call can add its analysis or outcome, each only once. The score shows gut accuracy, analysis accuracy, and which one won when they disagreed.
- The page routes jobs in the browser with `start/route.js`, which follows the same rules as the Python router.

**Editing questions:** Each call has `know_in_days`; keep at least three per track at 14 days or less (a test checks this) so a new member's first scorecard isn't empty. `exclude` phrases stop a track matching (e.g. "casino" for mining).  change `skool/router/data/questionnaires.json`, then copy it to `start/questionnaires.json`. A router test fails if the two copies differ.
