# Kamailio front door for API users. Debian 13 (trixie) to match the PBX OS.
FROM debian:trixie-slim
RUN apt-get update \
 && DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends kamailio gettext-base \
 && rm -rf /var/lib/apt/lists/*
# ZOIPER-PRO-TODO: add kamailio-tls-modules here for public TLS.
COPY kamailio.cfg.tpl entrypoint.sh /etc/kamailio/
RUN chmod +x /etc/kamailio/entrypoint.sh
ENTRYPOINT ["/etc/kamailio/entrypoint.sh"]
