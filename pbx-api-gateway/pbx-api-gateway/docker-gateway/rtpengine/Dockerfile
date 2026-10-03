# Audio relay. Userspace only (--table=-1): an LXC can't load the kernel module.
FROM debian:trixie-slim
RUN apt-get update \
 && DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends rtpengine-daemon curl ca-certificates \
 && rm -rf /var/lib/apt/lists/*
COPY entrypoint.sh pubip.sh /
RUN chmod +x /entrypoint.sh
ENTRYPOINT ["/entrypoint.sh"]
