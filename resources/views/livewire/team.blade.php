<div>
    {{-- Minimal placeholder: Task 6 builds out the real Team dashboard view. --}}
    <ul>
        @foreach ($members as $member)
            <li>{{ $member->email }}</li>
        @endforeach
    </ul>
    <ul>
        @foreach ($pendingInvitations as $invitation)
            <li>{{ $invitation->email }}</li>
        @endforeach
    </ul>
</div>
