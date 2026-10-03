# Optional Cloudflare dynamic DNS for the public door. Does nothing until CF_API_TOKEN + CF_RECORD are set.
FROM python:3.12-alpine
WORKDIR /app
COPY ddns.py .
RUN adduser -D -H -u 10003 ddns
USER ddns
CMD ["python", "-u", "/app/ddns.py"]
