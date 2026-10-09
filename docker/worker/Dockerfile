FROM python:3.13-slim

ENV PYTHONDONTWRITEBYTECODE=1 \
    PYTHONUNBUFFERED=1

WORKDIR /app

COPY worker/requirements.txt /app/requirements.txt
RUN pip install --no-cache-dir -r /app/requirements.txt

COPY worker /app
COPY VERSION /app/VERSION

RUN groupadd --gid 10001 trakfog \
    && useradd --create-home --uid 10001 --gid 10001 trakfog \
    && chown -R trakfog:trakfog /app

USER trakfog

CMD ["python", "-m", "trakfog_worker"]

HEALTHCHECK --interval=30s --timeout=5s --start-period=30s --retries=5 \
    CMD ["python", "/app/healthcheck.py"]
