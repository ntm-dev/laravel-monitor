{{-- Email-change verification email. See Mail\EmailChangeVerificationMail. --}}
<p>Confirm this is your email address to finish updating your {{ config('app.name', 'Laravel') }} Monitor account.</p>
<p><a href="{{ $verifyUrl }}">Verify this email address</a></p>
<p>This link expires in 60 minutes.</p>
