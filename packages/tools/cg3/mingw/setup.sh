$AUTOPKG_BITWIDTH-w64-mingw32.shared-cmake -DCMAKE_INSTALL_PREFIX=/opt/win32 .
mkdir -p /opt/$AUTOPKG_WINX-pkg/$PKG_NAME/opt/win32/bin/
ln -s vislcg3.exe /opt/$AUTOPKG_WINX-pkg/$PKG_NAME/opt/win32/bin/cg3.exe
