<?php

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('offers the same three appearance choices in the sidebar and account settings', function () {
    [$user, $team] = p2Business();
    $this->actingAs($user);

    $dashboard = $this->get(route('dashboard', $team))->assertOk();
    $settings = $this->get(route('appearance.edit'))->assertOk();
    foreach (['System', 'Light', 'Dark'] as $label) {
        $button = 'aria-label="'.$label.' appearance"';
        $dashboard->assertSee($button, false);
        expect(substr_count($settings->getContent(), $button))->toBe(2);
    }
});
