#!/bin/bash
# Phát hành bản mới: ./release.sh 1.2.1 "Sửa lỗi ..."  -> đổi số phiên bản, commit, tag, tạo GitHub Release kèm zip
set -e
cd "$(dirname "$0")"
V="$1"; NOTE="${2:-Cập nhật}"
[ -n "$V" ] || { echo "Cách dùng: ./release.sh X.Y.Z \"ghi chú\""; exit 1; }
sed -i '' -E "s/define\('LCC_VER', '[0-9.]+'\);/define('LCC_VER', '$V');/; s/^ \* Version: .*/ * Version: $V/" lucas-cham-cong.php
git add -A && git commit -qm "v$V: $NOTE" && git tag "v$V" && git push -q && git push -q --tags
TMP=$(mktemp -d); mkdir "$TMP/lucas-cham-cong"
git archive HEAD | tar -x -C "$TMP/lucas-cham-cong"
rm -f "$TMP/lucas-cham-cong/release.sh" "$TMP/lucas-cham-cong/.gitignore" "$TMP/lucas-cham-cong/README.md"
(cd "$TMP" && zip -qr lucas-cham-cong.zip lucas-cham-cong)
gh release create "v$V" "$TMP/lucas-cham-cong.zip" --title "v$V" --notes "$NOTE"
rm -rf "$TMP"; echo "Đã phát hành v$V — web sẽ thấy bản mới trong vài giờ (hoặc bấm 'Kiểm tra cập nhật ngay' trong wp-admin > Chấm công)."
