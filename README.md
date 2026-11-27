# Intuint website

Source for [intuint.co.za](https://www.intuint.co.za), Intuint's decision-intelligence site.

**Judge → Measure → Grow**

- `index.html`: homepage
- `survey/`: leadership survey
- `demo/`: demo dashboard (Calibration Desk)
- `noise-audit/`: Exco Noise Audit (self-contained; runs entirely in the browser, no data is sent anywhere)
- `start/`: "Your five calls", the Know the Score member page (job → five calls → sealed gut answers → outcomes and score). Saves to `~/intuint-data/` via `submit.php`, like the survey
- `skool/`: Know the Score Skool community, including the job router API (`skool/router/`, a Python app deployed separately, not part of the static site)

Hosted on domains.co.za. Private repository.
