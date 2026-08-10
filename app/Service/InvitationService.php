<?php

declare(strict_types=1);

namespace App\Service;

use App\Enums\Role;
use App\Events\OrganizationInvitationAdding;
use App\Exceptions\Api\InvitationForTheEmailAlreadyExistsApiException;
use App\Exceptions\Api\UserIsAlreadyMemberOfOrganizationApiException;
use App\Mail\OrganizationInvitationMail;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Session;

class InvitationService
{
    /**
     * Set when someone follows an invitation link without having an account yet.
     *
     * This is a normal session value rather than flash data on purpose: it has to survive
     * both the redirect to the sign-up screen and the form submission that follows it.
     * Nothing clears it, and nothing needs to - it only reveals the screen, and which email
     * may actually register is decided against the invitations table by hasInvitationFor().
     */
    public const REGISTRATION_INVITEE_SESSION_KEY = 'invitation.registration_invitee';

    /**
     * @throws UserIsAlreadyMemberOfOrganizationApiException|InvitationForTheEmailAlreadyExistsApiException
     */
    public function inviteUser(Organization $organization, string $email, Role $role, User $inviter): OrganizationInvitation
    {
        // Normalize the email so it matches how user emails are stored (see UserService::createUser),
        // otherwise a mixed-case invite silently fails to link on registration.
        $email = strtolower($email);

        if (app(MemberService::class)->isEmailAlreadyMember($organization, $email)) {
            throw new UserIsAlreadyMemberOfOrganizationApiException;
        }

        if (OrganizationInvitation::query()
            ->where('email', $email)
            ->whereBelongsTo($organization, 'organization')
            ->exists()) {
            throw new InvitationForTheEmailAlreadyExistsApiException;
        }

        OrganizationInvitationAdding::dispatch($organization, $email, $role, $inviter);

        $invitation = new OrganizationInvitation;
        $invitation->email = $email;
        $invitation->role = $role->value;
        $invitation->organization()->associate($organization);
        $invitation->save();

        Mail::to($email)->queue(new OrganizationInvitationMail($invitation));

        return $invitation;
    }

    public function rememberInviteeForRegistration(string $email): void
    {
        Session::put(self::REGISTRATION_INVITEE_SESSION_KEY, strtolower($email));
    }

    public function isInviteeRegistering(): bool
    {
        return Session::has(self::REGISTRATION_INVITEE_SESSION_KEY);
    }

    /**
     * Whether an invitation exists for this email, in any organization and whether or not it
     * has been accepted yet. This is what lets an invited person sign up while registration
     * is otherwise switched off, so it is deliberately checked against the database rather
     * than anything the browser sends. Casing follows processAcceptedInvitations().
     */
    public function hasInvitationFor(string $email): bool
    {
        return OrganizationInvitation::query()
            ->whereRaw('lower(email) = ?', [strtolower($email)])
            ->exists();
    }

    /**
     * @return Collection<int, Organization>
     */
    public function processAcceptedInvitations(User $user): Collection
    {
        $organizations = new Collection;

        $invitations = OrganizationInvitation::query()
            ->whereRaw('lower(email) = ?', [strtolower($user->email)])
            ->whereNotNull('accepted_at')
            ->get();

        foreach ($invitations as $invitation) {
            $organization = $invitation->organization;
            $role = Role::tryFrom($invitation->role);
            if ($role === null) {
                Log::error('Invalid role in invitation', [
                    'invitation' => $invitation->getKey(),
                    'role' => $invitation->role,
                ]);

                continue;
            }
            app(MemberService::class)->addMember($user, $organization, $role);

            $invitation->delete();

            $organizations->push($organization);
        }

        return $organizations;
    }
}
