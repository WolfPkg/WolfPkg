FROM amd64/debian:sid

ENV LANG=C.UTF-8 \
	LC_ALL=C.UTF-8 \
	DEBIAN_FRONTEND=noninteractive \
	DEBCONF_NONINTERACTIVE_SEEN=true

RUN apt-get -q update && \
    apt-get install -qfy --no-install-recommends \
      squid ca-certificates && \
    apt-get clean && \
    rm -rf /var/lib/apt/lists/* /tmp/* /var/tmp/* && \
    mkdir -pv /cache

RUN cat /etc/squid/squid.conf | egrep -v '^(cache_dir|cache_access_log|access_log|cache_log|cache_store_log|cache_replacement_policy|maximum_object_size|refresh_pattern)' >/etc/squid/pkg.conf

EXPOSE 3128

COPY pkg.conf /etc/squid/conf.d/pkg.conf
COPY start.sh /start.sh
RUN chmod +x /start.sh
CMD /start.sh
