# Lucas Chấm Công

Plugin WordPress chấm công cho Lucas Combo (lucas.vn/cham-cong).

- Nhân viên đăng nhập bằng mã PIN, bấm **Vào ca / Ra ca** — chỉ nhận khi đang dùng **wifi tiệm**.
- Loại ca tự xác định theo giờ thật (Full 9–20h, ca thường, nửa buổi, giờ làm thêm); trễ ≤ 15' vẫn đủ ca.
- Chủ shop: wp-admin → **Chấm công** (wifi tiệm, sửa ca, thưởng/ứng/khấu trừ, duyệt phiếu lương).
- Tool tính lương (repo riêng tư) đọc dữ liệu qua REST `lcc/v1/admin/*` bằng khoá API.

**Cập nhật:** mỗi bản mới là một GitHub Release `vX.Y.Z` kèm `lucas-cham-cong.zip`; plugin tự thấy bản mới
trong wp-admin → Plugin. Phát hành: `./release.sh X.Y.Z "ghi chú"`.

Không có bí mật trong repo này: khoá API chỉ nằm trong `lcc-config.php` của bản cài đầu (bị `.gitignore`).
