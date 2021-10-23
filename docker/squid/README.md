Package Squid Proxy
===================

Based on:
* https://launchpad.net/squid-deb-proxy
* https://github.com/pmoust/squid-deb-proxy
* https://raymondc.net/2019/02/22/simple-passthrough-proxy.html

Build:
* `docker build --squash -t wolfpkg/squid -t .`

Run:
* `docker run --name wolfpkg-squid --restart=unless-stopped -d -v /opt/wolfpkg/squid:/cache -p 3124:3128 wolfpkg/squid`

Use in Debian Dockerfile:
* `RUN export HOST_IP=$(cat /proc/net/route | mawk '/^[a-z]+[0-9]+\t00000000/ { printf("%d.%d.%d.%d\n", "0x" substr($3, 7, 2), "0x" substr($3, 5, 2), "0x" substr($3, 3, 2), "0x" substr($3, 1, 2)) }') && echo 'Acquire::http::Proxy "http://'$HOST_IP':3124"; Acquire::https::Proxy "http://'$HOST_IP':3124";' > /etc/apt/apt.conf.d/30autoproxy`

Use in RHEL/CentOS/Fedora/Rocky Dockerfile:
* `RUN export HOST_IP=$(cat /proc/net/route | awk '/^[a-z]+[0-9]+\t00000000/ { printf("%d.%d.%d.%d\n", strtonum("0x" substr($3, 7, 2)), strtonum("0x" substr($3, 5, 2)), strtonum("0x" substr($3, 3, 2)), strtonum("0x" substr($3, 1, 2))) }') && echo "proxy=http://$HOST_IP:3124" >> /etc/dnf/dnf.conf`
