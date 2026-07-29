import { InfoIcon } from 'lucide-react';
import { Alert, AlertDescription } from '@/components/ui/alert';
import type { TeamInvitationContext } from '@/types';

type Props = {
    invitation: TeamInvitationContext;
    action: 'Log in' | 'Register';
};

export default function TeamInvitationAlert({ invitation, action }: Props) {
    return (
        <Alert
            data-test="team-invitation-alert"
            className="border-blue-900/50 bg-blue-950/50 text-blue-100 [&>svg]:text-blue-400"
        >
            <InfoIcon />
            <AlertDescription className="text-blue-100">
                {action} to join the "{invitation.teamName}" team.
            </AlertDescription>
        </Alert>
    );
}
