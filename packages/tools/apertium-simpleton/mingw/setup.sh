/opt/mxe/usr/$AUTOPKG_BITWIDTH-w64-mingw32.shared/qt5/bin/qmake apertium-simpleton.pro PREFIX=/opt/win32
QT5="../qt5/bin"
export EXTRA_DEPS="7z.exe 7z.dll 7z-License.txt $EXTRA_DEPS"
export EXTRA_INST="release/apertium-simpleton.exe"

mkdir -pv /opt/$AUTOPKG_WINX-pkg/$PKG_NAME/opt/win32/bin
rsync -av /opt/mxe/usr/$AUTOPKG_BITWIDTH-w64-mingw32.shared/qt5/plugins/bearer /opt/$AUTOPKG_WINX-pkg/$PKG_NAME/opt/win32/bin/
rsync -av /opt/mxe/usr/$AUTOPKG_BITWIDTH-w64-mingw32.shared/qt5/plugins/platforms /opt/$AUTOPKG_WINX-pkg/$PKG_NAME/opt/win32/bin/
