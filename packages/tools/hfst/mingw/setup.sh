patch_all
export ICU_CONFIG=no
autoreconf -fi
./configure --disable-static --enable-all-tools --with-unicode-handler=icu --host=$AUTOPKG_BITWIDTH-w64-mingw32.shared --prefix=/opt/win32
