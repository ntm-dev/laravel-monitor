{{-- Email-change verification email. See Mail\EmailChangeVerificationMail. --}}
<p>{{ __('monitor::messages.email.change_verification_intro', ['app' => config('app.name', 'Laravel')]) }}</p>
<p><a href="{{ $verifyUrl }}">{{ __('monitor::messages.email.change_verification_link') }}</a></p>
<p>{{ __('monitor::messages.email.expires_in_60_minutes') }}</p>
