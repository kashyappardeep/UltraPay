<!DOCTYPE html>
<html>
<body style="margin:0;padding:24px;background:#f4f4f4;font-family:Arial,sans-serif;">
  <table width="100%" cellpadding="0" cellspacing="0" style="max-width:480px;margin:0 auto;background:#ffffff;border-radius:12px;">
    <tr>
      <td style="padding:28px;">
        <h2 style="margin:0 0 8px;color:#0F6E56;">UltraPay</h2>
        <p style="margin:0 0 20px;color:#333;font-size:15px;">Your verification code:</p>
        <p style="margin:0 0 20px;font-size:34px;font-weight:bold;letter-spacing:8px;color:#111;">{{ $code }}</p>
        <p style="margin:0 0 8px;color:#555;font-size:14px;">This code will expire in {{ $minutes }} minute(s).</p>
        <p style="margin:0;color:#9A3412;font-size:13px;">
          Do not share this code with anyone. UltraPay will never call or message you to ask for your OTP or PIN.
        </p>
      </td>
    </tr>
  </table>
  <p style="text-align:center;color:#999;font-size:12px;">If you did not request this, please ignore this email.</p>
</body>
</html>