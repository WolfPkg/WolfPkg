patch -p1 < ${AUTOPKG_PKG_DEF_PATH}/debian/patches/hfst_02_notimestamp.diff

autoreconf -fvi
./configure --enable-all-tools --disable-static --with-readline --with-unicode-handler=icu --with-openfst-upstream --with-foma-upstream --enable-python-bindings
