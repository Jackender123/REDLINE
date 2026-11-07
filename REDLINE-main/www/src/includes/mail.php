<?php
// www/src/includes/mail.php - Module Gửi Email Thông Báo REDLINE Motorsport ERP

/**
 * Hàm gửi email chuẩn HTML
 */
function sendGaraEmail($toEmail, $toName, $subject, $bodyHtml) {
    if (empty($toEmail) || !filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
        return false;
    }

    $headers  = "MIME-Version: 1.0" . "\r\n";
    $headers .= "Content-type:text/html;charset=UTF-8" . "\r\n";
    $headers .= "From: REDLINE Motorsport <no-reply@redlinemotorsport.vn>" . "\r\n";

    // Gửi qua hàm mail() hệ thống (hoặc tích hợp PHPMailer SMTP)
    return @mail($toEmail, $subject, $bodyHtml, $headers);
}

/**
 * Gửi email xác nhận khi khách đặt lịch trực tuyến
 */
function sendAppointmentConfirmationEmail($toEmail, $customerName, $serviceType, $requestTime) {
    $subject = "[REDLINE MOTORSPORT] Xác nhận đặt lịch hẹn dịch vụ thành công";
    $body = "
    <div style='font-family: Arial, sans-serif; background-color: #0D0D0F; color: #ffffff; padding: 24px; border-radius: 8px;'>
        <h2 style='color: #FF3B00; margin-top:0;'>/// REDLINE MOTORSPORT</h2>
        <p>Xin chào <strong>" . htmlspecialchars($customerName) . "</strong>,</p>
        <p>Gara đã nhận được yêu cầu đặt lịch bảo dưỡng & sửa chữa xe của quý khách.</p>
        <table style='width:100%; border-collapse:collapse; margin: 15px 0; background-color: #18181C; border: 1px solid #27272A;'>
            <tr><td style='padding: 10px; border-bottom: 1px solid #27272A; color:#A1A1AA;'>Dịch vụ:</td><td style='padding: 10px; border-bottom: 1px solid #27272A; color:#ffffff;'><strong>" . htmlspecialchars($serviceType) . "</strong></td></tr>
            <tr><td style='padding: 10px; color:#A1A1AA;'>Thời gian gửi:</td><td style='padding: 10px; color:#ffffff;'>" . htmlspecialchars($requestTime) . "</td></tr>
        </table>
        <p>Cố vấn dịch vụ sẽ liên hệ phản hồi xác nhận giờ tiếp nhận trong vòng 15 phút.</p>
        <hr style='border: 0; border-top: 1px solid #27272A; margin: 20px 0;'>
        <p style='font-size: 11px; color: #A1A1AA;'>Trụ sở: 123 Đường 3/2, Q. Ninh Kiều, TP. Cần Thơ | Hotline: 0901.234.567</p>
    </div>";

    return sendGaraEmail($toEmail, $customerName, $subject, $body);
}

/**
 * Gửi email thông báo khi xe đã hoàn tất sửa chữa (Chờ nhận xe)
 */
function sendVehicleReadyEmail($toEmail, $customerName, $licensePlate, $orderCode, $totalAmount) {
    $subject = "[REDLINE MOTORSPORT] Thông báo xe [{$licensePlate}] đã hoàn tất dịch vụ";
    $body = "
    <div style='font-family: Arial, sans-serif; background-color: #0D0D0F; color: #ffffff; padding: 24px; border-radius: 8px;'>
        <h2 style='color: #FF3B00; margin-top:0;'>/// REDLINE MOTORSPORT - SẴN SÀNG BÀN GIAO</h2>
        <p>Xin chào <strong>" . htmlspecialchars($customerName) . "</strong>,</p>
        <p>Xe ô tô biển số <strong style='color: #facc15;'>" . htmlspecialchars($licensePlate) . "</strong> (Mã phiếu: <strong>" . htmlspecialchars($orderCode) . "</strong>) đã hoàn thành toàn bộ công đoạn kiểm tra & sửa chữa.</p>
        <p><strong>Tổng chi phí dịch vụ:</strong> <span style='color: #34d399; font-size: 16px; font-weight: bold;'>" . number_format($totalAmount, 0, ',', '.') . " VNĐ</span></p>
        <p>Kính mời quý khách đến gara nhận xe và nghiệm thu chất lượng dịch vụ.</p>
        <hr style='border: 0; border-top: 1px solid #27272A; margin: 20px 0;'>
        <p style='font-size: 11px; color: #A1A1AA;'>Tra cứu tiến độ trực tuyến tại: <a href='https://redlinemotorsport.vn/track.php' style='color:#FF3B00;'>www.redlinemotorsport.vn/track.php</a></p>
    </div>";

    return sendGaraEmail($toEmail, $customerName, $subject, $body);
}