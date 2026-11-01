# Plain slim Python image. The sidecar speaks the Denon telnet control
# protocol over the standard-library socket module, so no compiled extension
# or apt package is needed (same reasoning as docker/teamspeak-admin).
FROM python:3.13-slim

WORKDIR /app

COPY requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt

COPY app.py .

# Run the FastAPI process as a non-root user (defense in depth: the sidecar
# holds the bearer token and the receiver's control session). Created after
# the pip install so the installed packages stay root-owned/read-only for it.
RUN useradd --system --create-home --shell /usr/sbin/nologin app \
    && chown -R app:app /app
USER app

EXPOSE 8000

# /healthz needs no auth and answers 200 while the process serves requests
# (it reports the telnet state in the body but does not fail on it).
HEALTHCHECK --interval=30s --timeout=5s --start-period=10s --retries=3 \
    CMD python -c "import urllib.request; urllib.request.urlopen('http://127.0.0.1:8000/healthz', timeout=3)"

CMD ["uvicorn", "app:app", "--host", "0.0.0.0", "--port", "8000"]
