# Ubuntu 24.04 (not a Python slim image): the Ice Python binding
# (zeroc-ice) has no manylinux wheel on PyPI (source dist only, needs a
# matching Ice ABI + a C++ toolchain to build). Ubuntu noble ships a
# prebuilt `python3-zeroc-ice` apt package (universe, Ice 3.7.10) that
# matches the Ice ABI baked into the official `mumblevoip/mumble-server`
# image (`libzeroc-ice3.7t64`), so we install it via apt instead of pip.
FROM ubuntu:24.04

ARG DEBIAN_FRONTEND=noninteractive

RUN apt-get update && apt-get install --no-install-recommends -y \
    python3 \
    python3-pip \
    python3-zeroc-ice \
    zeroc-ice-slice \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app

COPY requirements.txt .
# --break-system-packages: this image is single-purpose (this sidecar only),
# so we install directly into the distro's Python instead of a venv, the
# same interpreter python3-zeroc-ice (an apt package) is registered against.
RUN pip3 install --no-cache-dir --break-system-packages -r requirements.txt

COPY Murmur.ice app.py .

# Run the FastAPI process as a non-root user (defense in depth: this
# sidecar holds the Mumble Ice admin secret). Created after the apt/pip
# installs so the installed packages stay root-owned/read-only for it;
# `/app` is chowned to it so uvicorn (no writes needed beyond that) can
# still read its own source files.
RUN useradd --system --create-home --shell /usr/sbin/nologin app \
    && chown -R app:app /app
USER app

EXPOSE 8000

CMD ["uvicorn", "app:app", "--host", "0.0.0.0", "--port", "8000"]
