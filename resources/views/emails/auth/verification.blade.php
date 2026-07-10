<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Verify your email address</title>
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
        <p>Thank you for signing up. Please verify your email address by clicking the button below.</p>
        <p>This link will expire in <strong>24 hours</strong>.</p>
        <p>
            <a href="{{ $verificationLink }}" class="btn">Verify Email Address</a>
        </p>
        <p>Or copy and paste this link into your browser:</p>
        <p style="word-break: break-all; color: #555;">{{ $verificationLink }}</p>
        <div class="footer">
            <p>If you did not create an account, no further action is required.</p>
        </div>
    </div>
</body>
</html>
