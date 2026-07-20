<?php

namespace LaravelMonitor\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use LaravelMonitor\Livewire\Team;
use LaravelMonitor\Mail\EmailChangeVerificationMail;
use LaravelMonitor\Mail\TeamInvitationMail;
use LaravelMonitor\Models\MonitorEmailChange;
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

    public function test_inviting_an_invalid_email_does_not_create_an_invitation(): void
    {
        Mail::fake();

        Livewire::test(Team::class)->call('invite', 'not-an-email', 'viewer')->assertHasErrors('email');

        $this->assertSame(0, MonitorInvitation::where('email', 'not-an-email')->count());
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

    public function test_owner_can_change_a_members_role(): void
    {
        $viewer = MonitorUser::create([
            'name' => 'Viewer', 'email' => 'role-change-target@example.com',
            'password' => Hash::make('password'), 'role' => 'viewer',
        ]);

        Livewire::test(Team::class)->call('changeRole', $viewer->id, 'admin');

        $this->assertSame('admin', $viewer->fresh()->role);
    }

    public function test_admin_cannot_change_a_members_role(): void
    {
        $admin = MonitorUser::create([
            'name' => 'Admin', 'email' => 'non-role-changing-admin@example.com',
            'password' => Hash::make('password'), 'role' => 'admin',
        ]);
        $viewer = MonitorUser::create([
            'name' => 'Viewer', 'email' => 'protected-role-target@example.com',
            'password' => Hash::make('password'), 'role' => 'viewer',
        ]);
        $this->actingAs($admin, 'monitor');

        Livewire::test(Team::class)->call('changeRole', $viewer->id, 'admin')->assertForbidden();

        $this->assertSame('viewer', $viewer->fresh()->role);
    }

    public function test_owner_can_remove_a_member(): void
    {
        $viewer = MonitorUser::create([
            'name' => 'Viewer', 'email' => 'to-be-removed@example.com',
            'password' => Hash::make('password'), 'role' => 'viewer',
        ]);

        Livewire::test(Team::class)->call('removeMember', $viewer->id);

        $this->assertNull(MonitorUser::find($viewer->id));
    }

    public function test_owner_cannot_remove_themself(): void
    {
        $owner = MonitorUser::where('email', 'owner@example.com')->firstOrFail();

        Livewire::test(Team::class)->call('removeMember', $owner->id)->assertForbidden();

        $this->assertNotNull(MonitorUser::find($owner->id));
    }

    public function test_admin_cannot_remove_a_member(): void
    {
        $admin = MonitorUser::create([
            'name' => 'Admin', 'email' => 'non-removing-admin@example.com',
            'password' => Hash::make('password'), 'role' => 'admin',
        ]);
        $viewer = MonitorUser::create([
            'name' => 'Viewer', 'email' => 'protected-from-admin@example.com',
            'password' => Hash::make('password'), 'role' => 'viewer',
        ]);
        $this->actingAs($admin, 'monitor');

        Livewire::test(Team::class)->call('removeMember', $viewer->id)->assertForbidden();

        $this->assertNotNull(MonitorUser::find($viewer->id));
    }

    public function test_a_non_sole_owner_member_can_leave(): void
    {
        $viewer = MonitorUser::create([
            'name' => 'Viewer', 'email' => 'leaving-viewer@example.com',
            'password' => Hash::make('password'), 'role' => 'viewer',
        ]);
        $this->actingAs($viewer, 'monitor');

        Livewire::test(Team::class)->call('leave')->assertRedirect('/monitor/login');

        $this->assertNull(MonitorUser::find($viewer->id));
    }

    public function test_the_sole_owner_cannot_leave(): void
    {
        Livewire::test(Team::class)->call('leave');

        $owner = MonitorUser::where('email', 'owner@example.com')->first();
        $this->assertNotNull($owner, 'the sole owner must still exist — leave() must have been blocked');
    }

    public function test_owner_can_transfer_ownership_and_becomes_admin(): void
    {
        $viewer = MonitorUser::create([
            'name' => 'Future Owner', 'email' => 'future-owner@example.com',
            'password' => Hash::make('password'), 'role' => 'viewer',
        ]);
        $originalOwner = MonitorUser::where('email', 'owner@example.com')->firstOrFail();

        Livewire::test(Team::class)->call('transferOwnership', $viewer->id);

        $this->assertSame('owner', $viewer->fresh()->role);
        $this->assertSame('admin', $originalOwner->fresh()->role);
    }

    public function test_admin_cannot_transfer_ownership(): void
    {
        $admin = MonitorUser::create([
            'name' => 'Admin', 'email' => 'non-transferring-admin@example.com',
            'password' => Hash::make('password'), 'role' => 'admin',
        ]);
        $viewer = MonitorUser::create([
            'name' => 'Viewer', 'email' => 'not-getting-owner@example.com',
            'password' => Hash::make('password'), 'role' => 'viewer',
        ]);
        $this->actingAs($admin, 'monitor');

        Livewire::test(Team::class)->call('transferOwnership', $viewer->id)->assertForbidden();

        $this->assertSame('viewer', $viewer->fresh()->role);
    }

    public function test_any_role_can_request_an_email_change_for_themself(): void
    {
        Mail::fake();

        $viewer = MonitorUser::create([
            'name' => 'Viewer', 'email' => 'email-change-requester@example.com',
            'password' => Hash::make('password'), 'role' => 'viewer',
        ]);
        $this->actingAs($viewer, 'monitor');

        Livewire::test(Team::class)->call('requestEmailChange', 'new-for-viewer@example.com');

        $this->assertDatabaseHas('monitor_email_changes', ['user_id' => $viewer->id, 'new_email' => 'new-for-viewer@example.com']);
        Mail::assertSent(EmailChangeVerificationMail::class);
    }

    public function test_requesting_an_invalid_email_change_does_not_create_a_request(): void
    {
        Mail::fake();

        Livewire::test(Team::class)->call('requestEmailChange', 'not-an-email')->assertHasErrors('newEmail');

        $this->assertSame(0, MonitorEmailChange::count());
        Mail::assertNothingSent();
    }

    public function test_requesting_an_email_change_to_an_email_already_in_use_is_rejected(): void
    {
        Mail::fake();

        MonitorUser::create([
            'name' => 'Existing', 'email' => 'already-taken@example.com',
            'password' => Hash::make('password'), 'role' => 'viewer',
        ]);

        Livewire::test(Team::class)->call('requestEmailChange', 'already-taken@example.com')->assertHasErrors('newEmail');

        $this->assertSame(0, MonitorEmailChange::count());
        Mail::assertNothingSent();
    }
}
