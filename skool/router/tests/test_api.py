import pytest

from app.main import create_app

KEY = {"X-API-Key": "test-key"}


@pytest.fixture
def client(tmp_path):
    app = create_app(db_path=str(tmp_path / "t.db"), api_key="test-key")
    return app.test_client()


@pytest.mark.parametrize("job, expected", [
    ("SPAR franchisee", "retail_store"),
    ("Store Manager", "retail_store"),
    ("supermaket owner", "retail_store"),          # typo
    ("Shift boss - underground", "mining_operations"),
    ("Mine manager", "mining_operations"),
    ("Reliability engineer, processing plant", "mining_operations"),
    ("Sales manager", "sales"),
    ("Key Account Manager", "sales"),
    ("Estate agent", "sales"),
    ("Team leader", "people_manager"),
    ("HR manager", "people_manager"),
    ("Manager", "general"),                        # too vague on its own
    ("Teacher", "general"),
    ("", None),
])
def test_routing(client, job, expected):
    r = client.post("/route", json={"job_title": job})
    if expected is None:
        assert r.status_code == 400
        return
    assert r.status_code == 200, r.json
    assert r.json["occupation"] == expected, r.json
    assert len(r.json["questionnaire"]["questions"]) == 5
    assert "keywords" not in r.json["questionnaire"]


def test_route_by_link(client):
    r = client.get("/route?job=shop%20owner")
    assert r.json["occupation"] == "retail_store"


def test_writes_need_key(client):
    assert client.post("/answers", json={}).status_code == 401
    assert client.get("/members/m1/score").status_code == 401


def test_seal_outcome_score(client):
    r = client.post("/answers", headers=KEY, json={
        "member_id": "skool-123", "job_title": "SPAR owner", "occupation": "retail_store",
        "answers": [
            {"question_id": "promo_order", "gut_answer": "More", "confidence": 90},
            {"question_id": "weekend_staff", "gut_answer": "No", "confidence": 70},
        ]})
    assert r.status_code == 201
    a, b = r.json["answer_ids"]

    assert client.post(f"/answers/{a}/outcome", headers=KEY, json={"outcome": "Less"}).json["gut_was_right"] is False
    assert client.post(f"/answers/{b}/outcome", headers=KEY, json={"outcome": "No"}).json["gut_was_right"] is True
    # outcomes are final
    assert client.post(f"/answers/{a}/outcome", headers=KEY, json={"outcome": "More"}).status_code == 409

    s = client.get("/members/skool-123/score", headers=KEY).json
    assert s["resolved"] == 2 and s["gut_accuracy"] == 0.5
    # (0.9-0)^2 + (0.7-1)^2 = 0.81 + 0.09 = 0.90 / 2
    assert s["brier_score"] == 0.45


def test_rejects_bad_answers(client):
    bad = {"member_id": "m", "occupation": "retail_store",
           "answers": [{"question_id": "promo_order", "gut_answer": "Lots", "confidence": 90}]}
    assert client.post("/answers", headers=KEY, json=bad).status_code == 400
    bad["answers"][0].update(gut_answer="More", confidence=80)
    assert client.post("/answers", headers=KEY, json=bad).status_code == 400
