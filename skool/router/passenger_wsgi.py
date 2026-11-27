# Entry point for cPanel "Setup Python App" (Passenger). Set the startup file
# to passenger_wsgi.py and the entry point to "application".
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))

from app.main import create_app  # noqa: E402

application = create_app()
