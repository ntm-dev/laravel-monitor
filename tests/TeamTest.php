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

    public function test_members_are_sorted_by_role_then_id_descending(): void
    {
        $owner = MonitorUser::where('email', 'owner@example.com')->firstOrFail();
        $olderAdmin = MonitorUser::create([
            'name' => 'Older Admin', 'email' => 'older-admin@example.com',
            'password' => Hash::make('password'), 'role' => 'admin',
        ]);
        $newerAdmin = MonitorUser::create([
            'name' => 'Newer Admin', 'email' => 'newer-admin@example.com',
            'password' => Hash::make('password'), 'role' => 'admin',
        ]);
        $viewer = MonitorUser::create([
            'name' => 'A Viewer', 'email' => 'a-viewer@example.com',
            'password' => Hash::make('password'), 'role' => 'viewer',
        ]);

        $members = Livewire::test(Team::class)->viewData('members');

        $this->assertSame(
            [$owner->id, $newerAdmin->id, $olderAdmin->id, $viewer->id],
            $members->pluck('id')->all()
        );
    }

    public function test_members_can_be_filtered_by_role(): void
    {
        MonitorUser::create([
            'name' => 'A Viewer', 'email' => 'a-viewer@example.com',
            'password' => Hash::make('password'), 'role' => 'viewer',
        ]);

        $component = Livewire::test(Team::class)->set('memberRoleFilter', 'viewer');

        $roles = $component->viewData('members')->pluck('role')->unique()->all();
        $this->assertSame(['viewer'], $roles);
    }

    public function test_members_can_be_searched_by_name_or_email(): void
    {
        MonitorUser::create([
            'name' => 'Findable Person', 'email' => 'unrelated@example.com',
            'password' => Hash::make('password'), 'role' => 'viewer',
        ]);
        MonitorUser::create([
            'name' => 'Someone Else', 'email' => 'someone-else@example.com',
            'password' => Hash::make('password'), 'role' => 'viewer',
        ]);

        $members = Livewire::test(Team::class)->set('memberSearch', 'Findable')->viewData('members');

        $this->assertSame(['Findable Person'], $members->pluck('name')->all());
    }

    public function test_owner_can_invite_a_new_member_and_an_email_is_sent(): void
    {
        Mail::fake();

        Livewire::test(Team::class)->call('invite', 'new-member@example.com', 'viewer')
            ->assertDispatched('member-invited');

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
        Livewire::test(Team::class)->call('leave')->assertHasErrors('leave');

        $owner = MonitorUser::where('email', 'owner@example.com')->first();
        $this->assertNotNull($owner, 'the sole owner must still exist — leave() must have been blocked');
    }

    public function test_data_marks_sole_owner_so_the_leave_button_can_be_disabled(): void
    {
        $this->assertTrue(Livewire::test(Team::class)->viewData('soleOwner'));

        MonitorUser::create([
            'name' => 'Second Owner', 'email' => 'second-owner@example.com',
            'password' => Hash::make('password'), 'role' => 'owner',
        ]);

        $this->assertFalse(Livewire::test(Team::class)->viewData('soleOwner'));
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

        Livewire::test(Team::class)->call('requestEmailChange', 'new-for-viewer@example.com', 'password');

        $this->assertDatabaseHas('monitor_email_changes', ['user_id' => $viewer->id, 'new_email' => 'new-for-viewer@example.com']);
        Mail::assertSent(EmailChangeVerificationMail::class);
    }

    public function test_requesting_an_invalid_email_change_does_not_create_a_request(): void
    {
        Mail::fake();

        Livewire::test(Team::class)->call('requestEmailChange', 'not-an-email', 'password')->assertHasErrors('newEmail');

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

        Livewire::test(Team::class)->call('requestEmailChange', 'already-taken@example.com', 'password')->assertHasErrors('newEmail');

        $this->assertSame(0, MonitorEmailChange::count());
        Mail::assertNothingSent();
    }

    public function test_requesting_an_email_change_with_the_wrong_current_password_is_rejected(): void
    {
        Mail::fake();

        Livewire::test(Team::class)->call('requestEmailChange', 'new-owner-email@example.com', 'wrong-password')
            ->assertHasErrors('emailPassword');

        $this->assertSame(0, MonitorEmailChange::count());
        Mail::assertNothingSent();
    }

    public function test_password_can_be_changed_with_the_correct_current_password(): void
    {
        $owner = MonitorUser::where('email', 'owner@example.com')->firstOrFail();

        Livewire::test(Team::class)->call('changePassword', 'password', 'a-new-password', 'a-new-password')
            ->assertHasNoErrors()
            ->assertDispatched('password-changed');

        $this->assertTrue(Hash::check('a-new-password', $owner->fresh()->password));
    }

    public function test_changing_password_with_the_wrong_current_password_is_rejected(): void
    {
        $owner = MonitorUser::where('email', 'owner@example.com')->firstOrFail();
        $originalPassword = $owner->password;

        Livewire::test(Team::class)->call('changePassword', 'wrong-password', 'a-new-password', 'a-new-password')
            ->assertHasErrors('currentPassword');

        $this->assertSame($originalPassword, $owner->fresh()->password);
    }

    public function test_changing_password_with_a_short_new_password_is_rejected(): void
    {
        Livewire::test(Team::class)->call('changePassword', 'password', 'short', 'short')
            ->assertHasErrors('newPassword');
    }

    public function test_changing_password_with_a_mismatched_confirmation_is_rejected(): void
    {
        Livewire::test(Team::class)->call('changePassword', 'password', 'a-new-password', 'a-different-password')
            ->assertHasErrors('newPassword');
    }

    public function test_owner_can_approve_an_admins_verified_email_change(): void
    {
        $admin = MonitorUser::create([
            'name' => 'Admin', 'email' => 'approve-admin-test@example.com',
            'password' => Hash::make('password'), 'role' => 'admin',
        ]);
        ['emailChange' => $emailChange] = MonitorEmailChange::createFor($admin, 'admin-approved-new@example.com');
        $emailChange->forceFill(['verified_at' => now()])->save();

        Livewire::test(Team::class)->call('approveEmailChange', $emailChange->id);

        $this->assertSame('admin-approved-new@example.com', $admin->fresh()->email);
        $this->assertNull(MonitorEmailChange::find($emailChange->id));
    }

    public function test_admin_cannot_approve_another_admins_verified_email_change(): void
    {
        $requestingAdmin = MonitorUser::create([
            'name' => 'Requesting Admin', 'email' => 'requesting-admin-test@example.com',
            'password' => Hash::make('password'), 'role' => 'admin',
        ]);
        $decidingAdmin = MonitorUser::create([
            'name' => 'Deciding Admin', 'email' => 'deciding-admin-test@example.com',
            'password' => Hash::make('password'), 'role' => 'admin',
        ]);
        ['emailChange' => $emailChange] = MonitorEmailChange::createFor($requestingAdmin, 'blocked-admin-new@example.com');
        $emailChange->forceFill(['verified_at' => now()])->save();
        $this->actingAs($decidingAdmin, 'monitor');

        Livewire::test(Team::class)->call('approveEmailChange', $emailChange->id)->assertForbidden();

        $this->assertSame('requesting-admin-test@example.com', $requestingAdmin->fresh()->email);
    }

    public function test_owner_or_admin_can_approve_a_viewers_verified_email_change(): void
    {
        $viewer = MonitorUser::create([
            'name' => 'Viewer', 'email' => 'approve-viewer-test@example.com',
            'password' => Hash::make('password'), 'role' => 'viewer',
        ]);
        ['emailChange' => $emailChange] = MonitorEmailChange::createFor($viewer, 'viewer-approved-new@example.com');
        $emailChange->forceFill(['verified_at' => now()])->save();

        $admin = MonitorUser::create([
            'name' => 'Approving Admin', 'email' => 'approving-admin-test@example.com',
            'password' => Hash::make('password'), 'role' => 'admin',
        ]);
        $this->actingAs($admin, 'monitor');

        Livewire::test(Team::class)->call('approveEmailChange', $emailChange->id);

        $this->assertSame('viewer-approved-new@example.com', $viewer->fresh()->email);
    }

    public function test_viewer_cannot_approve_or_reject_another_viewers_email_change(): void
    {
        $requestingViewer = MonitorUser::create([
            'name' => 'Requesting Viewer', 'email' => 'requesting-viewer-test@example.com',
            'password' => Hash::make('password'), 'role' => 'viewer',
        ]);
        ['emailChange' => $emailChange] = MonitorEmailChange::createFor($requestingViewer, 'blocked-viewer-new@example.com');
        $emailChange->forceFill(['verified_at' => now()])->save();

        $decidingViewer = MonitorUser::create([
            'name' => 'Deciding Viewer', 'email' => 'deciding-viewer-test@example.com',
            'password' => Hash::make('password'), 'role' => 'viewer',
        ]);
        $this->actingAs($decidingViewer, 'monitor');

        Livewire::test(Team::class)->call('approveEmailChange', $emailChange->id)->assertForbidden();
        Livewire::test(Team::class)->call('rejectEmailChange', $emailChange->id)->assertForbidden();

        $this->assertNotNull(MonitorEmailChange::find($emailChange->id));
    }

    public function test_rejecting_an_email_change_deletes_it_without_changing_the_email(): void
    {
        $admin = MonitorUser::create([
            'name' => 'Admin', 'email' => 'reject-admin-test@example.com',
            'password' => Hash::make('password'), 'role' => 'admin',
        ]);
        ['emailChange' => $emailChange] = MonitorEmailChange::createFor($admin, 'rejected-new-email@example.com');
        $emailChange->forceFill(['verified_at' => now()])->save();

        Livewire::test(Team::class)->call('rejectEmailChange', $emailChange->id);

        $this->assertSame('reject-admin-test@example.com', $admin->fresh()->email);
        $this->assertNull(MonitorEmailChange::find($emailChange->id));
    }

    public function test_approving_an_unverified_email_change_does_nothing(): void
    {
        $admin = MonitorUser::create([
            'name' => 'Admin', 'email' => 'unverified-admin-test@example.com',
            'password' => Hash::make('password'), 'role' => 'admin',
        ]);
        ['emailChange' => $emailChange] = MonitorEmailChange::createFor($admin, 'unverified-new@example.com');

        Livewire::test(Team::class)->call('approveEmailChange', $emailChange->id);

        $this->assertSame('unverified-admin-test@example.com', $admin->fresh()->email);
        $this->assertNotNull(MonitorEmailChange::find($emailChange->id), 'an unverified request must not be silently deleted either');
    }

    public function test_approving_an_email_change_whose_target_email_was_claimed_meanwhile_fails_cleanly(): void
    {
        $admin = MonitorUser::create([
            'name' => 'Admin', 'email' => 'race-admin-test@example.com',
            'password' => Hash::make('password'), 'role' => 'admin',
        ]);
        ['emailChange' => $emailChange] = MonitorEmailChange::createFor($admin, 'claimed-meanwhile@example.com');
        $emailChange->forceFill(['verified_at' => now()])->save();

        // Someone else claims the target email between verification and approval.
        MonitorUser::create([
            'name' => 'Someone Else', 'email' => 'claimed-meanwhile@example.com',
            'password' => Hash::make('password'), 'role' => 'viewer',
        ]);

        Livewire::test(Team::class)->call('approveEmailChange', $emailChange->id)->assertHasErrors('emailChange');

        $this->assertSame('race-admin-test@example.com', $admin->fresh()->email, 'approval must not overwrite the requester\'s email once the target is taken');
        $this->assertNotNull(MonitorEmailChange::find($emailChange->id), 'the pending row must survive a failed approval so it can be re-decided');
    }

    public function test_an_unverified_email_change_never_appears_in_pending_email_changes(): void
    {
        $admin = MonitorUser::create([
            'name' => 'Admin', 'email' => 'unverified-visibility-test@example.com',
            'password' => Hash::make('password'), 'role' => 'admin',
        ]);
        MonitorEmailChange::createFor($admin, 'not-yet-verified@example.com');

        $component = Livewire::test(Team::class);

        $this->assertTrue($component->viewData('pendingEmailChanges')->isEmpty());
    }

    public function test_the_team_page_renders_when_a_verified_email_changes_requester_no_longer_exists(): void
    {
        $admin = MonitorUser::create([
            'name' => 'Admin', 'email' => 'orphan-visibility-test@example.com',
            'password' => Hash::make('password'), 'role' => 'admin',
        ]);
        ['emailChange' => $emailChange] = MonitorEmailChange::createFor($admin, 'orphan-new-email@example.com');
        $emailChange->forceFill(['verified_at' => now()])->save();

        $admin->delete();

        $component = Livewire::test(Team::class);

        $this->assertTrue($component->viewData('pendingEmailChanges')->isEmpty());
    }

    public function test_approving_an_email_change_whose_requester_no_longer_exists_deletes_it_without_erroring(): void
    {
        $admin = MonitorUser::create([
            'name' => 'Admin', 'email' => 'orphan-approve-test@example.com',
            'password' => Hash::make('password'), 'role' => 'admin',
        ]);
        ['emailChange' => $emailChange] = MonitorEmailChange::createFor($admin, 'orphan-approve-new@example.com');
        $emailChange->forceFill(['verified_at' => now()])->save();

        $admin->delete();

        Livewire::test(Team::class)->call('approveEmailChange', $emailChange->id);

        $this->assertNull(MonitorEmailChange::find($emailChange->id));
    }

    public function test_rejecting_an_email_change_whose_requester_no_longer_exists_deletes_it_without_erroring(): void
    {
        $admin = MonitorUser::create([
            'name' => 'Admin', 'email' => 'orphan-reject-test@example.com',
            'password' => Hash::make('password'), 'role' => 'admin',
        ]);
        ['emailChange' => $emailChange] = MonitorEmailChange::createFor($admin, 'orphan-reject-new@example.com');
        $emailChange->forceFill(['verified_at' => now()])->save();

        $admin->delete();

        Livewire::test(Team::class)->call('rejectEmailChange', $emailChange->id);

        $this->assertNull(MonitorEmailChange::find($emailChange->id));
    }
}
