{{-- Team invitation email. See Mail\TeamInvitationMail. --}}
<p>{{ __('monitor::messages.email.invitation_intro_before', ['inviter' => $inviterName, 'app' => config('app.name', 'Laravel')]) }} <strong>{{ ucfirst($role) }}</strong>{{ __('monitor::messages.email.invitation_intro_after') }}</p>
<p><a href="{{ $acceptUrl }}">{{ __('monitor::messages.email.invitation_link') }}</a></p>
<p>{{ __('monitor::messages.email.expires_in_2_hours') }}</p>
