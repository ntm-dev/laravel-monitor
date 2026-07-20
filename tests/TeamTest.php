<?php

namespace LaravelMonitor\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use LaravelMonitor\Livewire\Team;
use LaravelMonitor\Mail\TeamInvitationMail;
use LaravelMonitor\Models\MonitorInvitation;
use LaravelMonitor\Models\MonitorUser;
use Livewire\Livewire;

class TeamTest extends TestCase
{
    use RefreshDatabase;

    public function test_data_lists_members_and_pending_invitations(): void
    {
        $viewer = MonitorUser::create([
            'name' => 'Existing Viewer', 'email' => 'existing-viewer@example.com',
            'password' => Hash::make('password'), 'role' => 'viewer',
        ]);
        $owner = MonitorUser::where('email', 'owner@example.com')->firstOrFail();
        MonitorInvitation::createFor('pending@example.com', 'admin', $owner);

        $component = Livewire::test(Team::class);

        $memberEmails = $component->viewData('members')->pluck('email')->all();
        $this->assertContains('owner@example.com', $memberEmails);
        $this->assertContains($viewer->email, $memberEmails);

        $invitationEmails = $component->viewData('pendingInvitations')->pluck('email')->all();
        $this->assertContains('pending@example.com', $invitationEmails);
    }

    public function test_owner_can_invite_a_new_member_and_an_email_is_sent(): void
    {
        Mail::fake();

        Livewire::test(Team::class)->call('invite', 'new-member@example.com', 'viewer');

        $this->assertDatabaseHas('monitor_invitations', ['email' => 'new-member@example.com', 'role' => 'viewer']);
        Mail::assertSent(TeamInvitationMail::class, fn ($mail) => $mail->invitation->email === 'new-member@example.com');
    }

    public function test_admin_can_invite_a_new_member(): void
    {
        Mail::fake();

        $admin = MonitorUser::create([
            'name' => 'Admin', 'email' => 'inviting-admin@example.com',
            'password' => Hash::make('password'), 'role' => 'admin',
        ]);
        $this->actingAs($admin, 'monitor');

        Livewire::test(Team::class)->call('invite', 'admin-invited@example.com', 'viewer');

        $this->assertDatabaseHas('monitor_invitations', ['email' => 'admin-invited@example.com']);
    }

    public function test_viewer_cannot_invite(): void
    {
        Mail::fake();

        $viewer = MonitorUser::create([
            'name' => 'Viewer', 'email' => 'non-inviting-viewer@example.com',
            'password' => Hash::make('password'), 'role' => 'viewer',
        ]);
        $this->actingAs($viewer, 'monitor');

        Livewire::test(Team::class)->call('invite', 'blocked@example.com', 'viewer');

        $this->assertDatabaseMissing('monitor_invitations', ['email' => 'blocked@example.com']);
        Mail::assertNothingSent();
    }

    public function test_inviting_an_existing_members_email_does_not_create_an_invitation(): void
    {
        Mail::fake();

        Livewire::test(Team::class)->call('invite', 'owner@example.com', 'viewer');

        $this->assertSame(0, MonitorInvitation::where('email', 'owner@example.com')->count());
        Mail::assertNothingSent();
    }

    public function test_owner_can_cancel_any_pending_invitation(): void
    {
        $admin = MonitorUser::create([
            'name' => 'Admin', 'email' => 'cancel-test-admin@example.com',
            'password' => Hash::make('password'), 'role' => 'admin',
        ]);
        ['invitation' => $invitation] = MonitorInvitation::createFor('cancel-me@example.com', 'viewer', $admin);

        Livewire::test(Team::class)->call('cancelInvite', $invitation->id);

        $this->assertDatabaseMissing('monitor_invitations', ['id' => $invitation->id]);
    }

    public function test_admin_can_only_cancel_invitations_they_sent_themselves(): void
    {
        $owner = MonitorUser::where('email', 'owner@example.com')->firstOrFail();
        ['invitation' => $ownersInvitation] = MonitorInvitation::createFor('owners-invite@example.com', 'viewer', $owner);

        $admin = MonitorUser::create([
            'name' => 'Admin', 'email' => 'own-invite-admin@example.com',
            'password' => Hash::make('password'), 'role' => 'admin',
        ]);
        ['invitation' => $adminsInvitation] = MonitorInvitation::createFor('admins-invite@example.com', 'viewer', $admin);
        $this->actingAs($admin, 'monitor');

        Livewire::test(Team::class)->call('cancelInvite', $ownersInvitation->id);
        $this->assertDatabaseHas('monitor_invitations', ['id' => $ownersInvitation->id]);

        Livewire::test(Team::class)->call('cancelInvite', $adminsInvitation->id);
        $this->assertDatabaseMissing('monitor_invitations', ['id' => $adminsInvitation->id]);
    }
}
