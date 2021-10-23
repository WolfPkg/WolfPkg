#!/bin/bash

chown -R proxy:proxy /cache
chown proxy:proxy /dev/stdout

if [ ! -e /cache/00 ]; then
	/usr/sbin/squid -z -N -f /etc/squid/pkg.conf
fi

/usr/sbin/squid -N -f /etc/squid/pkg.conf
