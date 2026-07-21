{{-- Password reset email. See Mail\PasswordResetMail. --}}
<p>Someone requested a password reset for your {{ config('app.name', 'Laravel') }} Monitor account.</p>
<p><a href="{{ $resetUrl }}">Reset your password</a></p>
<p>If you didn't request this, you can safely ignore this email.</p>
<p>This link expires in 60 minutes.</p>
