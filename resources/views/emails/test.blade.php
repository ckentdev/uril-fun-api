<!doctype html>
<html>
<body style="font-family: Arial, sans-serif; color: #111; line-height: 1.5;">
    <h2 style="color: #dc2626; margin-bottom: 8px;">{{ config('app.name') }} &middot; test email</h2>
    <div style="white-space: pre-wrap;">{{ $body }}</div>
    <hr style="border: 0; border-top: 1px solid #e5e7eb; margin: 24px 0;">
    <p style="font-size: 12px; color: #6b7280;">
        Sent from the {{ config('app.name') }} demo by {{ $senderName }}.
        This is a test message; if you did not expect it you can ignore it.
    </p>
</body>
</html>
