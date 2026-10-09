FROM docker:27-cli

RUN apk add --no-cache ca-certificates docker-cli-compose python3

ENV PYTHONDONTWRITEBYTECODE=1 \
    PYTHONUNBUFFERED=1 \
    DOCKER_HOST=unix:///var/run/docker.sock \
    DOCKER_CONFIG=/tmp/docker

WORKDIR /app
COPY docker/updater/updater.py /app/updater.py

CMD ["python3", "/app/updater.py"]

HEALTHCHECK --interval=30s --timeout=5s --start-period=20s --retries=5 \
    CMD python3 -c "import urllib.request; urllib.request.urlopen('http://127.0.0.1:8765/health', timeout=2).read()" || exit 1
