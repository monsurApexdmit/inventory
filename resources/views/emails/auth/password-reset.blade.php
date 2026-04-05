<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Reset your password</title>
    <style>
        body { font-family: Arial, sans-serif; background: #f4f4f4; margin: 0; padding: 20px; }
        .container { max-width: 600px; margin: 0 auto; background: #ffffff; padding: 40px; border-radius: 8px; }
        .btn { display: inline-block; padding: 14px 28px; background: #4F46E5; color: #ffffff; text-decoration: none; border-radius: 6px; font-weight: bold; }
        .footer { margin-top: 30px; font-size: 12px; color: #888888; }
    </style>
</head>
<body>
    <div class="container">
        <h2>Hi {{ $fullName }},</h2>
        <p>We received a request to reset your password. Click the button below to set a new password.</p>
        <p>This link will expire in <strong>1 hour</strong>.</p>
        <p>
            <a href="{{ $resetLink }}" class="btn">Reset Password</a>
        </p>
        <p>Or copy and paste this link into your browser:</p>
        <p style="word-break: break-all; color: #555;">{{ $resetLink }}</p>
        <div class="footer">
            <p>If you did not request a password reset, no further action is required.</p>
        </div>
    </div>
</body>
</html>
