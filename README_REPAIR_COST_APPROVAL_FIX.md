# REDLINE – Fix Approve Repair Costs (2026-11-21)

## Lỗi đã sửa
- Repair Orders hoàn tất với tổng phí 0 VND không thể phê duyệt nếu chọn Standard service.
- Biểu mẫu không tự chọn Zero-cost/warranty và thiếu hướng dẫn bắt buộc nhập lý do.
- Lỗi server hiển thị không rõ hoặc điều hướng khỏi panel Repair Order Detail.

## File cập nhật
- `REDLINE/www/admin/orders/detail.php`: tự chọn Zero-cost nếu tổng chi phí là 0; kiểm tra lý do; gửi duyệt qua AJAX và hiển thị lỗi tại chỗ, không reload trang cha.
- `REDLINE/www/api/orders/review_costs.php`: API JSON hỗ trợ phản hồi rõ ràng; giữ POST/Redirect/GET dự phòng cùng `embed=1`.
- `REDLINE/www/includes/repair_cost_review.php`: gom các quy tắc xác nhận phí duyệt phía server, giữ kiểm tra tồn kho và lịch sử.
- `REDLINE/www/tests/cost_review_approval_test.php`: kiểm thử trường hợp miễn phí, có phí và chưa xử lý phụ tùng.

## Cách áp dụng
1. Sao lưu dự án và database.
2. Bản FULL: giải nén dự án hoàn chỉnh.
3. Bản PATCH: chép đè các file `REDLINE/www/...` đúng vị trí tương ứng vào dự án hiện tại.
4. Làm mới trang Admin > Appointments và mở lại Repair Order Detail.
5. Nếu tổng chi phí 0 VND, chọn Zero-cost / warranty (tự chọn), nhập lý do dài ít nhất 10 ký tự rồi Approve Repair Costs.
6. Nếu có chi phí thực, tạo labor/parts hợp lệ trước khi dùng Standard service.

## Lưu ý
- Không tự động đổi cấu trúc database. Hệ thống cần bảng `repair_order_cost_reviews` từ schema hoặc migration hiện có (`www/database/migrations/20261115_workshop_updates.sql`). Nếu bảng chưa được cài, xem thông báo trên trang và triển khai migration theo quy trình sao lưu riêng.
- Không dùng cách bỏ qua duyệt hoặc cố tình tạo chi phí ảo để phê duyệt.
- Đã chạy PHP lint, JavaScript syntax check, kiểm thử quy tắc phí và các test tĩnh liên quan. Chưa kiểm thử end-to-end với MySQL thật trên máy chủ.
