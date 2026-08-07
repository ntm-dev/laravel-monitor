{{-- Password reset email. See Mail\PasswordResetMail. --}}
<p>{{ __('monitor::messages.email.password_reset_intro', ['app' => config('app.name', 'Laravel')]) }}</p>
<p><a href="{{ $resetUrl }}">{{ __('monitor::messages.email.password_reset_link') }}</a></p>
<p>{{ __('monitor::messages.email.password_reset_ignore') }}</p>
<p>{{ __('monitor::messages.email.expires_in_60_minutes') }}</p>
