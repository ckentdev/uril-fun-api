<!doctype html>
<html>
<body style="font-family: Arial, sans-serif; color: #111; line-height: 1.5;">
    <h2 style="color: #dc2626;">Welcome, {{ $user->name }}!</h2>
    <p>Your {{ config('app.name') }} account is ready. Open the app to try authentication, email, the AI playground and the chatbot.</p>
    <p><a href="{{ config('app.frontend_url') }}" style="color: #dc2626;">Open {{ config('app.name') }}</a></p>
</body>
</html>
