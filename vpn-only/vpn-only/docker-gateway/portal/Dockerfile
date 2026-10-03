# User portal: each person sees only their own phone account (behind NPM + Authentik).
FROM python:3.12-slim
RUN apt-get update && apt-get install -y --no-install-recommends openssh-client \
 && rm -rf /var/lib/apt/lists/*
WORKDIR /app
COPY requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt
COPY app.py entrypoint.sh ./
COPY templates ./templates
COPY static ./static
RUN chmod +x entrypoint.sh && useradd -r -u 10002 portal
ENTRYPOINT ["/app/entrypoint.sh"]
