# Plain slim Python image: unlike mumble-admin (which needs the Ubuntu
# `python3-zeroc-ice` apt package for its Ice binding), this sidecar talks
# raw ServerQuery over a standard-library TCP socket (see app.py's
# `_ServerQueryConnection`) — no compiled extension or apt package needed, so
# a slim image is enough.
FROM python:3.13-slim

WORKDIR /app

COPY requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt

COPY app.py .

# Run the FastAPI process as a non-root user (defense in depth: this
# sidecar holds the TeamSpeak ServerQuery query-account password). Created
# after the pip install so the installed packages stay root-owned/read-only
# for it; `/app` is chowned to it so uvicorn (no writes needed beyond that)
# can still read its own source files. Mirrors mumble-admin's Dockerfile.
RUN useradd --system --create-home --shell /usr/sbin/nologin app \
    && chown -R app:app /app
USER app

EXPOSE 8000

CMD ["uvicorn", "app:app", "--host", "0.0.0.0", "--port", "8000"]
