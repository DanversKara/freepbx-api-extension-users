FROM python:3.12-slim
RUN apt-get update && apt-get install -y --no-install-recommends openssh-client \
 && rm -rf /var/lib/apt/lists/*
WORKDIR /app
COPY requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt
COPY app.py entrypoint.sh ./
COPY templates ./templates
COPY static ./static
RUN chmod +x entrypoint.sh && useradd -r -u 10001 panel
ENTRYPOINT ["/app/entrypoint.sh"]
