<?php

namespace App\Actions\Teams;

use App\Enums\TeamRole;
use App\Models\DashboardWidget;
use App\Models\Team;
use App\Models\User;
use App\Services\LokiOverridesWriter;
use Illuminate\Support\Facades\DB;

class CreateTeam
{
    public function __construct(private LokiOverridesWriter $overrides) {}

    /**
     * Create a new team and add the user as owner.
     */
    public function handle(User $user, string $name, bool $isPersonal = false): Team
    {
        $team = DB::transaction(function () use ($user, $name, $isPersonal) {
            $team = Team::create([
                'name' => $name,
                'is_personal' => $isPersonal,
            ]);

            $membership = $team->memberships()->create([
                'user_id' => $user->id,
                'role' => TeamRole::Owner,
            ]);

            $user->switchTeam($team);

            DashboardWidget::seedDefaults($team);

            return $team;
        });

        $this->overrides->write();

        return $team;
    }
}
