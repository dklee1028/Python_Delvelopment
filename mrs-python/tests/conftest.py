import os
import sys
import tempfile
from pathlib import Path

_tmp = tempfile.mkdtemp(prefix="mrs_test_")
os.environ["MRS_DB_URL"] = f"sqlite:///{_tmp}/test.db"
os.environ["MRS_DATA_DIR"] = f"{_tmp}/data"
os.environ["MRS_ADMIN_PASSWORD"] = "admin1234"
os.environ["MRS_ENV_FILE"] = f"{_tmp}/none.env"
os.environ["ANTHROPIC_API_KEY"] = ""
os.environ["MRS_SMTP_HOST"] = ""
sys.path.insert(0, str(Path(__file__).resolve().parent.parent))

import pytest  # noqa: E402
from fastapi.testclient import TestClient  # noqa: E402


@pytest.fixture(scope="session")
def client():
    from app.main import app

    with TestClient(app, base_url="http://testserver") as c:
        r = c.post("/mms/api/auth/login", json={"username": "admin", "password": "admin1234"})
        assert r.status_code == 200, r.text
        yield c
