<?php

use App\Models\User;

it('no longer exposes an appearance settings page', function () {
    $this->actingAs(User::factory()->create())
        ->get('/settings/appearance')
        ->assertNotFound();
});
