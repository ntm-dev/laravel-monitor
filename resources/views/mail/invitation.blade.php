{{-- Team invitation email. See Mail\TeamInvitationMail. --}}
<p>{{ $inviterName }} invited you to join the {{ config('app.name', 'Laravel') }} Monitor dashboard as <strong>{{ ucfirst($role) }}</strong>.</p>
<p><a href="{{ $acceptUrl }}">Accept the invitation</a></p>
<p>This link expires in 2 hours.</p>
